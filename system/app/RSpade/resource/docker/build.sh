#!/bin/bash
#
# Build the RSpade container image and register it under the names the
# docker-compose.yml expects, so `docker compose up` finds it locally instead of
# trying to pull an image that may not be published yet.
#
# Callable from anywhere - it works in its OWN directory, not yours:
#
#     bash build.sh                  # the dev image (default)
#     bash build.sh prod             # the production image
#     bash build.sh both             # both
#     bash build.sh dev --push       # build, then push to the registry
#     bash build.sh --claude         # ...with Claude Code baked into the image
#
# Run it with `bash build.sh`, never as a bare path: the exec bit does not
# survive every checkout, and a bare path then dies with "Permission denied".
#
set -u

# -----------------------------------------------------------------------------
# Work in the script's own directory.
#
# This is also the BUILD CONTEXT, and its scope is deliberate: this directory
# holds only the Dockerfile and a handful of small config files. Building from
# the project root instead would hand the Docker daemon system/vendor and
# system/node_modules - hundreds of megabytes - on every single build.
# -----------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)" || exit 1
cd "$SCRIPT_DIR" || { echo "ERROR: cannot enter $SCRIPT_DIR" >&2; exit 1; }

REGISTRY="rspade"

say() { echo "[build] $*"; }
die() { echo "[build] ERROR: $*" >&2; exit 1; }

# -----------------------------------------------------------------------------
# Arguments
# -----------------------------------------------------------------------------
TARGETS=""
PUSH=false
INSTALL_CLAUDE=false

for arg in "$@"; do
    case "$arg" in
        dev)          TARGETS="$TARGETS dev" ;;
        prod)         TARGETS="$TARGETS prod" ;;
        both|all)     TARGETS="dev prod" ;;
        --push)       PUSH=true ;;
        --claude)     INSTALL_CLAUDE=true ;;
        -h|--help)
            # Print the header comment block and stop at the first line of
            # actual code, so the usage text cannot drift out of range.
            awk 'NR>1 { if (/^#/) { sub(/^# ?/, ""); print } else { exit } }' "${BASH_SOURCE[0]}"
            exit 0
            ;;
        *)
            die "Unknown argument '$arg'. Expected: dev | prod | both | --push | --claude"
            ;;
    esac
done

[ -n "$TARGETS" ] || TARGETS="dev"

# -----------------------------------------------------------------------------
# Preconditions - fail with a diagnosis, not a stack trace
# -----------------------------------------------------------------------------
command -v docker >/dev/null 2>&1 \
    || die "docker is not installed (or not on PATH). Install Docker, then run this again."

docker info >/dev/null 2>&1 \
    || die "the Docker daemon is not reachable. Is Docker running, and does this user have permission to use it?"

[ -f "$SCRIPT_DIR/Dockerfile" ] \
    || die "no Dockerfile in $SCRIPT_DIR - this script must live beside it."

# -----------------------------------------------------------------------------
# Version tag, when the tree carries a release marker.
#
# Best effort: a monorepo checkout has no marker, and that is not an error - the
# image simply gets :latest and nothing else. Parsed with grep/sed rather than a
# JSON tool so this has no dependency beyond coreutils.
# -----------------------------------------------------------------------------
RELEASE_TAG=""
MARKER="$SCRIPT_DIR/../../../../.rspade-release.json"
if [ -f "$MARKER" ]; then
    RELEASE_TAG="$(grep -o '"release_id"[[:space:]]*:[[:space:]]*"[^"]*"' "$MARKER" 2>/dev/null \
        | sed 's/.*"\([^"]*\)"$/\1/' | cut -c1-12)"
fi

# -----------------------------------------------------------------------------
# Build
# -----------------------------------------------------------------------------
BUILT=""

for target in $TARGETS; do
    image="rspade-server-${target}"
    registry_name="${REGISTRY}/${image}"

    say "Building the ${target} image (this takes a while)..."

    # The registry-qualified :latest is the PRIMARY tag, because that is the name
    # docker-compose.yml declares. Tagging it here is what "registers" the image
    # locally: compose then finds it and does not attempt a pull.
    build_args="--target $target -t ${registry_name}:latest -t ${image}:latest"

    # Bake Claude Code in. Without this the launcher is still present and
    # installs it on first use - the flag decides whether that cost is paid at
    # build time or the first time somebody types `claude`.
    if [ "$INSTALL_CLAUDE" = true ]; then
        build_args="$build_args --build-arg INSTALL_CLAUDE=true"
    fi

    if [ -n "$RELEASE_TAG" ]; then
        build_args="$build_args -t ${registry_name}:${RELEASE_TAG}"
    fi

    # Unquoted on purpose - these are separate argv tokens, not one string.
    # shellcheck disable=SC2086
    docker build $build_args . \
        || die "the ${target} image failed to build (see the output above)."

    BUILT="$BUILT $target"

    if [ "$PUSH" = true ]; then
        say "Pushing ${registry_name}:latest ..."
        docker push "${registry_name}:latest" \
            || die "push failed. Are you logged in? (docker login ghcr.io)"

        if [ -n "$RELEASE_TAG" ]; then
            docker push "${registry_name}:${RELEASE_TAG}" \
                || die "push of the ${RELEASE_TAG} tag failed."
        fi
    fi
done

# -----------------------------------------------------------------------------
# Report
# -----------------------------------------------------------------------------
echo ""
say "Built:$BUILT"
[ "$INSTALL_CLAUDE" = true ] && say "Claude Code: installed in the image."

for target in $BUILT; do
    echo ""
    docker images --format '  {{.Repository}}:{{.Tag}}   {{.Size}}' \
        "${REGISTRY}/rspade-${target}" 2>/dev/null
done

echo ""
if [ "$PUSH" != true ]; then
    say "Registered locally. 'docker compose up' will now use these images"
    say "  without pulling. Add --push to publish them to ${REGISTRY}."
fi
echo ""
