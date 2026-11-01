<?php
/**
 * HTTP client for the public eFacturiPlus API.
 *
 * @author    CommerceStream Innovate SRL
 * @copyright 2026 CommerceStream Innovate SRL
 * @license   https://opensource.org/licenses/MIT MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EfacturiplusClient
{
    const DEFAULT_URL = 'https://www.efacturiplus.ro/app/api/parteneri/v1';

    public function account()
    {
        return $this->request('GET', '/cont');
    }

    public function createInvoice(array $payload)
    {
        return $this->request('POST', '/facturi', $payload);
    }

    public function invoice($invoiceId)
    {
        return $this->request('GET', '/facturi?invoice_id=' . (int) $invoiceId);
    }

    public function sendToSpv($invoiceId)
    {
        return $this->request('POST', '/facturi/' . (int) $invoiceId . '/spv', []);
    }

    public function storno($invoiceId)
    {
        return $this->request('POST', '/facturi/' . (int) $invoiceId . '/storno', []);
    }

    private function request($method, $path, $body = null)
    {
        $key = (string) Configuration::get('EFACTURIPLUS_API_KEY');
        if ($key === '') {
            return ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'Cheia API nu este completată.'];
        }
        $base = defined('_EFACTURIPLUS_API_URL_') ? _EFACTURIPLUS_API_URL_ : self::DEFAULT_URL;
        $ch = curl_init(rtrim($base, '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['X-Api-Key: ' . $key, 'Accept: application/json', 'Content-Type: application/json', 'User-Agent: eFacturiPlus-PrestaShop/3.0.0'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode((object) $body));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'body' => [], 'error' => $curlError];
        }
        $data = json_decode((string) $raw, true);
        $data = is_array($data) ? $data : [];
        $ok = $status >= 200 && $status < 300 && !empty($data['success']);

        return ['ok' => $ok, 'status' => $status, 'body' => $data, 'error' => $ok ? '' : (isset($data['message']) ? (string) $data['message'] : 'HTTP ' . $status)];
    }
}
