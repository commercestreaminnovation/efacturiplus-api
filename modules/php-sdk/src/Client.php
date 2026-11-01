<?php

namespace EFacturiPlus;

/** Client pentru API-ul public eFacturiPlus. Merge in orice proiect PHP, nu doar in Laravel. */
class Client
{
    public const DEFAULT_URL = 'https://www.efacturiplus.ro/app/api/parteneri/v1';

    public function __construct(private string $apiKey, private string $baseUrl = self::DEFAULT_URL, private int $timeout = 25) {}

    /** Firma cheii, starea contului, seriile si cotele de TVA. */
    public function account(): array
    {
        return $this->request('GET', '/cont');
    }

    /** Emite o factura. Trimite `external_id`, ca o repetare sa nu faca a doua factura. */
    public function createInvoice(array $invoice): array
    {
        return $this->request('POST', '/facturi', $invoice);
    }

    public function invoice(int $invoiceId): array
    {
        return $this->request('GET', '/facturi?invoice_id='.$invoiceId);
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

    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(rtrim($this->baseUrl, '/').$path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['X-Api-Key: '.$this->apiKey, 'Accept: application/json', 'Content-Type: application/json', 'User-Agent: efacturiplus-php/1.0'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode((object) $body));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new ApiException($error, 0);
        }
        $data = json_decode((string) $raw, true);
        if (! is_array($data) || empty($data['success'])) {
            throw new ApiException((string) ($data['message'] ?? "HTTP {$status}"), $status, $data['code'] ?? null, is_array($data) ? $data : []);
        }

        return $data;
    }
}
