#!/bin/sh
set -eu

secrets_dir=/var/run/secrets/farmlink
for secret_name in APP_KEY JWT_SECRET DB_PASSWORD MPESA_CONSUMER_KEY MPESA_CONSUMER_SECRET MPESA_PASSKEY MPESA_SHORTCODE WEATHER_WEBHOOK_SECRET; do
    secret_file="${secrets_dir}/${secret_name}"
    if [ ! -r "$secret_file" ]; then
        printf 'Required runtime secret is unavailable: %s\n' "$secret_name" >&2
        exit 1
    fi
    export "$secret_name=$(cat "$secret_file")"
done

exec "$@"
