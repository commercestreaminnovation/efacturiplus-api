using EFacturiPlus;
string key = Environment.GetEnvironmentVariable("EFP_KEY")!, url = Environment.GetEnvironmentVariable("EFP_URL")!, mock = Environment.GetEnvironmentVariable("EFP_MOCK")!, run = Environment.GetEnvironmentVariable("EFP_RUN")!;
var S = "SDKCS"; var n = 0; var d = DateTime.Now.ToString("yyyy-MM-dd");
void Ok(bool c, string m) { if (!c) { Console.WriteLine("FAIL " + m); Environment.Exit(1); } n++; }
async Task Raises(Func<Task> f, Func<ApiException, bool> check, string m) { try { await f(); Ok(false, m); } catch (ApiException e) { Ok(check(e), m); } }
using var c = new Client(key, url);
Ok((await c.AccountAsync())["company"]!["name"]!.GetValue<string>() != "", "cont");
object Inv(string e) => new Dictionary<string, object> { ["serie_factura"] = S, ["data_factura"] = d, ["external_id"] = e, ["customer_name"] = "O'Neil Ștefan SRL", ["customer_address"] = "Str. Ă 1", ["customer_loc"] = "Vaslui", ["customer_jud"] = "Vaslui", ["products"] = new[] { new Dictionary<string, object> { ["name"] = "Tricou \"alb\"", ["quantity"] = 2, ["price"] = 121, ["cota_tva"] = 21, ["um"] = "buc" } } };
var a = await c.CreateInvoiceAsync(Inv($"{S}-{run}-1")); var id = a["invoice_id"]!.GetValue<long>(); Ok(a["invoice"]!["total"]!.GetValue<decimal>() == 242, "creare");
Ok((await c.CreateInvoiceAsync(Inv($"{S}-{run}-1")))["duplicate"]?.GetValue<bool>() == true, "dublura");
Ok((await c.GetInvoiceAsync(id))["invoice_id"]!.GetValue<long>() == id, "citire");
Ok((await c.FindInvoiceAsync(a["invoice"]!["serie"]!.GetValue<string>(), a["invoice"]!["numar"]!.GetValue<long>()))["invoice_id"]!.GetValue<long>() == id, "cautare");
var p = await c.DownloadPdfAsync(a["pdf_url"]!.GetValue<string>()); Ok(System.Text.Encoding.ASCII.GetString(p, 0, 4) == "%PDF", "pdf");
await Raises(() => c.DownloadPdfAsync("https://rau.example/x.pdf"), e => true, "pdf strain");
await Raises(() => c.CreateInvoiceAsync(new { serie_factura = S, data_factura = d, products = Array.Empty<object>() }), e => e.Status == 422 && e.Code == "invalid" && e.Errors != null, "invalid");
await Raises(() => new Client("efp_gresita", url).GetInvoiceAsync(1), e => e.Status == 401 && e.Code == "unauthenticated", "cheie");
await Raises(() => { new Client("k", "http://exemplu.ro"); return Task.CompletedTask; }, e => true, "http");
Ok((await c.SendToSpvAsync(id))["success"]!.GetValue<bool>(), "spv");
var b = await c.CreateInvoiceAsync(Inv($"{S}-{run}-2")); var bid = b["invoice_id"]!.GetValue<long>();
Ok((await c.StornoAsync(bid))["invoice_id"]!.GetValue<long>() != bid, "storno"); Ok((await c.StornoAsync(bid))["duplicate"]?.GetValue<bool>() == true, "storno dublu");
var body = "{\"id\":41,\"event\":\"factura.actualizata\",\"nume\":\"Ștefan\"}"; var sig = "t=1730000000,v1=1e45532b6f5617891f61b00b3d4e6fca8cbc0ba7e2a1f7f0a6d70abe53543a11";
Ok(Client.VerifyWebhook(body, sig, "whsec_test", 300, 1730000100), "webhook");
Ok(!Client.VerifyWebhook(body + "x", sig, "whsec_test", 300, 1730000100), "webhook corp");
Ok(!Client.VerifyWebhook(body, sig, "alt", 300, 1730000100), "webhook secret");
Ok(!Client.VerifyWebhook(body, sig, "whsec_test", 300, 1730009999), "webhook expirat");
Ok(!Client.VerifyWebhook(body, "gunoi", "whsec_test"), "webhook format");
Ok((await new Client("k", mock + "/retry").AccountAsync())["attempts"]!.GetValue<int>() >= 3, "reincercare");
await Raises(() => new Client("k", mock + "/redir").AccountAsync(), e => true, "redirect");
Console.WriteLine("OK " + n);
