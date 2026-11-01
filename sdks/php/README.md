# Client PHP și Laravel

PHP 8.1+, cu `curl`. Merge în orice proiect PHP, fără Laravel. Se instalează din GitHub:

```json
{
    "repositories": [{"type": "vcs", "url": "https://github.com/commercestreaminnovation/efacturiplus-api"}],
    "require": {"commercestreaminnovation/efacturiplus": "dev-main"}
}
```

```php
$client = new \EFacturiPlus\Client(getenv('EFP_KEY'));

try {
    $r = $client->createInvoice([
        'serie_factura' => 'WEB',
        'data_factura' => date('Y-m-d'),
        'external_id' => 'comanda-1001',
        'customer_name' => 'MOLDOTEX VASLUI SRL',
        'customer_cui' => 'RO14399840',
        'customer_address' => 'Str. Ștefan cel Mare 1',
        'customer_loc' => 'Vaslui',
        'customer_jud' => 'Vaslui',
        'products' => [['name' => 'Tricou', 'quantity' => 2, 'price' => 121, 'cota_tva' => 21]],
    ]);
    echo $r['invoice']['label'];
    file_put_contents('factura.pdf', $client->downloadPdf($r['pdf_url']));
} catch (\EFacturiPlus\ApiException $e) {
    echo $e->status, ' ', $e->errorCode, ' ', $e->getMessage();
}
```

## Laravel

Pachetul se înregistrează singur. În `.env`: `EFACTURIPLUS_KEY=...`

```php
use EFacturiPlus\Client;

public function __construct(private Client $client) {}
```

Fișierul de configurare: `php artisan vendor:publish --tag=efacturiplus-config`.

Webhook:

```php
$valid = \EFacturiPlus\Client::verifyWebhook(file_get_contents('php://input'), $_SERVER['HTTP_X_SIGNATURE'] ?? '', $secret);
```

## Ce oferă toate metodele

| Ce faci | Metodă |
|---|---|
| Firma și starea contului | `account()` |
| Emite o factură | `createInvoice($invoice)` |
| Citește o factură | `getInvoice($id)` |
| Caută după serie și număr | `findInvoice($serie, $numar, $cui)` |
| Lista facturilor | `listInvoices($filters)` |
| Clienții firmei | `listCustomers($filters)` |
| Articolele firmei | `listProducts($filters)` |
| Pune în coada SPV | `sendToSpv($id)` |
| Stornează | `storno($id)` |
| Descarcă PDF | `downloadPdf($url)` |
| Verifică webhook | `Client::verifyWebhook(...)` |
| Deschide cont (partener) | `createAccount($data)` |
| Starea contului (partener) | `getAccount($cui)` |
| Link de activare nou (partener) | `reissueActivation($cui)` |

Comportament: repetă singur la `429` și `503`, după eroare de rețea repetă doar citirile, refuză adrese care nu sunt `https` (în afară de `localhost`) și nu urmează redirectări.

Câmpurile sunt în [referința API](../../docs/referinta-api.md), erorile în [erori.md](../../docs/erori.md), iar pașii în [ghidul de integrare](../../docs/ghid-integrare.md).
