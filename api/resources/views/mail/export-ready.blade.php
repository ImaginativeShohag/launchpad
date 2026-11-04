@extends('mail.layout', ['title' => $heading])
@section('body')
    <h1 style="font-size:20px;">{{ $heading }}</h1>
    <p>The data export for {{ $name }} is ready. Sign in to download it from your dashboard; it stays available for a limited time.</p>
    <p><a href="{{ $url }}">Open the dashboard of {{ $name }}</a></p>
@endsection
