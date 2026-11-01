# Client C# / .NET

.NET 8+, fără dependențe. Adaugă proiectul `EFacturiPlus/EFacturiPlus.csproj` ca referință sau copiază `Client.cs`. Nu există încă un pachet NuGet.

```csharp
using EFacturiPlus;

using var client = new Client(Environment.GetEnvironmentVariable("EFP_KEY")!);

try
{
    var r = await client.CreateInvoiceAsync(new
    {
        serie_factura = "WEB",
        data_factura = DateTime.Now.ToString("yyyy-MM-dd"),
        external_id = "comanda-1001",
        customer_name = "MOLDOTEX VASLUI SRL",
        customer_cui = "RO14399840",
        customer_address = "Str. Ștefan cel Mare 1",
        customer_loc = "Vaslui",
        customer_jud = "Vaslui",
        products = new[] { new { name = "Tricou", quantity = 2, price = 121, cota_tva = 21 } },
    });
    Console.WriteLine(r["invoice"]!["label"]);
}
catch (ApiException e)
{
    Console.WriteLine($"{e.Status} {e.Code} {e.Message}");
}
```

Răspunsurile sunt `JsonNode`. Păstrează un singur `Client` pe toată durata aplicației.

Opțiuni: `new Client(key, baseUrl, TimeSpan.FromSeconds(25), retries: 3)`.

## Ce oferă toate metodele

| Ce faci | Metodă |
|---|---|
| Firma și starea contului | `AccountAsync()` |
| Emite o factură | `CreateInvoiceAsync(invoice)` |
| Citește o factură | `GetInvoiceAsync(id)` |
| Caută după serie și număr | `FindInvoiceAsync(serie, numar, cui)` |
| Pune în coada SPV | `SendToSpvAsync(id)` |
| Stornează | `StornoAsync(id)` |
| Descarcă PDF | `DownloadPdfAsync(url)` |
| Verifică webhook | `Client.VerifyWebhook(body, signature, secret)` |
| Deschide cont (partener) | `CreateAccountAsync(data)` |
| Starea contului (partener) | `GetAccountAsync(cui)` |
| Link de activare nou (partener) | `ReissueActivationAsync(cui)` |

Comportament: repetă singur la `429` și `503`, după eroare de rețea repetă doar citirile, refuză adrese care nu sunt `https` (în afară de `localhost`) și nu urmează redirectări.

Câmpurile sunt în [referința API](../../docs/referinta-api.md), erorile în [erori.md](../../docs/erori.md), iar pașii în [ghidul de integrare](../../docs/ghid-integrare.md).
