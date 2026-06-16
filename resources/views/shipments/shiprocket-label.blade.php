<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Shipping Label {{ $shipment->waybill }}</title>
    <style>
        * { box-sizing: border-box; }
        body { color:#111827; font-family: DejaVu Sans, sans-serif; font-size: 12px; margin:0; }
        .label { border:2px solid #111827; padding:12px; width:100%; }
        .header { border-bottom:1px solid #d1d5db; padding-bottom:10px; margin-bottom:12px; }
        .header-title { font-size:16px; font-weight:bold; text-transform:uppercase; }
        .muted { color:#4b5563; font-size:10px; }
        .awb { border:2px solid #111827; font-size:28px; font-weight:bold; letter-spacing:1px; padding:14px; text-align:center; margin:0 0 12px; }
        .box { border:1px solid #9ca3af; padding:10px; margin-bottom:12px; }
        .title { font-size:13px; font-weight:bold; text-transform:uppercase; margin-bottom:4px; }
        table { border-collapse:collapse; width:100%; }
        th, td { border:1px solid #d1d5db; padding:6px; text-align:left; }
        th { background:#f3f4f6; font-size:10px; text-transform:uppercase; }
    </style>
</head>
<body>
<div class="label">
    <div class="header">
        <div class="header-title">Shiprocket Shipping Label</div>
        <div class="muted">Generated {{ $generatedAt->format('d M Y, h:i A') }}</div>
    </div>

    <div class="awb">AWB {{ $shipment->waybill }}</div>

    <div class="box">
        <div class="title">Shipment</div>
        <div class="muted">Order: {{ $order->order_number }}</div>
        <div class="muted">Weight: {{ $shipment->weight_grams }} g</div>
        <div class="muted">Payment: {{ $shipment->payment_mode }}</div>
    </div>

    <div class="box">
        <div class="title">Products</div>
        <div>
            {{ $order->orderItems->pluck('product_name')->implode(', ') }}
        </div>
    </div>
</div>
</body>
</html>

