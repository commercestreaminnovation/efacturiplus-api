package main

import (
	"context"
	"errors"
	"fmt"
	"os"
	"time"

	efp "github.com/commercestreaminnovation/efacturiplus-api/sdks/go"
)

var n int

func ok(c bool, m string) {
	if !c {
		fmt.Println("FAIL " + m)
		os.Exit(1)
	}
	n++
}
func f(v any) float64 { x, _ := v.(float64); return x }
func main() {
	ctx := context.Background()
	key, url, mock, run := os.Getenv("EFP_KEY"), os.Getenv("EFP_URL"), os.Getenv("EFP_MOCK"), os.Getenv("EFP_RUN")
	S := "SDKGO"
	c, _ := efp.NewWithURL(key, url)
	acc, err := c.Account(ctx)
	ok(err == nil && acc["company"].(efp.M)["name"] != "", "cont")
	d := time.Now().Format("2006-01-02")
	inv := func(e string) efp.M {
		return efp.M{"serie_factura": S, "data_factura": d, "external_id": e, "customer_name": "O'Neil Ștefan SRL", "customer_address": "Str. Ă 1", "customer_loc": "Vaslui", "customer_jud": "Vaslui",
			"products": []efp.M{{"name": "Tricou \"alb\"", "quantity": 2, "price": 121, "cota_tva": 21, "um": "buc"}}}
	}
	a, err := c.CreateInvoice(ctx, inv(S+"-"+run+"-1"))
	ok(err == nil && f(a["invoice"].(efp.M)["total"]) == 242, "creare")
	id := int64(f(a["invoice_id"]))
	r, err := c.CreateInvoice(ctx, inv(S+"-"+run+"-1"))
	ok(err == nil && r["duplicate"] == true, "dublura")
	r, err = c.GetInvoice(ctx, id)
	ok(err == nil && int64(f(r["invoice_id"])) == id, "citire")
	h := a["invoice"].(efp.M)
	r, err = c.FindInvoice(ctx, h["serie"].(string), int64(f(h["numar"])), "")
	ok(err == nil && int64(f(r["invoice_id"])) == id, "cautare")
	p, err := c.DownloadPDF(ctx, a["pdf_url"].(string))
	ok(err == nil && string(p[:4]) == "%PDF", "pdf")
	_, err = c.DownloadPDF(ctx, "https://rau.example/x.pdf")
	ok(err != nil, "pdf strain")
	_, err = c.CreateInvoice(ctx, efp.M{"serie_factura": S, "data_factura": d, "products": []efp.M{}})
	var ae *efp.ApiError
	ok(errors.As(err, &ae) && ae.Status == 422 && ae.Code == "invalid" && ae.Body["errors"] != nil, "invalid")
	bad, _ := efp.NewWithURL("efp_gresita", url)
	_, err = bad.GetInvoice(ctx, 1)
	ok(errors.As(err, &ae) && ae.Status == 401 && ae.Code == "unauthenticated", "cheie")
	_, err = efp.NewWithURL("k", "http://exemplu.ro")
	ok(err != nil, "http")
	r, err = c.SendToSpv(ctx, id)
	ok(err == nil && r["success"] == true, "spv")
	b, _ := c.CreateInvoice(ctx, inv(S+"-"+run+"-2"))
	bid := int64(f(b["invoice_id"]))
	r, err = c.Storno(ctx, bid)
	ok(err == nil && int64(f(r["invoice_id"])) != bid, "storno")
	r, err = c.Storno(ctx, bid)
	ok(err == nil && r["duplicate"] == true, "storno dublu")
	body := []byte("{\"id\":41,\"event\":\"factura.actualizata\",\"nume\":\"Ștefan\"}")
	sig := "t=1730000000,v1=1e45532b6f5617891f61b00b3d4e6fca8cbc0ba7e2a1f7f0a6d70abe53543a11"
	t := time.Unix(1730000100, 0)
	ok(efp.VerifyWebhook(body, sig, "whsec_test", 5*time.Minute, t), "webhook")
	ok(!efp.VerifyWebhook(append(body, 'x'), sig, "whsec_test", 5*time.Minute, t), "webhook corp")
	ok(!efp.VerifyWebhook(body, sig, "alt", 5*time.Minute, t), "webhook secret")
	ok(!efp.VerifyWebhook(body, sig, "whsec_test", 5*time.Minute, time.Unix(1730009999, 0)), "webhook expirat")
	ok(!efp.VerifyWebhook(body, "gunoi", "whsec_test", 5*time.Minute, t), "webhook format")
	m, _ := efp.NewWithURL("k", mock+"/retry")
	r, err = m.Account(ctx)
	ok(err == nil && f(r["attempts"]) >= 3, "reincercare")
	m, _ = efp.NewWithURL("k", mock+"/redir")
	_, err = m.Account(ctx)
	ok(err != nil, "redirect")
	r, err = c.ListInvoices(ctx, map[string]string{"per_pagina": "5"})
	ok(err == nil && f(r["total"]) >= 1, "lista")
	r, err = c.ListCustomers(ctx, map[string]string{"cauta": "Neil"})
	ok(err == nil && r["customers"] != nil, "clienti")
	r, err = c.ListProducts(ctx, nil)
	ok(err == nil && r["success"] == true, "produse")
	ex := inv(S + "-" + run + "-3")
	ex["scutire_tva"], ex["pret_fara_tva"], ex["products"] = "export", true, []efp.M{{"name": "Marfa", "quantity": 1, "price": 100, "cota_tva": 0}}
	r, err = c.CreateInvoice(ctx, ex)
	ok(err == nil && f(r["invoice"].(efp.M)["tva"]) == 0, "export")
	fmt.Println("OK", n)
}
