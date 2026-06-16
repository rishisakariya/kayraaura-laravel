# Shiprocket Setup Guide (Optional Fallback)

Delhivery stays the **primary** courier. Shiprocket is used only when fallback is enabled and Delhivery shipment creation fails.

**Important:** Shiprocket does **not** provide a separate sandbox API URL like Delhivery. Both staging and production use:

`https://apiv2.shiprocket.in`

So “staging vs production” in this project means:

| Mode | What it does |
|------|----------------|
| `SHIPROCKET_ENV=staging` + `SHIPROCKET_MOCK=true` | No real API calls (safe local testing) |
| `SHIPROCKET_ENV=staging` + `SHIPROCKET_MOCK=false` | Real Shiprocket API with **staging/test account** credentials |
| `SHIPROCKET_ENV=production` + `SHIPROCKET_MOCK=false` | Real Shiprocket API with **live account** credentials |

References:
- [Shiprocket Developers](https://www.shiprocket.in/developers/)
- [Shiprocket API Helpsheet](https://support.shiprocket.in/support/solutions/articles/43000337456-shiprocket-api-document-helpsheet)
- [API Docs](https://apidocs.shiprocket.in/)

---

## Step 1 — Create Shiprocket API user

1. Login to [Shiprocket Panel](https://app.shiprocket.in/)
2. Go to **Settings → API → Configure → Create an API User**
3. Use a **new email** (not your main Shiprocket login email)
4. Save the **email + password** → these go into `.env`

Auth API (for reference):

`POST https://apiv2.shiprocket.in/v1/external/auth/login`

---

## Step 2 — Create pickup location

1. In Shiprocket panel, add/configure a **Pickup Location**
2. Copy the **exact pickup location name**
3. Put it in `.env` as `SHIPROCKET_STAGING_PICKUP_LOCATION` or `SHIPROCKET_PRODUCTION_PICKUP_LOCATION`

This name must already exist in Shiprocket before order creation works.

---

## Step 3 — Choose environment setup

### A) Local / staging (recommended — no real charges)

```env
APP_ENV=local
SHIPROCKET_ENV=staging
SHIPROCKET_FALLBACK_ENABLED=true
SHIPROCKET_MOCK=true

# Optional in mock mode (not required for API calls)
SHIPROCKET_STAGING_EMAIL=
SHIPROCKET_STAGING_PASSWORD=
SHIPROCKET_STAGING_PICKUP_LOCATION=TestWarehouse
```

Mock mode returns fake AWB + tracking. No wallet deduction.

---

### B) Staging with real Shiprocket API (integration test)

Use a **separate Shiprocket test account** (or same account with small wallet balance).

```env
SHIPROCKET_ENV=staging
SHIPROCKET_FALLBACK_ENABLED=true
SHIPROCKET_MOCK=false

SHIPROCKET_STAGING_EMAIL=api_user@test-shiprocket-account.com
SHIPROCKET_STAGING_PASSWORD=your_api_password
SHIPROCKET_STAGING_PICKUP_LOCATION=YourStagingPickupName

# Optional
SHIPROCKET_STAGING_CHANNEL_ID=
SHIPROCKET_STAGING_COURIER_ID=
```

**Note:** Real test orders may charge wallet. Cancel test shipments in Shiprocket panel to get refund (usually 3–4 days per Shiprocket/Odoo docs).

---

### C) Production (live)

```env
SHIPROCKET_ENV=production
SHIPROCKET_FALLBACK_ENABLED=true
SHIPROCKET_MOCK=false

SHIPROCKET_PRODUCTION_EMAIL=api_user@live-account.com
SHIPROCKET_PRODUCTION_PASSWORD=your_live_api_password
SHIPROCKET_PRODUCTION_PICKUP_LOCATION=YourLivePickupName

# Optional
SHIPROCKET_PRODUCTION_CHANNEL_ID=
SHIPROCKET_PRODUCTION_COURIER_ID=
```

---

## Step 4 — Full `.env` variable reference

### Core switches

| Variable | Required | Where to get | Example |
|----------|----------|--------------|---------|
| `SHIPROCKET_ENV` | Yes | Your deployment type | `staging` or `production` |
| `SHIPROCKET_FALLBACK_ENABLED` | Yes | Your choice | `true` |
| `SHIPROCKET_MOCK` | Yes | `true` for safe test, `false` for real API | `true` |
| `SHIPROCKET_ENABLED` | No | Scheduler only | `false` |

### Staging credentials (`SHIPROCKET_ENV=staging`)

| Variable | Required when `MOCK=false` | Where to get |
|----------|---------------------------|--------------|
| `SHIPROCKET_STAGING_EMAIL` | Yes | Shiprocket API user email (Settings → API) |
| `SHIPROCKET_STAGING_PASSWORD` | Yes | Shiprocket API user password |
| `SHIPROCKET_STAGING_PICKUP_LOCATION` | Yes | Pickup location name in Shiprocket panel |
| `SHIPROCKET_STAGING_CHANNEL_ID` | No | Shiprocket channel list (optional) |
| `SHIPROCKET_STAGING_COURIER_ID` | No | Courier list / serviceability API (optional) |
| `SHIPROCKET_STAGING_BASE_URL` | No | Default `https://apiv2.shiprocket.in` |

### Production credentials (`SHIPROCKET_ENV=production`)

| Variable | Required when `MOCK=false` | Where to get |
|----------|---------------------------|--------------|
| `SHIPROCKET_PRODUCTION_EMAIL` | Yes | Live Shiprocket API user email |
| `SHIPROCKET_PRODUCTION_PASSWORD` | Yes | Live Shiprocket API user password |
| `SHIPROCKET_PRODUCTION_PICKUP_LOCATION` | Yes | Live pickup location name |
| `SHIPROCKET_PRODUCTION_CHANNEL_ID` | No | Optional |
| `SHIPROCKET_PRODUCTION_COURIER_ID` | No | Optional |
| `SHIPROCKET_PRODUCTION_BASE_URL` | No | Default `https://apiv2.shiprocket.in` |

### Legacy fallback keys (still supported)

If you don't use `STAGING_` / `PRODUCTION_` keys, these still work:

- `SHIPROCKET_EMAIL`
- `SHIPROCKET_PASSWORD`
- `SHIPROCKET_PICKUP_LOCATION`
- `SHIPROCKET_CHANNEL_ID`
- `SHIPROCKET_COURIER_ID`
- `SHIPROCKET_BASE_URL`

### Returns (optional — reverse pickup)

| Variable | Required | Where to get |
|----------|----------|--------------|
| `SHIPROCKET_SELLER_NAME` | For returns | Your warehouse/seller name |
| `SHIPROCKET_SELLER_ADDRESS_LINE_1` | For returns | Your return warehouse address |
| `SHIPROCKET_SELLER_CITY` | For returns | Warehouse city |
| `SHIPROCKET_SELLER_STATE` | For returns | Warehouse state |
| `SHIPROCKET_SELLER_POSTAL_CODE` | For returns | Warehouse pincode |
| `SHIPROCKET_SELLER_PHONE` | For returns | Warehouse phone |
| `SHIPROCKET_SELLER_EMAIL` | For returns | Warehouse email |

---

## Step 5 — How fallback works

1. Order triggers `CreateDelhiveryShipmentJob` (same as before)
2. Delhivery shipment is attempted first
3. If Delhivery fails **and** `SHIPROCKET_FALLBACK_ENABLED=true` **and** Shiprocket is configured:
   - Shiprocket creates order → assigns AWB → pickup → manifest
4. Frontend still shows `provider: delhivery` (Shiprocket is hidden from customers)

---

## Step 6 — Quick checklist

### Local dev
- [ ] `SHIPROCKET_ENV=staging`
- [ ] `SHIPROCKET_MOCK=true`
- [ ] `SHIPROCKET_FALLBACK_ENABLED=true`
- [ ] `php artisan config:clear`

### Staging server (real API test)
- [ ] `SHIPROCKET_ENV=staging`
- [ ] `SHIPROCKET_MOCK=false`
- [ ] Staging API user created in Shiprocket
- [ ] Staging pickup location exists
- [ ] Wallet has small balance for test shipments

### Production
- [ ] `SHIPROCKET_ENV=production`
- [ ] `SHIPROCKET_MOCK=false`
- [ ] Production API credentials set
- [ ] Production pickup location set
- [ ] Queue worker running (`php artisan queue:work`)

---

## Staging vs Production — direct answer

| Question | Answer |
|----------|--------|
| Separate sandbox URL? | **No** — Shiprocket uses one API host |
| Can we test without charges? | **Yes** — use `SHIPROCKET_MOCK=true` |
| Can we test real API on staging? | **Yes** — `SHIPROCKET_ENV=staging` + `SHIPROCKET_MOCK=false` + staging credentials |
| Live orders? | `SHIPROCKET_ENV=production` + `SHIPROCKET_MOCK=false` + production credentials |

After changing `.env`, run:

```bash
php artisan config:clear
```
