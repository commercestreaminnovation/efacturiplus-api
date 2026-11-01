<?php

namespace EFacturiPlus;

/** Client pentru API-ul public eFacturiPlus. Merge in orice proiect PHP 8.1+, nu doar in Laravel. */
class Client
{
    public const DEFAULT_URL = 'https://www.efacturiplus.ro/app/api/parteneri/v1';

    public const VERSION = '1.0.0';

    private string $baseUrl;

    public function __construct(private string $apiKey, string $baseUrl = self::DEFAULT_URL, private int $timeout = 25, private int $retries = 3)
    {
        self::assertSecure($baseUrl);
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Firma cheii, starea contului, seriile si cotele de TVA. Doar pentru cheile unei firme. */
    public function account(): array
    {
        return $this->request('GET', '/cont');
    }

    /** Emite o factura. Trimite `external_id`, ca o repetare sa nu faca a doua factura. */
    public function createInvoice(array $invoice): array
    {
        return $this->request('POST', '/facturi', $invoice);
    }

    /** Starea unei facturi trimise prin aceasta cheie, cu link PDF nou. */
    public function getInvoice(int $invoiceId): array
    {
        return $this->request('GET', '/facturi?invoice_id='.$invoiceId);
    }

    /** Numele vechi al lui getInvoice(). */
    public function invoice(int $invoiceId): array
    {
        return $this->getInvoice($invoiceId);
    }

    /** Cauta dupa serie si numar. `cui` e obligatoriu doar pentru cheile de partener. */
    public function findInvoice(string $serie, int $numar, ?string $cui = null): array
    {
        return $this->request('GET', '/facturi?'.http_build_query(array_filter(['serie' => $serie, 'numar' => $numar, 'cui' => $cui])));
    }

    /** Pune factura in coada SPV. Trimiterea la ANAF nu se poate retrage. */
    public function sendToSpv(int $invoiceId): array
    {
        return $this->request('POST', "/facturi/{$invoiceId}/spv", []);
    }

    /** Stornare integrala. Stornarea trebuie trimisa separat in SPV. */
    public function storno(int $invoiceId): array
    {
        return $this->request('POST', "/facturi/{$invoiceId}/storno", []);
    }

    /** Partenerii: deschide un cont pentru o firma. */
    public function createAccount(array $account): array
    {
        return $this->request('POST', '/conturi', $account);
    }

    /** Partenerii: starea contului unei firme legate. */
    public function getAccount(string $cui): array
    {
        return $this->request('GET', '/conturi/'.rawurlencode($cui));
    }

    /** Partenerii: link de activare nou. */
    public function reissueActivation(string $cui): array
    {
        return $this->request('POST', '/conturi/'.rawurlencode($cui).'/activare', []);
    }

    /** Descarca PDF-ul din `pdf_url` (link semnat, 24 de ore). Intoarce continutul binar. */
    public function downloadPdf(string $pdfUrl): string
    {
        $base = parse_url($this->baseUrl);
        $url = parse_url($pdfUrl);
        if (($url['host'] ?? '') !== ($base['host'] ?? '') || ($url['scheme'] ?? '') !== ($base['scheme'] ?? '') || ($url['port'] ?? null) !== ($base['port'] ?? null)) {
            throw new ApiException('Linkul PDF nu este de pe adresa API.', 0);
        }
        [$status, $raw] = $this->send('GET', $pdfUrl, null, false);
        if ($status !== 200 || ! str_starts_with($raw, '%PDF')) {
            throw new ApiException("PDF indisponibil (HTTP {$status}).", $status);
        }

        return $raw;
    }

    /**
     * Verifica un webhook. `$body` e corpul brut, nemodificat, iar `$signature` antetul X-Signature.
     * Respinge semnaturile mai vechi de `$tolerance` secunde.
     */
    public static function verifyWebhook(string $body, string $signature, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        if (! preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $signature, $m)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $m[1]) > $tolerance) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$body, $secret), $m[2]);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                [$status, $raw, $retryAfter] = $this->send($method, $this->baseUrl.$path, $body, true);
            } catch (ApiException $e) {
                // Fara raspuns nu stim daca s-a scris ceva: se repeta doar citirile.
                if ($method === 'GET' && $attempt < $this->retries) {
                    usleep(self::delay($attempt, null));

                    continue;
                }
                throw $e;
            }
            if (in_array($status, [429, 503], true) && $attempt < $this->retries) {
                usleep(self::delay($attempt, $retryAfter));

                continue;
            }
            $data = json_decode($raw, true);
            if (! is_array($data) || empty($data['success'])) {
                throw new ApiException((string) ($data['message'] ?? "HTTP {$status}"), $status, $data['code'] ?? null, is_array($data) ? $data : []);
            }

            return $data;
        }
    }

    /** @return array{0: int, 1: string, 2: ?string} */
    private function send(string $method, string $url, ?array $body, bool $withKey): array
    {
        $retryAfter = null;
        $headers = ['Accept: application/json, application/pdf', 'Content-Type: application/json', 'User-Agent: efacturiplus-php/'.self::VERSION];
        if ($withKey) {
            $headers[] = 'X-Api-Key: '.$this->apiKey;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$retryAfter) {
                if (stripos($line, 'retry-after:') === 0) {
                    $retryAfter = trim(substr($line, 12));
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode((object) $body, JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new ApiException($error, 0);
        }

        return [$status, (string) $raw, $retryAfter];
    }

    private static function delay(int $attempt, ?string $retryAfter): int
    {
        return (int) ((is_numeric($retryAfter) ? (float) $retryAfter : 0.5 * (2 ** $attempt)) * 1000000);
    }

    private static function assertSecure(string $url): void
    {
        if (! str_starts_with($url, 'https://') && ! preg_match('#^http://(127\.0\.0\.1|localhost)(:|/|$)#', $url)) {
            throw new ApiException('Adresa API trebuie să fie https: cheia nu se trimite necriptat.', 0);
        }
    }
}
