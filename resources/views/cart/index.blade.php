@extends('layouts.app')

@section('title', 'Cart — '.config('app.name'))

@section('content')
    @livewire('shopping-cart')
@endsection
