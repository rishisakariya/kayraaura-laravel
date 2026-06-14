<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Shipping Label {{ $shipment->waybill }}</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            color: #111827;
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            margin: 0;
        }

        .label {
            border: 2px solid #111827;
            padding: 12px;
            width: 100%;
        }

        .box {
            border: 1px solid #9ca3af;
            margin-bottom: 12px;
            padding: 10px;
        }

        .layout {
            border-collapse: collapse;
            margin: 0;
            table-layout: fixed;
            width: 100%;
        }

        .layout td {
            border: 0;
            padding: 0;
            vertical-align: top;
        }

        .header {
            border-bottom: 1px solid #d1d5db;
            margin-bottom: 12px;
            padding-bottom: 10px;
        }

        .header-title {
            font-size: 16px;
            font-weight: bold;
            line-height: 1.25;
            text-transform: uppercase;
        }

        .payment-badge {
            border: 1px solid #111827;
            display: inline-block;
            font-size: 18px;
            font-weight: bold;
            min-width: 92px;
            padding: 6px 10px;
            text-align: center;
            text-transform: uppercase;
        }

        .title {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .muted {
            color: #4b5563;
            font-size: 10px;
        }

        .awb {
            border: 2px solid #111827;
            font-size: 28px;
            font-weight: bold;
            letter-spacing: 1px;
            margin: 0 0 12px;
            padding: 14px;
            text-align: center;
        }

        .line {
            margin-bottom: 5px;
        }

        .label-key {
            color: #4b5563;
            display: inline-block;
            font-size: 10px;
            min-width: 86px;
            text-transform: uppercase;
        }

        table {
            border-collapse: collapse;
            margin-top: 8px;
            width: 100%;
        }

        th,
        td {
            border: 1px solid #d1d5db;
            padding: 6px;
            text-align: left;
        }

        th {
            background: #f3f4f6;
            font-size: 10px;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
@php
    $value = fn (array|string $keys, mixed $default = null) => collect((array) $keys)
        ->map(fn ($key) => data_get($package, $key))
        ->first(fn ($item) => filled($item), $default);

    $customerName = $value(['name', 'consignee_name'], data_get($order, 'shipping_address.name', data_get($order, 'user.name')));
    $address = $value(['add', 'address', 'consignee_address'], collect([
        data_get($order, 'shipping_address.address_line_1'),
        data_get($order, 'shipping_address.address_line_2'),
        data_get($order, 'shipping_address.landmark'),
    ])->filter()->implode(', '));
    $city = $value(['city'], data_get($order, 'shipping_address.city'));
    $state = $value(['state'], data_get($order, 'shipping_address.state'));
    $pin = $value(['pin', 'pincode'], data_get($order, 'shipping_address.postal_code'));
    $phone = $value(['phone', 'mobile'], data_get($order, 'shipping_address.phone'));
    $paymentMode = $value(['payment_mode'], $shipment->payment_mode);
    $sortCode = $value(['sort_code', 'sortcode', 'destination_code'], null);
    $orderNumber = $value(['order', 'order_id'], $order->order_number);
@endphp

<div class="label">
    <div class="header">
        <table class="layout">
            <tr>
                <td style="width: 68%;">
            <div class="header-title">Delhivery Shipping Label</div>
            <div class="muted">Generated {{ $generatedAt->format('d M Y, h:i A') }}</div>
                </td>
                <td style="width: 32%; text-align: right;">
            <div class="payment-badge">{{ $paymentMode }}</div>
            @if($sortCode)
                <div class="muted">Sort Code: {{ $sortCode }}</div>
            @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="awb">AWB {{ $shipment->waybill }}</div>

    <table class="layout">
        <tr>
            <td style="width: 50%; padding-right: 6px;">
            <div class="box">
                <div class="title">Ship To</div>
                <div class="line"><strong>{{ $customerName }}</strong></div>
                <div class="line">{{ $address }}</div>
                <div class="line">{{ collect([$city, $state, $pin])->filter()->implode(', ') }}</div>
                <div class="line">Phone: {{ $phone }}</div>
            </div>
            </td>
            <td style="width: 50%; padding-left: 6px;">
            <div class="box">
                <div class="title">Shipment</div>
                <div class="line"><span class="label-key">Order</span>{{ $orderNumber }}</div>
                <div class="line"><span class="label-key">Client</span>{{ $value(['client'], 'Delhivery') }}</div>
                <div class="line"><span class="label-key">Weight</span>{{ $value(['weight'], $shipment->weight_grams) }} g</div>
                <div class="line"><span class="label-key">COD Amount</span>{{ number_format((float) $shipment->cod_amount, 2) }}</div>
            </div>
            </td>
        </tr>
    </table>

    <div class="box">
        <div class="title">Products</div>
        <div>{{ $value(['products_desc', 'product_description'], $order->orderItems->pluck('product_name')->implode(', ')) }}</div>

        @if($order->orderItems->isNotEmpty())
            <table>
                <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                </tr>
                </thead>
                <tbody>
                @foreach($order->orderItems as $item)
                    <tr>
                        <td>{{ $item->product_name }}</td>
                        <td>{{ $item->quantity }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="muted">
        This label is generated from Delhivery packing-slip data for AWB {{ $shipment->waybill }}.
    </div>
</div>
</body>
</html>
