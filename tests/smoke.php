<?php
require __DIR__.'/../sdks/php/src/ApiException.php'; require __DIR__.'/../sdks/php/src/Client.php';
use EFacturiPlus\{Client, ApiException};
$key=getenv('EFP_KEY'); $url=getenv('EFP_URL'); $mock=getenv('EFP_MOCK'); $run=getenv('EFP_RUN'); $S='SDKPHP';
$n=0; $ok=function($c,$m) use(&$n){ if(!$c){echo "FAIL $m\n"; exit(1);} $n++; };
$c=new Client($key,$url);
$ok($c->account()['company']['name']!=='','cont');
$inv=fn($e)=>['serie_factura'=>$S,'data_factura'=>date('Y-m-d'),'external_id'=>$e,'customer_name'=>"O'Neil Ștefan SRL",'customer_address'=>'Str. Ă 1','customer_loc'=>'Vaslui','customer_jud'=>'Vaslui','products'=>[['name'=>'Tricou "alb"','quantity'=>2,'price'=>121,'cota_tva'=>21,'um'=>'buc']]];
$a=$c->createInvoice($inv("$S-$run-1")); $ok($a['invoice_id']>0 && $a['invoice']['total']==242,'creare');
$ok(!empty($c->createInvoice($inv("$S-$run-1"))['duplicate']),'dublura');
$ok($c->getInvoice($a['invoice_id'])['invoice_id']==$a['invoice_id'],'citire');
$ok($c->findInvoice($a['invoice']['serie'],$a['invoice']['numar'])['invoice_id']==$a['invoice_id'],'cautare');
$ok(str_starts_with($c->downloadPdf($a['pdf_url']),'%PDF'),'pdf');
try{$c->downloadPdf('https://rau.example/x.pdf');$ok(false,'pdf strain');}catch(ApiException $e){$n++;}
try{$c->createInvoice(['serie_factura'=>$S,'data_factura'=>date('Y-m-d'),'products'=>[]]);$ok(false,'invalid');}catch(ApiException $e){$ok($e->status==422&&$e->errorCode=='invalid'&&$e->errors(),'invalid');}
try{(new Client('efp_gresita',$url))->getInvoice(1);$ok(false,'cheie');}catch(ApiException $e){$ok($e->status==401&&$e->errorCode=='unauthenticated','cheie');}
try{new Client('k','http://exemplu.ro');$ok(false,'http');}catch(ApiException $e){$n++;}
$ok(!empty($c->sendToSpv($a['invoice_id'])['success']),'spv');
$b=$c->createInvoice($inv("$S-$run-2")); $st=$c->storno($b['invoice_id']); $ok($st['invoice_id']!=$b['invoice_id'],'storno'); $ok(!empty($c->storno($b['invoice_id'])['duplicate']),'storno dublu');
$body='{"id":41,"event":"factura.actualizata","nume":"Ștefan"}'; $sig='t=1730000000,v1=1e45532b6f5617891f61b00b3d4e6fca8cbc0ba7e2a1f7f0a6d70abe53543a11';
$ok(Client::verifyWebhook($body,$sig,'whsec_test',300,1730000100),'webhook');
$ok(!Client::verifyWebhook($body.'x',$sig,'whsec_test',300,1730000100),'webhook corp');
$ok(!Client::verifyWebhook($body,$sig,'alt',300,1730000100),'webhook secret');
$ok(!Client::verifyWebhook($body,$sig,'whsec_test',300,1730009999),'webhook expirat');
$ok(!Client::verifyWebhook($body,'gunoi','whsec_test'),'webhook format');
$ok((new Client('k',"$mock/retry"))->account()['attempts']>=3,'reincercare');
try{(new Client('k',"$mock/redir"))->account();$ok(false,'redirect');}catch(ApiException $e){$n++;}
echo "OK $n\n";
