@rsx_id('Frontend_Pagemodal_Layout')
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="ie=edge" http-equiv="X-UA-Compatible">
    <title>@yield('title', 'Page') - {{ config('rspade.name', 'RSX') }}</title>

    {{-- Bundle includes (CDN assets like Bootstrap Icons are included via bundle) --}}
    {!! Frontend_Bundle::render() !!}

    <style>
        /* Page modal layout - centered card with scroll */
        .pagemodal-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10vh 10vw;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .pagemodal-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 500px;
        }

        .pagemodal-header {
            padding: 2rem 2rem 1rem;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
        }

        .pagemodal-header h2 {
            margin: 0 0 0.5rem;
            font-size: 1.75rem;
            font-weight: 600;
            color: #212529;
        }

        .pagemodal-header p {
            margin: 0;
            color: #6c757d;
            font-size: 0.95rem;
        }

        .pagemodal-body {
            padding: 2rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .pagemodal-container {
                padding: 5vh 5vw;
            }

            .pagemodal-card {
                max-width: 100%;
            }

            .pagemodal-header {
                padding: 1.5rem 1.5rem 1rem;
            }

            .pagemodal-header h2 {
                font-size: 1.5rem;
            }

            .pagemodal-body {
                padding: 1.5rem;
            }
        }
    </style>
</head>

<body class="{{ rsx_body_class() }}">
    <div class="pagemodal-container">
        <div class="pagemodal-card">
            <div class="pagemodal-header">
                <h2>@yield('card_title', 'Page')</h2>
                @hasSection('card_subtitle')
                    <p>@yield('card_subtitle')</p>
                @endif
            </div>
            <div class="pagemodal-body">
                @yield('content')
            </div>
        </div>
    </div>
</body>

</html>
