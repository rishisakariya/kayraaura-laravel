# Frontend Checkout and Razorpay Integration Guide

This guide explains how the React frontend should use the backend APIs for address, checkout summary, order creation, COD order placement, online payment, and Razorpay modal handling.

## Base Setup

Base API URL:

```text
https://your-domain.com/api
```

For local development:

```text
http://localhost:8000/api
```

All checkout, address, order, and payment verify APIs are protected by Sanctum auth. Send the customer token with every protected request:

```http
Authorization: Bearer CUSTOMER_TOKEN
Accept: application/json
Content-Type: application/json
```

Login/register APIs return the token. Store it in your frontend auth state and attach it to the requests below.

## Important Frontend Rules

- Do not calculate final price in React for order placement.
- Do not send product price, subtotal, tax, shipping, or total to backend.
- Always send `product_size_id`, not only `product_id`, for buy-now checkout.
- Call checkout summary before placing the order so the user can review backend-calculated amounts.
- For `payment_method: "online"`, open Razorpay only after `POST /api/orders/create` succeeds and returns `data.razorpay`.
- After Razorpay success callback, call backend payment verify API. Do not mark the order paid only from frontend.
- The `/api/razorpay/webhook` API is for Razorpay server-to-server webhook only. React frontend should not call it.

## Checkout Types

There are two checkout sources.

### Cart Checkout

Use when the user is checking out all saved cart items.

Required payload fields:

```json
{
  "checkout_type": "cart",
  "address_id": 1,
  "payment_method": "cod"
}
```

For cart checkout, do not send `product_size_id` or `quantity`. Backend reads items from the user's cart.

### Buy Now Checkout

Use when the user clicks Buy Now from product detail page.

Required payload fields:

```json
{
  "checkout_type": "buy_now",
  "address_id": 1,
  "payment_method": "online",
  "product_size_id": 10,
  "quantity": 1
}
```

For buy-now checkout, frontend must send the selected size id as `product_size_id` and the requested `quantity`.

## API Flow Overview

### COD Flow

1. User logs in.
2. User adds products to cart or selects Buy Now product size.
3. User creates/selects address.
4. React calls `POST /api/checkout/summary`.
5. User reviews total amount.
6. React calls `POST /api/orders/create` with `payment_method: "cod"`.
7. Backend creates the order, deducts stock, clears cart if cart checkout, and returns final order.
8. React redirects user to order success page.

### Online Razorpay Flow

1. User logs in.
2. User adds products to cart or selects Buy Now product size.
3. User creates/selects address.
4. React calls `POST /api/checkout/summary`.
5. User reviews total amount.
6. React calls `POST /api/orders/create` with `payment_method: "online"`.
7. Backend creates local pending order and Razorpay order.
8. React opens Razorpay modal using `data.razorpay`.
9. On Razorpay success callback, React calls `POST /api/razorpay/payment/verify`.
10. Backend verifies signature, verifies payment with Razorpay, marks order paid, deducts stock, and clears cart if needed.
11. React redirects user to order success page.

## Address APIs

### Get Addresses

```http
GET /api/addresses
```

Response:

```json
{
  "status": true,
  "data": [
    {
      "id": 1,
      "name": "Aayush",
      "email": "aayush@example.com",
      "phone": "9876543210",
      "address_line_1": "House no, street",
      "address_line_2": "Area",
      "city": "Surat",
      "state": "Gujarat",
      "postal_code": "395006",
      "country": "India",
      "landmark": "Near temple",
      "address_type": "home",
      "is_default": true,
      "created_at": "2026-05-31 12:00:00",
      "updated_at": "2026-05-31 12:00:00"
    }
  ],
  "message": "Addresses retrieved successfully"
}
```

### Create Address

```http
POST /api/addresses
```

Request:

