// Package efacturiplus e clientul pentru API-ul public eFacturiPlus: facturare si e-Factura (SPV).
package efacturiplus

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"
)

const (
	DefaultURL = "https://www.efacturiplus.ro/app/api/parteneri/v1"
	Version    = "1.1.0"
)

// M e un obiect JSON: datele trimise si raspunsurile.
type M = map[string]any

// ApiError e un raspuns de eroare. Code e stabil, pentru program; Message e pentru oameni.
type ApiError struct {
	Message string
	Status  int
	Code    string
	Body    M
}

func (e *ApiError) Error() string { return e.Message }

// Client vorbeste cu API-ul. Se foloseste din mai multe gorutine fara probleme.
type Client struct {
	APIKey  string
	BaseURL string
	Retries int
	HTTP    *http.Client
}

var localURL = regexp.MustCompile(`^http://(127\.0\.0\.1|localhost)(:|/|$)`)

// New face un client spre adresa de productie.
func New(apiKey string) *Client {
	c, _ := NewWithURL(apiKey, DefaultURL)
	return c
}

// NewWithURL refuza orice adresa care nu e https, in afara de cele locale.
func NewWithURL(apiKey, baseURL string) (*Client, error) {
	if !strings.HasPrefix(baseURL, "https://") && !localURL.MatchString(baseURL) {
		return nil, &ApiError{Message: "Adresa API trebuie să fie https: cheia nu se trimite necriptat."}
	}
	return &Client{
		APIKey: apiKey, BaseURL: strings.TrimRight(baseURL, "/"), Retries: 3,
		HTTP: &http.Client{Timeout: 25 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error {
			return http.ErrUseLastResponse
		}},
	}, nil
}

// Account intoarce firma cheii, starea contului, seriile si cotele de TVA.
func (c *Client) Account(ctx context.Context) (M, error) { return c.request(ctx, "GET", "/cont", nil) }

// CreateInvoice emite o factura. Trimite external_id, ca o repetare sa nu faca a doua factura.
func (c *Client) CreateInvoice(ctx context.Context, invoice M) (M, error) {
	return c.request(ctx, "POST", "/facturi", invoice)
}

// GetInvoice intoarce starea unei facturi, cu link PDF nou.
func (c *Client) GetInvoice(ctx context.Context, id int64) (M, error) {
	return c.request(ctx, "GET", fmt.Sprintf("/facturi?invoice_id=%d", id), nil)
}

// FindInvoice cauta dupa serie si numar. cui e obligatoriu doar pentru cheile de partener.
func (c *Client) FindInvoice(ctx context.Context, serie string, numar int64, cui string) (M, error) {
	q := url.Values{"serie": {serie}, "numar": {strconv.FormatInt(numar, 10)}}
	if cui != "" {
		q.Set("cui", cui)
	}
	return c.request(ctx, "GET", "/facturi?"+q.Encode(), nil)
}

func query(filters map[string]string) string {
	q := url.Values{}
	for k, v := range filters {
		q.Set(k, v)
	}
	return q.Encode()
}

// ListInvoices cu filtrele de_la, pana_la, pagina, per_pagina; cui pentru cheile de partener.
func (c *Client) ListInvoices(ctx context.Context, filters map[string]string) (M, error) {
	return c.request(ctx, "GET", "/facturi/lista?"+query(filters), nil)
}

// ListCustomers cu filtrele cauta, pagina, per_pagina.
func (c *Client) ListCustomers(ctx context.Context, filters map[string]string) (M, error) {
	return c.request(ctx, "GET", "/clienti?"+query(filters), nil)
}

// ListProducts cu filtrele cauta, pagina, per_pagina.
func (c *Client) ListProducts(ctx context.Context, filters map[string]string) (M, error) {
	return c.request(ctx, "GET", "/produse?"+query(filters), nil)
}

// SendToSpv pune factura in coada SPV. Trimiterea la ANAF nu se poate retrage.
func (c *Client) SendToSpv(ctx context.Context, id int64) (M, error) {
	return c.request(ctx, "POST", fmt.Sprintf("/facturi/%d/spv", id), M{})
}

// Storno face stornarea integrala. Stornarea trebuie trimisa separat in SPV.
func (c *Client) Storno(ctx context.Context, id int64) (M, error) {
	return c.request(ctx, "POST", fmt.Sprintf("/facturi/%d/storno", id), M{})
}

// CreateAccount deschide un cont pentru o firma (chei de partener).
func (c *Client) CreateAccount(ctx context.Context, account M) (M, error) {
	return c.request(ctx, "POST", "/conturi", account)
}

