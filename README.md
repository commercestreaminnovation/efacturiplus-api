# eFacturiPlus API

API public pentru facturare și e-Factura (SPV) în România. Îl folosesc modulele de magazin de aici și îl poți folosi din orice program.

- **Specificație OpenAPI:** [`openapi.yaml`](openapi.yaml)
- **Exemple:** [`examples/`](examples)
- **Module:** [WooCommerce](modules/woocommerce/efacturiplus), [PrestaShop](modules/prestashop/efacturiplus), [client PHP și Laravel](modules/php-sdk)
- **Serviciul:** [efacturiplus.ro](https://www.efacturiplus.ro)

## Pornire în 3 pași

1. Deschide un cont pe [efacturiplus.ro](https://www.efacturiplus.ro/app/cont-nou).
2. Creează o cheie la **Firma mea → Integrări și API**. Cheia se arată o singură dată.
3. Emite prima factură:

```bash
curl https://www.efacturiplus.ro/app/api/parteneri/v1/facturi \
  -H "X-Api-Key: $EFP_KEY" -H "Content-Type: application/json" \
  -d '{
    "serie_factura": "WEB",
    "data_factura": "2026-11-01",
    "external_id": "comanda-1001",
    "customer_name": "MOLDOTEX VASLUI SRL",
    "customer_cui": "RO14399840",
    "customer_address": "Str. Ștefan cel Mare 1",
    "customer_loc": "Vaslui",
    "customer_jud": "Vaslui",
    "products": [
      {"name": "Tricou bumbac", "quantity": 2, "price": 121, "cota_tva": 21, "um": "buc"}
    ]
  }'
```

Adresa de bază: `https://www.efacturiplus.ro/app/api/parteneri/v1`

## Reguli pe scurt

| Regulă | Detaliu |
|---|---|
| Autentificare | Antetul `X-Api-Key`. Cheia unei firme ajunge doar la firma ei |
| Prețuri | Cu TVA, implicit. Trimite `"pret_fara_tva": true` pentru prețuri fără TVA |
| Reduceri | Linie cu `quantity` negativă și `price` pozitiv |
| Dubluri | Același `external_id` întoarce aceeași factură, cu `duplicate: true` |
| Numerotare | Lipsește `numar_facura`: eFacturiPlus dă următorul număr din serie |
| Serie | Dacă nu există, se creează la prima factură |
| Cote TVA | 21 și 11 din 01.08.2025; 0 doar pentru neplătitori |
| SPV | `POST /facturi/{id}/spv` sau trimitere automată, pornită din Integrări. **Trimiterea la ANAF nu se poate retrage** |
| Date | Factura nu poate fi mai veche de 90 de zile; totalul nu poate fi negativ (stornarea are rută proprie) |
| Texte | Marcajul HTML se scoate din nume, adrese și mențiuni |
| Limită | 600 de cereri pe minut pentru fiecare cheie |

## Erori

Răspunsurile de eroare au `success: false`, `code` (stabil) și `message` (pentru oameni).

| HTTP | `code` | Ce faci |
|---|---|---|
| 401 | `unauthenticated` | Verifică cheia |
| 402 | `account_expired`, `subscription_required` | Titularul pornește sau prelungește abonamentul |
| 403 | `forbidden`, `not_linked` | Cheia nu are acest drept sau e a altei firme |
| 404 | `not_found`, `company_not_found` | |
| 409 | `saved_as_draft`, `not_stornable`, `draft`, `cancelled` | Vezi `message` |
| 422 | `invalid`, `invalid_vat`, `date_order` | Corectează datele; nimic nu s-a scris |
| 503 | `busy` | Altă cerere pentru aceeași firmă e în lucru; reîncearcă |

## Webhook

Dacă treci o adresă `https://` publică, pe portul 443, la crearea cheii, primești `POST` când se schimbă starea unei facturi (`factura.actualizata`).
Antetul `X-Signature: t=<unix>,v1=<hex>`, unde `v1 = HMAC-SHA256(secret, "<t>.<corpul cererii>")`.
Răspunde cu 2xx. Altfel reîncercăm de până la 10 ori. Același `id` poate veni de două ori. Exemplu de verificare: [`examples/webhook.php`](examples/webhook.php).

## Securitate

Raportează vulnerabilitățile conform [SECURITY.md](SECURITY.md), nu într-un Issue public.

## Licențe

Documentația și exemplele: MIT. Modulul WooCommerce: GPL-2.0-or-later. Modulul PrestaShop: MIT.
Serviciul eFacturiPlus se folosește conform [termenilor](https://www.efacturiplus.ro/app/site/termeni).

Întrebări și erori: [Issues](../../issues).
