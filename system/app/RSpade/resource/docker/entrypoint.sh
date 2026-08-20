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
# 4. First-user credentials (PRODUCTION ONLY)
# -----------------------------------------------------------------------------
# DEVELOPMENT CREATES NO USER HERE, and that is the whole point: the first-run
# setup screen asks for the account in the browser, on first visit. Leaving
# RSPADE_DEFAULT_EMAIL / RSPADE_DEFAULT_PASSWORD blank is what lets it - the
# create_admin_test_user migration deliberately creates nothing when they are
# empty, and its sibling then skips the site profile that would belong to it.
#
# This step used to GENERATE a credential in development so that "clone it and
# run it" held. That predated the setup screen and, once the screen existed,
# actively defeated it: the generated admin@localhost account was already in
# login_users by the time anybody opened a browser, so the screen had nothing to
# offer and the developer logged in as a user they never chose (field report,
# 2026-08-20). Blank is not a gap to fill here. It is the signal.
#
# PRODUCTION has no such screen - the wizard is development-only by design - so
# the credentials remain REQUIRED there, and refusing early beats failing inside
# the migration with the database half-built.
if [ "$TARGET" = "prod" ]; then
    if [ -z "$(env_value RSPADE_DEFAULT_EMAIL)" ] || [ -z "$(env_value RSPADE_DEFAULT_PASSWORD)" ]; then
        die "RSPADE_DEFAULT_EMAIL and RSPADE_DEFAULT_PASSWORD must be set in .env before this container can migrate.

   They are the credentials of the first account you will sign in with. There is
   no default and no setup screen in production - a shared, published password is
   not a starting point. See .env.README."
    fi
fi

# -----------------------------------------------------------------------------
# 4b. Environment updates
# -----------------------------------------------------------------------------
# system/bin/environment_updates/*.sh make the surrounding environment correct:
# they relocate volatile storage out of system/ to <project>/storage, install the
# git pre-commit guard, wire the Claude Code status line, and so on.
#
# WHY THE CONTAINER HAS TO RUN THEM. Their triggers are a framework update and a
# successful manifest build - both of which assume an environment that has
# already been used. A freshly cloned project started for the first time has had
# neither, so it ran with none of these applied: uploads and logs landing inside
# system/ instead of the project's own storage/, no status line, no commit guard.
# The container start is the one moment guaranteed to happen before anything else
# in a new project, which makes it the right place to ask.
#
# BEFORE SUPERVISOR, and before the storage preparation below, on purpose. The
# storage relocation MOVES the tree every service is about to open files in;
# doing that underneath running services is how you get processes holding
# deleted paths. Ordering it here also means the ownership pass below applies to
# the final location rather than one we are about to abandon.
#
# The scripts are idempotent and silent when already applied (their published
# contract), so on every boot after the first this prints nothing. Failures are
# NON-FATAL: an environment that could not be improved still runs.
if [ -f "$APP_DIR/system/bin/post-update.sh" ]; then
    PROJECT_ROOT="$APP_DIR" SYSTEM_DIR="$APP_DIR/system" \
        bash "$APP_DIR/system/bin/post-update.sh" \
        || warn "environment updates reported a failure (non-fatal)"
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

    # And the database, for the same reason and with more force behind it.
    #
    # /var/lib/mysql is a bind mount into your project, so the uid mysqld runs as
    # is the uid that ends up owning your database files on your own disk. Left
    # as the packaged `mysql` user that is uid 999, which you are not: the files
    # land in your project owned by somebody who does not exist on your machine,
    # and moving or deleting your own project needs sudo.
    #
    # Docker cannot remap ownership across a bind mount (no idmap for binds, no
    # Podman's :U), so the only lever is which uid the writer runs as - and this
    # is it. The datadir chown in the MySQL step below follows the same PUID.
    #
    # The config file, NOT a command-line flag: mysqld reads its defaults file
    # first and keeps the FIRST `user` it sees, so a `--user=` on the command
    # line loses to this line and is silently ignored (with a warning buried in
    # the log). One place decides the identity.
    mysqld_cnf="/etc/mysql/mysql.conf.d/mysqld.cnf"
    if [ -f "$mysqld_cnf" ]; then
        sed -i "s/^user[[:space:]]*=.*/user = ${APP_USER}/" "$mysqld_cnf" \
            || warn "could not repoint mysqld at ${APP_USER}"
    fi

    # Hand over the things the application writes. Everything else in the project
    # is yours already - it came from your checkout.
    #
    # storage/mysql_data is EXCLUDED, and must stay excluded: it is the database's
    # bind-mounted data directory, mysqld requires it to be owned by mysql, and
    # handing it to the developer's uid stops the database from starting. It is
    # not a thing you edit by hand anyway.
    find storage -mindepth 1 -maxdepth 1 ! -name mysql_data \
        -exec chown -R "${APP_USER}:${APP_GROUP}" {} + 2>/dev/null \
        || warn "could not chown storage/ to ${APP_USER}"
    chown "${APP_USER}:${APP_GROUP}" storage 2>/dev/null || true
    [ -f "$APP_DIR/.env" ] && chown "${APP_USER}:${APP_GROUP}" "$APP_DIR/.env" 2>/dev/null

    say "Running the application as ${APP_USER} (uid ${PUID}, gid ${APP_GID})."

