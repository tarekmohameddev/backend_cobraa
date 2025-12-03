## Stox Integration Module - Feature Reference

**Purpose**: Export internal orders from UzMart to Stox as a fulfillment provider, supporting multiple Stox accounts, manual exports, automatic exports on status change, and full operation logging.

---

### 1. Module Overview

```text
Modules/Stox/
├── Config/stox.php
├── Database/Migrations/
│   ├── create_stox_accounts_table.php
│   ├── create_stox_orders_table.php
│   └── create_stox_operation_logs_table.php
├── Entities/
│   ├── StoxAccount.php
│   ├── StoxOrder.php
│   └── StoxOperationLog.php
├── Http/Controllers/Dashboard/Admin/
│   ├── StoxAccountController.php
│   ├── StoxOrderController.php
│   └── StoxOperationLogController.php
├── Http/Requests/
│   ├── StoxAccountRequest.php
│   ├── StoxAccountUpdateRequest.php
│   └── StoxOrderExportRequest.php
├── Http/Resources/
│   ├── StoxAccountResource.php
│   ├── StoxOrderResource.php
│   └── StoxOperationLogResource.php
├── Jobs/
│   └── ExportOrderToStoxJob.php
├── Observers/
│   └── OrderStatusObserver.php
├── Providers/
│   ├── RouteServiceProvider.php
│   └── StoxServiceProvider.php
├── Repositories/
│   ├── StoxAccountRepository.php
│   ├── StoxOrderRepository.php
│   └── StoxOperationLogRepository.php
├── Services/
│   ├── StoxApiService.php
│   ├── StoxOrderExportService.php
│   └── StoxOperationLogger.php
├── Traits/
│   └── LogsStoxOperations.php
└── Routes/api.php
```

---

### 2. Data Model

#### 2.1 `StoxAccount`

- Fields:
  - `name`, `description`, `status` (`active|inactive`)
  - `base_url` (default `https://merchants.stox-eg.com/api`)
  - `bearer_token` (encrypted Stox API token)
  - `webhook_signature` (for future webhook validation)
  - `settings` (JSON: per-account config, e.g. `shop_ids`, `payment_types`, `mobile_2`, `can_open`)
  - `default_payment_mapping` (JSON, reserved)
  - `auto_export_statuses` (JSON array of order statuses that trigger auto-export)
  - `export_delay_minutes` (int)

- Behavior:
  - `bearer_token` is **encrypted at rest** via accessors/mutators on `StoxAccount`.
  - `isActive()` helper for status checks.

#### 2.2 `StoxOrder`

- Tracks link between internal orders and Stox orders.
- Fields:
  - `stox_account_id`, `order_id`
  - `external_order_id` (Stox order id)
  - `awb_number`
  - `reference_number`
  - `export_status` (`pending|exporting|success|failed`)
  - `retry_count`
  - `last_error`
  - `exported_at`
  - `export_payload` (JSON snapshot of what we sent)
  - `response_data` (JSON snapshot of Stox response)

- Methods:
  - `markExported(externalId, awbNumber, response)` updates status, IDs, snapshot, timestamp.

#### 2.3 `StoxOperationLog`

- Audit trail of all Stox-related operations.
- Fields:
  - `stox_account_id`, `stox_order_id`, `order_id`, `user_id`
  - `operation_type` (e.g. `order_export_initiated`, `order_export_success`, `order_export_failed`)
  - `trigger_type` (`manual|automatic`)
  - `http_status`, `execution_time_ms`
  - `request_data`, `response_data` (JSON)
  - `error_message`, `stack_trace`
  - `ip_address`, `user_agent`

---

### 3. Configuration

#### 3.1 `Config/stox.php`

- Keys:
  - `enabled` (bool, default `false`)
  - `default_base_url` (`https://merchants.stox-eg.com/api`)
  - `queue_name` (queue name for exports, default `default`)
  - `max_retry_attempts`
  - `operation_log_retention_days`

Per-account overrides (base URL / token / delay / filters) live on `stox_accounts` records.

---

### 4. API Endpoints (Laravel Side)