// GetAccount intoarce starea contului unei firme legate (chei de partener).
func (c *Client) GetAccount(ctx context.Context, cui string) (M, error) {
	return c.request(ctx, "GET", "/conturi/"+url.PathEscape(cui), nil)
}

// ReissueActivation face un link de activare nou (chei de partener).
func (c *Client) ReissueActivation(ctx context.Context, cui string) (M, error) {
	return c.request(ctx, "POST", "/conturi/"+url.PathEscape(cui)+"/activare", M{})
}

// DownloadPDF descarca PDF-ul din pdf_url.
func (c *Client) DownloadPDF(ctx context.Context, pdfURL string) ([]byte, error) {
	a, err := url.Parse(pdfURL)
	if err != nil {
		return nil, err
	}
	b, _ := url.Parse(c.BaseURL)
	if a.Scheme != b.Scheme || a.Host != b.Host {
		return nil, &ApiError{Message: "Linkul PDF nu este de pe adresa API."}
	}
	status, raw, _, err := c.send(ctx, "GET", pdfURL, nil, false)
	if err != nil {
		return nil, &ApiError{Message: err.Error()}
	}
	if status != 200 || !bytes.HasPrefix(raw, []byte("%PDF")) {
		return nil, &ApiError{Message: fmt.Sprintf("PDF indisponibil (HTTP %d).", status), Status: status}
	}
	return raw, nil
}

func (c *Client) request(ctx context.Context, method, path string, body M) (M, error) {
	for attempt := 0; ; attempt++ {
		status, raw, retryAfter, err := c.send(ctx, method, c.BaseURL+path, body, true)
		if err != nil {
			// Fara raspuns nu stim daca s-a scris ceva: se repeta doar citirile.
			if method == "GET" && attempt < c.Retries && !errors.Is(err, context.Canceled) {
				time.Sleep(backoff(attempt, ""))
				continue
			}
			return nil, &ApiError{Message: err.Error()}
		}
		if (status == 429 || status == 503) && attempt < c.Retries {
			time.Sleep(backoff(attempt, retryAfter))
			continue
		}
		data := M{}
		_ = json.Unmarshal(raw, &data)
		if ok, _ := data["success"].(bool); !ok {
			message, _ := data["message"].(string)
			if message == "" {
				message = fmt.Sprintf("HTTP %d", status)
			}
			code, _ := data["code"].(string)
			return nil, &ApiError{Message: message, Status: status, Code: code, Body: data}
		}
		return data, nil
	}
}

func (c *Client) send(ctx context.Context, method, target string, body M, withKey bool) (int, []byte, string, error) {
	var reader io.Reader
	if body != nil {
		encoded, err := json.Marshal(body)
		if err != nil {
			return 0, nil, "", err
		}
		reader = bytes.NewReader(encoded)
	}
	req, err := http.NewRequestWithContext(ctx, method, target, reader)
	if err != nil {
		return 0, nil, "", err
	}
	req.Header.Set("Accept", "application/json, application/pdf")
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", "efacturiplus-go/"+Version)
	if withKey {
		req.Header.Set("X-Api-Key", c.APIKey)
	}
	res, err := c.HTTP.Do(req)
	if err != nil {
		return 0, nil, "", err
	}
	defer res.Body.Close()
	raw, err := io.ReadAll(res.Body)
	return res.StatusCode, raw, res.Header.Get("Retry-After"), err
}

func backoff(attempt int, retryAfter string) time.Duration {
	if seconds, err := strconv.ParseFloat(retryAfter, 64); err == nil {
		return time.Duration(seconds * float64(time.Second))
	}
	return time.Duration(500*math.Pow(2, float64(attempt))) * time.Millisecond
}

var signature = regexp.MustCompile(`^t=(\d+),v1=([0-9a-f]{64})$`)

// VerifyWebhook verifica antetul X-Signature pe corpul brut. tolerance e de obicei 5 minute.
func VerifyWebhook(body []byte, header, secret string, tolerance time.Duration, now time.Time) bool {
	m := signature.FindStringSubmatch(header)
	if m == nil {
		return false
	}
	t, err := strconv.ParseInt(m[1], 10, 64)
	if err != nil || math.Abs(float64(now.Unix()-t)) > tolerance.Seconds() {
		return false
	}
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(m[1] + "."))
	mac.Write(body)
	expected, _ := hex.DecodeString(m[2])
	return hmac.Equal(mac.Sum(nil), expected)
}
