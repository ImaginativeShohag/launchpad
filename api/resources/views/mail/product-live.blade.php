@extends('mail.layout', ['title' => $heading])
@section('body')
    <h1 style="font-size:20px;">{{ $heading }}</h1>
    <p>{{ $name }} is now live on Launchpad. Share it with your people.</p>
    <p><a href="{{ $url }}">See {{ $name }}</a></p>
@endsection
