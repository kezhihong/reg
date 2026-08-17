"""dev 短信通道 mock：POST /send 返回回显（docs/05 §2 sms-mock 服务）。"""
import json
import random
from http.server import BaseHTTPRequestHandler, HTTPServer


class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        length = int(self.headers.get("Content-Length", 0))
        body = json.loads(self.rfile.read(length) or b"{}")
        code = str(random.randint(100000, 999999))
        self.send_response(200)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.end_headers()
        self.wfile.write(json.dumps({
            "code": 0,
            "message": "ok",
            "data": {"mock_code": code, "sent_to": body.get("phone", "")},
        }).encode("utf-8"))

    def log_message(self, fmt, *args):
        pass  # 静默


if __name__ == "__main__":
    HTTPServer(("0.0.0.0", 9502), Handler).serve_forever()
