@props(['product'])
@php($card = app(\App\View\Storefront\ProductPresenter::class)->card($product))
<p data-acme-card>{{ $card['name'] }} {{ $card['price'] }}</p>
