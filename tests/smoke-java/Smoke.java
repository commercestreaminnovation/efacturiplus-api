import java.time.Duration;
import java.time.LocalDate;
import java.util.*;
import ro.efacturiplus.EFacturiPlus;
import ro.efacturiplus.EFacturiPlus.ApiException;

public class Smoke {
    static int n = 0;
    static void ok(boolean c, String m) { if (!c) { System.out.println("FAIL " + m); System.exit(1); } n++; }
    interface F { void run(); }
    interface C { boolean test(ApiException e); }
    static void raises(F f, C check, String m) { try { f.run(); ok(false, m); } catch (ApiException e) { ok(check.test(e), m); } }
    static long id(Map<String, Object> r) { return ((Number) r.get("invoice_id")).longValue(); }
    @SuppressWarnings("unchecked") static Map<String, Object> o(Object v) { return (Map<String, Object>) v; }
    static Map<String, Object> inv(String e) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("serie_factura", "SDKJV"); m.put("data_factura", LocalDate.now().toString()); m.put("external_id", e);
        m.put("customer_name", "O'Neil Ștefan SRL"); m.put("customer_address", "Str. Ă 1"); m.put("customer_loc", "Vaslui"); m.put("customer_jud", "Vaslui");
        m.put("products", List.of(Map.of("name", "Tricou \"alb\"", "quantity", 2, "price", 121, "cota_tva", 21, "um", "buc")));
        return m;
    }
    public static void main(String[] x) {
        String key = System.getenv("EFP_KEY"), url = System.getenv("EFP_URL"), mock = System.getenv("EFP_MOCK"), run = System.getenv("EFP_RUN"), S = "SDKJV";
        Duration t = Duration.ofSeconds(25);
        EFacturiPlus c = new EFacturiPlus(key, url, t, 3);
        ok(!o(c.account().get("company")).get("name").toString().isEmpty(), "cont");
        Map<String, Object> a = c.createInvoice(inv(S + "-" + run + "-1"));
        ok(((Number) o(a.get("invoice")).get("total")).doubleValue() == 242, "creare");
        ok(Boolean.TRUE.equals(c.createInvoice(inv(S + "-" + run + "-1")).get("duplicate")), "dublura");
        ok(id(c.getInvoice(id(a))) == id(a), "citire");
        ok(id(c.findInvoice(o(a.get("invoice")).get("serie").toString(), ((Number) o(a.get("invoice")).get("numar")).longValue(), null)) == id(a), "cautare");
        ok(new String(c.downloadPdf(a.get("pdf_url").toString()), 0, 4).equals("%PDF"), "pdf");
        raises(() -> c.downloadPdf("https://rau.example/x.pdf"), e -> true, "pdf strain");
        raises(() -> c.createInvoice(Map.of("serie_factura", S, "data_factura", LocalDate.now().toString(), "products", List.of())), e -> e.status == 422 && "invalid".equals(e.code) && e.errors() != null, "invalid");
        raises(() -> new EFacturiPlus("efp_gresita", url, t, 3).getInvoice(1), e -> e.status == 401 && "unauthenticated".equals(e.code), "cheie");
        raises(() -> new EFacturiPlus("k", "http://exemplu.ro", t, 3), e -> true, "http");
        ok(Boolean.TRUE.equals(c.sendToSpv(id(a)).get("success")), "spv");
        Map<String, Object> b = c.createInvoice(inv(S + "-" + run + "-2"));
        ok(id(c.storno(id(b))) != id(b), "storno");
        ok(Boolean.TRUE.equals(c.storno(id(b)).get("duplicate")), "storno dublu");
        String body = "{\"id\":41,\"event\":\"factura.actualizata\",\"nume\":\"Ștefan\"}", sig = "t=1730000000,v1=1e45532b6f5617891f61b00b3d4e6fca8cbc0ba7e2a1f7f0a6d70abe53543a11";
        ok(EFacturiPlus.verifyWebhook(body, sig, "whsec_test", 300, 1730000100L), "webhook");
        ok(!EFacturiPlus.verifyWebhook(body + "x", sig, "whsec_test", 300, 1730000100L), "webhook corp");
        ok(!EFacturiPlus.verifyWebhook(body, sig, "alt", 300, 1730000100L), "webhook secret");
        ok(!EFacturiPlus.verifyWebhook(body, sig, "whsec_test", 300, 1730009999L), "webhook expirat");
        ok(!EFacturiPlus.verifyWebhook(body, "gunoi", "whsec_test"), "webhook format");
        ok(((Number) new EFacturiPlus("k", mock + "/retry", t, 3).account().get("attempts")).intValue() >= 3, "reincercare");
        raises(() -> new EFacturiPlus("k", mock + "/redir", t, 3).account(), e -> true, "redirect");
        System.out.println("OK " + n);
    }
}
