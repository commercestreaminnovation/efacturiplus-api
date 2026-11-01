<?php
/**
 * HTTP client for the public eFacturiPlus API.
 *
 * @package eFacturiPlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over wp_remote_request; every method returns the same result shape.
 */
class EFacturiPlus_Client {

	const DEFAULT_URL = 'https://www.efacturiplus.ro/app/api/parteneri/v1';

	/**
	 * API key sent as X-Api-Key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string|null $api_key Key to use; defaults to the saved one.
	 */
	public function __construct( $api_key = null ) {
		$this->api_key = null === $api_key ? (string) get_option( 'efacturiplus_api_key', '' ) : $api_key;
	}

	/**
	 * Base URL. The constant exists for staging and local testing.
	 *
	 * @return string
	 */
	private function base_url() {
		$url = defined( 'EFACTURIPLUS_API_URL' ) ? EFACTURIPLUS_API_URL : self::DEFAULT_URL;
		return rtrim( (string) apply_filters( 'efacturiplus_api_url', $url ), '/' );
	}

	/**
	 * Company, account state, invoice series and VAT rates of the key.
	 *
	 * @return array
	 */
	public function account() {
		return $this->request( 'GET', '/cont' );
	}

	/**
	 * Create an invoice.
	 *
	 * @param array $payload Invoice payload.
	 * @return array
	 */
	public function create_invoice( array $payload ) {
		return $this->request( 'POST', '/facturi', $payload );
	}

	/**
	 * Current state of an invoice, with a fresh PDF link.
	 *
	 * @param int $invoice_id eFacturiPlus invoice id.
	 * @return array
	 */
	public function invoice( $invoice_id ) {
		return $this->request( 'GET', '/facturi?invoice_id=' . absint( $invoice_id ) );
	}

	/**
	 * Queue an invoice for SPV.
	 *
	 * @param int $invoice_id eFacturiPlus invoice id.
	 * @return array
	 */
	public function send_to_spv( $invoice_id ) {
		return $this->request( 'POST', '/facturi/' . absint( $invoice_id ) . '/spv', array() );
	}

	/**
	 * Reverse an invoice in full.
	 *
	 * @param int $invoice_id eFacturiPlus invoice id.
	 * @return array
	 */
	public function storno( $invoice_id ) {
		return $this->request( 'POST', '/facturi/' . absint( $invoice_id ) . '/storno', array() );
	}

	/**
	 * Perform a request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path under the base URL.
	 * @param array|null $body   JSON body.
	 * @return array{ok: bool, status: int, body: array, error: string, retry: bool}
	 */
	private function request( $method, $path, $body = null ) {
		if ( '' === $this->api_key ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'body'   => array(),
				'error'  => __( 'Cheia API nu este completată.', 'efacturiplus' ),
				'retry'  => false,
			);
		}

		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'X-Api-Key'    => $this->api_key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
				'User-Agent'   => 'eFacturiPlus-WooCommerce/' . EFACTURIPLUS_VERSION,
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( (object) $body );
		}

		$response = wp_remote_request( $this->base_url() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'body'   => array(),
				'error'  => $response->get_error_message(),
				'retry'  => true,
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : array();
		$ok     = $status >= 200 && $status < 300 && ! empty( $data['success'] );

		return array(
			'ok'     => $ok,
			'status' => $status,
			'body'   => $data,
			'error'  => $ok ? '' : ( isset( $data['message'] ) ? (string) $data['message'] : sprintf( 'HTTP %d', $status ) ),
			'retry'  => ! $ok && ( $status >= 500 || 429 === $status ),
		);
	}
}
