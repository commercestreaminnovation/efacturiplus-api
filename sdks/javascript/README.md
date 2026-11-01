# Client JavaScript și TypeScript

Node.js 18+, fără dependențe, cu tipuri incluse. **Doar pe server:** în browser cheia ar fi vizibilă oricui.

```bash
npm install github:commercestreaminnovation/efacturiplus-api
```

```js
import { Client, ApiError } from 'efacturiplus';

const client = new Client(process.env.EFP_KEY);

try {
  const r = await client.createInvoice({
    serie_factura: 'WEB',
    data_factura: new Date().toISOString().slice(0, 10),
    external_id: 'comanda-1001',
    customer_name: 'MOLDOTEX VASLUI SRL',
    customer_cui: 'RO14399840',
    customer_address: 'Str. Ștefan cel Mare 1',
    customer_loc: 'Vaslui',
    customer_jud: 'Vaslui',
    products: [{ name: 'Tricou', quantity: 2, price: 121, cota_tva: 21 }],
  });
  console.log(r.invoice.label);
} catch (e) {
  if (e instanceof ApiError) console.error(e.status, e.code, e.message, e.errors);
  else throw e;
}
```

Opțiuni: `new Client(key, { baseUrl, timeout: 25000, retries: 3 })`.

Webhook cu Express. Corpul trebuie să rămână brut:

```js
import express from 'express';
import { verifyWebhook } from 'efacturiplus';

const app = express();
app.post('/webhook', express.raw({ type: 'application/json' }), (req, res) => {
  if (!verifyWebhook(req.body, req.get('X-Signature'), process.env.EFP_WEBHOOK_SECRET)) return res.sendStatus(400);
  const event = JSON.parse(req.body);
  res.sendStatus(204);
});
```

## Ce oferă toate metodele

| Ce faci | Metodă |
|---|---|
| Firma și starea contului | `account()` |
| Emite o factură | `createInvoice(invoice)` |
| Citește o factură | `getInvoice(id)` |
| Caută după serie și număr | `findInvoice(serie, numar, cui)` |
| Lista facturilor | `listInvoices(filters)` |
| Clienții firmei | `listCustomers(filters)` |
| Articolele firmei | `listProducts(filters)` |
| Pune în coada SPV | `sendToSpv(id)` |
| Stornează | `storno(id)` |
| Descarcă PDF | `downloadPdf(url)` |
| Verifică webhook | `verifyWebhook(body, signature, secret)` |
| Deschide cont (partener) | `createAccount(data)` |
| Starea contului (partener) | `getAccount(cui)` |
| Link de activare nou (partener) | `reissueActivation(cui)` |

Comportament: repetă singur la `429` și `503`, după eroare de rețea repetă doar citirile, refuză adrese care nu sunt `https` (în afară de `localhost`) și nu urmează redirectări.

Câmpurile sunt în [referința API](../../docs/referinta-api.md), erorile în [erori.md](../../docs/erori.md), iar pașii în [ghidul de integrare](../../docs/ghid-integrare.md).
