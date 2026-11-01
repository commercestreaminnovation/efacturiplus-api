#!/usr/bin/env python3
"""Server de test pentru comportamentul clientilor: reincercari si redirectari. Doar local."""
import http.server, json, sys
state = {'cont': 0, 'evil': 0}

class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def do_GET(self):
        if self.path == '/state':
            return self.reply(200, state)
        if self.path == '/retry/cont':
            state['cont'] += 1
            if state['cont'] < 3:
                return self.reply(503, {'success': False, 'code': 'busy', 'message': 'ocupat'}, {'Retry-After': '0'})
            return self.reply(200, {'success': True, 'attempts': state['cont']})
        if self.path == '/redir/cont':
            self.send_response(302); self.send_header('Location', f'http://127.0.0.1:{PORT}/evil'); self.send_header('Content-Length', '0'); self.end_headers(); return
        if self.path == '/evil':
            state['evil'] += 1
            return self.reply(200, {'success': True, 'evil': True})
        self.reply(404, {'success': False, 'code': 'not_found', 'message': 'x'})
    def reply(self, code, body, headers=None):
        raw = json.dumps(body).encode()
        self.send_response(code); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(raw)))
        for k, v in (headers or {}).items(): self.send_header(k, v)
        self.end_headers(); self.wfile.write(raw)

PORT = int(sys.argv[1])
http.server.ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
