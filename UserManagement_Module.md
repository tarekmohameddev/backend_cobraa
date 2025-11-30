# Enhanced User Management Module

This module (`UserManagement`) provides simplified and enhanced endpoints for managing users, addresses, and order locations, specifically designed for POS and Admin Dashboard usage.

## Module Overview

- **Path:** `Modules/UserManagement`
- **Prefix:** `/api/v1/dashboard/admin/user-management`
- **Middleware:** `auth:sanctum` (Admin Only)

## Key Features

### 1. Unified User & Address Creation
Replaces the multi-step process of creating a user and then an address.
- **Endpoint:** `POST /users`
- **Function:** Creates a User and a linked UserAddress in a single atomic transaction.
- **Payload (request body):**
  ```json
  {
      "name": "John",
      "phone": "998901234567",
      "second_phone": "998909876543", // Optional
      "title": "Home",
      "area_id": 5,                   // Required
      "city_id": 1,                   // Optional: auto-derived from area if omitted
      "address": "Bänikonstrasse, 8426 Lufingen, Switzerland",
      "note": "Leave at door",
      "location": { "latitude": 41.0, "longitude": 69.0 }
  }
  ```
  - **Location Resolution Rules:**
    - **Required:** `area_id`
    - **Optional:** `city_id` – if not provided, it is automatically resolved from the selected `area_id`.
    - **Auto-filled:** `country_id` (and `region_id`) are inferred from the resolved area and do not need to be sent from the client.
  - **Storage Format (DB `user_addresses.address` column):**
    - Although the client sends `address` as a simple string, the controller wraps it into an array before saving, so it is stored as:
      ```json
      {
        "address": "Bänikonstrasse, 8426 Lufingen, Switzerland"
      }
      ```
    - This matches the core `UserAddress` model casting, which expects `address` to be an array/JSON object.

### 1.1 Create Address for Existing User
Allows attaching a new address to an already existing user (e.g. POS selects an existing customer and adds a new delivery address).
- **Endpoint:** `POST /user-addresses`
- **Function:** Creates a new `UserAddress` for an existing `User` using the same location resolution logic as `/users`.
- **Payload (request body):**
  ```json
  {
      "user_id": 123,
      "title": "Office",
      "area_id": 5,                   // Required
      "city_id": 1,                   // Optional: auto-derived from area if omitted
      "address": "Bänikonstrasse, 8426 Lufingen, Switzerland",
      "note": "Ring the bell",
      "location": { "latitude": 41.0, "longitude": 69.0 } // Optional
  }
  ```
  - **Behavior:**
    - Validates that `user_id` exists.
    - Resolves `city_id` from `area_id` when missing.
    - Derives `region_id` and `country_id` from the selected `Area`.
    - Copies `firstname`, `lastname`, and `phone` from the existing user.
    - Saves `address` as JSON in the DB in the same format as `/users`:
      ```json
      {
        "address": "Bänikonstrasse, 8426 Lufingen, Switzerland"
      }
      ```

### 2. Location Management
Simplified endpoints to traverse the Location (City/Area) hierarchy.
- **Get All Areas:** `GET /areas`
- **Get City by Area:** `GET /areas/{id}/city`
- **Get Areas by City:** `GET /cities/{id}/areas`

### 3. Order Location & Shipping Updates
Allows updating the destination of an existing order and **automatically recalculating shipping costs** based on the new location (City/Area) and Shop settings.
- **Get Order Details:** `GET /orders/{id}`
- **Update Order Location:** `PUT /orders/{id}/location`
  - **Payload:**
    ```json
    {
        "city_id": 2,
        "area_id": 15,
        "address": "456 New St", // Updates text address
        "location": { "latitude": 41.5, "longitude": 69.5 } // Updates Lat/Lon
    }
    ```
  - **Logic:**
    1. Updates `Order` address/location data.
    2. Finds the correct `DeliveryPrice` for the Shop + New Location.
    3. Triggers `OrderService` calculation to update `delivery_fee` and `total_price`.

### 4. Order Discount Updates

Allows applying an extra manual discount on an existing order and recalculating its totals via the core `OrderService`.
- **Endpoint:** `PUT /orders/{id}/discount`
- **Function:** Increases `total_discount` on the order and decreases `total_price` by the same amount (never below zero), by delegating to `OrderService::update()` / `calculateOrder()`.
- **Payload (request body):**
  ```json
  {
      "discount": 10.5,
      "reason": "Loyalty adjustment"
  }
  ```
- **Behavior Example:**
  - Before: `total_discount = 10`, `total_price = 100`
  - Request: `{ "discount": 5 }`
  - After: `total_discount = 15`, `total_price = 95`
- **Notes:**
  - The endpoint:
    - Loads the existing order and its products.
    - Builds a payload with the current products and a transient `discount` key.
    - Calls `OrderService->update(...)`, which passes the discount through to `calculateOrder`.
    - `calculateOrder` subtracts the extra discount from `total_price` and adds it to `total_discount`.
  - No database schema changes are required; the `discount` key is only used transiently inside the service layer.

## Technical Implementation

- **Controllers:**
  - `UserAddressController`: Handles User + Address creation.
  - `LocationController`: Handles Area/City lookups.
  - `OrderLocationController`: Handles Order updates and shipping recalculation.
- **Services Used:**
  - `UserService`, `UserAddressService` (Reused from core).
  - `OrderService` (Reused for calculation logic).
- **Repositories:**
  - `AreaRepository`, `CityRepository`.

## Common Issues & Fixes

- **Address Array Casting:** The `UserAddress` model casts `address` to an array. The controller must pass it as `['address' => 'text']` or similar structure to avoid casting errors (e.g., `mb_substr` expecting string).
- **String Helpers:** `Str::limit` and `Str::uuid` should be handled carefully in Observers to ensure strict string types are passed.

