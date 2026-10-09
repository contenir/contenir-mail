#!/usr/bin/env bash
# Start the mail servers for the integration suite, with certificates from a
# throwaway CA. The CA is printed last: run PHPUnit with
# -d openssl.cafile=<that path> so the servers' certificates are trusted.
set -euo pipefail

cd "$(dirname "$0")"
var="$PWD/var"
certs="$var/certs"
user="${MAIL_USER:-test}"
export MAIL_PASSWORD="${MAIL_PASSWORD:-secret}"
export MAIL_UID="$(id -u)"
export MAIL_GID="$(id -g)"

rm -rf "$var"
mkdir -p "$certs/expired" "$certs/self-signed" "$certs/issued" "$var/mail/$user" "$var/mail-strict/$user" "$var/mail-untrusted/$user"
touch "$var/mail/$user/INBOX" "$var/mail-strict/$user/INBOX" "$var/mail-untrusted/$user/INBOX"

# A key and a request for a certificate naming localhost, in directory $1.
request() {
  openssl req -newkey rsa:2048 -nodes -subj "/CN=localhost" \
    -keyout "$1/server.key" -out "$1/server.csr" 2>/dev/null
}

openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=contenir-mail test CA" \
  -keyout "$certs/ca.key" -out "$certs/ca.crt" 2>/dev/null

request "$certs"
openssl x509 -req -in "$certs/server.csr" -days 2 \
  -CA "$certs/ca.crt" -CAkey "$certs/ca.key" -CAcreateserial \
  -extfile <(printf 'subjectAltName=DNS:localhost\n') -out "$certs/server.crt" 2>/dev/null

# Signed by the same CA, but valid only for a day in 2024: openssl ca can backdate, x509 cannot.
request "$certs/expired"
touch "$certs/index.txt"
cat > "$certs/ca.cnf" <<CNF
[ca]
default_ca = test

[test]
database = $certs/index.txt
new_certs_dir = $certs/issued
certificate = $certs/ca.crt
private_key = $certs/ca.key
serial = $certs/ca.srl
default_md = sha256
policy = any

[any]
commonName = supplied
CNF
openssl ca -batch -notext -config "$certs/ca.cnf" -in "$certs/expired/server.csr" \
  -startdate 20240101000000Z -enddate 20240102000000Z \
  -extfile <(printf 'subjectAltName=DNS:localhost\n') -out "$certs/expired/server.crt" 2>/dev/null

openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=localhost" \
  -addext 'subjectAltName=DNS:localhost' \
  -keyout "$certs/self-signed/server.key" -out "$certs/self-signed/server.crt" 2>/dev/null

openssl dhparam -dsaparam -out "$certs/dh.pem" 2048 2>/dev/null
cp "$certs/dh.pem" "$certs/expired/dh.pem"
cp "$certs/dh.pem" "$certs/self-signed/dh.pem"

# GreenMail reads its certificate from a PKCS12 keystore.
openssl pkcs12 -export -name greenmail -passout pass:greenmail \
  -inkey "$certs/server.key" -in "$certs/server.crt" -certfile "$certs/ca.crt" -out "$certs/greenmail.p12"

chmod -R a+rX "$certs"

envsubst '${MAIL_PASSWORD} ${MAIL_UID} ${MAIL_GID}' < dovecot.conf.template > "$var/dovecot.conf"
envsubst '${MAIL_PASSWORD}' < dovecot/password.conf.template > "$var/dovecot-password.conf"
envsubst '${MAIL_PASSWORD}' < dovecot/strict.conf.template > "$var/dovecot-strict.conf"

docker compose up -d --build --wait >&2

# Docker accepts connections before a server is ready, so wait for each greeting.
# The TLS ports belong to the same servers as these plain ones.
for port in 143 110 1143 1110 2143 4143 587 2587 1025 3143; do
  for attempt in $(seq 1 60); do
    if { exec 3<>"/dev/tcp/127.0.0.1/$port"; } 2>/dev/null && read -r -t 5 greeting <&3 && [ -n "$greeting" ]; then
      break
    fi
    [ "$attempt" -lt 60 ] || { echo "No greeting on port $port" >&2; exit 1; }
    sleep 1
  done
  exec 3<&-
done

echo "$certs/ca.crt"
