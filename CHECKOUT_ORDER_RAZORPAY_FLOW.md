# Checkout, Order, and Razorpay API Flow

## Purpose

This document defines the planned checkout and order flow before coding changes are made.

Current code already has:

- Product APIs that return product sizes through `sizes`.
- Cart APIs that store cart items by `product_size_id`, `size_text`, `size_price`, and `quantity`.
- Order APIs that create orders, but the current order create flow still uses `product_id` and product-level stock.

The new order flow should be size-wise, payment-aware, and support both cart checkout and buy-now checkout.

## Existing API Situation

### Product Flow

Frontend product APIs:

```http
GET /api/products
GET /api/products/{slug}
GET /api/products/featured
GET /api/products/category/{category_id}
GET /api/products/search
```

Product response includes:

- Product details.
- Product images.
- Product sizes.
- Each size has `id`, `product_id`, `size_text`, `quantity`, and `price`.

Frontend should use `product_size_id` when adding items to cart.

### Cart Flow

Protected cart APIs:

```http
GET    /api/cart
POST   /api/cart/add
PUT    /api/cart/update-quantity
DELETE /api/cart/remove/{item_id}
DELETE /api/cart/clear
```

Cart currently stores:

- `user_id`
- `product_id`
- `product_size_id`
- `size_text`
- `size_price`
- `quantity`

Cart stock check already uses `product_sizes.quantity`.

### Current Order Flow Gap

Protected order APIs:

```http
GET  /api/orders
GET  /api/orders/{id}
POST /api/orders/create
POST /api/orders/{id}/cancel
```

Current order create flow:

- Accepts direct `items` from request.
- Uses `product_id`, not `product_size_id`.
- Calculates amount from product price.
- Deducts product-level stock.
- Stores address JSON directly in orders.

Required new flow:

- Store customer address first.
- Create order from either cart items or buy-now item.
- Validate all `product_size_id` quantities before order placement.
- Save selected payment method: `cod` or `online`.
- For COD, create order immediately.
- For online payment, create order in pending payment state, call Razorpay, store Razorpay request payload, then complete order only after verified success callback/webhook.

## Checkout Sources

The same order API should support two checkout sources.

### Source 1: Cart Checkout

```text
Cart
->
Checkout
->
Order Create
->
Razorpay
```

Use this when the user places an order from saved cart items.

Request must include:

```json
{
  "checkout_type": "cart",
  "address_id": 1,
  "payment_method": "online"
}
```

Backend behavior:

```php
if ($request->checkout_type === 'cart') {
    // Load items from authenticated user's cart.
}
```

### Source 2: Buy Now

```text
Product Detail
->
Buy Now
->
Checkout
->
Order Create
->
Razorpay
```

Use this when the user clicks Buy Now from product detail page without adding item to cart.

Request must include:

```json
{
  "checkout_type": "buy_now",
  "address_id": 1,
  "payment_method": "online",
  "product_size_id": 15,
  "quantity": 2
}
```

Backend behavior:

```php
if ($request->checkout_type === 'buy_now') {
    // Build temporary checkout item directly from product_size_id + quantity.
}
```

For both flows, the rest remains identical:

```text
Validate address
->
Resolve checkout items
->
Validate stock
->
Create local order
->
Create Razorpay order for online payment
->
Return checkout data
```

## Recommended Implementation Order

1. Create customer address API.
2. Update order database structure for size-wise order items and payment metadata.
3. Create Razorpay payment log table for request and response payloads.
4. Rework order create API to validate checkout source, checkout items, and selected address.
5. Add Razorpay create-payment-order API behavior for online payments.
6. Add Razorpay webhook callback API.
7. Update order status and stock only after correct payment result.
8. Add admin/order listing later if needed.

## Proposed Address API

Address should be stored separately so checkout can show saved addresses and the user can select one while placing the order.

### Routes

