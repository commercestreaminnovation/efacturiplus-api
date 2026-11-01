require_relative '../sdks/ruby/lib/efacturiplus'
include EFacturiPlus
key, url, mock, run = %w[EFP_KEY EFP_URL EFP_MOCK EFP_RUN].map { |k| ENV.fetch(k) }
S = 'SDKRB'; $n = 0
def ok(c, m) = c ? $n += 1 : (puts("FAIL #{m}"); exit 1)
def raises(m) ; yield; ok(false, m); rescue ApiError => e; ok(block_given? ? ($chk ? $chk.call(e) : true) : true, m); $chk = nil; end
c = Client.new(key, base_url: url); d = Time.now.strftime('%F')
ok(c.account['company']['name'], 'cont')
inv = ->(e) { { serie_factura: S, data_factura: d, external_id: e, customer_name: "O'Neil Ștefan SRL", customer_address: 'Str. Ă 1', customer_loc: 'Vaslui', customer_jud: 'Vaslui', products: [{ name: 'Tricou "alb"', quantity: 2, price: 121, cota_tva: 21, um: 'buc' }] } }
a = c.create_invoice(inv.("#{S}-#{run}-1")); ok(a['invoice_id'] > 0 && a['invoice']['total'] == 242, 'creare')
ok(c.create_invoice(inv.("#{S}-#{run}-1"))['duplicate'], 'dublura')
ok(c.get_invoice(a['invoice_id'])['invoice_id'] == a['invoice_id'], 'citire')
ok(c.find_invoice(a['invoice']['serie'], a['invoice']['numar'])['invoice_id'] == a['invoice_id'], 'cautare')
ok(c.download_pdf(a['pdf_url'])[0, 4] == '%PDF', 'pdf')
raises('pdf strain') { c.download_pdf('https://rau.example/x.pdf') }
$chk = ->(e) { e.status == 422 && e.code == 'invalid' && !e.errors.empty? }; raises('invalid') { c.create_invoice(serie_factura: S, data_factura: d, products: []) }
$chk = ->(e) { e.status == 401 && e.code == 'unauthenticated' }; raises('cheie') { Client.new('efp_gresita', base_url: url).get_invoice(1) }
raises('http') { Client.new('k', base_url: 'http://exemplu.ro') }
ok(c.send_to_spv(a['invoice_id'])['success'], 'spv')
b = c.create_invoice(inv.("#{S}-#{run}-2")); ok(c.storno(b['invoice_id'])['invoice_id'] != b['invoice_id'], 'storno'); ok(c.storno(b['invoice_id'])['duplicate'], 'storno dublu')
body = '{"id":41,"event":"factura.actualizata","nume":"Ștefan"}'; sig = 't=1730000000,v1=1e45532b6f5617891f61b00b3d4e6fca8cbc0ba7e2a1f7f0a6d70abe53543a11'
ok(EFacturiPlus.verify_webhook(body, sig, 'whsec_test', now: 1730000100), 'webhook')
ok(!EFacturiPlus.verify_webhook(body + 'x', sig, 'whsec_test', now: 1730000100), 'webhook corp')
ok(!EFacturiPlus.verify_webhook(body, sig, 'alt', now: 1730000100), 'webhook secret')
ok(!EFacturiPlus.verify_webhook(body, sig, 'whsec_test', now: 1730009999), 'webhook expirat')
ok(!EFacturiPlus.verify_webhook(body, 'gunoi', 'whsec_test'), 'webhook format')
ok(Client.new('k', base_url: "#{mock}/retry").account['attempts'] >= 3, 'reincercare')
raises('redirect') { Client.new('k', base_url: "#{mock}/redir").account }
ok(c.list_invoices(per_pagina: 5)['total'] >= 1, 'lista'); ok(c.list_customers(cauta: 'Neil').key?('customers'), 'clienti'); ok(c.list_products.key?('products'), 'produse')
e3 = c.create_invoice(inv.("#{S}-#{run}-3").merge(scutire_tva: 'export', pret_fara_tva: true, products: [{ name: 'Marfa', quantity: 1, price: 100, cota_tva: 0 }])); ok(e3['invoice']['tva'].to_f == 0, 'export')
puts "OK #{$n}"
