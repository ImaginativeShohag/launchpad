{!! $heading !!}

{!! $lead !!}

@foreach ($products as $product)
{!! $loop->iteration !!}. {!! $product['name'] !!} - {!! $product['url'] !!}
@endforeach

--
You are receiving this because of your Launchpad email settings.
Turn off this kind of email: {!! $unsubscribeUrl !!}
