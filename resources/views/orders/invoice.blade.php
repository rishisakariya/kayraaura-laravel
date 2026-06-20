<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoiceNumber }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            color: #111827;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 12px;
            line-height: 1.45;
            margin: 0;
        }
        .page { padding: 28px; }
        .header {
            border-bottom: 2px solid #111827;
            margin-bottom: 20px;
            padding-bottom: 14px;
        }
        .brand { float: left; width: 55%; }
        .invoice-meta { float: right; text-align: right; width: 45%; }
        .clearfix::after { clear: both; content: ""; display: table; }
        h1, h2, h3, p { margin: 0; }
        h1 { font-size: 28px; letter-spacing: 1px; }
        h2 { font-size: 16px; margin-bottom: 8px; }
        h3 { font-size: 13px; margin-bottom: 5px; }
        .muted { color: #6b7280; }
        .section { margin-bottom: 18px; }
        .columns { width: 100%; }
        .column {
            border: 1px solid #d1d5db;
            padding: 12px;
            vertical-align: top;
            width: 50%;
        }
        table { border-collapse: collapse; width: 100%; }
        th {
            background: #f3f4f6;
            color: #111827;
            font-weight: bold;
            text-align: left;
        }
        th, td {
            border: 1px solid #d1d5db;
            padding: 8px;
            vertical-align: top;
        }
        .text-right { text-align: right; }
        .totals {
            margin-left: auto;
            width: 45%;
        }
        .totals td { border: 0; padding: 5px 0; }
        .totals .grand-total td {
            border-top: 2px solid #111827;
            font-size: 15px;
            font-weight: bold;
            padding-top: 8px;
        }
        .badge {
            border: 1px solid #111827;
            display: inline-block;
            font-size: 10px;
            letter-spacing: .5px;
            padding: 2px 6px;
            text-transform: uppercase;
        }
        .footer {
            border-top: 1px solid #d1d5db;
            color: #6b7280;
            font-size: 10px;
            margin-top: 24px;
            padding-top: 10px;
            text-align: center;
        }
    </style>
</head>
<body>
@php
    $formatMoney = fn ($amount) => 'INR ' . number_format((float) $amount, 2);
    $formatAddress = function (?array $address): string {
        $parts = array_filter([
            $address['name'] ?? null,
            $address['address_line_1'] ?? $address['address'] ?? null,
            $address['address_line_2'] ?? null,
            $address['landmark'] ?? null,
            trim(implode(' ', array_filter([
                $address['city'] ?? null,
                $address['state'] ?? null,
                $address['postal_code'] ?? $address['pincode'] ?? null,
            ]))),
            $address['country'] ?? null,
            isset($address['phone']) ? 'Phone: ' . $address['phone'] : null,
            isset($address['email']) ? 'Email: ' . $address['email'] : null,
        ]);

        return implode('<br>', array_map('e', $parts));
    };

    $billingAddress = $order->billing_address ?: $order->shipping_address;
    $itemsSubtotal = $order->orderItems->sum(fn ($item) => (float) $item->total);
    $buyTwoGetOneDiscount = (float) ($order->buy_two_get_one_discount_amount ?? 0);
    $firstOrderDiscount = (float) ($order->first_order_discount_amount ?? 0);
    $onlinePaymentDiscount = (float) ($order->online_payment_discount_amount ?? 0);
    $couponDiscount = (float) ($order->discount_amount ?? 0);
@endphp

<div class="page">
    <div class="header clearfix">
        <div class="brand">
            <h1>{{ config('app.name', 'kayraaura') }}</h1>
            <p>{{ $webSetting->address }}</p>
            <p>Email: {{ $webSetting->email }}</p>
            <p>Phone: {{ $webSetting->mobile_number }}</p>
        </div>
        <div class="invoice-meta">
            <h2>Tax Invoice</h2>
            <p><strong>Invoice No:</strong> {{ $invoiceNumber }}</p>
            <p><strong>Order No:</strong> {{ $order->order_number }}</p>
            <p><strong>Invoice Date:</strong> {{ now()->format('d M Y') }}</p>
            <p><strong>Order Date:</strong> {{ $order->created_at->format('d M Y') }}</p>
            <p><span class="badge">{{ str_replace('_', ' ', $order->payment_status) }}</span></p>
        </div>
    </div>

    <div class="section">
        <table class="columns">
            <tr>
                <td class="column">
                    <h3>Bill To</h3>
                    {!! $formatAddress($billingAddress) ?: '<span class="muted">Not available</span>' !!}
                </td>
                <td class="column">
                    <h3>Ship To</h3>
                    {!! $formatAddress($order->shipping_address) ?: '<span class="muted">Not available</span>' !!}
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Item</th>
                    <th style="width: 90px;">Size</th>
                    <th class="text-right" style="width: 70px;">Qty</th>
                    <th class="text-right" style="width: 100px;">Rate</th>
                    <th class="text-right" style="width: 110px;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->orderItems as $item)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>
                            <strong>{{ $item->product_name }}</strong>
                            @if($item->product_slug)
                                <br><span class="muted">{{ $item->product_slug }}</span>
                            @endif
                        </td>
                        <td>{{ $item->size_text ?: '-' }}</td>
                        <td class="text-right">{{ $item->quantity }}</td>
                        <td class="text-right">{{ $formatMoney($item->price) }}</td>
                        <td class="text-right">{{ $formatMoney($item->total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <table class="totals">
            <tr>
                <td>Items Subtotal</td>
                <td class="text-right">{{ $formatMoney($itemsSubtotal) }}</td>
            </tr>
            @if($buyTwoGetOneDiscount > 0)
                <tr>
                    <td>Buy 2 Get 1 Discount</td>
                    <td class="text-right">-{{ $formatMoney($buyTwoGetOneDiscount) }}</td>
                </tr>
            @endif
            <tr>
                <td>Taxable Subtotal</td>
                <td class="text-right">{{ $formatMoney($order->subtotal) }}</td>
            </tr>
            <tr>
                <td>Tax</td>
                <td class="text-right">{{ $formatMoney($order->tax_amount) }}</td>
            </tr>
            <tr>
                <td>Shipping</td>
                <td class="text-right">{{ $formatMoney($order->shipping_amount) }}</td>
            </tr>
            @if($firstOrderDiscount > 0)
                <tr>
                    <td>First Order Discount</td>
                    <td class="text-right">-{{ $formatMoney($firstOrderDiscount) }}</td>
                </tr>
            @endif
            @if($onlinePaymentDiscount > 0)
                <tr>
                    <td>Online Payment Discount (10%)</td>
                    <td class="text-right">-{{ $formatMoney($onlinePaymentDiscount) }}</td>
                </tr>
            @endif
            @if((float) ($order->cod_charge ?? 0) > 0)
                <tr>
                    <td>COD Charge</td>
                    <td class="text-right">{{ $formatMoney($order->cod_charge) }}</td>
                </tr>
            @endif
            @if($couponDiscount > 0)
                <tr>
                    <td>Coupon Discount</td>
                    <td class="text-right">-{{ $formatMoney($couponDiscount) }}</td>
                </tr>
            @endif
            <tr class="grand-total">
                <td>Total</td>
                <td class="text-right">{{ $formatMoney($order->total_amount) }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <p><strong>Payment Method:</strong> {{ strtoupper((string) $order->payment_method) }}</p>
        <p><strong>Order Status:</strong> {{ ucwords(str_replace('_', ' ', $order->status)) }}</p>
        @if($order->razorpay_payment_id)
            <p><strong>Payment ID:</strong> {{ $order->razorpay_payment_id }}</p>
        @endif
        @if($order->scratch_coupon_code)
            <p><strong>Coupon:</strong> {{ $order->scratch_coupon_code }}</p>
        @endif
    </div>

    <div class="footer">
        <p>This is a system generated invoice for order {{ $order->order_number }}.</p>
        <p>Thank you for shopping with {{ config('app.name', 'kayraaura') }}.</p>
    </div>
</div>
</body>
</html>
