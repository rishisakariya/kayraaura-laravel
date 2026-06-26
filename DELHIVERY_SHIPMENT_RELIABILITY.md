# Delhivery Shipment Reliability

This document describes how Kayra Aura handles Delhivery shipment creation failures, duplicate responses, cancellation timing, and reconciliation.

## Goals

1. Never mark a shipment `failed` if Delhivery already created it.
2. Retry only temporary errors (timeouts / 5xx).
3. Recover duplicate / same-address errors by looking up the order on Delhivery.
4. Cancel on Delhivery before treating the order as safe to recreate.
5. Reconcile failed local records in the background.

## Shipment statuses

| Status | Meaning |
|--------|---------|
| `not_created` | Local placeholder only |
| `manifested` | AWB created successfully |
| `retry_pending` | Temporary error; queue will retry |
| `failed` | Permanent local failure after retries + reconciliation |
| `cancelled` | Cancelled on Delhivery |

## Create flow

```
Payment success
    ↓
CreateDelhiveryShipmentJob
    ↓
POST /api/cmu/create.json
    ↓
Success with AWB? → save manifested
    ↓
Error?
    ↓
Lookup Delhivery by order_number (ref_ids)
    ↓
Found AWB? → save manifested (recovery)
    ↓
Retryable error? → retry_pending + queue retry
    ↓
Permanent error? → failed
```

### Idempotent recovery

On any create error, the app calls:

```
GET /api/v1/packages/json/?ref_ids={order_number}
```

If Delhivery already has the shipment, the AWB is saved locally and status becomes `manifested`.

This covers:

- `same address not allowed`
- `duplicate shipment`
- `shipment already exists`
- network timeout after Delhivery already processed the request

## Error handling

| Error type | Action |
|------------|--------|
| Timeout / connection / HTTP 408, 429, 5xx | `retry_pending`, queue retries |
| Duplicate / same address | Lookup by `order_number`, recover AWB |
| Auth / config / validation | `failed` |
| After all retries exhausted | `failed`, reconciliation cron can still recover |

## Logging

Search logs for:

```
Delhivery shipment creation trace
```

Stages:

- `create_request`
- `create_response`
- `manifested`
- `recovered_from_delhivery`
- `recovered_after_error`
- `failed`

Each entry includes `order_number`, request/response payloads, parsed AWB, and final status.

## Cancellation flow

When an order is cancelled (customer API or admin shipment cancel):

1. Order cancelled locally
2. Online payment refunded when applicable
3. Delhivery cancel API called **synchronously** when possible
4. Tracking checked to verify cancellation
5. If sync cancel fails, `CancelDelhiveryShipmentJob` runs as fallback

This reduces “same address not allowed” on the next order with the same shipping address.

## Reconciliation cron

Command:

```bash
php artisan delhivery:reconcile-failed-shipments
```

Scheduled hourly when `DELHIVERY_ENABLED=true`.

It finds shipments with status:

- `failed`
- `retry_pending`
- `not_created`

…and tries to recover AWB from Delhivery by `order_number`.

`CreateDelhiveryShipmentJob::failed()` also runs one reconciliation attempt before keeping `failed`.

## Bulk labels

Cancelled shipments are rejected from bulk label download.

If Delhivery bulk packing slip returns fewer labels than selected orders, the app falls back to generating and merging individual labels.

## Manual recovery

For an order stuck as failed but visible on Delhivery:

```bash
php artisan delhivery:reconcile-failed-shipments
```

Or re-dispatch create only after confirming the order is not already manifested on Delhivery.

## Environment

```env
DELHIVERY_ENABLED=true
DELHIVERY_ENV=production
QUEUE_CONNECTION=database
```

Ensure a queue worker is running:

```bash
php artisan queue:work
```

Ensure Laravel scheduler is running for hourly reconciliation:

```bash
php artisan schedule:run
```

## Related files

- `app/Services/Delhivery/DelhiveryShipmentService.php`
- `app/Services/Delhivery/DelhiveryShipmentErrorClassifier.php`
- `app/Services/Delhivery/DelhiveryClient.php`
- `app/Jobs/CreateDelhiveryShipmentJob.php`
- `app/Jobs/CancelDelhiveryShipmentJob.php`
- `app/Console/Commands/ReconcileFailedDelhiveryShipments.php`
- `app/Services/OrderCancellationService.php`
