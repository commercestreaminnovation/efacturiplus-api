# Client Java

Java 11+, fără dependențe: un singur fișier. Copiază `src/ro/efacturiplus/EFacturiPlus.java` în proiect. Nu există încă un pachet Maven.

```java
import java.time.LocalDate;
import java.util.List;
import java.util.Map;
import ro.efacturiplus.EFacturiPlus;

EFacturiPlus client = new EFacturiPlus(System.getenv("EFP_KEY"));

try {
    Map<String, Object> r = client.createInvoice(Map.of(
        "serie_factura", "WEB",
        "data_factura", LocalDate.now().toString(),
        "external_id", "comanda-1001",
        "customer_name", "MOLDOTEX VASLUI SRL",
        "customer_cui", "RO14399840",
        "customer_address", "Str. Ștefan cel Mare 1",
        "customer_loc", "Vaslui",
        "customer_jud", "Vaslui",
        "products", List.of(Map.of("name", "Tricou", "quantity", 2, "price", 121, "cota_tva", 21))
    ));
    System.out.println(r.get("invoice_id"));
} catch (EFacturiPlus.ApiException e) {
    System.err.println(e.status + " " + e.code + " " + e.getMessage());
}
```

Răspunsurile sunt `Map`, listele sunt `List`, numerele sunt `Long` sau `Double`.

Opțiuni: `new EFacturiPlus(key, baseUrl, Duration.ofSeconds(25), 3)`.

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
| Verifică webhook | `EFacturiPlus.verifyWebhook(body, signature, secret)` |
| Deschide cont (partener) | `createAccount(data)` |
| Starea contului (partener) | `getAccount(cui)` |
| Link de activare nou (partener) | `reissueActivation(cui)` |

Comportament: repetă singur la `429` și `503`, după eroare de rețea repetă doar citirile, refuză adrese care nu sunt `https` (în afară de `localhost`) și nu urmează redirectări.

Câmpurile sunt în [referința API](../../docs/referinta-api.md), erorile în [erori.md](../../docs/erori.md), iar pașii în [ghidul de integrare](../../docs/ghid-integrare.md).
