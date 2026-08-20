@rsx_id('SSR_Test_Index')
@rsx_extends('SSR_Test_Layout')

@section('content')
    @if($error)
        <div class="container py-5">
            <div class="alert alert-danger">
                <strong>SSR Error:</strong> {{ $error }}
            </div>
        </div>
    @else
        {!! $html !!}
    @endif
@endsection

@section('footer')
    @if($error)
        <span class="SSR_Test_Layout__footer-error">SSR failed in {{ $total_ms }}ms</span>
    @else
        <span>SSR rendered in <strong>{{ $total_ms }}ms</strong> (PHP total)</span>
        @if(!empty($timing))
            <span>
                Node: <strong>{{ $timing['total_ms'] ?? '?' }}ms</strong>
                (bundle: {{ $timing['bundle_load_ms'] ?? '?' }}ms, render: {{ $timing['render_ms'] ?? '?' }}ms)
            </span>
        @endif
    @endif
@endsection
