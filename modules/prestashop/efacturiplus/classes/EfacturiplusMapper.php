<?php
/**
 * Turns a PrestaShop order into an eFacturiPlus invoice payload.
 *
 * @author    CommerceStream Innovate SRL
 * @copyright 2026 CommerceStream Innovate SRL
 * @license   https://opensource.org/licenses/MIT MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EfacturiplusMapper
{
    /**
     * Prices go out gross, from the order totals, so the invoice total matches the order total.
     * Order-level discounts have no VAT rate of their own: they are split over the rates of the order.
     *
     * @throws RuntimeException when a line has a VAT rate the API does not accept
     */
    public static function invoice(Order $order, array $account)
    {
        $payer = !empty($account['company']['platitor_tva']);
        $rates = isset($account['vat_rates']) ? array_map('intval', (array) $account['vat_rates']) : [21, 11];
        $default = (int) Configuration::get('EFACTURIPLUS_DEFAULT_VAT');
        $unit = (string) Configuration::get('EFACTURIPLUS_UNIT') ?: 'buc';
        $lines = [];
        $byRate = [];

        foreach ($order->getProducts() as $p) {
            $gross = (float) $p['total_price_tax_incl'];
            $net = (float) $p['total_price_tax_excl'];
            $qty = (float) $p['product_quantity'];
            $rate = $payer ? self::rate($net, $gross - $net, $rates, $default, $p['product_name']) : 0;
            $lines[] = self::line($p['product_name'], $qty, $gross, $rate, $unit);
            $byRate[$rate] = ($byRate[$rate] ?? 0) + $gross;
        }

        $shipping = (float) $order->total_shipping_tax_incl;
        if ($shipping > 0) {
            $net = (float) $order->total_shipping_tax_excl;
            $rate = $payer ? self::rate($net, $shipping - $net, $rates, $default, 'Transport') : 0;
            $lines[] = self::line((string) Configuration::get('EFACTURIPLUS_SHIPPING_NAME') ?: 'Transport', 1, $shipping, $rate, 'buc');
            $byRate[$rate] = ($byRate[$rate] ?? 0) + $shipping;
        }

        $wrapping = (float) $order->total_wrapping_tax_incl;
        if ($wrapping > 0) {
            $net = (float) $order->total_wrapping_tax_excl;
            $rate = $payer ? self::rate($net, $wrapping - $net, $rates, $default, 'Ambalaj') : 0;
            $lines[] = self::line('Ambalaj', 1, $wrapping, $rate, 'buc');
            $byRate[$rate] = ($byRate[$rate] ?? 0) + $wrapping;
        }

        $discount = (float) $order->total_discounts_tax_incl;
        if ($discount > 0 && $byRate) {
            $name = (string) Configuration::get('EFACTURIPLUS_DISCOUNT_NAME') ?: 'Discount';
            $base = array_sum($byRate);
            $left = $discount;
            $keys = array_keys($byRate);
            foreach ($keys as $i => $rate) {
                $part = $i === count($keys) - 1 ? $left : round($discount * $byRate[$rate] / $base, 2);
                $left -= $part;
                if ($part > 0) {
                    $lines[] = self::line($name, -1, $part, (int) $rate, 'buc');
                }
            }
        }

        // PrestaShop can round the order total differently from its lines; the invoice follows what the customer paid.
        $sum = 0.0;
        foreach ($lines as $line) {
            $sum += $line['quantity'] * $line['price'];
        }
        $diff = round((float) $order->total_paid_tax_incl - $sum, 2);
        if ($diff != 0 && abs($diff) <= 0.05) {
            $lines[0]['price'] = round(($lines[0]['price'] * $lines[0]['quantity'] + $diff) / $lines[0]['quantity'], 6);
        }

        $today = date('Y-m-d');
        $reference = $order->reference;

        return [
            'external_id' => 'ps-' . substr(md5(Tools::getShopDomain()), 0, 8) . '-' . (int) $order->id,
            'serie_factura' => (string) Configuration::get('EFACTURIPLUS_SERIES'),
            'data_factura' => $today,
            'scadenta_date' => $today,
            'currency_name' => (new Currency((int) $order->id_currency))->iso_code,
            'mentiuni' => 'Comanda ' . $reference,
            'products' => $lines,
        ] + self::customer($order);
    }

    private static function line($name, $qty, $gross, $rate, $unit)
    {
        return [
            'name' => Tools::substr(strip_tags((string) $name), 0, 255),
            'quantity' => $qty,
            'price' => round($gross / abs($qty), 6),
            'cota_tva' => $rate,
            'um' => $unit,
        ];
    }

    /** The legal rate closest to what the shop computed; rounding on small amounts must not change the rate. */
    private static function rate($net, $tax, array $rates, $default, $name)
    {
        if ((float) $net === 0.0) {
            return $default;
        }
        if (abs($tax) < 0.005 && !Configuration::get('PS_TAX')) {
            return $default;
        }
        $computed = abs($tax / $net) * 100;
        $best = null;
        foreach ($rates as $rate) {
            if ($best === null || abs($computed - $rate) < abs($computed - $best)) {
                $best = $rate;
            }
        }
        if ($best === null || abs($computed - $best) > max(0.6, 1.0 / abs($net))) {
            throw new RuntimeException(sprintf('Linia „%s” are TVA %s%%; eFacturiPlus acceptă prin API doar cotele %s%%. Emite această factură din aplicație.', $name, number_format($computed, 2, ',', ''), implode('%, ', $rates)));
        }

        return (int) $best;
    }

    /** A company is recognised by the VAT number on the invoice address; everyone else is a private person. */
    private static function customer(Order $order)
    {
        $a = new Address((int) $order->id_address_invoice);
        $customer = new Customer((int) $order->id_customer);
        $cui = Tools::strtoupper((string) preg_replace('/\s+/', '', (string) $a->vat_number));
        $person = trim($a->firstname . ' ' . $a->lastname);
        $state = $a->id_state ? State::getNameById((int) $a->id_state) : '';
        // The DNI field refuses "/", so the trade register number usually sits in "Other".
        $regCom = trim((string) $a->other);
        $regCom = preg_match('/^[JFC]\s*\d/i', $regCom) ? $regCom : (string) $a->dni;

        $fields = [
            'customer_name' => Tools::substr($cui !== '' && $a->company ? $a->company : $person, 0, 255),
            'customer_cui' => Tools::substr($cui, 0, 20),
            'customer_reg_com' => Tools::substr($regCom, 0, 20),
            'customer_address' => Tools::substr(trim($a->address1 . ' ' . $a->address2), 0, 255),
            'customer_loc' => Tools::substr((string) $a->city, 0, 255),
            'customer_jud' => Tools::substr((string) $state, 0, 100),
            'customer_country' => Country::getIsoById((int) $a->id_country),
            'customer_postcode' => Tools::substr((string) $a->postcode, 0, 20),
            'customer_email' => Tools::substr((string) $customer->email, 0, 100),
            'customer_telephone' => Tools::substr((string) ($a->phone ?: $a->phone_mobile), 0, 20),
        ];

        // Address fields come from anonymous visitors.
        foreach ($fields as $name => $value) {
            $fields[$name] = trim(strip_tags((string) $value));
        }
        $fields['customer_cui'] = (string) preg_replace('/[^A-Z0-9 \-]/', '', $fields['customer_cui']);
        if (!Validate::isEmail($fields['customer_email'])) {
            $fields['customer_email'] = '';
        }

        return $fields;
    }
}
