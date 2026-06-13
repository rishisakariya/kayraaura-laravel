# Existing API Changes After Delhivery Integration

This document lists only changes to APIs that already existed before the Delhivery work.

No existing API names were changed. No existing response fields were removed or renamed. Changes are additive unless noted as a new possible status value.

## Frontend APIs

### `GET /api/orders`

Each order now includes an appended `shipment` object.

```json
"shipment": {
  "provider": "delhivery",
  "waybill": null,
  "courier_tracking_url": null,
  "shipment_status": "not_created",
  "raw_status": null,
  "last_synced_at": null
}
```

If a shipment has been created, the same object may contain values:

```json
"shipment": {
  "provider": "delhivery",
  "waybill": "123456789012",
  "courier_tracking_url": "https://www.delhivery.com/track/package/123456789012",
  "shipment_status": "manifested",
  "raw_status": "Manifested",
  "last_synced_at": "2026-06-12 21:00:00"
}
```

Frontend compatibility note: existing order fields are unchanged. React can ignore `shipment` if not needed.

### `GET /api/orders/{id}`

Same additive `shipment` object is included in the order response.

No existing fields were removed or renamed.

### `POST /api/orders/create`

Existing response shape remains the same:

```json
{
  "status": true,
  "data": {
    "order": {},
    "razorpay": null
  },
  "message": "..."
}
```

Changes:

- `data.order.shipment` is appended.
- For COD orders, `data.order.status` can now be `pending_admin_confirmation`.
- For COD orders, `message` can now be:

```text
Order placed successfully. Your COD order is pending confirmation.
```

Important frontend note: COD order success should still be treated as success when `status: true`. Do not depend on order status being only `pending`.

For online orders, Delhivery shipment is still not created at this step. Razorpay flow remains unchanged.

### `POST /api/razorpay/payment/verify`

Existing response shape remains the same:

```json
{
  "status": true,
  "data": {},
  "message": "Payment verified successfully"
}
```

Changes:

- `data.shipment` is appended.
- Shipment creation is queued after successful payment verification, so the first response may show:

```json
"shipment": {
  "provider": "delhivery",
  "waybill": null,
  "courier_tracking_url": null,
  "shipment_status": "not_created",
  "raw_status": null,
  "last_synced_at": null
}
```

Frontend compatibility note: payment success should still be based on the existing `status` and `message` behavior. Do not require AWB to be present immediately after payment verification.

### `POST /api/orders/{id}/cancel`

Existing response shape remains the same.

Changes:

- Returned order includes appended `shipment`.
- If the order already has a Delhivery AWB, backend queues Delhivery cancellation automatically.

Frontend compatibility note: cancellation success still uses the existing `status: true` response. No extra frontend call is required for Delhivery cancellation.

## Admin APIs

### `GET /cpanel/orders?type=cod|online`

Each order now includes an appended `shipment` object.

The `type` query filter is required and accepts `cod` or `online`. Use separate admin menu links for COD Orders and Online Orders with the matching `type` value.

Optional filters were added to the same endpoint:

```text
shipping_status
waybill
shipping_provider
shipment_created_from
shipment_created_to
```

Existing filters still work.

### `GET /cpanel/orders/{id}`

The order response now includes appended shipment details for admin debugging.

Additional fields may appear inside `shipment`, such as:

```json
{
  "status_location": "Surat",
  "status_instructions": "Shipment connected",
  "pickup_location": "Registered Pickup Name",
  "payment_mode": "COD",
  "cod_amount": 1499,
  "weight_grams": 1000,
  "failed_reason": null,
  "request_payload": {},
  "response_payload": {},
  "tracking_payload": {},
  "tracking": []
}
```

Existing order fields are unchanged.

### Admin Product Create/Update

Existing product create/update API now accepts one optional field:

```json
"weight_grams": 100
```

Product responses now include:

```json
"weight_grams": 100
```

Frontend/admin compatibility note: existing product fields are unchanged. For Delhivery shipment creation, products should have `weight_grams` filled by admin.

## New Possible Status Value

COD orders can now have:

```text
pending_admin_confirmation
```

React/admin code should display this as a successful placed COD order waiting for admin confirmation, not as a checkout failure.

Suggested label:

```text
Pending Admin Confirmation
```

## Shipment Status Values

If React displays `shipment.shipment_status`, possible values are:

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

Recommended fallback label: replace underscores with spaces and title-case the text.
