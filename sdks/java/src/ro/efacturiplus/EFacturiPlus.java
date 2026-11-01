package ro.efacturiplus;

import java.io.IOException;
import java.net.URI;
import java.net.URLEncoder;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.security.InvalidKeyException;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.time.Duration;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

/** Client pentru API-ul public eFacturiPlus. Java 11+, fara dependente: un singur fisier. */
public final class EFacturiPlus {
    public static final String DEFAULT_URL = "https://www.efacturiplus.ro/app/api/parteneri/v1";
    public static final String VERSION = "1.1.0";

    /** Raspuns de eroare. code e stabil, pentru program; mesajul e pentru oameni. */
    public static final class ApiException extends RuntimeException {
        public final int status;
        public final String code;
        public final Map<String, Object> body;

        public ApiException(String message, int status, String code, Map<String, Object> body) {
            super(message);
            this.status = status;
            this.code = code;
            this.body = body;
        }

        /** Erorile pe campuri, la 422. */
        public Object errors() {
            return body == null ? null : body.get("errors");
        }
    }

    private final String apiKey;
    private final String baseUrl;
    private final int retries;
    private final Duration timeout;
    private final HttpClient http;

    public EFacturiPlus(String apiKey) {
        this(apiKey, DEFAULT_URL, Duration.ofSeconds(25), 3);
    }

    public EFacturiPlus(String apiKey, String baseUrl, Duration timeout, int retries) {
        if (!baseUrl.startsWith("https://") && !baseUrl.matches("^http://(127\\.0\\.0\\.1|localhost)(:|/|$).*")) {
            throw new ApiException("Adresa API trebuie să fie https: cheia nu se trimite necriptat.", 0, null, null);
        }
        this.apiKey = apiKey;
        this.baseUrl = baseUrl.replaceAll("/+$", "");
        this.timeout = timeout;
        this.retries = retries;
        this.http = HttpClient.newBuilder().followRedirects(HttpClient.Redirect.NEVER).connectTimeout(timeout).build();
    }

    public Map<String, Object> account() {
        return request("GET", "/cont", null);
    }

    /** Emite o factura. Trimite external_id, ca o repetare sa nu faca a doua factura. */
    public Map<String, Object> createInvoice(Map<String, ?> invoice) {
        return request("POST", "/facturi", invoice);
    }

    public Map<String, Object> getInvoice(long invoiceId) {
        return request("GET", "/facturi?invoice_id=" + invoiceId, null);
    }

    /** Cauta dupa serie si numar. cui e obligatoriu doar pentru cheile de partener; altfel null. */
    public Map<String, Object> findInvoice(String serie, long numar, String cui) {
        return request("GET", "/facturi?serie=" + encode(serie) + "&numar=" + numar + (cui == null ? "" : "&cui=" + encode(cui)), null);
    }

    /** Filtre: de_la, pana_la, pagina, per_pagina; cui pentru cheile de partener. */
    public Map<String, Object> listInvoices(Map<String, String> filters) {
        return request("GET", "/facturi/lista" + query(filters), null);
    }

    /** Filtre: cauta, pagina, per_pagina. */
    public Map<String, Object> listCustomers(Map<String, String> filters) {
        return request("GET", "/clienti" + query(filters), null);
    }

    /** Filtre: cauta, pagina, per_pagina. */
    public Map<String, Object> listProducts(Map<String, String> filters) {
        return request("GET", "/produse" + query(filters), null);
    }

    /** Pune factura in coada SPV. Trimiterea la ANAF nu se poate retrage. */
    public Map<String, Object> sendToSpv(long invoiceId) {
        return request("POST", "/facturi/" + invoiceId + "/spv", Map.of());
    }

    /** Stornare integrala. Stornarea trebuie trimisa separat in SPV. */
    public Map<String, Object> storno(long invoiceId) {
        return request("POST", "/facturi/" + invoiceId + "/storno", Map.of());
    }

    public Map<String, Object> createAccount(Map<String, ?> account) {
        return request("POST", "/conturi", account);
    }

    public Map<String, Object> getAccount(String cui) {
        return request("GET", "/conturi/" + encode(cui), null);
    }

    public Map<String, Object> reissueActivation(String cui) {
        return request("POST", "/conturi/" + encode(cui) + "/activare", Map.of());
    }

