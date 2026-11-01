# Client Ruby

Ruby 3.0+, fără dependențe.

```ruby
# Gemfile
gem 'efacturiplus', github: 'commercestreaminnovation/efacturiplus-api', glob: 'sdks/ruby/*.gemspec'
```

```ruby
require 'efacturiplus'

client = EFacturiPlus::Client.new(ENV.fetch('EFP_KEY'))

begin
  r = client.create_invoice(
    serie_factura: 'WEB',
    data_factura: Time.now.strftime('%F'),
    external_id: 'comanda-1001',
    customer_name: 'MOLDOTEX VASLUI SRL',
    customer_cui: 'RO14399840',
    customer_address: 'Str. Ștefan cel Mare 1',
    customer_loc: 'Vaslui',
    customer_jud: 'Vaslui',
    products: [{ name: 'Tricou', quantity: 2, price: 121, cota_tva: 21 }]
  )
  puts r['invoice']['label']
rescue EFacturiPlus::ApiError => e
  puts "#{e.status} #{e.code} #{e.message}"
end
```

Opțiuni: `Client.new(key, base_url: ..., timeout: 25, retries: 3)`.

## Ce oferă toate metodele

| Ce faci | Metodă |
|---|---|
| Firma și starea contului | `account` |
| Emite o factură | `create_invoice(invoice)` |
| Citește o factură | `get_invoice(id)` |
| Caută după serie și număr | `find_invoice(serie, numar, cui:)` |
| Pune în coada SPV | `send_to_spv(id)` |
| Stornează | `storno(id)` |
| Descarcă PDF | `download_pdf(url)` |
| Verifică webhook | `EFacturiPlus.verify_webhook(body, signature, secret)` |
| Deschide cont (partener) | `create_account(data)` |
| Starea contului (partener) | `get_account(cui)` |
| Link de activare nou (partener) | `reissue_activation(cui)` |

Comportament: repetă singur la `429` și `503`, după eroare de rețea repetă doar citirile, refuză adrese care nu sunt `https` (în afară de `localhost`) și nu urmează redirectări.

Câmpurile sunt în [referința API](../../docs/referinta-api.md), erorile în [erori.md](../../docs/erori.md), iar pașii în [ghidul de integrare](../../docs/ghid-integrare.md).
