// Client pentru API-ul public eFacturiPlus. Node.js 18+, fara dependente. Se foloseste doar pe server:
// cheia API nu are voie sa ajunga in browser.
import { createHmac, timingSafeEqual } from 'node:crypto';

export const DEFAULT_URL = 'https://www.efacturiplus.ro/app/api/parteneri/v1';
export const VERSION = '1.0.0';

export class ApiError extends Error {
  constructor(message, status = 0, code = null, body = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.body = body;
    this.errors = body?.errors ?? {};
  }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function assertSecure(url) {
  if (!url.startsWith('https://') && !/^http:\/\/(127\.0\.0\.1|localhost)(:|\/|$)/.test(url)) {
    throw new ApiError('Adresa API trebuie să fie https: cheia nu se trimite necriptat.');
  }
}

export class Client {
  constructor(apiKey, { baseUrl = DEFAULT_URL, timeout = 25000, retries = 3 } = {}) {
    assertSecure(baseUrl);
    this.apiKey = apiKey;
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.timeout = timeout;
    this.retries = retries;
  }

  account() { return this.#request('GET', '/cont'); }

  createInvoice(invoice) { return this.#request('POST', '/facturi', invoice); }

  getInvoice(invoiceId) { return this.#request('GET', `/facturi?invoice_id=${Number(invoiceId)}`); }

  findInvoice(serie, numar, cui = null) {
    const query = new URLSearchParams({ serie, numar: String(numar), ...(cui ? { cui } : {}) });
    return this.#request('GET', `/facturi?${query}`);
  }

  sendToSpv(invoiceId) { return this.#request('POST', `/facturi/${Number(invoiceId)}/spv`, {}); }

  storno(invoiceId) { return this.#request('POST', `/facturi/${Number(invoiceId)}/storno`, {}); }

  createAccount(account) { return this.#request('POST', '/conturi', account); }

  getAccount(cui) { return this.#request('GET', `/conturi/${encodeURIComponent(cui)}`); }

  reissueActivation(cui) { return this.#request('POST', `/conturi/${encodeURIComponent(cui)}/activare`, {}); }

  /** Descarca PDF-ul din `pdf_url`. Intoarce un Buffer. */
  async downloadPdf(pdfUrl) {
    if (new URL(pdfUrl).origin !== new URL(this.baseUrl).origin) {
      throw new ApiError('Linkul PDF nu este de pe adresa API.');
    }
    const response = await this.#fetch('GET', pdfUrl, undefined, false);
    const data = Buffer.from(await response.arrayBuffer());
    if (response.status !== 200 || data.subarray(0, 4).toString() !== '%PDF') {
      throw new ApiError(`PDF indisponibil (HTTP ${response.status}).`, response.status);
    }
    return data;
  }

  async #request(method, path, body) {
    for (let attempt = 0; ; attempt++) {
      let response;
      try {
        response = await this.#fetch(method, this.baseUrl + path, body, true);
      } catch (error) {
        // Fara raspuns nu stim daca s-a scris ceva: se repeta doar citirile.
        if (method === 'GET' && attempt < this.retries) {
          await sleep(500 * 2 ** attempt);
          continue;
        }
        throw new ApiError(error.message);
      }
      if ([429, 503].includes(response.status) && attempt < this.retries) {
        const after = Number(response.headers.get('retry-after'));
        await sleep(Number.isFinite(after) && response.headers.has('retry-after') ? after * 1000 : 500 * 2 ** attempt);
        continue;
      }
      const data = await response.json().catch(() => ({}));
      if (!data.success) {
        throw new ApiError(data.message ?? `HTTP ${response.status}`, response.status, data.code ?? null, data);
      }
      return data;
    }
  }

  #fetch(method, url, body, withKey) {
    return fetch(url, {
      method,
      redirect: 'manual',
      signal: AbortSignal.timeout(this.timeout),
      headers: {
        Accept: 'application/json, application/pdf',
        'Content-Type': 'application/json',
        'User-Agent': `efacturiplus-js/${VERSION}`,
        ...(withKey ? { 'X-Api-Key': this.apiKey } : {}),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  }
}

/** `body` e corpul brut (string sau Buffer), `signature` antetul X-Signature. */
export function verifyWebhook(body, signature, secret, { tolerance = 300, now = Date.now() / 1000 } = {}) {
  const match = /^t=(\d+),v1=([0-9a-f]{64})$/.exec(signature ?? '');
  if (!match || Math.abs(now - Number(match[1])) > tolerance) return false;
  const expected = createHmac('sha256', secret).update(`${match[1]}.`).update(body).digest();
  return timingSafeEqual(expected, Buffer.from(match[2], 'hex'));
}
