<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Env;

use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Env\Rsx_Env_Writer;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;

/**
 * FIRST-USER SETUP SCREEN
 *
 * An application with no accounts has a login page nobody can get past. RSpade
 * ships no default credentials on purpose - a framework with a built-in password
 * is a framework where every install in the world shares one - so a fresh
 * development install would otherwise present a login form and no way through it.
 *
 * This offers to create that first account instead.
 *
 * WHEN IT RUNS - all three required, so it is unreachable in normal operation:
 *   1. Development mode only. A production install creates its first user
 *      deliberately (RSPADE_DEFAULT_* plus migrate, or by hand), never by
 *      whoever browses it first.
 *   2. Only while login_users is EMPTY. Creating one account makes this
 *      permanently unreachable.
 *   3. Web requests only.
 *
 * Unlike the APP_URL screen this needs the DATABASE, so it cannot be a pre-boot
 * guard - it runs from the dispatcher, beside the hostname tripwire. It still
 * renders a self-contained page rather than a view, so the three setup screens
 * look like one flow and none of them depend on the asset pipeline.
 *
 * THE CHECK IS `login_users`, not `users`: credentials and site profiles are
 * different tables, and a seed migration puts a row in `users` regardless.
 */
class Rsx_First_User_Setup
{
    /**
     * Minimum password length for this account. Deliberately low - it is a local
     * development login, not a credential facing the internet - but not absent.
     */
    public const MIN_PASSWORD_LENGTH = 6;

    private const COOKIE_NAME = 'rsx_first_user';

