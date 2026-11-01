import os, sys, datetime
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'sdks', 'python'))
from efacturiplus import Client, ApiError, verify_webhook
key, url, mock, run = (os.environ[k] for k in ('EFP_KEY', 'EFP_URL', 'EFP_MOCK', 'EFP_RUN')); S = 'SDKPY'; n = 0
def ok(c, m):
    global n
    if not c: print('FAIL ' + m); sys.exit(1)
    n += 1
def raises(f, check, m):
    try: f(); ok(False, m)
    except ApiError as e: ok(check(e), m)
c = Client(key, url); d = datetime.date.today().isoformat()
ok(c.account()['company']['name'], 'cont')
inv = lambda e: dict(serie_factura=S, data_factura=d, external_id=e, customer_name="O'Neil Ștefan SRL", customer_address='Str. Ă 1', customer_loc='Vaslui', customer_jud='Vaslui', products=[dict(name='Tricou "alb"', quantity=2, price=121, cota_tva=21, um='buc')])
a = c.create_invoice(inv(f'{S}-{run}-1')); ok(a['invoice_id'] > 0 and a['invoice']['total'] == 242, 'creare')
ok(c.create_invoice(inv(f'{S}-{run}-1')).get('duplicate'), 'dublura')
ok(c.get_invoice(a['invoice_id'])['invoice_id'] == a['invoice_id'], 'citire')
ok(c.find_invoice(a['invoice']['serie'], a['invoice']['numar'])['invoice_id'] == a['invoice_id'], 'cautare')
ok(c.download_pdf(a['pdf_url'])[:4] == b'%PDF', 'pdf')
raises(lambda: c.download_pdf('https://rau.example/x.pdf'), lambda e: True, 'pdf strain')
raises(lambda: c.create_invoice(dict(serie_factura=S, data_factura=d, products=[])), lambda e: e.status == 422 and e.code == 'invalid' and e.errors, 'invalid')
raises(lambda: Client('efp_gresita', url).get_invoice(1), lambda e: e.status == 401 and e.code == 'unauthenticated', 'cheie')
raises(lambda: Client('k', 'http://exemplu.ro'), lambda e: True, 'http')
ok(c.send_to_spv(a['invoice_id'])['success'], 'spv')
b2 = c.create_invoice(inv(f'{S}-{run}-2')); ok(c.storno(b2['invoice_id'])['invoice_id'] != b2['invoice_id'], 'storno'); ok(c.storno(b2['invoice_id']).get('duplicate'), 'storno dublu')
body = '{"id":41,"event":"factura.actualizata","nume":"Ștefan"}'; sig = 't=1730000000,v1=1e45532b6f5617891f61b00b3d4e6fca8cbc0ba7e2a1f7f0a6d70abe53543a11'
ok(verify_webhook(body, sig, 'whsec_test', now=1730000100), 'webhook')
ok(not verify_webhook(body + 'x', sig, 'whsec_test', now=1730000100), 'webhook corp')
ok(not verify_webhook(body, sig, 'alt', now=1730000100), 'webhook secret')
ok(not verify_webhook(body, sig, 'whsec_test', now=1730009999), 'webhook expirat')
ok(not verify_webhook(body, 'gunoi', 'whsec_test'), 'webhook format')
ok(Client('k', mock + '/retry').account()['attempts'] >= 3, 'reincercare')
raises(lambda: Client('k', mock + '/redir').account(), lambda e: True, 'redirect')
print('OK', n)
