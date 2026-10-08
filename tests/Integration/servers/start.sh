#!/usr/bin/env bash
# Start Dovecot and Mailpit for the integration suite, with certificates from
# a throwaway CA. The CA is printed last: run PHPUnit with
# -d openssl.cafile=<that path> so the servers' certificates are trusted.
set -euo pipefail

cd "$(dirname "$0")"
var="$PWD/var"
user="${MAIL_USER:-test}"
export MAIL_PASSWORD="${MAIL_PASSWORD:-secret}"
export MAIL_UID="$(id -u)"
export MAIL_GID="$(id -g)"

rm -rf "$var"
mkdir -p "$var/certs" "$var/mail/$user"
touch "$var/mail/$user/INBOX"

openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=contenir-mail test CA" \
  -keyout "$var/certs/ca.key" -out "$var/certs/ca.crt" 2>/dev/null
openssl req -newkey rsa:2048 -nodes -subj "/CN=localhost" \
  -keyout "$var/certs/server.key" -out "$var/certs/server.csr" 2>/dev/null
openssl x509 -req -in "$var/certs/server.csr" -days 2 \
  -CA "$var/certs/ca.crt" -CAkey "$var/certs/ca.key" -CAcreateserial \
  -extfile <(printf 'subjectAltName=DNS:localhost\n') -out "$var/certs/server.crt" 2>/dev/null
openssl dhparam -dsaparam -out "$var/certs/dh.pem" 2048 2>/dev/null
chmod 644 "$var/certs/"*

envsubst '${MAIL_PASSWORD} ${MAIL_UID} ${MAIL_GID}' < dovecot.conf.template > "$var/dovecot.conf"

docker compose up -d --wait >&2

for port in 143 993 110 995 1025 8025; do
  for _ in $(seq 1 30); do
    (echo > "/dev/tcp/127.0.0.1/$port") 2>/dev/null && break
    sleep 1
  done
done

echo "$var/certs/ca.crt"