    /**
     * Dispatcher entry point. Renders and EXITS when setup is needed; returns
     * silently when it is not, which is every request after the first account
     * exists.
     */
    public static function check(): void
    {
        if (php_sapi_name() === 'cli') {
            return;
        }

        if (!Rsx::is_development()) {
            return;
        }

        if (!self::__needs_setup()) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['rsx_create_user'])) {
            self::__handle_submit();
            return;
        }

        self::__render_form();
    }

    /**
     * True when the application has no credential records at all.
     *
     * Any database failure answers FALSE - "do not show the setup screen". A
     * missing table or an unreachable server is a different problem with its own
     * loud reporting, and hijacking every request with a setup form would only
     * bury it.
     */
    private static function __needs_setup(): bool
    {
        try {
            return Login_User_Model::query()->limit(1)->count() === 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Submission
    // -------------------------------------------------------------------------

    private static function __handle_submit(): void
    {
        $submitted = (string) ($_POST['rsx_token'] ?? '');
        $expected = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');

        if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
            self::__render_form('Your session expired. Please try again.');
            return;
        }

        $email = trim((string) ($_POST['rsx_email'] ?? ''));
        $password = (string) ($_POST['rsx_password'] ?? '');
        $autofill = isset($_POST['rsx_autofill']);

        $error = self::validate($email, $password);
        if ($error !== null) {
            self::__render_form($error, $email, $autofill);
            return;
        }

        try {
            self::__create_login_user($email, $password);
        } catch (\Throwable $e) {
            self::__render_form('Could not create the account: ' . $e->getMessage(), $email, $autofill);
            return;
        }

        // Record the credentials in .env so this account is the one the login
        // form offers to fill in - but ONLY when asked, and scoped to this host.
        $env_note = null;
        if ($autofill) {
            $env_note = self::__enable_autofill($email, $password);
        }

        self::__render_success($email, $password, $autofill, $env_note);
    }

    /**
     * Validate the submitted pair. Returns an error message, or null when valid.
     */
    public static function validate(string $email, string $password): ?string
    {
        if ($email === '') {
            return 'Enter an email address.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'That does not look like an email address.';
        }

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'The password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
        }

        return null;
    }

    /**
     * Create the credential record.
     *
     * Through the model, so the framework's own save() path runs - audit stamps
     * and every other hook a credential record is entitled to.
     */
    private static function __create_login_user(string $email, string $password): void
    {
        // Every field assigned explicitly - never mass assignment, so what this
        // writes is visible here rather than inferred from a request.
        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make($password);
        $login_user->is_activated = 1;
        $login_user->is_verified = 1;
        $login_user->save();

        self::__create_site_membership($login_user->id, $email);
    }

    /**
     * Give the new credential a SITE MEMBERSHIP.
     *
     * A login_users row on its own is not an account anyone can use. Sign-in
     * looks up the enabled User_Model rows for that identity, and with none it
     * sends the person straight back out again - reported as "your account is
     * inactive", which is a confusing way of saying "you belong to no site".
     * Creating the credential without this produced exactly that: a wizard that
     * cheerfully made an account you could not log into (2026-08-20).
     *
     * The site is the configured default when it exists, and otherwise the
     * lowest-numbered site there is - a fresh install has one, and guessing an
     * id that does not exist would recreate the same dead end.
     */
    private static function __create_site_membership(int $login_user_id, string $email): void
    {
        $site_id = (int) config('multi-tenant.default_site_id', 1);

        if (Site_Model::query()->whereKey($site_id)->count() === 0) {
            $first_site = Site_Model::query()->orderBy('id')->first();
            if ($first_site === null) {
                // No sites at all - nothing to belong to. The account still
                // exists; the application's own setup decides what happens next.
                return;
            }
            $site_id = (int) $first_site->id;
        }

        $user = new User_Model();
        $user->login_user_id = $login_user_id;
        $user->site_id = $site_id;
        $user->email = $email;
        $user->is_enabled = 1;
        $user->save();
    }

    /**
     * Turn on credential auto-fill FOR THIS HOST.
     *
     * The declaration written is the hostname, not a boolean. A boolean travels
     * with .env - copy that file to staging and auto-fill follows it there - while
     * a host only matches the machine it names. Same file elsewhere, no auto-fill,
     * with nothing to remember to switch off.
     *
     * @return string|null a note for the success screen, or null when nothing was written
     */
    private static function __enable_autofill(string $email, string $password): ?string
    {
        $host = Rsx::get_hostname();
        if ($host === '') {
            return null;
        }

        $written = Rsx_Env_Writer::set_many([
            'RSPADE_DEFAULT_EMAIL' => $email,
            'RSPADE_DEFAULT_PASSWORD' => $password,
            'RSPADE_DEBUG_DOMAIN_SUFFIX' => $host,
        ]);

        if (!$written) {
            return 'Could not write .env - set RSPADE_DEFAULT_EMAIL, RSPADE_DEFAULT_PASSWORD'
                . ' and RSPADE_DEBUG_DOMAIN_SUFFIX by hand to enable auto-fill.';
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    private static function __render_form(
        ?string $error = null,
        string $email = '',
        bool $autofill = false
    ): void {
        $token = bin2hex(random_bytes(16));
        setcookie(self::COOKIE_NAME, $token, [
            'expires' => time() + 1800,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $host = Rsx::get_hostname();

        $body = '';

        if ($error !== null) {
            $body .= '<div class="error">' . self::__e($error) . '</div>';
        }

        $body .= '<form method="post">
      <input type="hidden" name="rsx_token" value="' . self::__e($token) . '">

      <label for="rsx_email">Email address</label>
      <input id="rsx_email" type="email" name="rsx_email" autocomplete="username"
             value="' . self::__e($email) . '" required autofocus>

      <label for="rsx_password">Password</label>
      <input id="rsx_password" type="text" name="rsx_password"
             autocomplete="off" spellcheck="false" required
             minlength="' . self::MIN_PASSWORD_LENGTH . '">
      <div class="hint">At least ' . self::MIN_PASSWORD_LENGTH . ' characters.</div>

      <label class="check">
        <input type="checkbox" name="rsx_autofill" value="1"' . ($autofill ? ' checked' : '') . '>
        <span>Fill these credentials in automatically on <strong>' . self::__e($host) . '</strong></span>
      </label>
      <div class="warn">
        Convenient while developing, and it stores this password in plain text in
        your <code>.env</code> file. Leave it off if this host is reachable by
        anyone else.
      </div>

      <button type="submit" name="rsx_create_user" value="1">Create account</button>
    </form>';

        self::__render_page(
            'Create your first account',
            'This application has no users yet. Create the account you will sign in with.',
            $body
        );
    }

    private static function __render_success(
        string $email,
        string $password,
        bool $autofill,
        ?string $env_note
    ): void {
        $body = '<div class="cred">
      <div class="cred-label">Email</div>
      <div class="cred-value">' . self::__e($email) . '</div>
    </div>
    <div class="cred">
      <div class="cred-label">Password</div>
      <div class="cred-value">' . self::__e($password) . '</div>
    </div>';

        if ($autofill && $env_note === null) {
            $body .= '<p class="note">Auto-fill is on for <strong>' . self::__e(Rsx::get_hostname())
                . '</strong>. The login form will arrive already filled in. Turn it off by clearing'
                . ' <code>RSPADE_DEBUG_DOMAIN_SUFFIX</code> in <code>.env</code>.</p>';
        } elseif ($env_note !== null) {
            $body .= '<div class="error" style="margin-top:1.25rem">' . self::__e($env_note) . '</div>';
        } else {
            $body .= '<p class="note">Write this password down - it is not stored anywhere you can read it back.</p>';
        }

        $body .= '<form method="get" action="/"><button type="submit">Continue to login</button></form>';

        self::__render_page('Account created', 'You can sign in now.', $body);
        exit;
    }

    /**
     * The shared page shell - the same card, palette and dark-mode handling as the
     * APP_URL screen, so the setup flow reads as one thing. Self-contained by
     * necessity as much as taste: this renders mid-dispatch on an application
     * that may never have compiled an asset bundle.
     */
    private static function __render_page(string $title, string $subtitle, string $body): void
    {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');

        echo '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . self::__e($title) . ' - RSpade</title>
<style>
  :root { color-scheme: light dark; }
  body {
    margin: 0; min-height: 100vh; display: flex; align-items: center;
    justify-content: center; background: #f4f5f7; color: #1c2024;
    font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
  }
  .card {
    background: #fff; max-width: 34rem; width: calc(100% - 2rem);
    border: 1px solid #d7dbe0; border-radius: 6px; padding: 2rem 2.25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
  }
  h1 { margin: 0 0 .25rem; font-size: 1.35rem; font-weight: 600; }
  .sub { margin: 0 0 1.5rem; color: #5b6470; }
  .label { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em;
           color: #5b6470; margin-bottom: .35rem; }
  .url { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
         font-size: 1.05rem; background: #f0f2f5; border: 1px solid #dfe3e8;
         border-radius: 4px; padding: .7rem .85rem; word-break: break-all; }
  label { display: block; font-size: .78rem; text-transform: uppercase;
          letter-spacing: .04em; color: #5b6470; margin: 1.1rem 0 .35rem; }
  input[type=email], input[type=text] {
    width: 100%; box-sizing: border-box; padding: .6rem .75rem; font-size: .98rem;
    color: inherit; background: #fff; border: 1px solid #cfd5dc; border-radius: 4px;
  }
  input[type=email]:focus, input[type=text]:focus {
    outline: none; border-color: #1f6feb; box-shadow: 0 0 0 3px rgba(31,111,235,.15);
  }
  .hint { margin-top: .35rem; font-size: .82rem; color: #5b6470; }
  label.check { display: flex; align-items: flex-start; gap: .55rem; margin-top: 1.5rem;
                text-transform: none; letter-spacing: 0; font-size: .95rem; color: inherit; }
  label.check input { margin-top: .2rem; }
  .warn { margin-top: .45rem; font-size: .84rem; color: #8a6d1f;
          background: #fdf6e3; border: 1px solid #f0e2b6; border-radius: 4px;
          padding: .6rem .7rem; }
  .error { margin-bottom: 1.25rem; font-size: .9rem; color: #96232b;
           background: #fdecee; border: 1px solid #f3c2c7; border-radius: 4px;
           padding: .65rem .75rem; }
  button { margin-top: 1.5rem; width: 100%; padding: .7rem 1rem; font-size: .95rem;
           font-weight: 600; color: #fff; background: #1f6feb; border: 1px solid #1a5fd0;
           border-radius: 4px; cursor: pointer; }
  button:hover { background: #1a5fd0; }
  .cred { text-align: center; margin-bottom: 1.1rem; }
  .cred-label { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em;
                color: #5b6470; margin-bottom: .2rem; }
  .cred-value { font-size: 1.1rem; font-weight: 700; word-break: break-all; }
  .note { margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #e6e9ed;
          font-size: .87rem; color: #5b6470; }
  code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
         background: #f0f2f5; padding: .1rem .3rem; border-radius: 3px; }
  @media (prefers-color-scheme: dark) {
    body { background: #16181c; color: #e6e9ed; }
    .card { background: #1c1f24; border-color: #2c313a; box-shadow: none; }
    .sub, .label, .hint, .note, label, .cred-label { color: #9aa4b2; }
    .url, code { background: #22262d; border-color: #2c313a; }
    input[type=email], input[type=text] { background: #22262d; border-color: #39404a; }
    .note { border-top-color: #2c313a; }
    .warn { color: #e8cf8a; background: #2a2413; border-color: #4a3f1c; }
    .error { color: #f3b0b6; background: #2c1619; border-color: #5a2a30; }
  }
</style>
</head>
<body>
  <div class="card">
    <h1>' . self::__e($title) . '</h1>
    <p class="sub">' . self::__e($subtitle) . '</p>
    ' . $body . '
  </div>
</body>
</html>';

        exit;
    }

    private static function __e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