Base path (protected by Sanctum):  
`/api/v1/dashboard/admin/stox/...`

#### 4.1 Accounts

- `GET /accounts`  
  - List accounts with filters (status, search, per_page).

- `POST /accounts`  
  - Body: `StoxAccountRequest` (name, bearer_token, optional settings…).

- `GET /accounts/{id}`  
  - Returns single account (`StoxAccountResource`).

- `PUT /accounts/{id}`  
  - Body: `StoxAccountUpdateRequest` (bearer_token optional; other fields patchable).

- `DELETE /accounts/{id}`  
  - Soft-remove account from use.

- `POST /accounts/{id}/test-connection`  
  - Uses `StoxApiService::testConnection()` to call `GET /api/orders?page=1` on Stox.
  - Verifies Stox base URL + bearer token are valid.

#### 4.2 Orders (Export to Stox)

- `GET /orders`  
  - List `StoxOrder` records with filters (status, stox_account_id, order_id, date range).

- `POST /orders/{orderId}/export`  
  - Body: `StoxOrderExportRequest`:
    - `stox_account_id` (required)
    - `override_data` (optional, see payload mapping below)
  - Triggers synchronous export via `StoxOrderExportService`.

- `GET /orders/{stoxOrderId}`  
  - Returns `StoxOrderResource` (includes account + internal `order` when loaded).

- `POST /orders/{stoxOrderId}/retry`  
  - Re-sends using stored `export_payload` for that Stox order.

#### 4.3 Operation Logs

- `GET /operation-logs`  
  - Paginated listing of `StoxOperationLog` with filters (operation_type, account, order, trigger_type, date range).

- `GET /operation-logs/export`  
  - CSV export of filtered logs.

---

### 5. Export Flow

#### 5.1 Trigger Paths

1. **Manual export**:
   - Admin calls `POST /orders/{orderId}/export` with `stox_account_id`.
2. **Automatic export**:
   - `OrderStatusObserver` observes `Order::updated`.
   - If `status` changed and matches `StoxAccount.auto_export_statuses`, and optional filters (shop, payment types) pass, it dispatches `ExportOrderToStoxJob`.

#### 5.2 Export Service: `StoxOrderExportService::export`

Steps:

1. **Validation & loading**:
   - Ensure Stox account is `active`.
   - Load order relations:
     - `orderDetails.stock.product`
     - `children.orderDetails.stock.product` (for parent orders)
     - `user`, `myAddress`

2. **Build payload** (`buildPayload`):
   - Derive:
     - `customer_name`: from override, or `order.username` / user firstname.
     - `address`: from override or order address.
     - `mobile_1`, `mobile_2`: from override / order / account settings.
     - `area_id`, `area_name`: from override or order address; defaults to simple fallback.
     - `email`.
     - `reference_number`: override or order id.
     - `payment_type` + `payment_type_id`:
       - From override if provided.
       - Else from latest `Transaction` for the order:
         - `status = paid` → `CC`
         - otherwise → `COD`.
     - `cod_amount`:
       - Override if provided.
       - Else: if `payment_type = COD`, use order total.
     - `qty`:
       - Override if provided.
       - Else sum of `orderDetails.quantity`.
     - `note`, `can_open`, `is_part_delivered`, `return_qty`.
   - `products`: via `buildProductPayload()`:
     - If `override_data.products` is present:
       - Normalize each to `{id|null, sku, qty:int, item_price|null}`.
     - Else:
       - Use `orderDetails` (or children’s details for parent orders).
       - Map each to `{id (product id), sku (stock.sku), qty, item_price}`.
       - If no product has a valid `sku`, throw `RuntimeException("Order does not contain mappable products for Stox.")`.

3. **Persist StoxOrder** (`getOrCreateStoxOrder`):
   - `firstOrCreate` by `(stox_account_id, order_id)`.
   - Update `reference_number`, set `export_status = exporting`, bump `retry_count`, and store `export_payload`.

4. **Call Stox API** (`StoxApiService::sendOrder`):
   - Build body: `{ "orders": [ {payload} ] }`.
   - POST to `stox_accounts.base_url . '/orders/store'` with:
     - Headers:
       - `Accept: application/json`
       - `Content-Type: application/json`
       - `Authorization: Bearer <decrypted bearer_token>`

