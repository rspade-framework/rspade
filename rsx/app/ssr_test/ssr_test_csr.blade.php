@rsx_id('SSR_Test_CSR')
@rsx_extends('SSR_Test_Layout')

@section('content')
    <SSR_Test_Page></SSR_Test_Page>
@endsection

@section('footer')
    <span>Client-side rendered (CSR)</span>
@endsection
