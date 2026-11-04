@extends('mail.layout', ['title' => $heading])
@section('body')
    <h1 style="font-size:20px;">{{ $heading }}</h1>
    <p>{{ $lead }}</p>
    <ol>
        @foreach ($products as $product)
            <li><a href="{{ $product['url'] }}">{{ $product['name'] }}</a></li>
        @endforeach
    </ol>
@endsection
