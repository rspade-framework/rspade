<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * PRE-BOOT ENV SYMLINK GUARD
 *
 * `system/.env` is FRAMEWORK SPACE and is always a symlink to the project-root
 * `.env`, which is developer space. Anything else sitting at that path is a
 * broken distribution, not a configuration choice: Laravel boots from
 * `system/`, so a real file there SHADOWS the root `.env` and the application
 * silently reads settings nobody wrote. That is invisible - the app starts,
 * serves pages, and every value it was configured with is quietly ignored.
 *
 * The field failure this exists for (2026-08-20): a fresh clone has no
 * `system/.env` at all, because a blanket `.env` ignore rule kept the symlink
 * out of the distribution. `system/artisan` then "helpfully" seeded a real file
 * from `.env.dist`, so a container whose entrypoint had correctly written
 * APP_URL into the root `.env` served every request against the template
 * instead - reporting its own container id as the application hostname.
 *
 * Required by BOTH entrypoints (`system/artisan` and `system/public/index.php`)
 * before anything reads configuration. It must therefore run with no autoloader,
 * no framework, and no config: plain filesystem calls only.
 *
 * The healthy path is two cheap syscalls (is_link + readlink) and no writes.
 */

(static function (): void {
    $system_dir = dirname(__DIR__);
    $system_env = $system_dir . '/.env';
    $link_target = '../.env';

    // Healthy: already the symlink we want. The overwhelmingly common path.
    if (is_link($system_env) && readlink($system_env) === $link_target) {
        return;
    }

    // Anything else at this path is wrong by definition - a real file, a
    // directory, or a symlink pointing somewhere else. Remove it and install the
    // link. There is deliberately no merge or backup step: the root .env is the
    // only .env an RSpade project has, so nothing here can be the sole copy of
    // anybody's configuration.
    if (is_link($system_env) || file_exists($system_env)) {
        @unlink($system_env);
    }

    if (@symlink($link_target, $system_env)) {
        return;
    }

    // Could not repair. Fail LOUD rather than boot against a shadowed config -
    // the whole point of this guard is that silently reading the wrong file is
    // the worst available outcome. Plain text on both channels, because neither
    // the exception handler nor the response layer exists yet.
    $message = "RSpade: cannot repair the system/.env symlink.\n\n"
        . "  system/.env must be a symlink to ../.env (the project-root .env).\n"
        . "  It is not, and it could not be replaced - most likely the filesystem\n"
        . "  is read-only or the process lacks permission.\n\n"
        . "  Fix it by hand:\n"
        . "      rm -f " . $system_env . "\n"
        . "      ln -s ../.env " . $system_env . "\n";

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }

    exit(1);
})();
