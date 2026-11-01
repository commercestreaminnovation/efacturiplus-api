# Erori

Răspunsurile de eroare au forma:

```json
{"success": false, "code": "invalid", "message": "Câmpul ... este obligatoriu.", "errors": {"products": ["..."]}}
```

Programul tău decide după `code`, care rămâne stabil. `message` e pentru oameni. `errors` apare la validare, pe câmpuri.

| HTTP | `code` | Când | Ce faci |
|---|---|---|---|
| 400 | `company_key_required` | `/cont` cu cheie de partener | Folosește o cheie a firmei |
| 401 | `unauthenticated` | Cheie lipsă, greșită sau revocată | Verifică cheia |
| 402 | `account_expired` | Contul firmei a expirat | Titularul prelungește abonamentul |
| 402 | `subscription_required` | Abonament nepornit | Titularul îl pornește |
| 403 | `forbidden` | Cheia nu are acest drept | |
| 403 | `not_linked` | Firma nu e legată de partener | Titularul permite facturile din aplicație |
| 404 | `not_found`, `company_not_found` | Nu există sau nu e a acestei chei | |
| 409 | `saved_as_draft`, `draft` | Factura e proiect | Titularul o confirmă |
| 409 | `not_stornable`, `cancelled` | Nu se poate storna sau trimite | Vezi `message` |
| 409 | `email_taken`, `already_active` | La conturi | Vezi `message` |
| 422 | `invalid`, `invalid_vat`, `date_order` | Date greșite; nimic nu s-a scris | Corectează |
| 429 | | Prea multe cereri | Așteaptă `Retry-After` |
| 503 | `busy` | Altă cerere pentru aceeași firmă e în lucru | Reîncearcă |

`date_order` apare când data ar strica ordinea cronologică din serie.

În biblioteci, erorile devin excepții cu statusul HTTP și `code`:

| Limbaj | Tip | Status | Cod |
|---|---|---|---|
| PHP | `EFacturiPlus\ApiException` | `$e->status` | `$e->errorCode` |
| JavaScript | `ApiError` | `e.status` | `e.code` |
| Python | `ApiError` | `e.status` | `e.code` |
| Java | `EFacturiPlus.ApiException` | `e.status` | `e.code` |
| C# | `ApiException` | `e.Status` | `e.Code` |
| Go | `*ApiError` | `e.Status` | `e.Code` |
| Ruby | `EFacturiPlus::ApiError` | `e.status` | `e.code` |

Status `0` înseamnă că nu a existat răspuns: rețea, timeout sau o adresă refuzată.
