#!/bin/bash
#
# RSpade container entrypoint.
#
# Brings a mounted project from "freshly cloned" to "serving", then hands the
# container over to supervisor. Every step is idempotent: a second start finds
# its work already done and says nothing.
#
# ORDERING NOTE, because it is not the obvious arrangement. supervisord is
# started in the BACKGROUND and the bootstrap runs after it, rather than the
# reverse. `migrate` in development mode snapshots the database by stopping and
# restarting MySQL through supervisorctl - so supervisor has to already be up
# when migrations run, or the snapshot has nothing to talk to.
#
set -u

APP_DIR=/var/www/html
TARGET="${RSPADE_CONTAINER_TARGET:-dev}"

say()  { echo "[rspade] $*"; }
warn() { echo "[rspade] WARNING: $*" >&2; }
die()  { echo "[rspade] ERROR: $*" >&2; exit 1; }

# -----------------------------------------------------------------------------
# 0. Sanity: is a project actually mounted here?
# -----------------------------------------------------------------------------
if [ ! -f "$APP_DIR/system/artisan" ]; then
    die "No RSpade project found at $APP_DIR (system/artisan is missing).

  This image runs YOUR project - it does not contain one. Mount a checkout:

      docker run -v \"\$(pwd)\":$APP_DIR -p 8080:80 <image>

  If you have not created a project yet, start from the RSpade starter
  repository: https://github.com/rspade-framework/rspade"
fi

cd "$APP_DIR" || die "Cannot enter $APP_DIR"

# -----------------------------------------------------------------------------
# 1. .env - create it from the template on first boot
# -----------------------------------------------------------------------------
if [ ! -f "$APP_DIR/.env" ]; then
    [ -f "$APP_DIR/.env.dist" ] || die ".env is missing and there is no .env.dist to create it from."
    cp "$APP_DIR/.env.dist" "$APP_DIR/.env" || die "Could not create .env"
    say "Created .env from .env.dist"
fi

# Read a key's current value from .env (empty when absent or blank).
#
# Takes the last NON-EMPTY definition rather than the first line that matches.
# A .env can legitimately end up with a key declared twice - a blank one from the
# template plus a real one appended later - and reading the blank one makes a
# configured value look unset.
env_value() {
    sed -n "s/^$1=//p" "$APP_DIR/.env" | grep -v '^[[:space:]]*$' | tail -n1
}

# Set a key in .env, replacing the line if present and appending if not.
#
# Pure bash on purpose. sed would need a delimiter that cannot appear in the
# value (an APP_URL contains slashes, a generated password can contain almost
# anything), and reaching for python here would add a runtime dependency that is
# only present in this image transitively.
#
# The file is rewritten through `cat >`, not `mv`, so that .env keeps its
# identity - it may be the target of a symlink, and replacing the inode would
# break the link rather than update the file.
env_set() {
    local key="$1" value="$2" tmp line found=0
    tmp="$(mktemp)"

    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            "$key="*)
                printf '%s=%s\n' "$key" "$value" >> "$tmp"
                found=1
                ;;
            *)
                printf '%s\n' "$line" >> "$tmp"
                ;;
        esac
    done < "$APP_DIR/.env"

    [ "$found" -eq 1 ] || printf '%s=%s\n' "$key" "$value" >> "$tmp"

    cat "$tmp" > "$APP_DIR/.env"
    rm -f "$tmp"
}

# -----------------------------------------------------------------------------
# 2. APP_KEY - a random key, generated here rather than by artisan
# -----------------------------------------------------------------------------
# An application key is 32 random bytes, base64-encoded. That is all it is - so
# it is generated in ten characters of shell, BEFORE anything else runs.
#
# The alternative was `artisan key:generate`, and it was a trap: writing a random
# string into a file looks local, but artisan boots the entire framework to do
# it, and that boot wants Redis, the database, and a provisioned schema. Which
# means the key could only be made AFTER the services were up - while two of
# those services (rsx-lockd, the realtime relay) refuse to start WITHOUT a key.
# A circle with no entry point, and on a fresh install it failed exactly there.
#
# Doing it in shell breaks the circle: by the time supervisor starts anything,
# the key exists.
if [ -z "$(env_value APP_KEY)" ]; then
    new_key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    env_set APP_KEY "$new_key"
    say "Generated APP_KEY"