```http
GET    /api/addresses
POST   /api/addresses
GET    /api/addresses/{id}
PUT    /api/addresses/{id}
DELETE /api/addresses/{id}
POST   /api/addresses/{id}/default
```

All routes should be protected by `auth:sanctum`.

### Table: `user_addresses`

Suggested fields:

```text
id
user_id
name
email
phone
address_line_1
address_line_2
city
state
postal_code
country
landmark
address_type        // home, work, other
is_default
created_at
updated_at
```

### Store Address Request

```json
{
  "name": "Customer Name",
  "email": "customer@example.com",
  "phone": "9876543210",
  "address_line_1": "House no, street",
  "address_line_2": "Area",
  "city": "Surat",
  "state": "Gujarat",
  "postal_code": "395006",
  "country": "India",
  "landmark": "Near temple",
  "address_type": "home",
  "is_default": true
}
```

### Address Rules

- User can only access their own addresses.
- If `is_default = true`, reset other addresses of same user to `false`.
- Order should copy address snapshot into `orders.shipping_address` at order time, because address may change later.

## Proposed Checkout Summary API

Before clicking place order, frontend should call a checkout summary API.

### Route

```http
POST /api/checkout/summary
```

### Request

Cart checkout:

```json
{
  "checkout_type": "cart",
  "address_id": 1,
  "payment_method": "online"
}
```

Buy-now checkout:

```json
{
  "checkout_type": "buy_now",
  "address_id": 1,
  "payment_method": "online",
  "product_size_id": 15,
  "quantity": 2
}
```

### Response

```json
{
  "status": true,
  "data": {
    "items": [],
    "subtotal": 1500,
    "tax_amount": 270,
    "shipping_amount": 0,
    "total_amount": 1770,
    "payment_method": "online",
    "address": {}
  },
  "message": "Checkout summary generated successfully"
}
```

### Validation

- User must be authenticated.
- `checkout_type` must be `cart` or `buy_now`.
- Address must belong to authenticated user.
- For `cart`, cart must not be empty.
- For `cart`, every cart item must have a valid active product and product size.
- For `buy_now`, `product_size_id` and `quantity` are required.
- For `buy_now`, backend should build one temporary checkout item from `product_size_id` and `quantity`.
- If product tracks stock, `product_sizes.quantity` must be greater than or equal to checkout quantity.
- Price should be calculated from `product_sizes.price`, not frontend payload.

## Proposed Order Create API

### Route

```http
POST /api/orders/create
```

### Request

Cart checkout:

```json
{
  "checkout_type": "cart",
  "address_id": 1,
  "payment_method": "cod",
  "notes": "Please deliver after 5 PM"
}
```

Buy-now checkout:

```json
{
  "checkout_type": "buy_now",
  "address_id": 1,
  "payment_method": "cod",
  "product_size_id": 15,
  "quantity": 2,
  "notes": "Please deliver after 5 PM"
}
```

Allowed `checkout_type` values:

- `cart`
- `buy_now`

Allowed `payment_method` values:

- `cod`
- `online`

Frontend should not send product price, size price, subtotal, tax, shipping, or total amount. Backend must calculate all amounts for both checkout sources.

## COD Order Flow

1. User starts checkout from cart or clicks Buy Now from product detail.
2. User saves/selects address.
3. User selects `cod`.
4. User clicks place order.
5. Backend validates `checkout_type`.
6. If `checkout_type = cart`, backend loads authenticated user's cart items.
7. If `checkout_type = buy_now`, backend builds one temporary checkout item from `product_size_id` and `quantity`.
8. Backend starts DB transaction.
9. Backend locks selected `product_sizes` rows.
10. Backend validates product active status and size quantity.
11. Backend calculates subtotal, tax, shipping, and total.
12. Backend creates order:
   - `status = pending` or `processing`
   - `payment_method = cod`
   - `payment_status = pending`
13. Backend creates order items with size snapshot.
14. Backend deducts `product_sizes.quantity`.
15. Backend clears user cart only when `checkout_type = cart`.
16. Backend commits transaction.
17. API returns created order.

