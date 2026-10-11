@rsx_id('Errors_Layout')
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="ie=edge" http-equiv="X-UA-Compatible">
    <title>@yield('title', $error->title) - {{ config('rspade.name', 'RSX') }}</title>

    {{-- Bundle includes (CDN assets like Bootstrap Icons are included via bundle) --}}
    {!! Errors_Bundle::render() !!}
</head>

{{-- The theme is on <html>, put there before first paint by the autotheme script the
     bundle emits, exactly as on Login_Layout and the authenticated SPA shell. --}}
<body class="Errors_Layout {{ rsx_body_class() }}">
    <main class="Errors_Layout__viewport">
        <div class="card Errors_Layout__card">
            <div class="card-body Errors_Layout__body">
                {{-- The chip carries the status verbatim: a person reading the page and a
                     developer reading a screenshot both want the number. --}}
                <div class="Errors_Layout__status">@yield('status', 'ERROR ' . $error->status)</div>

                <h1 class="Errors_Layout__heading">@yield('heading', $error->title)</h1>

                <div class="Errors_Layout__content">
                    @yield('content')
                </div>

                <div class="Errors_Layout__actions">
                    {{-- home_url is the realm's home, computed by the framework: the staff
                         root here, the portal prefix in the portal realm. --}}
                    <a class="btn btn-primary" href="{{ $error->home_url }}">@yield('action_label', 'Return to Home')</a>
                    @yield('actions')
                </div>
            </div>

            @hasSection('detail')
                <div class="Errors_Layout__detail">
                    @yield('detail')
                </div>
            @endif
        </div>
    </main>
</body>

</html>
