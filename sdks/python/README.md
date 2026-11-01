# Client Python

Python 3.9+, fără dependențe.

```bash
pip install "git+https://github.com/commercestreaminnovation/efacturiplus-api.git#subdirectory=sdks/python"
```

```python
import os, datetime
from efacturiplus import Client, ApiError

client = Client(os.environ['EFP_KEY'])

try:
    r = client.create_invoice({
        'serie_factura': 'WEB',
        'data_factura': datetime.date.today().isoformat(),
        'external_id': 'comanda-1001',
        'customer_name': 'MOLDOTEX VASLUI SRL',
        'customer_cui': 'RO14399840',
        'customer_address': 'Str. Ștefan cel Mare 1',
        'customer_loc': 'Vaslui',
        'customer_jud': 'Vaslui',
        'products': [{'name': 'Tricou', 'quantity': 2, 'price': 121, 'cota_tva': 21}],
    })
    print(r['invoice']['label'])
    open('factura.pdf', 'wb').write(client.download_pdf(r['pdf_url']))
except ApiError as e:
    print(e.status, e.code, e, e.errors)
```

Opțiuni: `Client(key, base_url=..., timeout=25, retries=3)`.

Webhook cu Flask:

```python
from efacturiplus import verify_webhook

@app.post('/webhook')
def webhook():
    if not verify_webhook(request.get_data(), request.headers.get('X-Signature'), SECRET):
        return '', 400
    event = request.get_json()
    return '', 204
```

## Ce oferă toate metodele

| Ce faci | Metodă |
|---|---|
| Firma și starea contului | `account()` |
| Emite o factură | `create_invoice(invoice)` |
| Citește o factură | `get_invoice(id)` |
| Caută după serie și număr | `find_invoice(serie, numar, cui)` |
| Pune în coada SPV | `send_to_spv(id)` |
| Stornează | `storno(id)` |
| Descarcă PDF | `download_pdf(url)` |
| Verifică webhook | `verify_webhook(body, signature, secret)` |
| Deschide cont (partener) | `create_account(data)` |
| Starea contului (partener) | `get_account(cui)` |
| Link de activare nou (partener) | `reissue_activation(cui)` |

Comportament: repetă singur la `429` și `503`, după eroare de rețea repetă doar citirile, refuză adrese care nu sunt `https` (în afară de `localhost`) și nu urmează redirectări.

Câmpurile sunt în [referința API](../../docs/referinta-api.md), erorile în [erori.md](../../docs/erori.md), iar pașii în [ghidul de integrare](../../docs/ghid-integrare.md).
