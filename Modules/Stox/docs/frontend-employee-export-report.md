# STOX Employee Export Report – Frontend Implementation

This document describes how to integrate the **STOX Employee Export Counts** report in the frontend (admin dashboard).

---

## 1. Overview

The report returns how many orders have been successfully sent to STOX per employee, based on STOX operation logs. Counts are filtered by the date range of **export** (`exported_at`). Only employees who have at least one exported order in the selected period appear in the list.

---

## 2. API Reference

### Endpoint

| Property | Value |
|----------|--------|
| **Method** | `GET` |
| **URL** | `/api/v1/dashboard/admin/stox/reports/employee-export-counts` |
| **Authentication** | Required (Bearer token, e.g. Sanctum) |
| **Content-Type** | `application/json` (response) |

### Query parameters (all optional)

| Parameter   | Type   | Required | Format   | Description |
|------------|--------|----------|----------|-------------|
| `date_from` | string | No       | `Y-m-d`  | Start of date range (inclusive). Filter by order **export** date. |
| `date_to`   | string | No       | `Y-m-d`  | End of date range (inclusive). Must be `>= date_from` if both are sent. |

**Examples**

- Last 30 days (client-side example):  
  `date_from=2026-01-08&date_to=2026-02-07`
- No filters: omit both params to get counts for all time.

---

## 3. Success response (200 OK)

### Response envelope

All success responses use the same envelope:

```json
{
  "timestamp": "2026-02-07T18:00:00.000000Z",
  "status": true,
  "message": "...",
  "data": [ ... ]
}
```

- **timestamp** (string): Server time of the response.
- **status** (boolean): Always `true` on success.
- **message** (string): Success message (can be used for toasts/notifications).
- **data** (array): List of employee export count objects (see below).

### Data item shape

Each element in `data`:

| Field                   | Type    | Description |
|-------------------------|---------|-------------|
| `user_id`               | number \| null | User (employee) ID. `null` when the export was not attributed to a user (e.g. system/queue). |
| `user_name`             | string \| null | Display name (e.g. firstname + lastname). `null` when `user_id` is null or name is missing. |
| `orders_exported_count` | number  | Count of **distinct** orders exported to STOX by this employee in the filtered period. |

### Example success response

```json
{
  "timestamp": "2026-02-07T18:00:00.000000Z",
  "status": true,
  "message": "OK",
  "data": [
    {
      "user_id": 1,
      "user_name": "John Doe",
      "orders_exported_count": 42
    },
    {
      "user_id": 5,
      "user_name": "Jane Smith",
      "orders_exported_count": 18
    },
    {
      "user_id": null,
      "user_name": null,
      "orders_exported_count": 3
    }
  ]
}
```

---

## 4. Error responses

### Validation errors (422 Unprocessable Entity)

Sent when query parameters are invalid (e.g. wrong date format or `date_to` &lt; `date_from`).

**Example body:**

```json
{
  "message": "The date from field must match the format Y-m-d.",
  "errors": {
    "date_from": ["The date from field must match the format Y-m-d."]
  }
}
```

Common cases:

- `date_from` or `date_to` not in `Y-m-d` format.
- `date_to` is before `date_from`.

### Unauthenticated (401)

Missing or invalid Bearer token. Handle like other admin API 401 responses (e.g. redirect to login).

---

## 5. Frontend implementation notes

### Request

- Use the same base URL and auth as other dashboard admin STOX APIs (e.g. `/api/v1/dashboard/admin/stox/...`).
- Send optional `date_from` and `date_to` as **query parameters**.
- Dates must be **YYYY-MM-DD** (e.g. `2026-02-07`). If the UI uses another format or timezone, convert to this format before calling the API.

### Display

- Show a table or list: columns e.g. **Employee** (use `user_name`; if `null`, show a fallback like “System” or “—”), **Orders exported** (`orders_exported_count`).
- You can sort client-side by `orders_exported_count` or `user_name` as needed.
- Empty `data` means no exports in the selected period (or no filters and no exports at all).

### Filters (UI)

- **Date range**: two date pickers or a range picker; send selected values as `date_from` and `date_to` in `Y-m-d`.
- Both filters optional: user can run the report for “all time” or for a custom range.
- Validate in the UI that `date_to >= date_from` to avoid 422.

### Loading and errors

- Show a loading state while the request is in progress.
- On 422, show validation messages from `errors` (e.g. under the form or in a toast).
- On 401, follow the app’s standard auth handling.

---

## 6. Example usage (JavaScript)

```javascript
const baseUrl = '/api/v1/dashboard/admin/stox';
const token = 'YOUR_SANCTUM_BEARER_TOKEN';

async function fetchEmployeeExportReport(params = {}) {
  const searchParams = new URLSearchParams();
  if (params.dateFrom) searchParams.set('date_from', params.dateFrom);
  if (params.dateTo) searchParams.set('date_to', params.dateTo);

  const url = `${baseUrl}/reports/employee-export-counts?${searchParams.toString()}`;
  const res = await fetch(url, {
    method: 'GET',
    headers: {
      'Accept': 'application/json',
      'Authorization': `Bearer ${token}`,
    },
  });

  const json = await res.json();
  if (!res.ok) {
    throw new Error(json.message || 'Request failed');
  }
  return json.data; // array of { user_id, user_name, orders_exported_count }
}

// Example: last 30 days
const end = new Date();
const start = new Date();
start.setDate(start.getDate() - 30);
const dateFrom = start.toISOString().slice(0, 10);
const dateTo = end.toISOString().slice(0, 10);

const reportData = await fetchEmployeeExportReport({ dateFrom, dateTo });
```

---

## 7. Summary

| Item | Detail |
|------|--------|
| **Endpoint** | `GET /api/v1/dashboard/admin/stox/reports/employee-export-counts` |
| **Auth** | Bearer (Sanctum) |
| **Query params** | `date_from`, `date_to` (optional, `Y-m-d`) |
| **Success** | 200, `data`: array of `{ user_id, user_name, orders_exported_count }` |
| **Errors** | 401 Unauthorized, 422 Validation (date format / range) |
