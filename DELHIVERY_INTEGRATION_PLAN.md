# Delhivery Shipping Integration Plan

This document is for backend, frontend, and admin panel coordination before coding the Delhivery integration.

Goal:

- If customer selects `online`, create shipment in Delhivery only after Razorpay payment is successfully verified by backend.
- If customer selects `cod`, create local order first, keep shipment pending, and create Delhivery shipment only after admin confirms the order.
- Show shipment/AWB/tracking status on frontend order pages.
- Show Delhivery shipment details and actions in admin panel.
- Do not break current working frontend APIs. Add new APIs or append new response fields only.

## Current Project Flow

Current frontend order APIs:

```http
GET  /api/orders
GET  /api/orders/{id}
POST /api/orders/create
POST /api/orders/{id}/cancel
POST /api/orders/{id}/return
POST /api/razorpay/payment/verify
```

Current admin order APIs:

```http
GET /cpanel/orders
GET /cpanel/orders/{id}
```

Current checkout behavior:

- COD: `POST /api/orders/create` creates order, deducts stock, clears cart, and returns order.
- Online: `POST /api/orders/create` creates local pending order and Razorpay order. `POST /api/razorpay/payment/verify` verifies payment, marks order paid, deducts stock, and clears cart.

Delhivery should be connected after these successful backend points, not directly from React.
For COD, Delhivery should still wait for admin confirmation even though the local order is created from the frontend checkout API.

## Delhivery Documentation References

- Delhivery One B2C docs: https://one.delhivery.com/developer-portal/documents/b2c/
- Package/order creation API: https://delhivery-express-api-doc.readme.io/reference/order-creation-api
- Order tracking API: https://delhivery-express-api-doc.readme.io/reference/order-tracking-api
- Cancel order API: https://delhivery-express-api-doc.readme.io/reference/cancel-order-api
- API token help: https://help.delhivery.com/docs/api-token-generation

## Required Delhivery Credentials

Ask Delhivery/Delhivery One account owner for these before development:

```env
DELHIVERY_ENV=staging
DELHIVERY_TOKEN=your_test_or_live_token
DELHIVERY_CLIENT_NAME=registered_client_name
DELHIVERY_PICKUP_LOCATION=exact_registered_pickup_location_name
DELHIVERY_SELLER_GST_TIN=your_seller_gst_number
DELHIVERY_DEFAULT_HSN_CODE=product_hsn_code
DELHIVERY_DEFAULT_LENGTH_CM=10
DELHIVERY_DEFAULT_WIDTH_CM=10
DELHIVERY_DEFAULT_HEIGHT_CM=5
DELHIVERY_WEBHOOK_SECRET=optional_custom_secret_for_our_webhook
```

Important notes:

- Delhivery token is different for staging and production.
- Token should be sent in API requests as required by Delhivery, normally `Authorization: Token YOUR_TOKEN` or token query/body parameter depending on endpoint.
- Pickup location name must exactly match the warehouse/pickup name registered in Delhivery, including case.
- For order creation, Delhivery requires `format=json&data=...` payload format.
- Delhivery mandatory delivery fields include customer `pin`, `phone`, and `address`.
- GST fields may be required for shipment creation, especially `seller_gst_tin` and `hsn_code`.
- Shipment weight is important for reliable Delhivery shipment creation and billing. For jewellery, package dimensions can usually use standard box defaults.

## Delhivery URLs

### Shipment Creation

Staging:

```text
https://staging-express.delhivery.com/api/cmu/create.json
```

Production:

```text
https://track.delhivery.com/api/cmu/create.json
```

### Tracking Pull API

Staging:

```text
https://staging-express.delhivery.com/api/v1/packages/json/?waybill=AWB_NUMBER&token=TOKEN
```

Production:

```text
https://track.delhivery.com/api/v1/packages/json/?waybill=AWB_NUMBER&token=TOKEN
```

### Cancel Shipment

Staging:

```text
https://staging-express.delhivery.com/api/p/edit
```

