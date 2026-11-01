# Client Go

Go 1.21+, doar biblioteca standard.

```bash
go get github.com/commercestreaminnovation/efacturiplus-api/sdks/go@latest
```

```go
package main

import (
	"context"
	"errors"
	"fmt"
	"os"
	"time"

	efp "github.com/commercestreaminnovation/efacturiplus-api/sdks/go"
)

func main() {
	client := efp.New(os.Getenv("EFP_KEY"))
	r, err := client.CreateInvoice(context.Background(), efp.M{
		"serie_factura":    "WEB",
		"data_factura":     time.Now().Format("2006-01-02"),
		"external_id":      "comanda-1001",
		"customer_name":    "MOLDOTEX VASLUI SRL",
		"customer_cui":     "RO14399840",
		"customer_address": "Str. Ștefan cel Mare 1",
		"customer_loc":     "Vaslui",
		"customer_jud":     "Vaslui",
		"products":         []efp.M{{"name": "Tricou", "quantity": 2, "price": 121, "cota_tva": 21}},
	})
	var apiErr *efp.ApiError
	if errors.As(err, &apiErr) {
		fmt.Println(apiErr.Status, apiErr.Code, apiErr.Message)
		return
	}
	fmt.Println(r["invoice"].(efp.M)["label"])
}
```

`efp.M` e `map[string]any`, iar numerele din răspuns sunt `float64`.

Adresă locală: `efp.NewWithURL(key, url)`. Timpul maxim se schimbă din `client.HTTP.Timeout`, iar reîncercările din `client.Retries`.

## Ce oferă toate metodele

| Ce faci | Metodă |
|---|---|
| Firma și starea contului | `Account(ctx)` |
| Emite o factură | `CreateInvoice(ctx, invoice)` |
| Citește o factură | `GetInvoice(ctx, id)` |
| Caută după serie și număr | `FindInvoice(ctx, serie, numar, cui)` |
| Pune în coada SPV | `SendToSpv(ctx, id)` |
| Stornează | `Storno(ctx, id)` |
| Descarcă PDF | `DownloadPDF(ctx, url)` |
| Verifică webhook | `VerifyWebhook(body, header, secret, tolerance, now)` |
| Deschide cont (partener) | `CreateAccount(ctx, data)` |
| Starea contului (partener) | `GetAccount(ctx, cui)` |
| Link de activare nou (partener) | `ReissueActivation(ctx, cui)` |

Comportament: repetă singur la `429` și `503`, după eroare de rețea repetă doar citirile, refuză adrese care nu sunt `https` (în afară de `localhost`) și nu urmează redirectări.

Câmpurile sunt în [referința API](../../docs/referinta-api.md), erorile în [erori.md](../../docs/erori.md), iar pașii în [ghidul de integrare](../../docs/ghid-integrare.md).