elif [ "$TARGET" = "prod" ]; then
    # Production has no bind-mounted developer tree to fight, so it runs as
    # www-data unconditionally and owns what it writes.
    find storage -mindepth 1 -maxdepth 1 ! -name mysql_data \
        -exec chown -R www-data:www-data {} + 2>/dev/null \
        || warn "Could not chown storage/ to www-data"
    chown www-data:www-data storage 2>/dev/null || true
fi

# -----------------------------------------------------------------------------
# 6. MySQL data directory (development target only)
# -----------------------------------------------------------------------------
# The identity mysqld runs as (see the PUID step above: it is YOUR uid when PUID
# is set, so the database files in your project belong to you). Everything mysqld
# opens outside the data directory has to follow it - the runtime socket dir, the
# error log, the secure-file and keyring dirs. Miss one and mysqld aborts at
# startup on that path alone, having said nothing about the others.
mysql_runtime_owner="mysql"
mysql_runtime_group="mysql"
if [ -n "${PUID:-}" ]; then
    mysql_runtime_owner="${APP_USER}"
    mysql_runtime_group="${APP_GROUP}"
fi

mkdir -p /var/run/mysqld 2>/dev/null || true
for mysql_path in /var/run/mysqld /var/log/mysql /var/lib/mysql-files /var/lib/mysql-keyring; do
    [ -e "$mysql_path" ] || continue
    chown -R "${mysql_runtime_owner}:${mysql_runtime_group}" "$mysql_path" 2>/dev/null || true
done

# /var/lib/mysql is a BIND MOUNT to storage/mysql_data in your project, so you
# can see the database, back it up by copying a directory, and keep it across a
# `docker compose down -v` that would have destroyed a named volume.
#
# The cost of that choice is this block. A named volume arrives pre-populated
# (Docker copies the image's directory content in the first time it is mounted
# empty); a bind mount arrives EXACTLY as empty as it is on the host, and mysqld
# will not start against an uninitialised data directory. So the pristine tree
# from image build time is unpacked here on the first boot.
#
# The test is the `mysql` system schema, not "is the directory empty" - the host
# directory routinely holds a stray .DS_Store or an editor's dotfile, and
# treating that as an initialised database would fail much later and much worse.
if [ "$TARGET" = "dev" ]; then
    if [ ! -d /var/lib/mysql/mysql ]; then
        template="/opt/rspade/mysql-datadir-template.tgz"

        if [ ! -f "$template" ]; then
            die "The MySQL data directory at /var/lib/mysql is not initialised, and
   the image's template ($template) is missing.

   This image was not built correctly. Rebuild it:
       bash system/app/RSpade/resource/docker/build.sh"
        fi

        say "First run: initialising the database in storage/mysql_data..."
        mkdir -p /var/lib/mysql
        tar -xzf "$template" -C /var/lib/mysql \
            || die "Could not unpack the MySQL data directory template."
    fi

    # WHO OWNS THE DATA DIRECTORY. mysqld here runs as root (see
    # supervisor/conf.d/mysql.conf), so it can read and write the datadir
    # whoever owns it - which means ownership is free to serve the person
    # OUTSIDE the container instead.
    #
    # That matters because this is a bind mount: the files are in your project,
    # and files owned by a uid you do not have are files you need sudo to move,
    # copy or delete. So when PUID says who you are, the database belongs to you
    # too - the same promise PUID already makes for everything else the
    # application writes. Without PUID it stays with mysql, which is the
    # historic owner and the right default on macOS and Windows, where Docker
    # Desktop presents every file as yours regardless.
    #
    # Docker has no uid-remapping for bind mounts (no idmap, no Podman's :U), so
    # a chown is the whole mechanism available. It is CHECKED before it is
    # applied - a recursive chown across a live database on every boot would be
    # pointless work.
    datadir_owner="$mysql_runtime_owner"
    datadir_group="$mysql_runtime_group"

    want_uid="$(id -u "$datadir_owner" 2>/dev/null || echo -1)"
    if [ "$(stat -c %u /var/lib/mysql 2>/dev/null || echo -1)" != "$want_uid" ] \
        || [ "$(stat -c %u /var/lib/mysql/mysql 2>/dev/null || echo -1)" != "$want_uid" ]; then
        say "Handing the database data directory to ${datadir_owner}..."
        chown -R "${datadir_owner}:${datadir_group}" /var/lib/mysql 2>/dev/null \
            || warn "could not chown /var/lib/mysql to ${datadir_owner}"
    fi
fi

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
        warn "Run them yourself to see the full error:  php system/artisan migrate"
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
