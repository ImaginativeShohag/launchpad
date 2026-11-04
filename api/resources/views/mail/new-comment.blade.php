@extends('mail.layout', ['title' => $heading])
@section('body')
    <h1 style="font-size:20px;">{{ $heading }}</h1>
    <p>{{ $lead }}</p>
    <p>{{ $context }}</p>
    <p><a href="{{ $url }}">Read it on Launchpad</a></p>
@endsection
