# Autentificare și chei

Fiecare cerere trimite cheia în antet:

```
X-Api-Key: efp_...
```

## Tipuri de chei

| Cheie | Cum o obții | La ce ajunge |
|---|---|---|
| **A firmei** | **Firma mea → Integrări și API** | Doar la firma ei: `/cont`, `/facturi`. `Codul fiscal` e opțional |
| **De partener** | La cerere, de la eFacturiPlus, pentru platforme care facturează pentru mai multe firme | Conturile legate de partener: `/facturi` cu `Codul fiscal`, `/conturi` |

Cheia se arată o singură dată. Dacă o pierzi, creezi alta și o revoci pe cea veche.

## Reguli

- Ține cheia pe server, în variabile de mediu sau într-un seif de secrete. Nu o pune în cod, în git sau în aplicații de browser și de telefon.
- Bibliotecile refuză adrese care nu sunt `https`, în afară de `localhost`, și nu urmează redirectări.
- Pentru schimbarea cheii: creezi una nouă, muți programul pe ea, apoi o revoci pe cea veche.
- Revocarea are efect imediat.

## Limite

| Ce | Limită |
|---|---|
| Cereri API | 600 pe minut, pe cheie |
| Link-uri PDF | 120 pe minut, pe adresă IP |

Peste limită primești `429`, de obicei cu antetul `Retry-After`.
