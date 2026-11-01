<?php
// Verificarea semnaturii unui webhook eFacturiPlus.
$secret = getenv('EFP_WEBHOOK_SECRET');
$body = file_get_contents('php://input');
parse_str(str_replace(',', '&', $_SERVER['HTTP_X_SIGNATURE'] ?? ''), $sig);
$expected = hash_hmac('sha256', ($sig['t'] ?? '') . '.' . $body, $secret);
if (!hash_equals($expected, $sig['v1'] ?? '') || abs(time() - (int) ($sig['t'] ?? 0)) > 300) {
    http_response_code(400);
    exit;
}
$event = json_decode($body, true);
// $event['event'] = factura.actualizata; acelasi $event['id'] poate veni de doua ori.
http_response_code(204);
