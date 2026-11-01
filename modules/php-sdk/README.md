# Client PHP și Laravel pentru eFacturiPlus

```php
$client = new \EFacturiPlus\Client(getenv('EFP_KEY'));
$r = $client->createInvoice([
    'serie_factura' => 'WEB',
    'data_factura' => date('Y-m-d'),
    'external_id' => 'comanda-1001',
    'customer_name' => 'Ana Pop',
    'products' => [['name' => 'Tricou', 'quantity' => 1, 'price' => 121, 'cota_tva' => 21]],
]);
echo $r['invoice']['label'], ' ', $r['pdf_url'];
```

În Laravel, pune `EFACTURIPLUS_KEY` în `.env` și folosește `EFacturiPlus\Facade` sau injectează `EFacturiPlus\Client`.
Erorile aruncă `EFacturiPlus\ApiException`, cu `status`, `errorCode` și mesajul API-ului.