Production:

```text
https://track.delhivery.com/api/p/edit
```

## Local Database Changes Needed

Recommended: create a separate shipment table instead of putting everything directly in `orders`.

### New Table: `order_shipments`

Suggested columns:

```text
id
order_id
provider                         // delhivery
waybill                          // AWB/tracking number
provider_reference               // Delhivery internal shipment/reference id if returned
delhivery_order_id               // Delhivery reference if returned
shipment_status                  // local mapped status
raw_status                       // original Delhivery status text
status_location
status_instructions
pickup_location
payment_mode                     // COD or Pre-paid
cod_amount
courier_tracking_url             // https://www.delhivery.com/track/package/{waybill}
weight_grams
length_cm
width_cm
height_cm
shipping_label_url               // if label API added later
last_synced_at
manifested_at
delivered_at
cancelled_at
rto_at
failed_reason
request_payload                  // json
response_payload                 // json
tracking_payload                 // json
created_at
updated_at
```

DB constraints:

```text
UNIQUE(order_id)
UNIQUE(waybill)
```

These constraints prevent duplicate shipment records and duplicate AWBs even if a queue job is retried.

Optional columns on `orders` for faster listing/filtering:

```text
shipping_provider
shipping_status
awb_number
courier_tracking_url
shipped_at
delivered_at
```

If using separate table, these can be appended in `OrderResource` from relation and do not need to duplicate data.

### Product Weight And Package Dimensions

For this jewellery store, product-level weight matters more than package dimensions because most orders can ship in standard boxes.

Add this column to the `products` table:

```text
weight_grams
```

Do not use a static/default shipment weight for production shipment creation. Every product should have `weight_grams` entered from the admin panel before it can be shipped through Delhivery.

Recommended DB field:

```text
products.weight_grams unsignedInteger nullable initially, then required in admin validation
```

Admin product create/update should include `weight_grams`. If an existing product has no `weight_grams`, admin should update it before creating a Delhivery shipment for that order.

Package dimensions can use defaults:

```text
DELHIVERY_DEFAULT_LENGTH_CM
DELHIVERY_DEFAULT_WIDTH_CM
DELHIVERY_DEFAULT_HEIGHT_CM
```

Recommended jewellery defaults:

```env
DELHIVERY_DEFAULT_LENGTH_CM=10
DELHIVERY_DEFAULT_WIDTH_CM=10
DELHIVERY_DEFAULT_HEIGHT_CM=5
```

Use `DELHIVERY_DEFAULT_HSN_CODE` unless product/category-level HSN is added later.

For multi-item orders, backend should calculate:

- `weight` = sum of each product `weight_grams` times quantity.
- `shipment_length`, `shipment_width`, `shipment_height` = standard jewellery box defaults.
- `hsn_code` = product HSN if available, otherwise default HSN.

## Shipment Status Mapping

Frontend/admin should use backend normalized statuses, not raw Delhivery text.

Suggested local statuses:

```text
not_created
manifested
pickup_scheduled
pickup_pending
picked_up
in_transit
out_for_delivery
delivered
rto
cancelled
failed
```

Delhivery webhook/pull raw status should be saved in `raw_status` for admin debugging.

## Backend Integration Points

### 1. COD Order Flow

Current flow:

```text
POST /api/orders/create
-> create local order
-> deduct stock
-> clear cart
-> return order
```

New flow:

```text
POST /api/orders/create
-> create local order
-> set local order status = pending_admin_confirmation
-> return order without AWB
-> admin verifies COD order
-> admin confirms order
-> dispatch CreateDelhiveryShipmentJob
-> job creates Delhivery shipment
-> save AWB/status/tracking URL in order_shipments
```

If Delhivery fails:

- Keep local order created.
- Save shipment status as `failed`.
- Admin should have recreate/retry shipment action only when `waybill` is `NULL`.

Recommended frontend message:

```text
Order placed successfully. Your COD order is pending confirmation.
```

Reason:

