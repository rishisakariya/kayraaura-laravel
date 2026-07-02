<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoiceNumber }}</title>
    <style>
        @page {
            margin: 22mm 20mm 22mm 20mm;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            color: #111111;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
        }
        .page { padding: 16px 18px 16px 18px; }
        table { border-collapse: collapse; width: 100%; }
        .text-right { text-align: right; }
        .muted { color: #666666; font-size: 8.5px; }

        /* Brand: Kayra Aura — black + warm gold */
        .brand-black { color: #111111; }
        .brand-gold { color: #c6a055; }

        /* Header */
        .header-table td { vertical-align: top; padding: 0; }
        .brand-cell { width: 50%; }
        .meta-cell { width: 50%; text-align: right; }
        .logo-wrap { margin-bottom: 5px; }
        .logo {
            height: 95px;
            max-width: 300px;
            width: auto;
        }
        .brand-rule {
            background-color: #c6a055;
            height: 2px;
            margin: 4px 0 6px;
            width: 120px;
        }
        .company-line {
            color: #444444;
            font-size: 9px;
            line-height: 1.5;
            margin-top: 2px;
        }
        .company-line .gold-icon { color: #c6a055; }
        .tax-banner {
            background-color: #111111;
            border-bottom: 3px solid #c6a055;
            color: #ffffff;
            display: inline-block;
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 1.8px;
            padding: 6px 16px 5px;
            text-transform: uppercase;
        }
        .meta-details { margin-top: 8px; }
        .meta-details td {
            color: #333333;
            font-size: 9px;
            padding: 1px 0;
            text-align: right;
        }
        .meta-details .label { color: #888888; padding-right: 8px; }
        .meta-details strong { color: #111111; }
        .status-badge {
            background-color: #f0fdf4;
            border: 1px solid #c6a055;
            border-radius: 10px;
            color: #15803d;
            display: inline-block;
            font-size: 8.5px;
            font-weight: bold;
            letter-spacing: 0.5px;
            margin-top: 5px;
            padding: 2px 9px;
            text-transform: uppercase;
        }
        .status-badge.pending {
            background-color: #fffbeb;
            border-color: #c6a055;
            color: #92400e;
        }
        .status-badge.failed {
            background-color: #fef2f2;
            border-color: #dc2626;
            color: #b91c1c;
        }
        .status-badge.refunded {
            background-color: #faf5eb;
            border-color: #c6a055;
            color: #111111;
        }
        .header-divider {
            border: 0;
            border-top: 1px solid #c6a055;
            margin: 14px 0 12px;
            opacity: 0.45;
        }

        /* Bill To + Payment block */
        .info-table { margin-bottom: 12px; }
        .info-box {
            background-color: #faf8f4;
            border: 1px solid #e8dcc4;
            border-top: 2px solid #c6a055;
            padding: 10px 12px;
            vertical-align: top;
            width: 50%;
        }
        .info-box-title {
            border-bottom: 1px solid #e8dcc4;
            color: #111111;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.8px;
            margin-bottom: 6px;
            padding-bottom: 5px;
            text-transform: uppercase;
        }
        .info-box-title span { color: #c6a055; }
        .info-box p { color: #333333; font-size: 9.5px; line-height: 1.5; margin: 0; }
        .info-box .customer-name { color: #111111; font-size: 11px; font-weight: bold; margin-bottom: 3px; }
        .detail-row { color: #333333; font-size: 9.5px; line-height: 1.55; }
        .detail-row .detail-label { color: #888888; display: inline-block; width: 88px; }
        .detail-row strong { color: #111111; }

        /* Items table */
        .items-table { margin-bottom: 10px; }
        .items-table th {
            background-color: #111111;
            border-bottom: 2px solid #c6a055;
            color: #ffffff;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.5px;
            padding: 7px 8px;
            text-transform: uppercase;
        }
        .items-table td {
            border-bottom: 1px solid #ece5d8;
            color: #333333;
            font-size: 9.5px;
            padding: 6px 8px;
            vertical-align: top;
        }
        .items-table tr.even td { background-color: #faf8f4; }
        .items-table .item-name { color: #111111; font-weight: bold; }

        /* Totals */
        .bottom-table td { vertical-align: top; }
        .totals-wrap { width: 100%; }
        .totals-wrap td {
            border: 0;
            color: #333333;
            font-size: 9.5px;
            padding: 3px 0;
        }
        .totals-wrap .discount td { color: #15803d; }
        .totals-wrap .grand-total td {
            background-color: #111111;
            border-top: 2px solid #c6a055;
            color: #ffffff;
            font-size: 11px;
            font-weight: bold;
            padding: 7px 10px;
        }

        /* Perks footer */
        .perks-section {
            margin-top: 16px;
            width: 100%;
        }
        .perks-grid {
            background-color: #f3f3f3;
            width: 100%;
        }
        .perks-grid td {
            padding: 14px 8px 12px;
            text-align: center;
            vertical-align: top;
            width: 33.33%;
        }
        .perks-icon {
            background-color: #ffffff;
            border-radius: 50%;
            color: #444444;
            display: inline-block;
            font-size: 16px;
            font-weight: bold;
            height: 38px;
            line-height: 38px;
            margin-bottom: 8px;
            text-align: center;
            width: 38px;
        }
        .perks-text {
            color: #111111;
            font-size: 9px;
            font-weight: bold;
            line-height: 1.35;
        }
        .perks-text.underlined { text-decoration: underline; }
        .delivery-banner {
            background-color: #e4e4e4;
            color: #111111;
            font-size: 9.5px;
            font-weight: bold;
            padding: 8px 10px;
            text-align: center;
        }

        /* Collections footer */
        .collections-section {
            background-color: #ffffff;
            border: 0;
            border-bottom: 2px solid #c6a055;
            bottom: 5mm;
            left: 20mm;
            padding: 8px 0 0;
            position: fixed;
            right: 20mm;
            text-align: center;
        }
        .collections-title {
            color: #111111;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1.4px;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        .collections-grid {
            margin: 0 auto 8px;
            width: 100%;
        }
        .collections-grid td {
            color: #333333;
            font-size: 7.5px;
            font-weight: bold;
            line-height: 1.4;
            padding: 0 2px;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
        }
        .collections-grid .cat-bullet {
            color: #c6a055;
            padding-right: 4px;
        }
        .collections-website {
            color: #c6a055;
            font-size: 9.5px;
            font-weight: bold;
            margin-bottom: 6px;
            text-align: center;
        }
        .collections-section .footer {
            color: #888888;
            font-size: 8.5px;
            margin: 0;
            padding: 0 0 4px;
            text-align: center;
        }
        .collections-section .footer p { line-height: 1.35; margin: 0; }
        .collections-section .footer .brand-name { color: #c6a055; font-weight: bold; }
    </style>
</head>
<body>
@php
    $formatMoney = fn ($amount) => 'INR ' . number_format((float) $amount, 2);

    $formatBillTo = function (?array $address): string {
        if (!$address) {
            return '';
        }

        $html = '';

        if ($address['name'] ?? null) {
            $html .= '<div class="customer-name">' . e($address['name']) . '</div>';
        }

        $lines = array_filter([
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

        if ($lines !== []) {
            $html .= '<p>' . implode('<br>', array_map('e', $lines)) . '</p>';
        }

        return $html;
    };

    $billingAddress = $order->billing_address ?: $order->shipping_address;
    $itemsSubtotal = $order->orderItems->sum(fn ($item) => (float) $item->total);
    $buyTwoGetOneDiscount = (float) ($order->buy_two_get_one_discount_amount ?? 0);
    $firstOrderDiscount = (float) ($order->first_order_discount_amount ?? 0);
    $onlinePaymentDiscount = (float) ($order->online_payment_discount_amount ?? 0);
    $couponDiscount = (float) ($order->discount_amount ?? 0);

    $logoPath = public_path('uploads/kayraauralogo.png');
    $paymentStatusClass = match ($order->payment_status) {
        'paid' => '',
        'pending' => 'pending',
        'failed' => 'failed',
        'refunded' => 'refunded',
        default => '',
    };

    $perkLine1 = $webSetting->offer_line1 ?: 'Cash on Delivery';
    $perkLine2 = $webSetting->offer_line2 ?: 'Return or Exchange within ' . \App\Models\Order::RETURN_WINDOW_DAYS . ' days';
    $perkLine3 = $webSetting->offer_line3 ?: 'Free delivery on orders above ₹1000.';
    $deliveryBanner = $webSetting->offer_line4 ?: 'Get it delivered in 3-6 days';

    $collectionCategories = [
        'Rings', 'Bangles', 'Bracelets', 'Earrings', 'Necklaces', 'Chains', 'Anklets',
    ];
@endphp

<div class="page">
    {{-- Header --}}
    <table class="header-table">
        <tr>
            <td class="brand-cell">
                <div class="logo-wrap">
                    @if(file_exists($logoPath))
                        <img src="{{ $logoPath }}" alt="Kayra Aura" class="logo">
                    @else
                        <div style="font-size: 20px; font-weight: bold; color: #111111; margin-bottom: 4px;">Kayra Aura</div>
                    @endif
                </div>
                <div class="brand-rule"></div>
                @if($webSetting->address)
                    <div class="company-line">{{ $webSetting->address }}</div>
                @endif
                @if($webSetting->email)
                    <div class="company-line"><span class="gold-icon">&#9993;</span> {{ $webSetting->email }}</div>
                @endif
                @if($webSetting->mobile_number)
                    <div class="company-line"><span class="gold-icon">&#9742;</span> {{ $webSetting->mobile_number }}</div>
                @endif
            </td>
            <td class="meta-cell">
                <div class="tax-banner">Tax Invoice</div>
                <table class="meta-details" align="right">
                    <tr>
                        <td class="label">Invoice No</td>
                        <td><strong>{{ $invoiceNumber }}</strong></td>
                    </tr>
                    <tr>
                        <td class="label">Order No</td>
                        <td><strong>{{ $order->order_number }}</strong></td>
                    </tr>
                    <tr>
                        <td class="label">Invoice Date</td>
                        <td>{{ now()->format('d M Y') }}</td>
                    </tr>
                    <tr>
                        <td class="label">Order Date</td>
                        <td>{{ $order->created_at->format('d M Y') }}</td>
                    </tr>
                </table>
                <span class="status-badge {{ $paymentStatusClass }}">&#10003; {{ str_replace('_', ' ', $order->payment_status) }}</span>
            </td>
        </tr>
    </table>

    <hr class="header-divider">

    {{-- Bill To + Payment Details --}}
    <table class="info-table">
        <tr>
            <td class="info-box" style="padding-right: 6px;">
                <div class="info-box-title"><span>&#9670;</span> Bill To</div>
                {!! $formatBillTo($billingAddress) ?: '<span class="muted">Not available</span>' !!}
            </td>
            <td class="info-box" style="padding-left: 6px;">
                <div class="info-box-title"><span>&#9670;</span> Payment &amp; Status</div>
                <div class="detail-row">
                    <span class="detail-label">Payment Method</span>
                    <strong>{{ strtoupper((string) $order->payment_method) }}</strong>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Order Status</span>
                    <strong>{{ ucwords(str_replace('_', ' ', $order->status)) }}</strong>
                </div>
                @if($order->razorpay_payment_id)
                    <div class="detail-row">
                        <span class="detail-label">Payment ID</span>
                        <span>{{ $order->razorpay_payment_id }}</span>
                    </div>
                @endif
                @if($order->scratch_coupon_code)
                    <div class="detail-row">
                        <span class="detail-label">Coupon</span>
                        <span>{{ $order->scratch_coupon_code }}</span>
                    </div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Items --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 28px;">#</th>
                <th>Item</th>
                <th style="width: 72px;">Size</th>
                <th class="text-right" style="width: 38px;">Qty</th>
                <th class="text-right" style="width: 78px;">Rate</th>
                <th class="text-right" style="width: 82px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->orderItems as $item)
                <tr class="{{ $loop->even ? 'even' : '' }}">
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        <span class="item-name">{{ $item->product_name }}</span>
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

    {{-- Totals --}}
    <table class="bottom-table">
        <tr>
            <td style="width: 50%;"></td>
            <td style="width: 50%;">
                <table class="totals-wrap">
                    <tr>
                        <td>Items Subtotal</td>
                        <td class="text-right">{{ $formatMoney($itemsSubtotal) }}</td>
                    </tr>
                    @if($buyTwoGetOneDiscount > 0)
                        <tr class="discount">
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
                        <tr class="discount">
                            <td>First Order Discount</td>
                            <td class="text-right">-{{ $formatMoney($firstOrderDiscount) }}</td>
                        </tr>
                    @endif
                    @if($onlinePaymentDiscount > 0)
                        <tr class="discount">
                            <td>Online Payment Discount ({{ (int) ($webSetting->online_payment_discount_percent ?? 0) }}%)</td>
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
                        <tr class="discount">
                            <td>Coupon Discount</td>
                            <td class="text-right">-{{ $formatMoney($couponDiscount) }}</td>
                        </tr>
                    @endif
                    <tr class="grand-total">
                        <td>TOTAL</td>
                        <td class="text-right">{{ $formatMoney($order->total_amount) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="collections-section">
        <div class="collections-title">Discover Our Collections</div>

        <table class="collections-grid" align="center">
            <tr>
                @foreach($collectionCategories as $category)
                    <td><span class="cat-bullet">&#9670;</span>{{ $category }}</td>
                @endforeach
            </tr>
        </table>

        <div class="collections-website">www.kayraaura.com</div>

        <div class="footer">
            <p>This is a system generated invoice for order {{ $order->order_number }}.</p>
            <p>Thank you for shopping with <span class="brand-name">{{ config('app.name', 'kayraaura') }}</span>.</p>
        </div>
    </div>
</div>
</body>
</html>
