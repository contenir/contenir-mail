"""A token introspection endpoint (RFC 7662) for Dovecot's oauth2 passdb.

Only the tokens below are active, each for its user; any other is inactive.
"""
import json
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs

TOKENS = {
    "valid-token-for-test": "test",
}


class Introspect(BaseHTTPRequestHandler):
    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0"))
        token = parse_qs(self.rfile.read(length).decode()).get("token", [""])[0]
        user = TOKENS.get(token)
        body = json.dumps({"active": True, "username": user} if user else {"active": False}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


HTTPServer(("", 8080), Introspect).serve_forever()
