using System.Net;
using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace EFacturiPlus;

/// <summary>Raspuns de eroare. Code e stabil, pentru program; Message e pentru oameni.</summary>
public class ApiException : Exception
{
    public int Status { get; }
    public string? Code { get; }
    public JsonNode? Body { get; }

    public ApiException(string message, int status = 0, string? code = null, JsonNode? body = null) : base(message)
    {
        Status = status;
        Code = code;
        Body = body;
    }

    public JsonNode? Errors => Body?["errors"];
}

/// <summary>Client pentru API-ul public eFacturiPlus. .NET 8+, fara dependente. Se poate tine pe toata durata aplicatiei.</summary>
public sealed class Client : IDisposable
{
    public const string DefaultUrl = "https://www.efacturiplus.ro/app/api/parteneri/v1";
    public const string Version = "1.0.0";

    private readonly string _apiKey;
    private readonly string _baseUrl;
    private readonly int _retries;
    private readonly HttpClient _http;

    public Client(string apiKey, string baseUrl = DefaultUrl, TimeSpan? timeout = null, int retries = 3)
    {
        if (!baseUrl.StartsWith("https://") && !Regex.IsMatch(baseUrl, @"^http://(127\.0\.0\.1|localhost)(:|/|$)"))
        {
            throw new ApiException("Adresa API trebuie să fie https: cheia nu se trimite necriptat.");
        }
        _apiKey = apiKey;
        _baseUrl = baseUrl.TrimEnd('/');
        _retries = retries;
        _http = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false }) { Timeout = timeout ?? TimeSpan.FromSeconds(25) };
    }

    public Task<JsonNode> AccountAsync(CancellationToken ct = default) => RequestAsync(HttpMethod.Get, "/cont", null, ct);

    /// <summary>Emite o factura. Trimite external_id, ca o repetare sa nu faca a doua factura.</summary>
    public Task<JsonNode> CreateInvoiceAsync(object invoice, CancellationToken ct = default) => RequestAsync(HttpMethod.Post, "/facturi", invoice, ct);

    public Task<JsonNode> GetInvoiceAsync(long invoiceId, CancellationToken ct = default) => RequestAsync(HttpMethod.Get, $"/facturi?invoice_id={invoiceId}", null, ct);

    /// <summary>Cauta dupa serie si numar. cui e obligatoriu doar pentru cheile de partener.</summary>
    public Task<JsonNode> FindInvoiceAsync(string serie, long numar, string? cui = null, CancellationToken ct = default) =>
        RequestAsync(HttpMethod.Get, $"/facturi?serie={Uri.EscapeDataString(serie)}&numar={numar}" + (cui is null ? "" : $"&cui={Uri.EscapeDataString(cui)}"), null, ct);

    /// <summary>Pune factura in coada SPV. Trimiterea la ANAF nu se poate retrage.</summary>
    public Task<JsonNode> SendToSpvAsync(long invoiceId, CancellationToken ct = default) => RequestAsync(HttpMethod.Post, $"/facturi/{invoiceId}/spv", new { }, ct);

    /// <summary>Stornare integrala. Stornarea trebuie trimisa separat in SPV.</summary>
    public Task<JsonNode> StornoAsync(long invoiceId, CancellationToken ct = default) => RequestAsync(HttpMethod.Post, $"/facturi/{invoiceId}/storno", new { }, ct);

    public Task<JsonNode> CreateAccountAsync(object account, CancellationToken ct = default) => RequestAsync(HttpMethod.Post, "/conturi", account, ct);

    public Task<JsonNode> GetAccountAsync(string cui, CancellationToken ct = default) => RequestAsync(HttpMethod.Get, "/conturi/" + Uri.EscapeDataString(cui), null, ct);

    public Task<JsonNode> ReissueActivationAsync(string cui, CancellationToken ct = default) => RequestAsync(HttpMethod.Post, "/conturi/" + Uri.EscapeDataString(cui) + "/activare", new { }, ct);

    /// <summary>Descarca PDF-ul din pdf_url.</summary>
    public async Task<byte[]> DownloadPdfAsync(string pdfUrl, CancellationToken ct = default)
    {
        var a = new Uri(pdfUrl);
        var b = new Uri(_baseUrl);
        if (a.Scheme != b.Scheme || a.Authority != b.Authority)
        {
            throw new ApiException("Linkul PDF nu este de pe adresa API.");
        }
        using var response = await SendAsync(HttpMethod.Get, pdfUrl, null, false, ct);
        var data = await response.Content.ReadAsByteArrayAsync(ct);
        if (response.StatusCode != HttpStatusCode.OK || data.Length < 4 || Encoding.ASCII.GetString(data, 0, 4) != "%PDF")
        {
            throw new ApiException($"PDF indisponibil (HTTP {(int)response.StatusCode}).", (int)response.StatusCode);
        }
        return data;
    }

    /// <summary>Verifica un webhook: corpul brut si antetul X-Signature.</summary>
    public static bool VerifyWebhook(string body, string signature, string secret, int toleranceSeconds = 300, long? now = null)
    {
        var match = Regex.Match(signature ?? "", @"\At=(\d+),v1=([0-9a-f]{64})\z");
        if (!match.Success || Math.Abs((now ?? DateTimeOffset.UtcNow.ToUnixTimeSeconds()) - long.Parse(match.Groups[1].Value)) > toleranceSeconds)
        {
            return false;
        }
        var expected = HMACSHA256.HashData(Encoding.UTF8.GetBytes(secret), Encoding.UTF8.GetBytes(match.Groups[1].Value + "." + body));
        return CryptographicOperations.FixedTimeEquals(expected, Convert.FromHexString(match.Groups[2].Value));
    }

    public void Dispose() => _http.Dispose();

    private async Task<JsonNode> RequestAsync(HttpMethod method, string path, object? body, CancellationToken ct)
    {
        for (var attempt = 0; ; attempt++)
        {
            HttpResponseMessage response;
            try
            {
                response = await SendAsync(method, _baseUrl + path, body, true, ct);
            }
            catch (Exception e) when (e is HttpRequestException or TaskCanceledException && !ct.IsCancellationRequested)
            {
                // Fara raspuns nu stim daca s-a scris ceva: se repeta doar citirile.
                if (method == HttpMethod.Get && attempt < _retries)
                {
                    await Task.Delay(Backoff(attempt, null), ct);
                    continue;
                }
                throw new ApiException(e.Message);
            }
            using (response)
            {
                var status = (int)response.StatusCode;
                if (status is 429 or 503 && attempt < _retries)
                {
                    await Task.Delay(Backoff(attempt, response.Headers.RetryAfter?.Delta), ct);
                    continue;
                }
                JsonNode? data = null;
                try
                {
                    data = JsonNode.Parse(await response.Content.ReadAsStringAsync(ct));
                }
                catch (JsonException)
                {
                }
                if (data is not JsonObject || data["success"]?.GetValue<bool>() != true)
                {
                    throw new ApiException(data?["message"]?.GetValue<string>() ?? $"HTTP {status}", status, data?["code"]?.GetValue<string>(), data);
                }
                return data;
            }
        }
    }

    private Task<HttpResponseMessage> SendAsync(HttpMethod method, string url, object? body, bool withKey, CancellationToken ct)
    {
        var request = new HttpRequestMessage(method, url);
        request.Headers.TryAddWithoutValidation("Accept", "application/json, application/pdf");
        request.Headers.TryAddWithoutValidation("User-Agent", "efacturiplus-dotnet/" + Version);
        if (withKey)
        {
            request.Headers.TryAddWithoutValidation("X-Api-Key", _apiKey);
        }
        if (body is not null)
        {
            request.Content = JsonContent.Create(body, body.GetType());
        }
        return _http.SendAsync(request, ct);
    }

    private static TimeSpan Backoff(int attempt, TimeSpan? retryAfter) => retryAfter ?? TimeSpan.FromMilliseconds(500 * Math.Pow(2, attempt));
}
