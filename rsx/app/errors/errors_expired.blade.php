@rsx_id('Errors_Expired')
@rsx_extends('Errors_Layout')

@section('title', 'Page Expired')

@section('content')
    <p>{{ $error->message }}</p>
    <p class="mb-0">
        This happens when a page sits open long enough for its security token to expire, or
        when the same form is submitted from two tabs. Nothing was saved. Open the form
        again and resubmit it.
    </p>
@endsection