fi

# -----------------------------------------------------------------------------
# 3. APP_URL - the container cannot discover its own published port
# -----------------------------------------------------------------------------
# Docker does not tell a container which host port maps to it, so APP_URL cannot
# be inferred - it must be declared. RSPADE_APP_URL is the declaration; without
# it we leave whatever .env already says alone.
if [ -n "${RSPADE_APP_URL:-}" ]; then
    if [ "$(env_value APP_URL)" != "$RSPADE_APP_URL" ]; then
        env_set APP_URL "$RSPADE_APP_URL"
        say "Set APP_URL=$RSPADE_APP_URL"
    fi
fi

APP_URL_NOW="$(env_value APP_URL)"
case "$APP_URL_NOW" in
    http://*|https://*) : ;;
    *) warn "APP_URL is '$APP_URL_NOW', which is not an http(s) URL. Pass -e RSPADE_APP_URL=http://localhost:8080 (matching your published port), or edit .env." ;;
esac

# -----------------------------------------------------------------------------
# 4. First-user credentials
# -----------------------------------------------------------------------------
# The framework refuses to create the first user while these are blank, and
# ships no default on purpose. In DEVELOPMENT we generate a password so that
# "clone it and run it" holds, and print it once - the developer can change it
# in .env any time before the first migrate. In PRODUCTION we refuse: inventing
# a credential for a production system is not ours to do.
if [ -z "$(env_value RSPADE_DEFAULT_EMAIL)" ] || [ -z "$(env_value RSPADE_DEFAULT_PASSWORD)" ]; then
    if [ "$TARGET" = "prod" ]; then
        die "RSPADE_DEFAULT_EMAIL and RSPADE_DEFAULT_PASSWORD must be set in .env before this container can migrate. See .env.README."
    fi

    generated_email="${RSPADE_DEFAULT_EMAIL:-admin@localhost}"
    generated_password="$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | head -c 20)"

    env_set RSPADE_DEFAULT_EMAIL "$generated_email"
    env_set RSPADE_DEFAULT_PASSWORD "$generated_password"

    echo ""
    say "=============================================================="
    say " Generated the first-user credentials (development target)"
    say ""
    say "   email:    $generated_email"
    say "   password: $generated_password"
    say ""
    say " They are stored in .env as RSPADE_DEFAULT_EMAIL and"
    say " RSPADE_DEFAULT_PASSWORD. Change them there before the first"
    say " migrate if you want something else. See .env.README."
    say "=============================================================="
    echo ""
fi

# -----------------------------------------------------------------------------
# 5. Writable storage
# -----------------------------------------------------------------------------
mkdir -p storage/rsx-build storage/rsx-tmp storage/flock storage/rsx-framework storage/logs 2>/dev/null || true

