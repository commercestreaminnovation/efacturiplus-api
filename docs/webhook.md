# Webhook

Cu webhook nu mai întrebi tu de stare: eFacturiPlus te anunță. Adresa se dă la crearea cheii și trebuie să fie `https`, publică, pe portul 443. Secretul se arată o singură dată.

## Ce primești

`POST` JSON:

```json
{"id": 41, "event": "factura.actualizata", "created_at": "2026-11-01T10:01:00+00:00", "data": {"invoice_id": 60123, "external_id": "comanda-1001", "efactura_status": "acceptata"}}
```

| Eveniment | Când |
|---|---|
| `factura.actualizata` | Emisă din proiect, anulată, încărcată, acceptată sau respinsă în SPV. `data` are câmpurile de la `POST /facturi`, plus `external_id` și `cui` |
| `cont.actualizat` | Doar pentru parteneri: cont activat, abonament pornit, expirat |

Antete: `X-Event` și `X-Signature: t=<unix>,v1=<hex>`.

## Verificarea semnăturii

`v1 = HMAC-SHA256(secret, "<t>.<corpul brut>")`. Calculează pe corpul brut, înainte de orice decodare JSON, și respinge un `t` mai vechi de câteva minute.

| Limbaj | Apel |
|---|---|
| PHP | `Client::verifyWebhook($body, $signature, $secret)` |
| JavaScript | `verifyWebhook(body, signature, secret)` |
| Python | `verify_webhook(body, signature, secret)` |
| Java | `EFacturiPlus.verifyWebhook(body, signature, secret)` |
| C# | `Client.VerifyWebhook(body, signature, secret)` |
| Go | `efacturiplus.VerifyWebhook(body, signature, secret, 5*time.Minute, time.Now())` |
| Ruby | `EFacturiPlus.verify_webhook(body, signature, secret)` |

## Reguli pentru serverul tău

- Răspunde repede cu 2xx și fă munca după aceea. Altfel reîncercăm de până la 10 ori, la 1, 2, 4, 8... minute.
- Același `id` poate veni de două ori, deci ține minte ce ai procesat.
- Tratează webhook-ul ca un semnal: pentru starea sigură poți citi `GET /facturi`.
