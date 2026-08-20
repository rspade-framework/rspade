@rsx_id('Login_Layout')
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="ie=edge" http-equiv="X-UA-Compatible">
    <title>@yield('title', 'Page') - {{ config('rspade.name', 'RSX') }}</title>

    {{-- Bundle includes (CDN assets like Bootstrap Icons are included via bundle) --}}
    {!! Login_Bundle::render() !!}
</head>

<body class="Login_Layout preload  {{ rsx_body_class() }}">
    <div class="pagemodal-container">
        <div class="pagemodal-card">
            @hasSection('card_title')
                <div class="pagemodal-header">
                    <h2>@yield('card_title')</h2>
                    @hasSection('card_subtitle')
                        <p>@yield('card_subtitle')</p>
                    @endif
                </div>
            @endif
            <div class="pagemodal-body">
                @yield('content')
            </div>
        </div>
    </div>
</body>

</html>
