// EFP_KEY=... node invoice.mjs
const base = 'https://www.efacturiplus.ro/app/api/parteneri/v1';
const res = await fetch(`${base}/facturi`, {
  method: 'POST',
  headers: {'X-Api-Key': process.env.EFP_KEY, 'Content-Type': 'application/json', Accept: 'application/json'},
  body: JSON.stringify({
    serie_factura: 'WEB',
    data_factura: new Date().toISOString().slice(0, 10),
    external_id: 'comanda-1001',
    customer_name: 'Ana Pop',
    products: [{name: 'Tricou bumbac', quantity: 2, price: 121, cota_tva: 21}],
  }),
});
const r = await res.json();
console.log(r.success ? `${r.invoice.label} ${r.pdf_url}` : `${r.code}: ${r.message}`);