```json
{
  "name": "Aayush",
  "email": "aayush@example.com",
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

Required fields:

- `name`
- `email`
- `phone`
- `address_line_1`
- `city`
- `state`
- `postal_code`
- `country`

Optional fields:

- `address_line_2`
- `landmark`
- `address_type`: `home`, `work`, or `other`
- `is_default`: `true` or `false`

Other address APIs:

```http
GET    /api/addresses/{id}
PUT    /api/addresses/{id}
DELETE /api/addresses/{id}
POST   /api/addresses/{id}/default
```

## Checkout Summary API

Use this API on the checkout page before final order placement.

```http
POST /api/checkout/summary
```

### Cart Summary Request

```json
{
  "checkout_type": "cart",
  "address_id": 1,
  "payment_method": "online"
}
```

### Buy Now Summary Request

```json
{
  "checkout_type": "buy_now",
  "address_id": 1,
  "payment_method": "online",
  "product_size_id": 10,
  "quantity": 2
}
```

### Summary Response

```json
{
  "status": true,
  "data": {
    "items": [
      {
        "product_id": 5,
        "product_size_id": 10,
        "product_name": "Gold Plated Ring",
        "product_slug": "gold-plated-ring",
        "size_text": "18",
        "size_price": 999,
        "quantity": 2,
        "price": 999,
        "total": 1998
      }
    ],
    "subtotal": 1998,
    "tax_amount": 359.64,
    "shipping_amount": 0,
    "total_amount": 2357.64,
    "payment_method": "online",
    "address": {
      "id": 1,
      "name": "Aayush",
      "email": "aayush@example.com",
      "phone": "9876543210"
    }
  },
  "message": "Checkout summary generated successfully"
}
```

Show this data on the review page. If the user changes address, payment method, quantity, or selected size, call this API again.

## Create Order API

Use this API when the user clicks Place Order / Pay Now.

```http
POST /api/orders/create
```

### COD Cart Order Request

```json
{
  "checkout_type": "cart",
  "address_id": 1,
  "payment_method": "cod",
  "notes": "Please call before delivery"
}
```

### Online Buy Now Order Request

```json
{
  "checkout_type": "buy_now",
  "address_id": 1,
  "payment_method": "online",
  "product_size_id": 10,
  "quantity": 2,
  "notes": "Gift packing if available"
}
```

### COD Order Response

For COD, `data.razorpay` will be `null`.

```json
{
  "status": true,
  "data": {
    "order": {
      "id": 12,
      "order_number": "ORD202605310001",
      "checkout_type": "cart",
      "status": "pending",
      "payment_status": "pending",
      "payment_method": "cod",
      "subtotal": 1998,
      "tax_amount": 359.64,
      "shipping_amount": 0,
      "total_amount": 2357.64,
      "order_items": []
    },
    "razorpay": null
  },
  "message": "Order created successfully"
}
```

Frontend action:

- Show order success page.
- Use `data.order.id` to open order details if needed.

### Online Order Response

For online payment, backend returns `data.razorpay`. Use this object to open Razorpay modal.

```json
{
  "status": true,
  "data": {
    "order": {
      "id": 13,
      "order_number": "ORD202605310002",
      "checkout_type": "buy_now",
      "status": "pending",
      "payment_status": "pending",
      "payment_method": "online",
      "razorpay_order_id": "order_Qabc123456",
      "total_amount": 2357.64
    },
    "razorpay": {
      "key": "rzp_test_xxxxx",
      "order_id": "order_Qabc123456",
      "amount": 235764,
      "currency": "INR",
      "name": "Kayraaura",
      "description": "Order ORD202605310002",
      "prefill": {
        "name": "Aayush",
        "email": "aayush@example.com",
        "contact": "9876543210"
      }
    }
  },
  "message": "Order created successfully"
}
```

Frontend action:

- Keep `data.order.id`; this is local backend order id.
- Pass `data.razorpay` values into Razorpay Checkout.
- Do not redirect to success page yet.
- Wait for Razorpay success callback and backend verification.

## Load Razorpay Checkout Script

Add the Razorpay checkout script before opening modal.

```js
function loadRazorpayScript() {
  return new Promise((resolve) => {
    if (window.Razorpay) {
      resolve(true);
      return;
    }

    const script = document.createElement("script");
    script.src = "https://checkout.razorpay.com/v1/checkout.js";
    script.onload = () => resolve(true);
    script.onerror = () => resolve(false);
    document.body.appendChild(script);
  });
}
```

If using TypeScript, add this somewhere in your frontend types:

```ts
declare global {
  interface Window {
    Razorpay?: any;
  }
}
```

## Open Razorpay Modal From React

Example React helper:

```js
async function openRazorpayPayment({ order, razorpay, token, apiBaseUrl }) {
  const scriptLoaded = await loadRazorpayScript();

  if (!scriptLoaded) {
    throw new Error("Razorpay SDK failed to load");
  }

  const options = {
    key: razorpay.key,
    amount: razorpay.amount,
    currency: razorpay.currency,
    name: razorpay.name,
    description: razorpay.description,
    order_id: razorpay.order_id,
    prefill: razorpay.prefill,
    handler: async function (response) {
      const verifyResponse = await fetch(`${apiBaseUrl}/razorpay/payment/verify`, {
        method: "POST",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "Authorization": `Bearer ${token}`
        },
        body: JSON.stringify({
          order_id: order.id,
          razorpay_order_id: response.razorpay_order_id,
          razorpay_payment_id: response.razorpay_payment_id,
          razorpay_signature: response.razorpay_signature
        })
      });

      const verifyJson = await verifyResponse.json();

      if (!verifyResponse.ok || !verifyJson.status) {
        throw new Error(verifyJson.message || "Payment verification failed");
      }

      // Payment is verified by backend. Redirect to success page.
      window.location.href = `/order-success/${verifyJson.data.id}`;
    },
    modal: {
      ondismiss: function () {
        // User closed payment modal. Keep order as pending and show retry payment UI if needed.
        console.log("Razorpay modal closed");
      }
    },
    theme: {
      color: "#111827"
    }
  };

  const paymentObject = new window.Razorpay(options);
  paymentObject.open();
}
```

## Full Place Order Example

```js
async function placeOrder(payload, token) {
  const apiBaseUrl = "http://localhost:8000/api";

  const response = await fetch(`${apiBaseUrl}/orders/create`, {
    method: "POST",
    headers: {
      "Accept": "application/json",
      "Content-Type": "application/json",
      "Authorization": `Bearer ${token}`
    },
    body: JSON.stringify(payload)
  });

  const json = await response.json();

  if (!response.ok || !json.status) {
    throw new Error(json.message || "Order creation failed");
  }

  const { order, razorpay } = json.data;

  if (payload.payment_method === "cod") {
    window.location.href = `/order-success/${order.id}`;
    return;
  }

  if (!razorpay) {
    throw new Error("Razorpay checkout data missing");
  }

  await openRazorpayPayment({
    order,
    razorpay,
    token,
    apiBaseUrl
  });
}
```

Cart checkout call:

```js
await placeOrder({
  checkout_type: "cart",
  address_id: selectedAddressId,
  payment_method: "online"
}, token);
```

Buy-now checkout call:

```js
await placeOrder({
  checkout_type: "buy_now",
  address_id: selectedAddressId,
  payment_method: "online",
  product_size_id: selectedProductSizeId,
  quantity: selectedQuantity
}, token);
```

## Payment Verify API

React calls this API only inside Razorpay success handler.

```http
POST /api/razorpay/payment/verify
```

Request:

```json
{
  "order_id": 13,
  "razorpay_order_id": "order_Qabc123456",
  "razorpay_payment_id": "pay_Qxyz123456",
  "razorpay_signature": "generated_signature_from_razorpay"
}
```

Success response:

```json
{
  "status": true,
  "data": {
    "id": 13,
    "order_number": "ORD202605310002",
    "status": "processing",
    "payment_status": "paid",
    "payment_method": "online",
    "razorpay_order_id": "order_Qabc123456",
    "razorpay_payment_id": "pay_Qxyz123456"
  },
  "message": "Payment verified successfully"
}
```

Possible special response:

```json
{
  "status": false,
  "data": {
    "id": 13,
    "status": "manual_review",
    "payment_status": "paid_stock_failed"
  },
  "message": "Payment captured but stock is no longer available"
}
```

If status code is `409` and message says stock is unavailable, payment is captured but order needs manual review. Show a support message to the user instead of retrying payment.

## Order APIs

### List Orders

```http
GET /api/orders
```

Use this for My Orders page.

### Get Order Detail

```http
GET /api/orders/{id}
```

Use this for order success/detail page.

### Cancel Order

```http
POST /api/orders/{id}/cancel
```

Request:

```json
{
  "reason": "Ordered by mistake"
}
```

## Recommended React Page Flow

### Product Detail Buy Now

1. User selects product size.
2. User selects quantity.
3. User clicks Buy Now.
4. Navigate to checkout page with:

```js
{
  checkout_type: "buy_now",
  product_size_id: selectedProductSizeId,
  quantity: selectedQuantity
}
```

5. On checkout page, user selects address and payment method.
6. Call summary API.
7. Call order create API.
8. If online, open Razorpay modal.

### Cart Checkout

1. User adds products to cart.
2. User clicks Checkout from cart page.
3. Navigate to checkout page with:

```js
{
  checkout_type: "cart"
}
```

4. On checkout page, user selects address and payment method.
5. Call summary API.
6. Call order create API.
7. If online, open Razorpay modal.

## Error Handling

Handle these common errors in React:

- `401`: User is not logged in. Redirect to login.
- `422`: Validation error. Show field-level messages if backend returns validation details.
- `400`: Business error, such as cart empty, inactive product, invalid signature, or insufficient stock.
- `409`: Payment captured but stock failed. Show support/manual-review message.
- `500`: Server error. Show generic retry message.

Common backend messages:

- `Cart is empty`
- `Selected address was not found`
- `Product not found or inactive`
- `Insufficient stock available for selected size`
- `Razorpay credentials are not configured`
- `Invalid Razorpay payment signature`
- `Razorpay payment amount does not match local order total`
- `Payment captured but stock is no longer available`

## Frontend Checklist

- Add auth token to protected API calls.
- Build address selection UI using `/api/addresses`.
- Store selected address id as `address_id`.
- For buy-now, store selected `product_size_id` and `quantity`.
- Call `/api/checkout/summary` before placing order.
- Call `/api/orders/create` on final Place Order / Pay Now click.
- For COD, redirect to success after order create.
- For online, open Razorpay modal from `data.razorpay`.
- In Razorpay handler, call `/api/razorpay/payment/verify`.
- Redirect to success only after backend payment verification succeeds.
- Do not call `/api/razorpay/webhook` from React.