- COD customers can place fake orders.
- Customers may cancel within a few minutes.
- Admin confirmation prevents unnecessary AWBs and Delhivery shipment charges.
- COD stock should still be deducted immediately on local order creation to prevent overselling. Existing checkout already does this today.
- Admin confirmation is only for shipment creation, not inventory reservation.

### 2. Online Razorpay Flow

Current flow:

```text
POST /api/orders/create
-> create local pending order
-> create Razorpay order
-> React opens Razorpay
-> POST /api/razorpay/payment/verify
-> backend verifies payment and marks paid
```

New flow:

```text
POST /api/razorpay/payment/verify
-> verify Razorpay signature/payment
-> mark local order paid
-> deduct stock
-> clear cart
-> dispatch CreateDelhiveryShipmentJob with payment_mode = Pre-paid
-> return order with shipment pending
-> job creates Delhivery shipment
-> save AWB/status/tracking URL in order_shipments
```

Never create Delhivery shipment for online order before payment is verified by backend.
Use queue jobs from the first version so Razorpay verify response does not become slow if Delhivery API is slow.

## Delhivery Order Creation Payload

Backend should build this from existing order, order items, and address data.

Example structure for our service layer:

```json
{
  "shipments": [
    {
      "name": "Customer Name",
      "add": "Full delivery address",
      "pin": "395006",
      "city": "Surat",
      "state": "Gujarat",
      "country": "India",
      "phone": "9876543210",
      "order": "ORD202606120001",
      "payment_mode": "COD",
      "cod_amount": "1499.00",
      "total_amount": "1499.00",
      "products_desc": "Product names / SKU summary",
      "quantity": "2",
      "weight": "1000",
      "seller_gst_tin": "GST_NUMBER",
      "hsn_code": "HSN_CODE",
      "shipment_width": "10",
      "shipment_height": "10",
      "shipment_length": "10"
    }
  ],
  "pickup_location": {
    "name": "Exact Delhivery Pickup Location"
  }
}
```

Delhivery request body should be sent as:

```text
format=json&data=<json_encoded_payload>
```

Payment mode mapping:

```text
payment_method = cod    -> payment_mode = COD, cod_amount = order total
payment_method = online -> payment_mode = Pre-paid, cod_amount = 0
```

Weight mapping:

```text
Delhivery weight should be sent in grams.
Order weight = sum(products.weight_grams * quantity).
If any product weight is missing, block Delhivery shipment creation and show admin a clear message to update product weight first.
```

Implementation note:

```php
$orderWeight = $order->items->sum(
    fn ($item) => $item->product->weight_grams * $item->quantity
);
```

## Backend APIs To Add

These are new APIs. Existing APIs should continue working.

### Frontend Customer APIs

#### Get Order Tracking

```http
GET /api/orders/{id}/shipment
```

Auth:

```text
Customer Sanctum token
```

Purpose:

- Show AWB, provider, current shipment status, and tracking timeline on order detail page.
- Only allow the logged-in customer to access their own order shipment.
- Customer can track from our system using local order id/order number. Backend resolves the AWB internally.

Suggested response:

```json
{
  "status": true,
  "data": {
    "provider": "delhivery",
    "waybill": "123456789012",
    "shipment_status": "in_transit",
    "raw_status": "In Transit",
    "status_location": "Surat",
    "status_instructions": "Shipment connected",
    "last_synced_at": "2026-06-12 21:00:00",
    "tracking": [
      {
        "status": "Manifested",
        "location": "Surat",
        "instructions": "Manifest uploaded",
        "date_time": "2026-06-12 18:20:00"
      }
    ]
  }
}
```

#### Track Shipment By Order Number

```http
GET /api/shipments/track?order_number=ORD202606120001
```

Purpose:

- Customer can track using order number when they do not remember AWB.
- Backend finds the logged-in customer's order, then returns shipment/tracking data.

#### Track Shipment By AWB Number

```http
GET /api/shipments/track?awb=123456789012
```

Purpose:

