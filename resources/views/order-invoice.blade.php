<!doctype html>
<html lang="en">
<?php
/** @var App\Models\Order $order */
/** @var string $logo */
/** @var string $lang */

use App\Helpers\ArabicPdfText;
use App\Helpers\ResponseError;

$userName  = ArabicPdfText::shape(trim("{$order->user?->firstname} {$order->user?->lastname}"));
$userPhone = $order->phone ?? $order->user?->phone;
$address   = ArabicPdfText::shape((string) data_get($order, 'address.address', ''));
$floorLine = ArabicPdfText::shape(trim(implode(' ', array_filter([
    data_get($order, 'address.floor'),
    data_get($order, 'address.house'),
    data_get($order, 'address.office'),
]))));
$position  = $order?->currency?->position;
$symbol    = $order?->currency?->symbol;
$products  = [];

foreach ($order->orderDetails as $orderDetail) {
    $title = (string) $orderDetail->stock?->product?->translation?->title;

    $extras = [];
    foreach ($orderDetail->stock?->stockExtras?->sortDesc() ?? [] as $item) {
        $extras[] = trim(($item->group?->translation?->title ?? '') . ': ' . ($item->value?->value ?? ''));
    }

    if ($extras) {
        $title .= ' (' . implode(', ', $extras) . ')';
    }

    $products[] = [
        'id'                => $orderDetail->stock->product->id,
        'title'             => ArabicPdfText::shape($title),
        'quantity'          => $orderDetail->quantity,
        'rate_discount'     => $orderDetail->rate_discount,
        'rate_origin_price' => $orderDetail->rate_origin_price,
        'rate_total_price'  => $orderDetail->rate_total_price,
    ];
}
?>
<head>
    <meta charset="UTF-8">
    <title>{{ __('errors.' . ResponseError::ORDER, locale: $lang) }} {{ $order?->id }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        @page { margin: 24px; }
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10px;
            color: #222;
            line-height: 1.35;
            padding: 24px;
        }
        .header {
            width: 100%;
            margin-bottom: 14px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        .header td { vertical-align: top; }
        .logo { width: 56px; height: 56px; }
        .invoice-title { font-size: 16px; font-weight: bold; text-align: right; }
        .invoice-date { font-size: 11px; color: #666; text-align: right; margin-top: 2px; }
        .section { margin-bottom: 14px; }
        .section-title { font-size: 11px; font-weight: bold; margin-bottom: 4px; }
        .muted { color: #555; }
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
            table-layout: fixed;
        }
        table.data th, table.data td {
            border: 1px solid #ccc;
            padding: 5px 6px;
            font-size: 9px;
            vertical-align: top;
            word-wrap: break-word;
        }
        table.data th {
            background: #f3f3f3;
            font-weight: bold;
            text-align: left;
        }
        .text-right { text-align: right; }
        .col-id { width: 10%; }
        .col-qty { width: 10%; }
        .col-num { width: 14%; }
    </style>
</head>
<body>
<table class="header">
    <tr>
        <td style="width: 70px;">
            @if($logo)
                <img class="logo" src="{{ $logo }}" alt="logo"/>
            @endif
        </td>
        <td>
            <div class="section-title">{{ __('errors.' . ResponseError::ADDRESS_PLACE, locale: $lang) }}</div>
            <div class="muted">{{ $userName }}</div>
            @if($address !== '')
                <div class="muted">{{ $address }}</div>
            @endif
            @if($floorLine !== '')
                <div class="muted">{{ $floorLine }}</div>
            @endif
            @if(!empty($userPhone))
                <div class="muted">+{{ str_replace('+', '', $userPhone) }}</div>
            @endif
        </td>
        <td class="text-right" style="width: 38%;">
            <div class="invoice-title">{{ __('errors.' . ResponseError::INVOICE, locale: $lang) }} #{{ $order->id }}</div>
            <div class="invoice-date">{{ $order->created_at?->format('Y-m-d') }}</div>
        </td>
    </tr>
</table>

<table class="data">
    <thead>
    <tr>
        <th class="col-id">#</th>
        <th>{{ __('errors.' . ResponseError::PRODUCT, locale: $lang) }}</th>
        <th class="col-qty">{{ __('errors.' . ResponseError::QUANTITY, locale: $lang) }}</th>
        <th class="col-num">{{ __('errors.' . ResponseError::DISCOUNT, locale: $lang) }}</th>
        <th class="col-num">{{ __('errors.' . ResponseError::PRICE, locale: $lang) }}</th>
        <th class="col-num">{{ __('errors.' . ResponseError::TOTAL_PRICE, locale: $lang) }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse($products as $product)
        <tr>
            <td>#{{ $product['id'] ?? 0 }}</td>
            <td>{{ $product['title'] ?? 'no name' }}</td>
            <td>{{ $product['quantity'] ?? 0 }}</td>
            <td>{{ number_format((float) ($product['rate_discount'] ?? 0), 2) }}</td>
            <td>
                {{ $position === 'before' ? $symbol : '' }}
                {{ number_format($product['rate_origin_price'] ?? 0, 2) }}
                {{ $position === 'after' ? $symbol : '' }}
            </td>
            <td>
                {{ $position === 'before' ? $symbol : '' }}
                {{ number_format($product['rate_total_price'] ?? 0, 2) }}
                {{ $position === 'after' ? $symbol : '' }}
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="6">—</td>
        </tr>
    @endforelse
    </tbody>
</table>

<table class="data">
    <thead>
    <tr>
        <th>{{ __('errors.' . ResponseError::PRICE, locale: $lang) }}</th>
        <th>{{ __('errors.' . ResponseError::DELIVERY_FEE, locale: $lang) }}</th>
        <th>{{ __('errors.' . ResponseError::COUPON, locale: $lang) }}</th>
        <th>
            {{ $position === 'before' ? $symbol : '' }}
            {{ __('errors.' . ResponseError::TOTAL_PRICE, locale: $lang) }}
            {{ $position === 'after' ? $symbol : '' }}
        </th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td>
            {{ $position === 'before' ? $symbol : '' }}
            {{ number_format($order->rate_total_price - $order->rate_delivery_fee - $order->rate_coupon_price, 2) }}
            {{ $position === 'after' ? $symbol : '' }}
        </td>
        <td>
            {{ $position === 'before' ? $symbol : '' }}
            {{ number_format($order->rate_delivery_fee, 2) }}
            {{ $position === 'after' ? $symbol : '' }}
        </td>
        <td>
            {{ $position === 'before' ? $symbol : '' }}
            {{ number_format($order->rate_coupon_price, 2) }}
            {{ $position === 'after' ? $symbol : '' }}
        </td>
        <td>
            {{ $position === 'before' ? $symbol : '' }}
            {{ number_format($order->rate_total_price, 2) }}
            {{ $position === 'after' ? $symbol : '' }}
        </td>
    </tr>
    </tbody>
</table>
</body>
</html>
