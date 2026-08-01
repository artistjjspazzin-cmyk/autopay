#!/usr/bin/env python3
"""
Squire API Proxy — bypasses Cloudflare challenge using cloudscraper.
Runs as a local HTTP server on port 9876. PHP calls this instead of hitting Squire directly.
"""
import json
import sys
import os
import time
import threading
from http.server import HTTPServer, BaseHTTPRequestHandler
import cloudscraper

SQUIRE_API_BASE = os.getenv("SQUIRE_API_BASE", "https://api.getsquire.com")
PORT = int(os.getenv("SQUIRE_PROXY_PORT", "9876"))
US_PROXY_URL = os.getenv("US_PROXY_URL", "")

# Shared scraper instance with lock for thread safety
scraper_lock = threading.Lock()
scraper = cloudscraper.create_scraper(
    browser={"browser": "chrome", "platform": "windows", "mobile": False}
)
if US_PROXY_URL:
    scraper.proxies = {
        "http": US_PROXY_URL,
        "https": US_PROXY_URL,
    }

DEFAULT_HEADERS = {
    "Content-Type": "application/json",
    "Accept": "application/json",
    "Squire-Version": "2023-11-12",
    "Origin": "https://app.getsquire.com",
    "Referer": "https://app.getsquire.com/",
}


def do_request(method, endpoint, data=None, token=None):
    """Make a request to Squire API through cloudscraper."""
    url = SQUIRE_API_BASE + endpoint
    headers = dict(DEFAULT_HEADERS)
    if token:
        headers["Authorization"] = "JWT " + token

    with scraper_lock:
        try:
            if method == "POST":
                resp = scraper.post(url, json=data, headers=headers, timeout=30)
            elif method == "GET":
                resp = scraper.get(url, headers=headers, timeout=30)
            elif method == "PUT":
                resp = scraper.put(url, json=data, headers=headers, timeout=30)
            elif method == "DELETE":
                resp = scraper.delete(url, headers=headers, timeout=30)
            else:
                resp = scraper.request(method, url, json=data, headers=headers, timeout=30)

            try:
                body = resp.json()
            except Exception:
                body = None

            raw = resp.text[:2000] if body is None else None
            gateway_unavailable = resp.status_code == 403 and raw and (
                "Access Blocked" in raw
                or "Security Check" in raw
                or "This request was blocked by SQUIRE" in raw
                or "<title>Blocked" in raw
                or "cf-mitigated" in resp.headers
            )
            if gateway_unavailable:
                return {
                    "code": 503,
                    "body": {"error": "SQUIRE temporarily blocked the gateway connection. No charge was submitted. Please try again later."},
                    "raw": raw,
                    "gatewayUnavailable": True,
                    "upstreamCode": resp.status_code,
                }

            return {
                "code": resp.status_code,
                "body": body,
                "raw": raw,
                "gatewayUnavailable": False,
            }
        except Exception as e:
            return {"code": 0, "body": {"error": str(e)}, "raw": None}


class ProxyHandler(BaseHTTPRequestHandler):
    def do_POST(self):
        content_length = int(self.headers.get("Content-Length", 0))
        raw_body = self.rfile.read(content_length) if content_length > 0 else b""

        try:
            request = json.loads(raw_body) if raw_body else {}
        except json.JSONDecodeError:
            self.send_error(400, "Invalid JSON")
            return

        method = request.get("method", "POST")
        endpoint = request.get("endpoint", "")
        data = request.get("data")
        token = request.get("token")

        if not endpoint:
            self.send_response(400)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(json.dumps({"error": "endpoint required"}).encode())
            return

        result = do_request(method, endpoint, data, token)

        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps(result).encode())

    def do_GET(self):
        """Health check."""
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps({"status": "ok", "service": "squire-proxy"}).encode())

    def log_message(self, format, *args):
        pass  # Suppress access logs


def main():
    server = HTTPServer(("127.0.0.1", PORT), ProxyHandler)
    print(f"Squire proxy running on http://127.0.0.1:{PORT}", flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        server.shutdown()


if __name__ == "__main__":
    main()
