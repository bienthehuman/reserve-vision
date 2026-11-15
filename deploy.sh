#!/usr/bin/env bash
# Deploy ReserveVision on a single Docker host (DEPLOY.md).
#
#   ./deploy.sh v0.2.0     fetch tags, check out v0.2.0, then deploy it
#   ./deploy.sh --current  deploy the code that is checked out now (CI uses this)
#
# Steps: check out, build, start db + storage, migrate as `migrator`, start the
# stack, `php artisan optimize`, `php artisan app:doctor`, GET /up. Any failing
# step stops the deploy and prints the rollback command.
#
# Environment:
#   ENV_FILE            default .env.production
#   COMPOSE_FILE        default compose.prod.yaml (Compose reads it as well)
#   DEPLOY_HEALTH_URL   default https://<DOMAIN>/up, resolved to this host
set -Eeuo pipefail

cd "$(dirname "$0")"

ENV_FILE="${ENV_FILE:-.env.production}"
export COMPOSE_FILE="${COMPOSE_FILE:-compose.prod.yaml}"

current_step="start"
previous_ref=""
target=""

step() {
    current_step="$*"
    printf '\n\033[1;35m==> %s\033[0m\n' "$*"
}

die() {
    printf '\033[1;31mError:\033[0m %s\n' "$*" >&2
    exit 1
}

on_error() {
    local status=$?
    printf '\n\033[1;31mDeploy of %s FAILED at step: %s (exit %s)\033[0m\n' "${target:-?}" "$current_step" "$status" >&2
    if [[ -n "$previous_ref" ]]; then
        printf 'Roll back to the previous release with:\n\n    ./deploy.sh %s\n\n' "$previous_ref" >&2
    else
        printf 'No previous tag is known on this host; redeploy the last good tag with ./deploy.sh <tag>.\n' >&2
    fi
    printf 'Migrations that already ran are not reverted; see DEPLOY.md, "Rollback".\n' >&2
    exit "$status"
}
trap on_error ERR

compose() { docker compose --env-file "$ENV_FILE" "$@"; }

env_value() {
    # The value of KEY in the env file, without surrounding quotes.
    local value
    value="$({ grep -E "^$1=" "$ENV_FILE" || true; } | tail -n1 | cut -d= -f2-)"
    value="${value%\"}"
    value="${value#\"}"
    printf '%s' "$value"
}

usage() {
    sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'
    exit 2
}

# Everything runs inside main(), which bash parses completely before running it:
# checking out another tag replaces this file on disk mid-deploy.
main() {
    local mode
    [[ $# -eq 1 ]] || usage
    case "$1" in
        -h | --help) usage ;;
        --current) mode=current ;;
        -*) usage ;;
        *) mode=tag ;;
    esac

    step "Preflight"
    for tool in docker git curl; do
        command -v "$tool" >/dev/null || die "$tool is not installed"
    done
    docker compose version >/dev/null || die "Docker Compose v2 is not available"
    [[ -f "$ENV_FILE" ]] || die "$ENV_FILE is missing: cp .env.production.example $ENV_FILE and fill it in (DEPLOY.md)"
    for key in DOMAIN APP_KEY APP_URL DB_PASSWORD DB_MIGRATOR_PASSWORD DB_ROOT_PASSWORD \
        MINIO_ROOT_USER MINIO_ROOT_PASSWORD AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY MAIL_FROM_ADDRESS; do
        [[ -n "$(env_value "$key")" ]] || die "$key is empty in $ENV_FILE"
    done
    previous_ref="$(git describe --tags --exact-match HEAD 2>/dev/null || true)"
    echo "Currently checked out: ${previous_ref:-$(git rev-parse --short HEAD) (no tag)}"

    if [[ "$mode" == tag ]]; then
        target="$1"
        step "Checking out $target"
        if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
            die "the working tree has local changes; a deploy must match a tag exactly"
        fi
        git fetch --tags --force --quiet origin
        git rev-parse --verify --quiet "refs/tags/$target" >/dev/null || die "tag $target does not exist"
        git -c advice.detachedHead=false checkout --quiet --detach "refs/tags/$target"
        export IMAGE_TAG="$target"
    else
        target="$(git describe --tags --always --dirty)"
        export IMAGE_TAG="${IMAGE_TAG:-current}"
    fi
    echo "Deploying $target as image reservevision:$IMAGE_TAG"

    step "Building the image"
    compose build --pull

    step "Starting the database and storage"
    compose up -d --wait db minio
    compose run --rm minio-setup

    step "Migrating as migrator"
    compose run --rm --no-deps app php artisan migrate --database=mysql_migrator --force --no-interaction

    step "Starting the stack"
    compose up -d --wait --remove-orphans

    step "Caching config, routes, views and events (php artisan optimize)"
    # Each container has its own filesystem, so every PHP service caches its own.
    for service in app worker scheduler; do
        compose exec -T "$service" php artisan optimize --no-interaction
    done
    # Workers finish their current job and restart with the cached config.
    compose exec -T app php artisan queue:restart --no-interaction

    step "Checking the environment (php artisan app:doctor)"
    compose exec -T app php artisan app:doctor --no-interaction

    step "Checking /up"
    domain="$(env_value DOMAIN)"
    if [[ -n "${DEPLOY_HEALTH_URL:-}" ]]; then
        curl -fsS --retry 10 --retry-delay 3 --retry-all-errors "$DEPLOY_HEALTH_URL" >/dev/null
    else
        # Through Caddy and real TLS, resolved to this host so DNS changes don't matter.
        curl -fsS --retry 10 --retry-delay 3 --retry-all-errors \
            --resolve "${domain}:$(env_value HTTPS_PORT | grep . || echo 443):127.0.0.1" \
            "https://${domain}/up" >/dev/null
    fi
    echo "/up is healthy"

    trap - ERR
    printf '\n\033[1;32mDeployed %s.\033[0m\n' "$target"
    if [[ -n "$previous_ref" && "$previous_ref" != "$target" ]]; then
        printf 'To roll back: ./deploy.sh %s\n' "$previous_ref"
    fi
}

main "$@"
