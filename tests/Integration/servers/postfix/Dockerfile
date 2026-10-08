# Postfix as a submission server: STARTTLS required, AUTH checked by Dovecot,
# and mail for example.org handed to Dovecot over LMTP.
FROM debian:bookworm-slim

RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends postfix ca-certificates \
 && rm -rf /var/lib/apt/lists/*

COPY main.cf /etc/postfix/main.cf

# start-fg does not copy resolv.conf into a chroot, so no service may run in one.
RUN postconf -F '*/*/chroot = n' \
 && postconf -M 'submission/inet=submission inet n - n - - smtpd' \
 && postconf -P 'submission/inet/smtpd_tls_security_level=encrypt' \
                'submission/inet/smtpd_sasl_auth_enable=yes' \
                'submission/inet/smtpd_client_restrictions=permit_sasl_authenticated,reject'

CMD ["postfix", "start-fg"]
