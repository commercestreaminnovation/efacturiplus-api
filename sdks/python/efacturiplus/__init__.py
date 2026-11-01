"""Client pentru API-ul public eFacturiPlus. Python 3.9+, fara dependente."""
import hashlib
import hmac
import json
import re
import time
import urllib.error
import urllib.parse
import urllib.request

DEFAULT_URL = 'https://www.efacturiplus.ro/app/api/parteneri/v1'
VERSION = '1.0.0'
__all__ = ['Client', 'ApiError', 'verify_webhook', 'DEFAULT_URL']


class ApiError(Exception):
    """Raspuns de eroare. `code` e stabil, pentru program; mesajul e pentru oameni."""

    def __init__(self, message, status=0, code=None, body=None):
        super().__init__(message)
        self.status = status
        self.code = code
        self.body = body or {}
        self.errors = self.body.get('errors') or {}


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


_opener = urllib.request.build_opener(_NoRedirect)


def _assert_secure(url):
    if not url.startswith('https://') and not re.match(r'^http://(127\.0\.0\.1|localhost)(:|/|$)', url):
        raise ApiError('Adresa API trebuie să fie https: cheia nu se trimite necriptat.')


class Client:
    def __init__(self, api_key, base_url=DEFAULT_URL, timeout=25, retries=3):
        _assert_secure(base_url)
        self.api_key = api_key
        self.base_url = base_url.rstrip('/')
        self.timeout = timeout
        self.retries = retries

    def account(self):
        """Firma cheii, starea contului, seriile si cotele de TVA."""
        return self._request('GET', '/cont')

    def create_invoice(self, invoice):
        """Emite o factura. Trimite `external_id`, ca o repetare sa nu faca a doua factura."""
        return self._request('POST', '/facturi', invoice)

    def get_invoice(self, invoice_id):
        return self._request('GET', f'/facturi?invoice_id={int(invoice_id)}')

    def find_invoice(self, serie, numar, cui=None):
        query = {'serie': serie, 'numar': numar}
        if cui:
            query['cui'] = cui
        return self._request('GET', '/facturi?' + urllib.parse.urlencode(query))

    def send_to_spv(self, invoice_id):
        """Pune factura in coada SPV. Trimiterea la ANAF nu se poate retrage."""
        return self._request('POST', f'/facturi/{int(invoice_id)}/spv', {})

    def storno(self, invoice_id):
        """Stornare integrala. Stornarea trebuie trimisa separat in SPV."""
        return self._request('POST', f'/facturi/{int(invoice_id)}/storno', {})

    def create_account(self, account):
        return self._request('POST', '/conturi', account)

    def get_account(self, cui):
        return self._request('GET', '/conturi/' + urllib.parse.quote(cui, safe=''))

    def reissue_activation(self, cui):
        return self._request('POST', '/conturi/' + urllib.parse.quote(cui, safe='') + '/activare', {})

    def download_pdf(self, pdf_url):
        """Descarca PDF-ul din `pdf_url`. Intoarce bytes."""
        a, b = urllib.parse.urlsplit(pdf_url), urllib.parse.urlsplit(self.base_url)
        if (a.scheme, a.netloc) != (b.scheme, b.netloc):
            raise ApiError('Linkul PDF nu este de pe adresa API.')
        status, raw, _ = self._send('GET', pdf_url, None, False)
        if status != 200 or not raw.startswith(b'%PDF'):
            raise ApiError(f'PDF indisponibil (HTTP {status}).', status)
        return raw

    def _request(self, method, path, body=None):
        attempt = 0
        while True:
            try:
                status, raw, retry_after = self._send(method, self.base_url + path, body, True)
            except OSError as error:
                # Fara raspuns nu stim daca s-a scris ceva: se repeta doar citirile.
                if method == 'GET' and attempt < self.retries:
                    time.sleep(0.5 * 2 ** attempt)
                    attempt += 1
                    continue
                raise ApiError(str(error)) from error
            if status in (429, 503) and attempt < self.retries:
                time.sleep(float(retry_after) if retry_after else 0.5 * 2 ** attempt)
                attempt += 1
                continue
            try:
                data = json.loads(raw)
            except ValueError:
                data = {}
            if not isinstance(data, dict) or not data.get('success'):
                data = data if isinstance(data, dict) else {}
                raise ApiError(data.get('message') or f'HTTP {status}', status, data.get('code'), data)
            return data

    def _send(self, method, url, body, with_key):
        headers = {'Accept': 'application/json, application/pdf', 'Content-Type': 'application/json', 'User-Agent': f'efacturiplus-python/{VERSION}'}
        if with_key:
            headers['X-Api-Key'] = self.api_key
        data = None if body is None else json.dumps(body, ensure_ascii=False).encode()
        request = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with _opener.open(request, timeout=self.timeout) as response:
                return response.status, response.read(), response.headers.get('Retry-After')
        except urllib.error.HTTPError as error:
            return error.code, error.read(), error.headers.get('Retry-After')


def verify_webhook(body, signature, secret, tolerance=300, now=None):
    """`body` e corpul brut (bytes sau str), `signature` antetul X-Signature."""
    match = re.fullmatch(r't=(\d+),v1=([0-9a-f]{64})', signature or '')
    if not match or abs((time.time() if now is None else now) - int(match[1])) > tolerance:
        return False
    raw = body.encode() if isinstance(body, str) else body
    expected = hmac.new(secret.encode(), match[1].encode() + b'.' + raw, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, match[2])
