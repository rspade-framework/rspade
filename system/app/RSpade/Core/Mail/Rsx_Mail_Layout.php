<?php

namespace App\RSpade\Core\Mail;

/**
 * Rsx_Mail_Layout - HTML email layout helper
 *
 * Provides header() and footer() methods that wrap email template content
 * in a responsive HTML email layout. Used in Blade templates:
 *
 *   {!! Rsx_Mail_Layout::header($subject) !!}
 *   ... email content ...
 *   {!! Rsx_Mail_Layout::footer($unsubscribe_url) !!}
 */
class Rsx_Mail_Layout
{
    /**
     * Render the email header (everything before content)
     */
    public static function header(string $subject = ''): string
    {
        $app_name = config('rsx.name', 'RSpade');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f5f7;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            font-size: 15px;
            line-height: 1.6;
            color: #333333;
            -webkit-text-size-adjust: 100%;
        }
        .email-wrapper {
            width: 100%;
            background-color: #f4f5f7;
            padding: 32px 0;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        .email-header {
            background-color: #212529;
            color: #ffffff;
            padding: 20px 32px;
            font-size: 17px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        .email-body {
            padding: 32px;
        }
        .email-body p {
            margin: 0 0 16px 0;
        }
        .email-body a {
            color: #0d6efd;
        }
        .email-button {
            display: inline-block;
            background-color: #0d6efd;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 28px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 15px;
            margin: 8px 0 16px 0;
        }
        .email-button:hover {
            background-color: #0b5ed7;
        }
        .email-muted {
            font-size: 13px;
            color: #868e96;
        }
        .email-divider {
            border: 0;
            border-top: 1px solid #e9ecef;
            margin: 24px 0;
        }
        .email-footer {
            padding: 20px 32px;
            background-color: #f8f9fa;
            border-top: 1px solid #e9ecef;
            font-size: 12px;
            color: #868e96;
            text-align: center;
        }
        .email-footer a {
            color: #868e96;
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
            <tr>
                <td align="center">
                    <div class="email-container">
                        <div class="email-header">
                            {$app_name}
                        </div>
                        <div class="email-body">
HTML;
    }

    /**
     * Render the email footer (everything after content)
     */
    public static function footer(?string $unsubscribe_url = null): string
    {
        $app_name = config('rsx.name', 'RSpade');
        $year = date('Y');

        $unsubscribe_html = '';
        if ($unsubscribe_url) {
            $unsubscribe_html = "<p><a href=\"{$unsubscribe_url}\">Unsubscribe</a> from these emails.</p>";
        }

        return <<<HTML
                        </div>
                        <div class="email-footer">
                            <p>&copy; {$year} {$app_name}. All rights reserved.</p>
                            {$unsubscribe_html}
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
HTML;
    }
}