# -----------------------------------------------------------------------------
# 5b. PUID / PGID - run the application as YOUR user
# -----------------------------------------------------------------------------
# THE PROBLEM THIS SOLVES, which only Linux hosts have. Your project is a bind
# mount, and by default the application runs as root inside the container - so
# every file it writes (.env, compiled bundles, logs, uploads) comes out owned by
# root ON YOUR MACHINE, and you cannot edit your own project without sudo. macOS
# and Windows never see it: Docker Desktop's filesystem layer presents everything
# as yours regardless.
#
# Set PUID and PGID to your own ids and the application runs as you instead:
#
#     id -u    # -> PUID
#     id -g    # -> PGID
#
# UNSET IS THE DEFAULT and changes nothing. Mac and Windows users never need to
# think about this, and nobody gets a surprise ownership change for upgrading.
#
# nginx runs as root here, so it reaches the FPM socket whoever owns it - the one
# thing that usually makes this awkward is not a problem.
if [ -n "${PUID:-}" ]; then
    APP_GID="${PGID:-$PUID}"
    APP_USER="rspade"

    # Reuse an existing group/user with those ids rather than colliding with it -
    # uid 33 is already www-data, and creating a second name for one id is how
    # ownership gets confusing later.
    existing_group="$(getent group "$APP_GID" 2>/dev/null | cut -d: -f1)"
    if [ -n "$existing_group" ]; then
        APP_GROUP="$existing_group"
    else
        APP_GROUP="$APP_USER"
        groupadd -g "$APP_GID" "$APP_GROUP" 2>/dev/null \
            || warn "could not create group $APP_GID"
    fi

    existing_user="$(getent passwd "$PUID" 2>/dev/null | cut -d: -f1)"
    if [ -n "$existing_user" ]; then
        APP_USER="$existing_user"
    else
        useradd -u "$PUID" -g "$APP_GID" -M -s /usr/sbin/nologin "$APP_USER" 2>/dev/null \
            || warn "could not create user $PUID"
    fi

    # Point both FPM pools at that identity.
    for pool in /etc/php/8.4/fpm/pool.d/www.conf /etc/php/8.4/fpm/pool.d/ajax.conf; do
        [ -f "$pool" ] || continue
        sed -i \
            -e "s/^user = .*/user = ${APP_USER}/" \
            -e "s/^group = .*/group = ${APP_GROUP}/" \
            -e "s/^listen.owner = .*/listen.owner = ${APP_USER}/" \
            -e "s/^listen.group = .*/listen.group = ${APP_GROUP}/" \
            "$pool" || warn "could not repoint $(basename "$pool")"
    done

    # Hand over the things the application writes. Everything else in the project
    # is yours already - it came from your checkout.
    chown -R "${APP_USER}:${APP_GROUP}" storage 2>/dev/null \
        || warn "could not chown storage/ to ${APP_USER}"
    [ -f "$APP_DIR/.env" ] && chown "${APP_USER}:${APP_GROUP}" "$APP_DIR/.env" 2>/dev/null

    say "Running the application as ${APP_USER} (uid ${PUID}, gid ${APP_GID})."

elif [ "$TARGET" = "prod" ]; then
    # Production has no bind-mounted developer tree to fight, so it runs as
    # www-data unconditionally and owns what it writes.
    chown -R www-data:www-data storage 2>/dev/null || warn "Could not chown storage/ to www-data"
fi

# -----------------------------------------------------------------------------
# 6. MySQL runtime directory (development target only)
# -----------------------------------------------------------------------------
# The DATA directory needs nothing here. Installing mysql-server initialises
# /var/lib/mysql at build time, and Docker copies an image directory into a named
# volume the first time that volume is mounted empty - so a fresh volume arrives
# already initialised. (Whether the databases exist is a separate question,
# answered after MySQL is actually listening - see below.)
mkdir -p /var/run/mysqld 2>/dev/null || true
chown mysql:mysql /var/run/mysqld 2>/dev/null || true

# -----------------------------------------------------------------------------
# 7. Start supervisor in the background
# -----------------------------------------------------------------------------
say "Starting services..."
supervisord -c /etc/supervisor/supervisord.conf &
SUPERVISOR_PID=$!

# Forward a container stop to supervisor rather than dying and orphaning it.
trap 'kill -TERM "$SUPERVISOR_PID" 2>/dev/null; wait "$SUPERVISOR_PID"' TERM INT

# -----------------------------------------------------------------------------
# 8. Wait for Redis
# -----------------------------------------------------------------------------
# Every artisan command boots the framework, and the framework reaches for its
# cache while booting - so nothing that runs artisan may happen before this.
say "Waiting for Redis..."
until (echo > /dev/tcp/127.0.0.1/6379) >/dev/null 2>&1; do
    sleep 1
done

# -----------------------------------------------------------------------------
# 9. Wait for the database to answer
# -----------------------------------------------------------------------------
# Deliberately unbounded. A database that is slow to come up is normal - it is
# replaying a log or warming a large buffer pool - and a deadline here would
# convert "wait longer" into a failed start. The container is stoppable
# throughout, and every attempt is visible.
db_host="$(env_value DB_HOST)";     db_host="${db_host:-127.0.0.1}"
db_port="$(env_value DB_PORT)";     db_port="${db_port:-3306}"