## Online Payment Order Flow

1. User starts checkout from cart or clicks Buy Now from product detail.
2. User saves/selects address.
3. User selects `online`.
4. User clicks place order.
5. Backend validates `checkout_type`.
6. If `checkout_type = cart`, backend loads authenticated user's cart items.
7. If `checkout_type = buy_now`, backend builds one temporary checkout item from `product_size_id` and `quantity`.
8. Backend starts DB transaction.
9. Backend locks selected `product_sizes` rows.
10. Backend validates product active status and size quantity.
11. Backend calculates subtotal, tax, shipping, and total.
12. Backend creates local order:
   - `status = pending`
   - `payment_method = online`
   - `payment_status = pending`
13. Backend creates order items with size snapshot.
14. Backend creates Razorpay order using final backend-calculated amount.
15. Backend stores Razorpay request payload and response payload.
16. Backend stores Razorpay order id against local order.
17. Backend commits transaction.
18. API returns local order details and Razorpay checkout data.
19. Frontend opens Razorpay checkout.
20. Razorpay sends success/failure response to frontend and webhook to backend.
21. Backend verifies Razorpay signature.
22. On verified success, backend must process everything in one DB transaction:
   - Lock order and selected `product_sizes` rows.
   - Re-check size-wise stock.
   - Deduct `product_sizes.quantity`.
   - Update payment status to `paid`.
   - Update order status to `processing`.
   - Clear user cart only when `checkout_type = cart`.
   - Commit transaction.
23. On failure:
   - Update payment status to `failed`.
   - Keep order status as `pending` or `cancelled`.
   - Do not deduct stock.
   - Do not clear cart.

## Important Payment Decision

For online payments, stock should be deducted only after verified payment success.

Reason:

- If stock is deducted before payment and payment fails, stock needs rollback logic.
- If many users start payment together, size quantity must still be checked again before confirming payment success.

Recommended approach:

- During online order creation, create order and Razorpay order.
- During payment success, use this exact transaction sequence:

```text
Payment Success
->
DB Transaction
->
Lock product_sizes
->
Re-check stock
->
Deduct stock
->
Update order/payment status
->
Clear cart only when checkout_type = cart
->
Commit
```

- During webhook success or frontend payment verification, lock order and product size rows again.
- Re-check size quantity before marking the order as processing.
- Deduct stock, update order/payment status, and clear cart in one DB transaction.

If the product size stock is no longer available when webhook success arrives:

- Mark payment as `paid_stock_failed` or `manual_review`.
- Do not mark order as processing.
- Admin must refund or resolve manually.

## Required Order Table Changes

Current `orders` table already has:

- `user_id`
- `order_number`
- `status`
- `subtotal`
- `tax_amount`
- `shipping_amount`
- `total_amount`
- `payment_method`
- `payment_status`
- `shipping_address`
- `billing_address`
- `notes`

Suggested new fields:

```text
address_id
checkout_type
razorpay_order_id
razorpay_payment_id
razorpay_signature
paid_at
payment_failed_at
```

Suggested `checkout_type` values:

```text
cart
buy_now
```

Notes:

- Store `checkout_type` on the order for reporting and debugging.
- `cart` means the order was created from authenticated user's cart.
- `buy_now` means the order was created directly from product detail using `product_size_id` and `quantity`.

Required indexes:

```text
UNIQUE(order_number)
```

Notes:

- `order_number` must stay unique because customers, admin, payment logs, and support flows can use it as a public order reference.
- Current migration already defines `order_number` as unique; keep this requirement in future order migrations/refactors.

Suggested status values:

```text
pending
processing
shipped
delivered
cancelled
manual_review
```

Suggested payment status values:

```text
pending
paid
failed
refunded
paid_stock_failed
```

## Required Order Items Changes

Current `order_items` table only stores `product_id`, `quantity`, `price`, and `total`.

Order items should store size snapshot also.

