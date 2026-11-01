# Referința API

Adresa de bază: `https://www.efacturiplus.ro/app/api/parteneri/v1`

Specificația completă, pentru generatoare de cod și Postman: [`openapi.yaml`](../openapi.yaml).

| Metodă | Adresă | Ce face | Cheie |
|---|---|---|---|
| GET | `/cont` | Firma, starea contului, SPV, serii, cote TVA | a firmei |
| POST | `/facturi` | Creează o factură | oricare |
| GET | `/facturi` | Citește o factură | oricare |
| GET | `/facturi/lista` | Lista facturilor | oricare |
| GET | `/clienti` | Clienții firmei | a firmei |
| GET | `/produse` | Articolele firmei | a firmei |
| POST | `/facturi/{id}/spv` | Pune factura în coada SPV | oricare |
| POST | `/facturi/{id}/storno` | Stornează integral | oricare |
| GET | `/facturi/{id}/pdf` | PDF, prin linkul semnat din `pdf_url` | fără |
| POST | `/conturi` | Deschide un cont pentru o firmă | de partener |
| GET | `/conturi/{cui}` | Starea contului unei firme legate | de partener |
| POST | `/conturi/{cui}/activare` | Link de activare nou | de partener |

## GET /cont

```json
{
  "success": true,
  "company": {"company_id": 346, "name": "FIRMA SRL", "cui": "RO123", "platitor_tva": true},
  "account": {"state": "activ", "can_invoice": true, "expires_at": "2027-01-23"},
  "spv": {"connected": true, "auto_send": false},
  "series": ["WEB"],
  "vat_rates": [21, 11]
}
```

`account.state`: `activ`, `expirat`, `fara_abonament`. Cu o cheie de partener răspunde `400 company_key_required`.

## POST /facturi

| Câmp | Obligatoriu | Detalii |
|---|---|---|
| `Codul fiscal` | Doar cu cheie de partener | CUI-ul emitentului, cu sau fără RO |
| `serie_factura` | Da | Litere, cifre, `-`, `/`; cel mult 20 de caractere. Se creează dacă nu există |
| `numar_facura` | Nu | Lipsește: următorul număr din serie. Numele are forma din API-ul vechi; se acceptă și `numar_factura` |
| `data_factura` | Da | `YYYY-MM-DD`; cel mult 90 de zile în urmă și un an în viitor |
| `scadenta_date` | Nu | `YYYY-MM-DD`, nu înainte de data facturii |
| `currency_name` | Nu | 3 litere, implicit `RON` |
| `external_id` | Recomandat | Id-ul tău: litere, cifre, `.`, `_`, `:`, `-`; cel mult 64. Previne dublurile |
| `customer_name` | Recomandat | Cel mult 255 |
| `customer_cui` | Nu | Gol sau `0000000000000` pentru persoane fizice |
| `customer_reg_com` | Nu | Cel mult 20 |
| `customer_address`, `customer_loc` | Recomandat | Necesare pentru e-Factura |
| `customer_jud` | Recomandat | Județul. Pentru București, numele sectorului merge la localitate |
| `customer_country` | Nu | Cod ISO sau nume; implicit România |
| `customer_postcode`, `customer_email`, `customer_telephone` | Nu | |
| `customer_bank_name`, `customer_bank_account` | Nu | |
| `pret_fara_tva` | Nu | `true` dacă prețurile sunt fără TVA. Implicit sunt **cu TVA** |
| `mentiuni` | Nu | Cel mult 1000 |
| `draft` | Nu | `true`: rămâne proiect până îl confirmă titularul |
| `scutire_tva` | Nu | Factură fără TVA a unui plătitor; vezi tabelul de mai jos. Toate liniile au `cota_tva: 0` |
| `motiv_scutire` | La `alta-scutire` | Temeiul legal, cel mult 255 |
| `products` | Da | Între 1 și 500 de linii |

Linia din `products`:

| Câmp | Obligatoriu | Detalii |
|---|---|---|
| `name` | Da | Cel mult 255 |
| `quantity` | Da | Diferită de 0. Negativă pentru reduceri |
| `price` | Da | Preț unitar, pozitiv |
| `cota_tva` | Nu | 21 sau 11 din 01.08.2025; 19, 9, 5 înainte; 0 pentru neplătitori |
| `um` | Nu | Implicit `buc` |
| `mentions` | Nu | Cel mult 255 |

`scutire_tva`:

| Valoare | Când |
|---|---|
| `export` | Bunuri expediate în afara UE (art. 294 alin. 1) |
| `livrare-ue` | Bunuri către o firmă cu cod de TVA din alt stat UE (art. 294 alin. 2) |
| `servicii-ue` | Servicii către firme din UE (art. 278 alin. 2) |
| `taxare-inversa` | Taxare inversă (art. 331) |
| `scutit-292` | Scutit fără drept de deducere (art. 292) |
| `neimpozabil` | Nu face obiectul TVA în România |
| `alta-scutire` | Altă scutire; temeiul în `motiv_scutire` |

Alegerea scutirii corecte rămâne a emitentului. Neplătitorii de TVA nu trimit acest câmp, doar `cota_tva: 0`.

Marcajul HTML se scoate din texte. Totalul facturii nu poate fi negativ.

Răspuns `201`:

```json
{
  "success": true,
  "message": "Factura WEB17 a fost înregistrată cu succes.",
  "invoice_id": 60123,
  "invoice": {"serie": "WEB", "numar": 17, "label": "WEB17", "status": "emisa", "data": "2026-11-01", "scadenta": "2026-11-01", "total": 242, "tva": 42},
  "efactura_status": "netrimisa",
  "efactura": {"status": "netrimisa", "label": "Netrimisă în SPV", "index_incarcare": null, "errors": []},
  "pdf_url": "https://www.efacturiplus.ro/app/api/parteneri/v1/facturi/60123/pdf?exp=...&sig=..."
}
```

Dacă factura există deja: `200`, cu `duplicate: true` și aceleași câmpuri.

`pdf_url` e semnat, merge fără cheie și e valabil 24 de ore. `GET /facturi` dă un link nou. Proiectele nu au PDF.

## GET /facturi

Cu `?invoice_id=60123` sau `?serie=WEB&numar=17`. Cu o cheie de partener se adaugă `&cui=...`. Răspunsul e același ca la creare. Se văd doar facturile trimise prin aceeași cheie sau cele fără partener ale firmei cheii.

## GET /facturi/lista

Facturile emise, cele mai noi primele. Filtre: `de_la`, `pana_la` (`YYYY-MM-DD`), `pagina`, `per_pagina` (maximum 100, implicit 50). Cheia unei firme vede facturile firmei, fără cele trimise de parteneri. Cheia unui partener trimite și `cui` și vede doar facturile trimise de ea.

```json
{"success": true, "page": 1, "per_page": 50, "total": 120, "invoices": [{"invoice_id": 60123, "invoice": {"label": "WEB17"}, "efactura_status": "acceptata", "pdf_url": "..."}]}
```

## GET /clienti și GET /produse

Doar cu cheia unei firme, doar citire. Filtre: `cauta` (nume, CUI sau cod), `pagina`, `per_pagina`. Răspund cu `customers`, respectiv `products`, plus `page`, `per_page`, `total`.

## POST /facturi/{id}/spv

Pune factura în coada de trimitere. Răspunde cu starea facturii. Factura trebuie să fi fost trimisă prin aceeași cheie, iar contul să fie activ. **Trimiterea la ANAF nu se poate retrage.**

## POST /facturi/{id}/storno

Face factura de stornare, integral. A doua cerere întoarce aceeași stornare, cu `duplicate: true`. Stornarea pleacă în SPV singură doar dacă firma are trimiterea automată pornită; altfel chemi `/spv` pentru ea.

## Conturi (parteneri)

Vezi [conturi-parteneri.md](conturi-parteneri.md).

## Erori

Vezi [erori.md](erori.md).