    /** Descarca PDF-ul din pdf_url. */
    public byte[] downloadPdf(String pdfUrl) {
        URI a = URI.create(pdfUrl);
        URI b = URI.create(baseUrl);
        if (!a.getScheme().equals(b.getScheme()) || !a.getAuthority().equals(b.getAuthority())) {
            throw new ApiException("Linkul PDF nu este de pe adresa API.", 0, null, null);
        }
        try {
            HttpResponse<byte[]> response = http.send(build("GET", pdfUrl, null, false), HttpResponse.BodyHandlers.ofByteArray());
            byte[] data = response.body();
            if (response.statusCode() != 200 || data.length < 4 || !new String(data, 0, 4, StandardCharsets.US_ASCII).equals("%PDF")) {
                throw new ApiException("PDF indisponibil (HTTP " + response.statusCode() + ").", response.statusCode(), null, null);
            }
            return data;
        } catch (IOException e) {
            throw new ApiException(e.getMessage(), 0, null, null);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            throw new ApiException("Întrerupt.", 0, null, null);
        }
    }

    /** Verifica un webhook: corpul brut si antetul X-Signature. */
    public static boolean verifyWebhook(String body, String signature, String secret, long toleranceSeconds, long now) {
        Matcher m = Pattern.compile("\\At=(\\d+),v1=([0-9a-f]{64})\\z").matcher(signature == null ? "" : signature);
        if (!m.matches() || Math.abs(now - Long.parseLong(m.group(1))) > toleranceSeconds) {
            return false;
        }
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
            byte[] hash = mac.doFinal((m.group(1) + "." + body).getBytes(StandardCharsets.UTF_8));
            StringBuilder hex = new StringBuilder();
            for (byte b : hash) {
                hex.append(String.format("%02x", b));
            }
            return MessageDigest.isEqual(hex.toString().getBytes(StandardCharsets.US_ASCII), m.group(2).getBytes(StandardCharsets.US_ASCII));
        } catch (NoSuchAlgorithmException | InvalidKeyException e) {
            throw new IllegalStateException(e);
        }
    }

    public static boolean verifyWebhook(String body, String signature, String secret) {
        return verifyWebhook(body, signature, secret, 300, System.currentTimeMillis() / 1000);
    }

    private Map<String, Object> request(String method, String path, Map<String, ?> body) {
        for (int attempt = 0;; attempt++) {
            try {
                HttpResponse<String> response = http.send(build(method, baseUrl + path, body, true), HttpResponse.BodyHandlers.ofString());
                int status = response.statusCode();
                if ((status == 429 || status == 503) && attempt < retries) {
                    String after = response.headers().firstValue("Retry-After").orElse(null);
                    Thread.sleep(after != null && after.matches("\\d+") ? Long.parseLong(after) * 1000 : backoff(attempt));
                    continue;
                }
                Map<String, Object> data = parseObject(response.body());
                if (!Boolean.TRUE.equals(data.get("success"))) {
                    Object message = data.get("message");
                    Object code = data.get("code");
                    throw new ApiException(message != null ? message.toString() : "HTTP " + status, status, code == null ? null : code.toString(), data);
                }
                return data;
            } catch (IOException e) {
                // Fara raspuns nu stim daca s-a scris ceva: se repeta doar citirile.
                if (method.equals("GET") && attempt < retries) {
                    sleep(backoff(attempt));
                    continue;
                }
                throw new ApiException(String.valueOf(e.getMessage()), 0, null, null);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                throw new ApiException("Întrerupt.", 0, null, null);
            }
        }
    }

    private HttpRequest build(String method, String url, Map<String, ?> body, boolean withKey) {
        HttpRequest.Builder b = HttpRequest.newBuilder(URI.create(url)).timeout(timeout)
            .header("Accept", "application/json, application/pdf")
            .header("Content-Type", "application/json")
            .header("User-Agent", "efacturiplus-java/" + VERSION);
        if (withKey) {
            b.header("X-Api-Key", apiKey);
        }
        return b.method(method, body == null ? HttpRequest.BodyPublishers.noBody() : HttpRequest.BodyPublishers.ofString(Json.write(body))).build();
    }

    private static String query(Map<String, String> filters) {
        StringBuilder out = new StringBuilder();
        for (Map.Entry<String, String> f : filters.entrySet()) {
            out.append(out.length() == 0 ? '?' : '&').append(encode(f.getKey())).append('=').append(encode(f.getValue()));
        }
        return out.toString();
    }

    private static long backoff(int attempt) {
        return 500L << attempt;
    }

    private static void sleep(long ms) {
        try {
            Thread.sleep(ms);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    private static String encode(String value) {
        return URLEncoder.encode(value, StandardCharsets.UTF_8).replace("+", "%20");
    }

    @SuppressWarnings("unchecked")
    private static Map<String, Object> parseObject(String text) {
        try {
            Object value = new Json(text).parse();
            return value instanceof Map ? (Map<String, Object>) value : new LinkedHashMap<>();
        } catch (IllegalArgumentException e) {
            return new LinkedHashMap<>();
        }
    }

    /** JSON minimal: Java nu are unul in biblioteca standard. Obiecte = Map, liste = List, numere = Double sau Long. */
    static final class Json {
        private final String s;
        private int i;

        Json(String s) {
            this.s = s;
        }

        Object parse() {
            Object value = value();
            space();
            if (i != s.length()) {
                throw new IllegalArgumentException("JSON invalid");
            }
            return value;
        }

        private void space() {
            while (i < s.length() && " \t\r\n".indexOf(s.charAt(i)) >= 0) {
                i++;
            }
        }

        private Object value() {
            space();
            if (i >= s.length()) {
                throw new IllegalArgumentException("JSON invalid");
            }
            char c = s.charAt(i);
            if (c == '{') {
                return object();
            }
            if (c == '[') {
                return array();
            }
            if (c == '"') {
                return string();
            }
            if (s.startsWith("true", i)) {
                i += 4;
                return Boolean.TRUE;
            }
            if (s.startsWith("false", i)) {
                i += 5;
                return Boolean.FALSE;
            }
            if (s.startsWith("null", i)) {
                i += 4;
                return null;
            }
            int start = i;
            while (i < s.length() && "+-0123456789.eE".indexOf(s.charAt(i)) >= 0) {
                i++;
            }
            String number = s.substring(start, i);
            if (number.isEmpty()) {
                throw new IllegalArgumentException("JSON invalid");
            }
            return number.matches("-?\\d+") ? (Object) Long.valueOf(number) : (Object) Double.valueOf(number);
        }

        private Map<String, Object> object() {
            Map<String, Object> out = new LinkedHashMap<>();
            i++;
            space();
            if (s.charAt(i) == '}') {
                i++;
                return out;
            }
            while (true) {
                space();
                String key = string();
                space();
                expect(':');
                out.put(key, value());
                space();
                if (s.charAt(i) == ',') {
                    i++;
                    continue;
                }
                expect('}');
                return out;
            }
        }

        private List<Object> array() {
            List<Object> out = new ArrayList<>();
            i++;
            space();
            if (s.charAt(i) == ']') {
                i++;
                return out;
            }
            while (true) {
                out.add(value());
                space();
                if (s.charAt(i) == ',') {
                    i++;
                    continue;
                }
                expect(']');
                return out;
            }
        }

        private String string() {
            expect('"');
            StringBuilder out = new StringBuilder();
            while (true) {
                char c = s.charAt(i++);
                if (c == '"') {
                    return out.toString();
                }
                if (c != '\\') {
                    out.append(c);
                    continue;
                }
                char e = s.charAt(i++);
                switch (e) {
                    case 'n': out.append('\n'); break;
                    case 't': out.append('\t'); break;
                    case 'r': out.append('\r'); break;
                    case 'b': out.append('\b'); break;
                    case 'f': out.append('\f'); break;
                    case 'u': out.append((char) Integer.parseInt(s.substring(i, i + 4), 16)); i += 4; break;
                    default: out.append(e);
                }
            }
        }

        private void expect(char c) {
            if (i >= s.length() || s.charAt(i) != c) {
                throw new IllegalArgumentException("JSON invalid");
            }
            i++;
        }

        static String write(Object value) {
            StringBuilder out = new StringBuilder();
            write(value, out);
            return out.toString();
        }

        private static void write(Object value, StringBuilder out) {
            if (value == null || value instanceof Number || value instanceof Boolean) {
                out.append(value);
            } else if (value instanceof Map) {
                out.append('{');
                boolean first = true;
                for (Map.Entry<?, ?> e : ((Map<?, ?>) value).entrySet()) {
                    if (!first) {
                        out.append(',');
                    }
                    first = false;
                    write(String.valueOf(e.getKey()), out);
                    out.append(':');
                    write(e.getValue(), out);
                }
                out.append('}');
            } else if (value instanceof Iterable) {
                out.append('[');
                boolean first = true;
                for (Object item : (Iterable<?>) value) {
                    if (!first) {
                        out.append(',');
                    }
                    first = false;
                    write(item, out);
                }
                out.append(']');
            } else {
                out.append('"');
                for (char c : value.toString().toCharArray()) {
                    if (c == '"' || c == '\\') {
                        out.append('\\').append(c);
                    } else if (c < 0x20) {
                        out.append(String.format("\\u%04x", (int) c));
                    } else {
                        out.append(c);
                    }
                }
                out.append('"');
            }
        }
    }
}
