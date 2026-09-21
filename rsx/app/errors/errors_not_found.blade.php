@rsx_id('Errors_Not_Found')
@rsx_extends('Errors_Layout')

@section('title', 'Page Not Found')

@section('content')
    <p class="mb-0">{{ $error->message }}</p>
@endsection