5. **Handle response**:
   - On success:
     - Parse Stox response `data[0].id` and `data[0].awb_number`.
     - Call `StoxOrder::markExported()` to set `external_order_id`, `awb_number`, `export_status = success`, `response_data`, `exported_at`.
   - On error:
     - Set `StoxOrder.export_status = failed`, `last_error`.

6. **Operation logging**:
   - All important stages log a `StoxOperationLog` via `LogsStoxOperations` / `StoxOperationLogger`.

#### 5.3 Order status after successful export (UzMart side)

- After a successful export to Stox, the service also updates the **internal order status** using `OrderStatusUpdateService` (the same flow used by `/api/v1/dashboard/admin/order/{id}/status`):
  - If the current status is **not** `accepted`, it first calls `statusUpdate(order, ['status' => 'accepted', 'notes' => []])`.
  - Then it calls `statusUpdate(order, ['status' => 'on_a_way', 'notes' => []])`.
- This means that, for all Stox exports (manual and automatic), the order is automatically transitioned to `on_a_way` after a successful Stox response, without needing a separate admin API call.
- If any of these status updates fail (validation, DB errors, etc.), the export still counts as **successful**, but a `StoxOperationLog` with `operation_type = order_status_auto_update_failed` is written for debugging.

---

### 6. Request Payload Shape (to Stox)

Final payload for a single order (before wrapping in `orders`):

```json
{
  "customer_name": "Tarek Mohamed",
  "address": "dadada",
  "mobile_1": "01155522984",
  "mobile_2": "01100000000",
  "area_id": 331,
  "area_name": "331",
  "email": "tarek@example.com",
  "reference_number": "1013",
  "payment_type": "COD",
  "payment_type_id": "COD",
  "cod_amount": 23,
  "qty": 3,
  "note": "Test order from API",
  "can_open": true,
  "is_part_delivered": false,
  "products": [
    {
      "id": null,
      "sku": "Cobraa-TMMaster-Black",
      "qty": 3,
      "item_price": 100
    }
  ]
}
```

Wrapped for Stox API:

```json
{
  "orders": [
    { /* payload above */ }
  ]
}
```

Stox’s validation is sensitive to:

- `orders` must be a non-empty array.
- `payment_type` is required (`COD` or Stox-accepted value).
- `products[*].sku` must exist in Stox catalog for that merchant.

---

### 7. Usage Notes / Gotchas

- **Bearer token**:
  - Must be saved as a **plain** Stox token via the admin API; the model encrypts it.
  - Do not insert pre-encrypted values manually.

- **Route model binding**:
  - Stox routes skip Laravel’s default `api` group; controllers load models by ID manually.
  - This avoids side-effects from global middleware while keeping behavior explicit.

- **Parent orders**:
  - If a parent order has no `orderDetails`, children’s details are used to build products.

- **Override data**:
  - `override_data` allows forcing external-facing values (area, totals, products, etc.) without changing the core order.
  - Useful for debugging and matching known-good payloads (e.g. from Postman).

- **Order status auto-update after export**:
  - You no longer need to manually call `/api/v1/dashboard/admin/order/{id}/status` to move orders to `on_a_way` after exporting to Stox.
  - The system will automatically:
    - Move the order to `accepted` (if it is not already).
    - Then move it to `on_a_way`, reusing the central `OrderStatusUpdateService` so all usual side effects (notifications, cashback, etc.) still apply.
  - Failures in this auto-update path are logged as Stox operations but do **not** mark the Stox export itself as failed.

---

### 8. Future Enhancements (Ideas)

- SKU mapping table:
  - Allow internal SKUs to map to Stox SKUs instead of requiring them to match 1:1.
- Area mapping:
  - Store mapping from internal area IDs to Stox `area_id` / `area_name`.
- Status sync:
  - Add webhooks/cron to synchronize Stox order statuses back to internal orders.
- Retry & backoff strategies:
  - Distinguish between transient errors (network / 5xx) and permanent validation errors.


