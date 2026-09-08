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

{{-- The theme is painted here, in the first bytes of HTML: rsx_body_class() carries the
     mode class and the dark class when dark is definitely on, and rsx_body_attributes()
     carries this application's own vocabulary (data-bs-theme). An anonymous visitor has
     no stored preference, so the configured default applies - AUTO by default, which
     Rsx_Dark_Mode.js resolves against the operating system at boot. Exactly what the
     authenticated SPA shell does; there is no login-only theme rule. --}}
<body class="Login_Layout preload {{ rsx_body_class() }}"{!! rsx_body_attributes() !!}>
    <main class="Login_Layout__viewport">
        <div class="card Login_Layout__card">
            @hasSection('card_title')
                <div class="card-header Login_Layout__header">
                    <h1 class="Login_Layout__title">@yield('card_title')</h1>
                    @hasSection('card_subtitle')
                        <p class="Login_Layout__subtitle">@yield('card_subtitle')</p>
                    @endif
                </div>
            @endif
            <div class="card-body Login_Layout__body">
                @yield('content')
            </div>
        </div>
    </main>
</body>

</html>
