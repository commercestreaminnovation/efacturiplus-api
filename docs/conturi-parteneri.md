# Conturi pentru platforme (parteneri)

Pentru platformele care facturează pentru mai multe firme și le deschid conturi. Cheia de partener se obține de la eFacturiPlus.

## Ce conturi poate atinge o cheie

- Conturile deschise de ea prin `POST /conturi`.
- Conturile existente, după ce titularul permite facturile din aplicație.
- Contul propriu al partenerului, legat de eFacturiPlus.

Altfel: `403 not_linked`. Contul trebuie să fie și activ.

## POST /conturi

```json
{
  "cui": "RO12345678", "name": "Vaslui Taxi SRL", "reg_com": "J37/12/2015",
  "address": "Str. Unirii 3", "loc": "Vaslui", "jud": "Vaslui",
  "email": "ion@example.ro", "phone": "+40740123456",
  "firstname": "Ion", "lastname": "Popescu",
  "platitor_tva": true,
  "partner_ref": "KUL-PF-00012"
}
```

Obligatorii: `cui`, `name`, `email`, `firstname`.

- **Firmă nouă** (`201`, `existing: false`): se deschide contul, cu regulile oricărui cont. Titularul primește pe email un link de activare, valabil 7 zile, își alege parola și pornește abonamentul cu cardul. Până atunci contul nu primește facturi (`402 subscription_required`).
- **Firma are deja cont** (`200`, `existing: true`): contul nu se schimbă. Se leagă de partener, iar facturile merg abia după ce titularul le permite.
- Email folosit de alt cont: `409 email_taken`.

```json
{
  "success": true, "existing": false, "company_id": 360, "admin_id": 363, "partner_ref": "KUL-PF-00012",
  "account": {"state": "neactivat", "created_by_partner": true, "allow_invoices": true, "activated_at": null, "expires_at": "2027-01-23"},
  "activation_url": "https://www.efacturiplus.ro/app/activare/...",
  "activation_expires_at": "2026-11-08T10:00:00+00:00"
}
```

`account.state`: `neactivat`, `fara_abonament`, `activ`, `expirat`.

## GET /conturi/{cui}

Starea contului, fără link.

## POST /conturi/{cui}/activare

Link nou; cel vechi nu mai merge. Doar pentru conturile deschise de partener și neactivate. Altfel: `409 already_active`.
