{{--
    RSpade error screen - the one server-rendered page every terminal request
    outcome uses (App\RSpade\Core\Errors\Error_Screens).

    STANDALONE ON PURPOSE: inline styles, no bundle, no manifest view lookup, no
    JavaScript. This page has to render when the application is broken.

    Palette tracks rsx/theme/variables.scss (primary #0d6efd, gray-900 #212529,
    gray-600 #6c757d, gray-300 #dee2e6, gray-100 #f8f9fa).

    Variables: $status, $heading, $message, $detail (array|null), $home_url
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $status }} {{ $heading }}</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            background: #f8f9fa;
            color: #212529;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 16px;
            line-height: 1.5;
        }

        .rsx-error {
            width: 100%;
            max-width: 720px;
            background: #ffffff;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }

        .rsx-error__body {
            padding: 40px;
        }

        .rsx-error__status {
            display: inline-block;
            padding: 2px 10px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            background: #f8f9fa;
            color: #6c757d;
            font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, Courier, monospace;
            font-size: 13px;
            letter-spacing: 0.08em;
        }

        .rsx-error__heading {
            margin: 0 0 12px;
            font-size: 28px;
            font-weight: 600;
            line-height: 1.2;
        }

        .rsx-error__message {
            margin: 0 0 32px;
            color: #6c757d;
        }

        .rsx-error__action {
            display: inline-block;
            padding: 8px 18px;
            border-radius: 4px;
            background: #0d6efd;
            color: #ffffff;
            font-size: 15px;
            text-decoration: none;
        }

        .rsx-error__action:hover {
            background: #0b5ed7;
        }

        .rsx-error__detail {
            border-top: 1px solid #dee2e6;
            padding: 24px 40px 32px;
            background: #f8f9fa;
        }

        .rsx-error__detail-label {
            margin: 0 0 10px;
            color: #6c757d;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .rsx-error__detail-message {
            margin: 0 0 6px;
            font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, Courier, monospace;
            font-size: 14px;
            word-break: break-word;
        }

        .rsx-error__detail-origin {
            margin: 0 0 18px;
            color: #6c757d;
            font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, Courier, monospace;
            font-size: 13px;
            word-break: break-word;
        }

        .rsx-error__trace {
            margin: 0;
            padding: 12px;
            max-height: 320px;
            overflow: auto;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            background: #ffffff;
            color: #495057;
            font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, Courier, monospace;
            font-size: 12.5px;
            line-height: 1.7;
            white-space: pre;
        }
    </style>
</head>
<body>
    <div class="rsx-error">
        <div class="rsx-error__body">
            <div class="rsx-error__status">ERROR {{ $status }}</div>
            <h1 class="rsx-error__heading">{{ $heading }}</h1>
            <p class="rsx-error__message">{{ $message }}</p>
            <a class="rsx-error__action" href="{{ $home_url }}">Return to Home</a>
        </div>

        @if ($detail)
            <div class="rsx-error__detail">
                <p class="rsx-error__detail-label">{{ $detail['class'] }}</p>
                <p class="rsx-error__detail-message">{{ $detail['message'] }}</p>
                <p class="rsx-error__detail-origin">{{ $detail['file'] }}:{{ $detail['line'] }}</p>
                @if (!empty($detail['frames']))
                    <pre class="rsx-error__trace">{{ implode("\n", $detail['frames']) }}</pre>
                @endif
            </div>
        @endif
    </div>
</body>
</html>