say "Waiting for the database at ${db_host}:${db_port}..."
attempt=0
until (echo > "/dev/tcp/${db_host}/${db_port}") >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ $((attempt % 30)) -eq 0 ]; then
        say "  still waiting for ${db_host}:${db_port} (${attempt}s)"
    fi
    sleep 1
done
say "Database is accepting connections."

# -----------------------------------------------------------------------------
# 9b. Provision the databases (development target, first boot only)
# -----------------------------------------------------------------------------
if [ "$TARGET" = "dev" ]; then
    # The datadir being present says nothing about whether OUR databases exist -
    # a fresh volume carries the server's own system schema and nothing else. So
    # the question is asked of the server directly, which is both the honest
    # signal and naturally idempotent.
    until mysql -uroot -e "SELECT 1" >/dev/null 2>&1; do
        sleep 1
    done

    if ! mysql -uroot -e "USE rspade" >/dev/null 2>&1; then
        say "Provisioning databases..."
        mysql -uroot < /opt/rspade/provision.sql \
            || die "Database provisioning failed. See mysql/provision.sql in the image."
        say "Databases provisioned."
    fi
fi

# -----------------------------------------------------------------------------
# 10. Migrations
# -----------------------------------------------------------------------------
# DEVELOPMENT migrates automatically: a freshly cloned project should just work.
#
# PRODUCTION DOES NOT. Schema changes are a deploy decision, not a side effect of
# a container restart - and with more than one replica, several containers
# starting at once would race each other through the same migration. Run it
# yourself, once, as a deliberate step:
#
#     docker exec <container> php system/artisan migrate
#
if [ "$TARGET" = "dev" ]; then
    say "Running migrations..."
    if ! php system/artisan migrate --force; then
        warn "Migrations did not complete. The application may not work until this is resolved."
        warn "A common first-run cause: RSPADE_DEFAULT_EMAIL / RSPADE_DEFAULT_PASSWORD are blank in .env (see .env.README)."
    fi
else
    if php system/artisan migrate:pending 2>/dev/null | grep -qv "No pending migrations"; then
        say "NOTE: there are pending migrations. Production does not migrate automatically:"
        say "      php system/artisan migrate"
    fi
fi

# -----------------------------------------------------------------------------
# 11. The application's own container hook
# -----------------------------------------------------------------------------
# A documented extension point: rsx/resource/docker/configure.sh runs as root at
# container startup, before the application serves traffic. Invoked through bash
# rather than executed directly - the exec bit does not survive every checkout.
if [ -f "$APP_DIR/rsx/resource/docker/configure.sh" ]; then
    say "Running the application's configure.sh..."
    bash "$APP_DIR/rsx/resource/docker/configure.sh" \
        || warn "configure.sh exited non-zero"
fi

# -----------------------------------------------------------------------------
# 12. Ready
# -----------------------------------------------------------------------------
echo ""
say "RSpade is up. ${APP_URL_NOW:-(APP_URL unset)}"
echo ""

# -----------------------------------------------------------------------------
# 13. Hand over
# -----------------------------------------------------------------------------
# With a COMMAND passed to the container, run it now that the application is
# actually serving, and take the container down when it finishes. That is what
# makes this work:
#
#     docker compose run --service-ports --rm app bash
#
# - boot in the foreground, watch the setup happen, land at a prompt, and the
# container stops when the shell exits. It is also just the conventional
# entrypoint contract: an image that ignores its own CMD cannot be composed with
# anything.
#
# With no command - the ordinary `docker compose up` - wait on supervisor and
# keep serving, which is what a development server should do: outlive the
# terminal that started it.
if [ "$#" -gt 0 ]; then
    "$@"
    status=$?

    say "Command finished (exit ${status}). Stopping services..."
    kill -TERM "$SUPERVISOR_PID" 2>/dev/null
    wait "$SUPERVISOR_PID" 2>/dev/null

    exit "$status"
fi

wait "$SUPERVISOR_PID"
