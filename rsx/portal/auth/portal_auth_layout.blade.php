@rsx_id('Portal_Auth_Layout')
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="ie=edge" http-equiv="X-UA-Compatible">
    <title>@yield('title', 'Portal') - Client Portal</title>

    {{-- Bundle includes --}}
    {!! Portal_Auth_Bundle::render() !!}
</head>

<body class="Portal_Auth_Layout preload {{ rsx_body_class() }}">
    <div class="pagemodal-container">
        <div class="pagemodal-card">
            <div class="pagemodal-header">
                <h2>@yield('card_title', 'Client Portal')</h2>
                @hasSection('card_subtitle')
                    <p>@yield('card_subtitle')</p>
                @endif
            </div>
            <div class="pagemodal-body">
                @yield('content')
            </div>
        </div>
        {{-- Below the card: what the page shows outside it (the human-verification widget,
             tied back to its form with Turnstile_Input $form). --}}
        @hasSection('below_card')
            <div class="pagemodal-below-card">
                @yield('below_card')
            </div>
        @endif
    </div>
</body>

</html>
