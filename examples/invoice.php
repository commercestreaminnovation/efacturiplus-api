<?php
// php invoice.php (cu EFP_KEY in mediu)
$base = 'https://www.efacturiplus.ro/app/api/parteneri/v1';
$invoice = [
    'serie_factura' => 'WEB',
    'data_factura' => date('Y-m-d'),
    'external_id' => 'comanda-1001',
    'customer_name' => 'Ana Pop',
    'products' => [['name' => 'Tricou bumbac', 'quantity' => 2, 'price' => 121, 'cota_tva' => 21]],
];
$ch = curl_init($base . '/facturi');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => json_encode($invoice),
    CURLOPT_HTTPHEADER => ['X-Api-Key: ' . getenv('EFP_KEY'), 'Content-Type: application/json', 'Accept: application/json'],
]);
$r = json_decode(curl_exec($ch), true);
echo $r['success'] ? $r['invoice']['label'] . ' ' . $r['pdf_url'] . "\n" : $r['code'] . ': ' . $r['message'] . "\n";