Suggested new fields:

```text
product_size_id
product_name
product_slug
size_text
size_price
quantity
price
total
```

Notes:

- `product_size_id` keeps relation to selected size.
- `size_text` and `size_price` preserve order history if admin changes product size later.
- `price` can equal size price at purchase time.
- `total = price * quantity`.

## Razorpay Log Table

Create one table to store request payload and receive payload as discussed.

### Table: `razorpay_payment_logs`

Suggested fields:

```text
id
order_id
user_id
razorpay_order_id
razorpay_payment_id
event_type
status
request_payload      // JSON sent to Razorpay
response_payload     // JSON received from Razorpay API/frontend/webhook
webhook_payload      // Full webhook body
webhook_signature
signature_verified
error_code
error_description
created_at
updated_at
```

### What To Store

When creating Razorpay order:

- Store local `order_id`.
- Store request payload sent to Razorpay.
- Store Razorpay create-order response payload.
- Store Razorpay order id.

When frontend payment success callback comes:

- Store `razorpay_order_id`.
- Store `razorpay_payment_id`.
- Store `razorpay_signature`.
- Store full callback payload.
- Verify signature before updating payment status.

When webhook comes:

- Store full webhook payload.
- Store webhook signature header.
- Store event type.
- Store signature verification result.
- Update order only after signature verification passes.

## Razorpay Webhook API

### Route

```http
POST /api/razorpay/webhook
```

This route should not require `auth:sanctum`, because Razorpay calls it directly.

### Required Security

- Verify `X-Razorpay-Signature` using `RAZORPAY_WEBHOOK_SECRET`.
- Reject invalid signature.
- Log invalid webhook payload for debugging.
- Make webhook idempotent so duplicate Razorpay events do not create duplicate updates.

### Important Events

Minimum required:

```text
payment.captured
payment.failed
order.paid
refund.processed
```

Recommended success event:

- Use `payment.captured` or `order.paid` as final payment success trigger.

## Final API Sequence For Frontend

### Source 1: Cart Checkout Page Load

```http
GET /api/cart
GET /api/addresses
```

Frontend shows:

- Cart items.
- Size-wise price and quantity.
- Saved addresses.
- Payment options: COD and Pay Online.

### Source 2: Buy Now Checkout Page Load

From product detail page, frontend already has selected:

- `product_size_id`
- `quantity`

Frontend should load saved addresses:

```http
GET /api/addresses
```

Checkout page shows:

- Selected product and size.
- Selected quantity.
- Backend-calculated amount after summary API.
- Saved addresses.
- Payment options: COD and Pay Online.

### Add New Address

```http
POST /api/addresses
```

Frontend saves address and receives address id.

### Checkout Summary

```http
POST /api/checkout/summary
```

Cart checkout request:

```json
{
  "checkout_type": "cart",
  "address_id": 1,
  "payment_method": "online"
}
```

Buy-now checkout request:

```json
{
  "checkout_type": "buy_now",
  "address_id": 1,
  "payment_method": "online",
  "product_size_id": 15,
  "quantity": 2
}
```

Frontend receives backend-calculated total amount.

### Place COD Order From Cart

```http
POST /api/orders/create
```

Request:

```json
{
  "checkout_type": "cart",
  "address_id": 1,
  "payment_method": "cod"
}
```

Backend creates order, deducts stock, clears cart, and returns success.

### Place COD Order From Buy Now

```http
POST /api/orders/create
```

Request:

```json
{
  "checkout_type": "buy_now",
  "address_id": 1,
  "payment_method": "cod",
  "product_size_id": 15,
  "quantity": 2
}
```

Backend creates order, deducts stock, does not touch cart, and returns success.

### Place Online Order From Cart

```http
POST /api/orders/create
```

Request:

```json
{
  "checkout_type": "cart",
  "address_id": 1,
  "payment_method": "online"
}
```

Backend creates local pending order, creates Razorpay order, logs payloads, and returns Razorpay checkout data.

