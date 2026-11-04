@extends('mail.layout', ['title' => $heading])
@section('body')
    <h1 style="font-size:20px;">{{ $heading }}</h1>
    <p>{{ $lead }}</p>
    <p><a href="{{ $url }}">Open {{ $name }} on Launchpad</a></p>
@endsection