- Customer can track using Delhivery AWB number.
- Backend should only return data if the AWB belongs to the authenticated customer.

#### Refresh Order Tracking

```http
POST /api/orders/{id}/shipment/refresh
```

Purpose:

- Optional. Pull latest status from Delhivery when user opens tracking page.
- Use rate limit to avoid too many Delhivery calls.

Recommended:

- If `last_synced_at` is less than 10-15 minutes old, return cached status.
- Cron sync should be primary source in first version.
- Later, webhook can become the fastest update path.

### Admin APIs

#### List Orders With Shipment Filters

Existing:

```http
GET /cpanel/orders?type=cod
GET /cpanel/orders?type=online
```

Required filter:

```text
type=cod|online
```

Use separate admin menu links for COD Orders and Online Orders with the corresponding `type` value.

Optional filters:

```text
shipping_status
waybill
shipping_provider
shipment_created_from
shipment_created_to
```

Append shipment summary to each order response.

#### Get Admin Order Detail With Shipment

Existing:

```http
GET /cpanel/orders/{id}
```

Append:

```text
shipment
shipment.tracking
shipment.request_payload
shipment.response_payload
shipment.failed_reason
```

#### Create/Recreate Shipment

```http
POST /cpanel/orders/{id}/shipment/create
```

Purpose:

- Admin can create shipment after confirming COD order.
- Admin can recreate/retry shipment only when `waybill` is `NULL`.
- Must be idempotent: if AWB already exists, return existing shipment and do not create duplicate AWB.

#### Sync Shipment Status

```http
POST /cpanel/orders/{id}/shipment/sync
```

Purpose:

- Pull latest tracking status from Delhivery by AWB and update local shipment.

#### Cancel Delhivery Shipment

```http
POST /cpanel/orders/{id}/shipment/cancel
```

Purpose:

- Call Delhivery cancel API when order is cancelled before pickup/delivery.
- Delhivery cancellation is allowed only in valid shipment states.

### Customer Order Cancellation With Delhivery

Existing customer API:

```http
POST /api/orders/{id}/cancel
```

Required flow:

```text
Customer cancels order
-> check AWB exists
-> if no AWB, cancel local order only
-> if AWB exists, call Delhivery cancel API
-> update local order status
-> update local shipment status
```

Reason:

- Local order and Delhivery shipment statuses must stay consistent.
- If AWB exists and only local order is cancelled, Delhivery may still continue shipment movement.

#### Download/Show Label

```http
GET /cpanel/orders/{id}/shipment/label
```

Purpose:

- Optional second phase after shipment creation.
- Use Delhivery packing slip/label API if required by warehouse team.

### Delhivery Webhook API

```http
POST /api/delhivery/webhook
```

Purpose:

- Receive Delhivery scan push status.
- Update `order_shipments` by AWB.
- Store raw payload for admin troubleshooting.
- This is a later phase. First version should use pull tracking API with cron every 30 minutes because it is easier to develop and debug.

Security:

- Ask Delhivery to send a custom header secret if possible.
- Validate `DELHIVERY_WEBHOOK_SECRET`.
- Log unknown AWB payloads but return `200` after saving logs if needed.

## Changes To Existing API Responses

Do not remove or rename current fields.

Append this object to order responses in:

```http
GET  /api/orders
GET  /api/orders/{id}
POST /api/orders/create
POST /api/razorpay/payment/verify
GET  /cpanel/orders
GET  /cpanel/orders/{id}
```

Suggested appended field:

```json
{
  "shipment": {
    "provider": "delhivery",
    "waybill": "123456789012",
    "courier_tracking_url": "https://www.delhivery.com/track/package/123456789012",
    "shipment_status": "manifested",
    "raw_status": "Manifested",
    "last_synced_at": "2026-06-12 21:00:00"
  }
}
```

If shipment is not created yet:

```json
{
  "shipment": {
    "provider": "delhivery",
    "waybill": null,
    "courier_tracking_url": null,
    "shipment_status": "not_created",
    "raw_status": null,
    "last_synced_at": null
  }
}
```

