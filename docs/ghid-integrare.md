# Ghid de integrare

Rețeta pentru un program care facturează, în orice limbaj.

## 1. Pregătire

1. Deschide un cont și creează o cheie la **Firma mea → Integrări și API**.
2. Pune cheia într-o variabilă de mediu, de exemplu `EFP_KEY`.
3. Cheamă `GET /cont` și verifică `account.can_invoice`, `spv.connected`, `company.platitor_tva`.

## 2. Emiterea

La plata unei comenzi:

1. Construiești factura, cu `external_id` = id-ul comenzii.
2. O trimiți. Salvezi `invoice_id`, `invoice.label` și, dacă vrei, PDF-ul.
3. La `429` sau `503` repeți. La timeout repeți cu același `external_id`.
4. La `422` nu repeți: ai o problemă de date. O înregistrezi și o rezolvi.

Plătitorii de TVA folosesc cotele valabile la data facturii. Neplătitorii trimit `cota_tva: 0`.

Reducerile sunt linii cu `quantity` negativă și `price` pozitiv.

## 3. Trimiterea în SPV

Ai două variante:

- **Automat:** firma pornește trimiterea automată din Integrări. Tu nu mai chemi nimic.
- **La cerere:** chemi `POST /facturi/{id}/spv`.

Termenul legal de trimitere rămâne responsabilitatea firmei.

## 4. Urmărirea stării

Cu webhook: verifici semnătura și actualizezi comanda. Fără webhook: citești `GET /facturi` la câteva minute, până când `efactura_status` e `acceptata` sau `respinsa`.

La `respinsa`, motivele sunt în `efactura.errors`. Corectezi prin stornare și factură nouă, cu alt `external_id`.

## 5. Teste

Folosește un cont de test sau o serie separată. Nu există un mediu separat de sandbox: `POST /facturi` emite o factură reală în contul firmei, iar `/spv` trimite la ANAF. Pentru încercări trimite `draft: true` sau nu chema `/spv`.

## Greșeli frecvente

| Greșeală | Efect | Soluție |
|---|---|---|
| Lipsește `external_id` | Facturi dublate la reîncercări | Trimite-l mereu |
| Prețuri fără TVA, fără `pret_fara_tva` | Total greșit | Adaugă `pret_fara_tva: true` |
| Dată veche | `422` | Maximum 90 de zile în urmă |
| Date în ordine greșită în serie | `422 date_order` | Trimite facturile în ordine |
| Cheie în browser | Oricine o poate lua | Cheamă API-ul doar de pe server |
| Adresă incompletă la client | Respingere la ANAF | Completează adresa, localitatea, județul |
