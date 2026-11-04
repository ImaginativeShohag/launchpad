@extends('mail.layout', ['title' => $heading])
@section('body')
    <h1 style="font-size:20px;">{{ $heading }}</h1>
    <p>{{ $name }} goes live tomorrow ({{ $launchDate }}, UTC). Check that everything is ready.</p>
    <p><a href="{{ $url }}">Open {{ $name }} on Launchpad</a></p>
@endsection