## Frontend Work Needed

### Order Success Page

After COD order or Razorpay verify success:

- Show current order success as now.
- If response has `shipment.waybill`, show tracking/AWB.
- If response has `shipment.courier_tracking_url`, show direct Delhivery tracking link.
- If shipment failed or pending, show:

```text
Shipment will be updated shortly.
```

For COD order success, expected first message:

```text
Order placed successfully. Your COD order is pending confirmation.
```

### My Orders List

Use existing:

```http
GET /api/orders
```

Show:

- Order status
- Payment status
- Shipment status
- AWB if available
- Direct tracking link if `courier_tracking_url` is available

### Order Detail Page

Use existing:

```http
GET /api/orders/{id}
```

Then optionally call:

```http
GET /api/orders/{id}/shipment
```

Show:

- AWB/tracking number
- Direct Delhivery tracking link
- Current shipment status
- Tracking timeline
- Last updated time
- Search/track support by order number and AWB number

## Admin Panel Work Needed

### Orders List

Use existing:

```http
GET /cpanel/orders
```

Add columns:

- AWB
- Direct tracking link
- Shipping provider
- Shipment status
- Last synced at

Add filters:

- `shipping_status`
- `waybill`
- `order_number`

### Order Detail

Use existing:

```http
GET /cpanel/orders/{id}
```

Show:

- Delhivery AWB
- Direct tracking link
- Current status
- Raw status
- Pickup location
- COD amount
- Last tracking sync
- Shipment creation failure reason, if any
- Tracking timeline

Admin actions:

- Confirm COD order and create shipment
- Recreate shipment only when `waybill` is `NULL`
- Sync status
- Cancel shipment
- Download label, if label API is added

## Recommended Backend Classes

```text
app/Services/Delhivery/DelhiveryClient.php
app/Services/Delhivery/DelhiveryShipmentService.php
app/Http/Controllers/API/OrderShipmentController.php
app/Http/Controllers/API/DelhiveryWebhookController.php
app/Http/Controllers/Admin/OrderShipmentController.php
app/Models/OrderShipment.php
```

Responsibilities:

- `DelhiveryClient`: low-level HTTP calls to Delhivery.
- `DelhiveryShipmentService`: map local order to Delhivery payload, create shipment, sync tracking, cancel shipment.
- `OrderShipmentController`: customer tracking APIs.
- `Admin\OrderShipmentController`: admin create/recreate/sync/cancel/label APIs.
- `DelhiveryWebhookController`: receive scan push updates.

## Queue Jobs Required From First Version

Delhivery API calls should run in queues from the first version.

```text
CreateDelhiveryShipmentJob
SyncDelhiveryShipmentStatusJob
ProcessDelhiveryWebhookJob       // later phase with webhook
```

`CreateDelhiveryShipmentJob` retry policy:

```php
public int $tries = 3;

public function backoff(): array
{
    return [60, 300, 900];
}
```

This gives Delhivery temporary outages time to recover without marking the shipment permanently failed too quickly.

Why:

- Order placement should not become slow because Delhivery is slow.
- Razorpay verify should not wait for Delhivery response.
- Failed shipment creation can be retried.
- Tracking sync can be scheduled.

Do not call Delhivery synchronously from checkout/order success APIs.

### Tracking Sync Strategy

Start with pull tracking API.

```text
Laravel scheduler / cron
-> every 30 minutes
-> dispatch SyncDelhiveryShipmentStatusJob for active shipments
-> call Delhivery tracking API by AWB
-> update local normalized shipment status
```

Active statuses to sync every 30 minutes:

```text
manifested
pickup_scheduled
pickup_pending
picked_up
in_transit
out_for_delivery
```

Terminal statuses where syncing should stop:

```text
delivered
cancelled
rto
failed
```

This saves Delhivery API calls after the shipment has reached a final state.

Webhook should be added later after pull tracking is stable. This makes first development easier and debugging simpler.

