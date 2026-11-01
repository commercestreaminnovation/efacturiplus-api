<?php
/**
 * Company fields at checkout, for both the block and the classic checkout.
 *
 * @package eFacturiPlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tax code and trade register number are needed on invoices issued to companies.
 */
class EFacturiPlus_Checkout {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_fields' ) );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'classic_fields' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_classic_fields' ), 10, 2 );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_classic' ) );
		add_action( 'woocommerce_blocks_validate_location_order_fields', array( __CLASS__, 'validate_block' ), 10, 3 );
	}

	/**
	 * Block checkout fields (WooCommerce 8.9+).
	 */
	public static function register_block_fields() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		$fields = array(
			'company' => __( 'Denumire firmă (pentru factură pe firmă)', 'efacturiplus' ),
			'cui'     => __( 'CUI (pentru factură pe firmă)', 'efacturiplus' ),
			'reg_com' => __( 'Nr. Registrul Comerțului', 'efacturiplus' ),
		);
		foreach ( $fields as $id => $label ) {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => 'efacturiplus/' . $id,
					'label'    => $label,
					'location' => 'order',
					'type'     => 'text',
					'required' => false,
				)
			);
		}
	}

	/**
	 * Classic checkout fields.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public static function classic_fields( $fields ) {
		$fields['billing']['billing_efacturiplus_cui']     = array(
			'label'    => __( 'CUI (pentru factură pe firmă)', 'efacturiplus' ),
			'required' => false,
			'class'    => array( 'form-row-first' ),
			'priority' => 31,
		);
		$fields['billing']['billing_efacturiplus_reg_com'] = array(
			'label'    => __( 'Nr. Registrul Comerțului', 'efacturiplus' ),
			'required' => false,
			'class'    => array( 'form-row-last' ),
			'priority' => 32,
		);
		return $fields;
	}

	/**
	 * Save the classic fields. WooCommerce has already verified the checkout nonce at this point.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted checkout data.
	 */
	public static function save_classic_fields( $order, $data ) {
		foreach ( array( 'cui', 'reg_com' ) as $key ) {
			if ( ! empty( $data[ 'billing_efacturiplus_' . $key ] ) ) {
				$order->update_meta_data( '_efacturiplus_' . $key, sanitize_text_field( $data[ 'billing_efacturiplus_' . $key ] ) );
			}
		}
	}

	/**
	 * Whether a tax code has a plausible format (RO prefix optional, or another EU prefix).
	 *
	 * @param string $cui Tax code.
	 * @return bool
	 */
	public static function valid_cui( $cui ) {
		$cui = strtoupper( (string) preg_replace( '/\s+/', '', $cui ) );
		return '' === $cui || (bool) preg_match( '/^([A-Z]{2})?[0-9A-Z]{2,14}$/', $cui ) && (bool) preg_match( '/\d/', $cui );
	}

	/**
	 * Classic checkout validation. WooCommerce verifies the nonce before running this hook.
	 */
	public static function validate_classic() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WC_Checkout::process_checkout().
		$cui = isset( $_POST['billing_efacturiplus_cui'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_efacturiplus_cui'] ) ) : '';
		if ( ! self::valid_cui( $cui ) ) {
			wc_add_notice( __( 'CUI-ul nu este valid.', 'efacturiplus' ), 'error' );
		}
	}

	/**
	 * Block checkout validation.
	 *
	 * @param WP_Error $errors Errors.
	 * @param array    $fields Field values.
	 * @param string   $group  Field group.
	 */
	public static function validate_block( $errors, $fields, $group ) {
		unset( $group );
		if ( isset( $fields['efacturiplus/cui'] ) && ! self::valid_cui( $fields['efacturiplus/cui'] ) ) {
			$errors->add( 'efacturiplus_cui', __( 'CUI-ul nu este valid.', 'efacturiplus' ) );
		}
	}

	/**
	 * A checkout value from the order: block field first, then the classic one.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   company, cui or reg_com.
	 * @return string
	 */
	public static function meta( WC_Order $order, $key ) {
		$block = (string) $order->get_meta( '_wc_other/efacturiplus/' . $key );
		return trim( '' !== $block ? $block : (string) $order->get_meta( '_efacturiplus_' . $key ) );
	}
}