Frontend opens Razorpay checkout with returned data.

### Place Online Order From Buy Now

```http
POST /api/orders/create
```

Request:

```json
{
  "checkout_type": "buy_now",
  "address_id": 1,
  "payment_method": "online",
  "product_size_id": 15,
  "quantity": 2
}
```

Backend creates one temporary checkout item, creates local pending order, creates Razorpay order, logs payloads, and returns Razorpay checkout data.

Frontend opens Razorpay checkout with returned data.

### Razorpay Success Handling

Frontend may call:

```http
POST /api/razorpay/payment/verify
```

Request:

```json
{
  "order_id": 10,
  "razorpay_order_id": "order_xxx",
  "razorpay_payment_id": "pay_xxx",
  "razorpay_signature": "signature_xxx"
}
```

Backend verifies signature and can mark payment as paid.

Webhook should still be the final source of truth.

## Backend Validation Checklist

Before any order is created:

- User authenticated.
- `checkout_type` is `cart` or `buy_now`.
- Address belongs to user.
- Payment method is `cod` or `online`.
- If `checkout_type = cart`, cart has at least one item.
- If `checkout_type = cart`, every cart item has `product_size_id`.
- If `checkout_type = buy_now`, `product_size_id` and `quantity` are required.
- If `checkout_type = buy_now`, backend builds temporary checkout item from selected product size.
- Every selected product is active.
- Every selected product size exists.
- Size belongs to product.
- Quantity is greater than zero.
- If `track_stock = true`, size quantity is enough.
- Backend calculates all prices.

Before payment success is accepted:

- Razorpay signature is valid.
- Razorpay order id matches local order.
- Razorpay amount matches local order total.
- Payment is not already processed.
- Product size stock is still available.

## Response Format

Use current frontend style where possible:

```json
{
  "status": true,
  "data": {},
  "message": "Order created successfully"
}
```

For errors:

```json
{
  "status": false,
  "message": "Insufficient stock available for selected size"
}
```

## Suggested Files To Add Later

Controllers:

```text
app/Http/Controllers/API/AddressController.php
app/Http/Controllers/API/CheckoutController.php
app/Http/Controllers/API/RazorpayController.php
```

Requests:

```text
app/Http/Requests/AddressStoreRequest.php
app/Http/Requests/CheckoutSummaryRequest.php
app/Http/Requests/OrderStoreRequest.php
app/Http/Requests/RazorpayPaymentVerifyRequest.php
```

Models:

```text
app/Models/UserAddress.php
app/Models/RazorpayPaymentLog.php
```

Resources:

```text
app/Http/Resources/AddressResource.php
app/Http/Resources/CheckoutSummaryResource.php
```

Migrations:

```text
create_user_addresses_table
add_payment_fields_to_orders_table
add_size_fields_to_order_items_table
create_razorpay_payment_logs_table
```

## Main Changes Needed In Current Order Flow Later

Current `OrderController@store` should be changed later from:

- Request `items.*.product_id`
- Product-level price
- Product-level stock
- Direct shipping address array

To:

- Request `checkout_type`, `address_id`, and `payment_method`
- For `checkout_type = cart`, use authenticated user's cart
- For `checkout_type = buy_now`, use request `product_size_id` and `quantity` as a temporary checkout item
- Use `product_size_id`
- Use `product_sizes.price`
- Use `product_sizes.quantity`
- Store selected address snapshot
- Store size snapshot in order items
- Split COD and online payment handling
- Log Razorpay payloads for online payment

## Open Decisions

- Whether online payment should reserve stock before payment or deduct only after webhook success. Recommended: deduct only after verified success.
- Whether frontend payment verify API is required or webhook-only is enough. Recommended: keep both, but webhook is final source of truth.
- Whether COD order status should start as `pending` or `processing`. Recommended: `pending` until admin accepts.
- Whether tax and shipping rules should stay fixed or move to configurable settings.
