# EFP_KEY=... python3 invoice.py
import datetime, json, os, urllib.error, urllib.request

body = {
    "serie_factura": "WEB",
    "data_factura": datetime.date.today().isoformat(),
    "external_id": "comanda-1001",
    "customer_name": "Ana Pop",
    "products": [{"name": "Tricou bumbac", "quantity": 2, "price": 121, "cota_tva": 21}],
}
req = urllib.request.Request(
    "https://www.efacturiplus.ro/app/api/parteneri/v1/facturi",
    data=json.dumps(body).encode(),
    headers={"X-Api-Key": os.environ["EFP_KEY"], "Content-Type": "application/json", "Accept": "application/json"},
)
try:
    r = json.load(urllib.request.urlopen(req))
    print(r["invoice"]["label"], r["pdf_url"])
except urllib.error.HTTPError as e:
    r = json.load(e)
    print(r["code"], r["message"])
