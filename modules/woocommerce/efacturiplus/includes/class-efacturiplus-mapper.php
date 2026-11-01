<?php
/**
 * Turns a WooCommerce order into an eFacturiPlus invoice payload.
 *
 * @package eFacturiPlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prices go out gross (VAT included), computed from order totals, so the invoice total matches the order total.
 */
class EFacturiPlus_Mapper {

	/**
	 * Build the payload.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $account Result of GET /cont (company, vat_rates).
	 * @return array
	 * @throws RuntimeException When an order line has a VAT rate the invoice cannot carry.
	 */
	public static function invoice( WC_Order $order, array $account ) {
		$payer = ! empty( $account['company']['platitor_tva'] );
		$rates = isset( $account['vat_rates'] ) ? array_map( 'intval', (array) $account['vat_rates'] ) : array( 21, 11 );
		$lines = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$lines[] = self::line( $item->get_name(), (float) $item->get_quantity(), (float) $item->get_total(), (float) $item->get_total_tax(), self::unit(), $payer, $rates );
		}
		foreach ( $order->get_items( 'fee' ) as $item ) {
			$lines[] = self::line( $item->get_name(), 1.0, (float) $item->get_total(), (float) $item->get_total_tax(), 'buc', $payer, $rates );
		}
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$total = (float) $item->get_total();
			if ( $total > 0 ) {
				$name    = get_option( 'efacturiplus_shipping_name', 'Transport' );
				$lines[] = self::line( $name ? $name : 'Transport', 1.0, $total, (float) $item->get_total_tax(), 'buc', $payer, $rates );
			}
		}

		$today   = wp_date( 'Y-m-d' );
		$payload = array(
			'external_id'   => 'wc-' . substr( md5( home_url() ), 0, 8 ) . '-' . $order->get_id(),
			'serie_factura' => (string) get_option( 'efacturiplus_series', 'WEB' ),
			'data_factura'  => $today,
			'scadenta_date' => $today,
			'currency_name' => $order->get_currency(),
			/* translators: %s: order number. */
			'mentiuni'      => sprintf( __( 'Comanda %s', 'efacturiplus' ), $order->get_order_number() ),
			'products'      => $lines,
		);

		return $payload + self::customer( $order );
	}

	/**
	 * One invoice line. A negative amount (discount fee) becomes a negative quantity, as the API expects.
	 *
	 * @param string $name  Line name.
	 * @param float  $qty   Quantity.
	 * @param float  $net   Line total without tax.
	 * @param float  $tax   Line tax.
	 * @param string $unit  Unit.
	 * @param bool   $payer Whether the issuer is VAT registered.
	 * @param int[]  $rates VAT rates accepted by the API.
	 * @return array
	 */
	private static function line( $name, $qty, $net, $tax, $unit, $payer, array $rates ) {
		$qty   = 0.0 === $qty ? 1.0 : $qty;
		$gross = $net + $tax;
		$rate  = 0;

		if ( $payer ) {
			if ( ! wc_tax_enabled() ) {
				$rate = (int) get_option( 'efacturiplus_default_vat', 21 );
			} else {
				$rate = self::rate( $net, $tax, $rates, $name );
			}
		}
		if ( $gross < 0 ) {
			$qty   = -abs( $qty );
			$gross = abs( $gross );
		}

		return array(
			'name'     => mb_substr( wp_strip_all_tags( $name ), 0, 255 ),
			'quantity' => $qty,
			'price'    => round( $gross / abs( $qty ), 6 ),
			'cota_tva' => $rate,
			'um'       => $unit,
		);
	}

	/**
	 * The legal rate closest to what WooCommerce computed; rounding on small amounts must not change the rate.
	 *
	 * @param float  $net   Line total without tax.
	 * @param float  $tax   Line tax.
	 * @param int[]  $rates VAT rates accepted by the API.
	 * @param string $name  Line name, for the error.
	 * @return int
	 * @throws RuntimeException When the line has no usable rate.
	 */
	private static function rate( $net, $tax, array $rates, $name ) {
		if ( 0.0 === (float) $net ) {
			return (int) get_option( 'efacturiplus_default_vat', 21 );
		}
		$computed = abs( $tax / $net ) * 100;
		$best     = null;
		foreach ( $rates as $rate ) {
			if ( null === $best || abs( $computed - $rate ) < abs( $computed - $best ) ) {
				$best = $rate;
			}
		}
		// One cent of rounding on the line is tolerated; anything else is a real difference.
		$tolerance = max( 0.6, 1.0 / abs( $net ) );
		if ( null === $best || abs( $computed - $best ) > $tolerance ) {
			throw new RuntimeException(
				sprintf(
					/* translators: 1: line name, 2: VAT percent, 3: accepted VAT rates. */
					esc_html__( 'Linia „%1$s” are TVA %2$s%%; eFacturiPlus acceptă prin API doar cotele %3$s%%. Emite această factură din aplicație.', 'efacturiplus' ),
					esc_html( $name ),
					esc_html( number_format( $computed, 2, ',', '' ) ),
					esc_html( implode( '%, ', $rates ) )
				)
			);
		}
		return (int) $best;
	}

	/**
	 * Unit of measure used for products.
	 *
	 * @return string
	 */
	private static function unit() {
		$unit = (string) get_option( 'efacturiplus_unit', 'buc' );
		return '' === $unit ? 'buc' : $unit;
	}

	/**
	 * Customer fields. A company is recognised by its tax code; everyone else is a private person.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function customer( WC_Order $order ) {
		$cui     = strtoupper( (string) preg_replace( '/\s+/', '', EFacturiPlus_Checkout::meta( $order, 'cui' ) ) );
		$company = trim( $order->get_billing_company() ? $order->get_billing_company() : EFacturiPlus_Checkout::meta( $order, 'company' ) );
		$person  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$country = $order->get_billing_country();
		$state   = $order->get_billing_state();
		$states  = WC()->countries ? WC()->countries->get_states( $country ) : array();

		$fields = array(
			'customer_name'      => mb_substr( '' !== $cui && '' !== $company ? $company : $person, 0, 255 ),
			'customer_cui'       => mb_substr( $cui, 0, 20 ),
			'customer_reg_com'   => mb_substr( EFacturiPlus_Checkout::meta( $order, 'reg_com' ), 0, 20 ),
			'customer_address'   => mb_substr( trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ), 0, 255 ),
			'customer_loc'       => mb_substr( $order->get_billing_city(), 0, 255 ),
			'customer_jud'       => mb_substr( isset( $states[ $state ] ) ? $states[ $state ] : $state, 0, 100 ),
			'customer_country'   => $country,
			'customer_postcode'  => mb_substr( $order->get_billing_postcode(), 0, 20 ),
			'customer_email'     => mb_substr( $order->get_billing_email(), 0, 100 ),
			'customer_telephone' => mb_substr( $order->get_billing_phone(), 0, 20 ),
		);

		// Checkout values come from anonymous visitors.
		$fields = array_map( 'sanitize_text_field', $fields );
		if ( ! is_email( $fields['customer_email'] ) ) {
			$fields['customer_email'] = '';
		}
		$fields['customer_cui'] = (string) preg_replace( '/[^A-Z0-9 \-]/', '', $fields['customer_cui'] );
		return $fields;
	}
}
