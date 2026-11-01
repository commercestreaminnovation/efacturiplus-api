=== eFacturiPlus for WooCommerce ===
Contributors: commercestream
Tags: woocommerce, invoice, e-factura, anaf, romania
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Issues invoices from WooCommerce orders in eFacturiPlus and sends them to Romania's e-Factura system (SPV).

== Description ==

Facturi din comenzile WooCommerce, trimise în e-Factura (SPV).

Romanian VAT-registered and non-registered businesses must report their invoices through ANAF's e-Factura system. This plugin connects a WooCommerce shop to an eFacturiPlus account:

* issues the invoice automatically when an order reaches the status you choose, or manually from the order;
* adds company fields (tax code, trade register number) to the block and classic checkouts;
* keeps VAT rates, coupons, fees and shipping, so the invoice total equals the order total;
* never creates the same invoice twice;
* waits for confirmed payment: cash on delivery, cheque and bank transfer orders are invoiced when completed;
* sends the invoice to SPV, automatically or with one click, and shows the SPV state on the order;
* lets you reverse (storno) an invoice from the order;
* gives customers the PDF in My Account;
* works with High-Performance Order Storage and the Cart and Checkout blocks.

= External service =

This plugin requires an account at [eFacturiPlus](https://www.efacturiplus.ro), a paid invoicing service operated by CommerceStream Innovate SRL. The invoicing, the PDF and the connection to ANAF all happen in that service; without an account the plugin cannot issue invoices.

Data is sent to `https://www.efacturiplus.ro/app/api/parteneri/v1` only after you save an API key, and only when an invoice is issued, reversed, sent to SPV or refreshed, or when the settings screen checks the connection. For each invoice the plugin sends the billing name, company, tax code, address, email and phone of the customer, the order number and the order lines. eFacturiPlus sends the invoice to ANAF only if you enable that option or press the button.

* [Terms of use](https://www.efacturiplus.ro/app/site/termeni)
* [Privacy policy](https://www.efacturiplus.ro/app/site/confidentialitate)

= Limits =

* The API accepts the standard Romanian VAT rates. Orders with another rate, including 0% for exports and EU reverse charge, are not invoiced automatically: the order shows the reason and you issue that invoice in eFacturiPlus.
* Partial refunds are handled in eFacturiPlus.

= Developers =

The API is public and documented: [github.com/commercestreaminnovation/efacturiplus-api](https://github.com/commercestreaminnovation/efacturiplus-api).

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. In eFacturiPlus, open Firma mea → Integrări și API and create a key.
3. In WordPress, open WooCommerce → eFacturiPlus, paste the key and choose the invoice series.
4. Place a test order and check the invoice before turning on automatic sending to SPV.

== Frequently Asked Questions ==

= Do I need an eFacturiPlus account? =

Yes. Plans and the current trial period are listed on [the pricing page](https://www.efacturiplus.ro/app/site/preturi).

= Who gives the invoice number? =

eFacturiPlus gives the next number in the series, so numbering stays continuous.

= What happens if the service is unreachable? =

The plugin retries for about two hours. After that the order shows the error and you can issue the invoice with one click.

== Changelog ==

= 3.0.1 =
* Security: the API key is no longer printed in the settings page.
* Security: orders without confirmed payment no longer trigger an invoice automatically.
* Checkout data is sanitized before it is sent.

= 3.0.0 =
* Rewritten for the public eFacturiPlus API, with per-company keys.
* Block checkout and HPOS support.
* Reversal, SPV state and PDF from the order.
