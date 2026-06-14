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
            padding: 18px;
            width: 100%;
        }

        .row {
            clear: both;
            width: 100%;
        }

        .col {
            float: left;
            width: 50%;
        }

        .box {
            border: 1px solid #9ca3af;
            margin-bottom: 12px;
            padding: 10px;
        }

        .title {
            font-size: 18px;
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
            margin: 10px 0;
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
    <div class="row">
        <div class="col">
            <div class="title">Delhivery Shipping Label</div>
            <div class="muted">Generated {{ $generatedAt->format('d M Y, h:i A') }}</div>
        </div>
        <div class="col" style="text-align: right;">
            <div class="title">{{ $paymentMode }}</div>
            @if($sortCode)
                <div class="muted">Sort Code: {{ $sortCode }}</div>
            @endif
        </div>
    </div>

    <div class="awb">AWB {{ $shipment->waybill }}</div>

    <div class="row">
        <div class="col">
            <div class="box" style="margin-right: 6px;">
                <div class="title" style="font-size: 13px;">Ship To</div>
                <div class="line"><strong>{{ $customerName }}</strong></div>
                <div class="line">{{ $address }}</div>
                <div class="line">{{ collect([$city, $state, $pin])->filter()->implode(', ') }}</div>
                <div class="line">Phone: {{ $phone }}</div>
            </div>
        </div>
        <div class="col">
            <div class="box" style="margin-left: 6px;">
                <div class="title" style="font-size: 13px;">Shipment</div>
                <div class="line"><span class="label-key">Order</span>{{ $orderNumber }}</div>
                <div class="line"><span class="label-key">Client</span>{{ $value(['client'], 'Delhivery') }}</div>
                <div class="line"><span class="label-key">Weight</span>{{ $value(['weight'], $shipment->weight_grams) }} g</div>
                <div class="line"><span class="label-key">COD Amount</span>{{ number_format((float) $shipment->cod_amount, 2) }}</div>
            </div>
        </div>
    </div>

    <div class="box">
        <div class="title" style="font-size: 13px;">Products</div>
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