## Important Safety Rules

- Never create duplicate Delhivery shipment for same local order.
- Save Delhivery request and response JSON for debugging.
- Use local `order_number` as Delhivery `order` field.
- For online orders, only create shipment after payment is verified by backend.
- COD pre-confirmation order status should be `pending_admin_confirmation`.
- For COD orders, shipment should be created only after admin confirms the order.
- If shipment creation fails, do not delete/cancel local order automatically.
- Customer cancellation should call Delhivery cancel API when AWB exists, then update local shipment status.
- Do not expose Delhivery token to frontend.
- Frontend should only call our Laravel APIs, never Delhivery directly.
- Admin create/recreate must check existing `waybill` first and must not create duplicate AWB.
- Tracking API should be cached/rate limited.
- Generate and save `courier_tracking_url` after AWB is available.
- Shipment jobs must be idempotent so retrying a job cannot create duplicate shipment.
- Add DB unique constraints on `order_shipments.order_id` and `order_shipments.waybill`.
- `CreateDelhiveryShipmentJob` should use `tries = 3` and `backoff = [60, 300, 900]`.
- Stop tracking sync after `delivered`, `cancelled`, `rto`, or `failed`.

## Implementation Phases

### Phase 1: Basic Shipment Creation

1. Add Delhivery config/env.
2. Add `order_shipments` table/model, `provider_reference`, `courier_tracking_url`, and unique constraints.
3. Add `weight_grams` to `products` table and admin product create/update forms.
4. Add `DelhiveryClient` and `DelhiveryShipmentService`.
5. Add queue jobs and dispatch `CreateDelhiveryShipmentJob` with retry/backoff policy.
6. For COD, create shipment only after admin confirms order.
7. For online, dispatch shipment job only after Razorpay verify success.
8. Add customer cancellation logic to cancel Delhivery shipment when AWB exists.
9. Append `shipment` object to order API resources.

### Phase 2: Frontend Tracking

1. Add `GET /api/orders/{id}/shipment`.
2. Add `GET /api/shipments/track?order_number=...`.
3. Add `GET /api/shipments/track?awb=...`.
4. Add optional `POST /api/orders/{id}/shipment/refresh`.
5. Show AWB, shipment status, direct tracking URL, and timeline in order list/detail.

### Phase 3: Admin Shipment Management

1. Add shipment fields to admin order list/detail.
2. Add COD order confirmation flow.
3. Add create/recreate shipment API with `waybill IS NULL` guard.
4. Add sync shipment API.
5. Add cancel shipment API.
6. Add label API if warehouse needs it.

### Phase 4: Pull Tracking Automation

1. Add Laravel scheduler command for active Delhivery shipments only.
2. Run cron every 30 minutes.
3. Dispatch `SyncDelhiveryShipmentStatusJob`.
4. Save raw Delhivery tracking payload and normalized status.
5. Stop syncing terminal statuses: `delivered`, `cancelled`, `rto`, `failed`.

### Phase 5: Webhook Automation

1. Add `POST /api/delhivery/webhook`.
2. Configure Delhivery scan push webhook.
3. Process webhook payload by AWB and update local shipment status.
4. Add queue retries and failure alerts.

## Questions To Confirm Before Coding

- Do we have Delhivery staging token and live token?
- What is the exact registered `client_name`?
- What is the exact registered pickup location name?
- Add `weight_grams` to the `products` table.
- Admin must enter product `weight_grams`; do not use static/default product weight.
- Package dimensions can use jewellery box defaults: `10cm x 10cm x 5cm`.
- COD orders should deduct stock immediately on local order creation.
- Use `pending_admin_confirmation` for COD orders before admin confirmation.
- Should shipment creation failure keep order successful and show admin retry/recreate shipment?
- Do we need Delhivery label/packing slip in admin panel in first version?
- Customer cancellation should call Delhivery cancellation automatically when AWB exists.
- Pull tracking will be first with cron every 30 minutes. Webhook will be added later.

