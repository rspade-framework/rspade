@rsx_id('SSR_Test_Layout')
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>SSR Test - {{ config('rspade.name', 'RSX') }}</title>

    {{-- Bundle CSS and JS --}}
    {!! SSR_Test_Bundle::render() !!}

    <style>
        .SSR_Test_Layout__footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #1a1a2e;
            color: #a0a0b0;
            font-family: monospace;
            font-size: 12px;
            padding: 6px 16px;
            display: flex;
            justify-content: space-between;
            z-index: 1000;
        }
        .SSR_Test_Layout__footer strong { color: #e0e0f0; }
        .SSR_Test_Layout__footer-error { color: #ff6b6b; }
        body { padding-bottom: 32px; }
    </style>
</head>

<body class="SSR_Test_Layout bg-light {{ rsx_body_class() }}">
    @yield('content')

    <div class="SSR_Test_Layout__footer">
        @yield('footer')
    </div>
</body>

</html>
