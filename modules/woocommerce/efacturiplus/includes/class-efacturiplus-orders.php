<?php
/**
 * Invoice lifecycle on an order: automatic issue, retries, admin box, customer download.
 *
 * @package eFacturiPlus
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * Order integration.
 */
class EFacturiPlus_Orders {

	const ACTION      = 'efacturiplus_issue_invoice';
	const MAX_RETRIES = 4;

	/**
	 * Register hooks.
	 */
	public static function init() {
		$status = (string) get_option( 'efacturiplus_trigger_status', 'processing' );
		if ( 'manual' !== $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'schedule' ) );
			// Comenzile ramburs, transfer sau cec se factureaza abia la finalizare.
			add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'schedule' ) );
		}
		add_action( self::ACTION, array( __CLASS__, 'issue' ), 10, 2 );
		add_action( 'woocommerce_order_fully_refunded', array( __CLASS__, 'on_refund' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_post_efacturiplus_order', array( __CLASS__, 'admin_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'customer_action' ), 10, 2 );
		add_action( 'admin_post_efacturiplus_pdf', array( __CLASS__, 'pdf' ) );
	}

	/**
	 * Issue outside the checkout request, so a slow API never delays the customer.
	 *
	 * @param int $order_id Order id.
	 */
	public static function schedule( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_efacturiplus_invoice_id' ) ) {
			return;
		}
		// A cash-on-delivery order reaches "processing" without any payment, so anyone could otherwise generate fiscal documents.
		if ( 'processing' === $order->get_status() && in_array( $order->get_payment_method(), array( 'cod', 'cheque', 'bacs' ), true ) ) {
			$order->add_order_note( __( 'Plata nu e confirmată: factura se emite la finalizarea comenzii sau manual.', 'efacturiplus' ) );
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION, array( (int) $order_id, 0 ), 'efacturiplus' );
		} else {
			self::issue( $order_id, 0 );
		}
	}

	/**
	 * Create the invoice for an order. Safe to repeat: the API deduplicates on external_id.
	 *
	 * @param int $order_id Order id.
	 * @param int $attempt  Retry counter.
	 * @return bool
	 */
	public static function issue( $order_id, $attempt = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_efacturiplus_invoice_id' ) ) {
			return false;
		}

		$client  = new EFacturiPlus_Client();
		$account = $client->account();
		if ( ! $account['ok'] ) {
			return self::failed( $order, $account, $attempt );
		}

		try {
			$payload = EFacturiPlus_Mapper::invoice( $order, $account['body'] );
		} catch ( RuntimeException $e ) {
			self::record_error( $order, $e->getMessage() );
			return false;
		}

		$result = $client->create_invoice( $payload );
		if ( ! $result['ok'] ) {
			return self::failed( $order, $result, $attempt );
		}

		self::store( $order, $result['body'] );
		$order->delete_meta_data( '_efacturiplus_error' );
		/* translators: %s: invoice number. */
		$order->add_order_note( sprintf( __( 'Factura %s a fost emisă în eFacturiPlus.', 'efacturiplus' ), $result['body']['invoice']['label'] ) );
		$order->save();

		if ( 'yes' === get_option( 'efacturiplus_send_spv', 'no' ) ) {
			$spv = $client->send_to_spv( (int) $result['body']['invoice_id'] );
			if ( ! $spv['ok'] ) {
				/* translators: %s: error message. */
				$order->add_order_note( sprintf( __( 'Factura nu a putut fi pusă în coada SPV: %s', 'efacturiplus' ), $spv['error'] ) );
			}
		}
		return true;
	}

	/**
	 * Keep the invoice data on the order.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $body  API response.
	 */
	private static function store( WC_Order $order, array $body ) {
		$order->update_meta_data( '_efacturiplus_invoice_id', (int) $body['invoice_id'] );
		$order->update_meta_data( '_efacturiplus_invoice_label', (string) $body['invoice']['label'] );
		$order->update_meta_data( '_efacturiplus_spv', isset( $body['efactura']['label'] ) ? (string) $body['efactura']['label'] : '' );
	}

	/**
	 * Retry temporary failures; show everything else on the order.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $result  Client result.
	 * @param int      $attempt Retry counter.
	 * @return bool
	 */
	private static function failed( WC_Order $order, array $result, $attempt ) {
		if ( $result['retry'] && $attempt < self::MAX_RETRIES && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 300 * ( 2 ** $attempt ), self::ACTION, array( $order->get_id(), $attempt + 1 ), 'efacturiplus' );
			return false;
		}
		self::record_error( $order, $result['error'] );
		return false;
	}

	/**
	 * Show an error on the order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $error Message.
	 */
	private static function record_error( WC_Order $order, $error ) {
		$order->update_meta_data( '_efacturiplus_error', $error );
		/* translators: %s: error message. */
		$order->add_order_note( sprintf( __( 'Factura nu a fost emisă: %s', 'efacturiplus' ), $error ) );
		$order->save();
	}

	/**
	 * Reverse the invoice when the order is fully refunded, if the shop asked for it.
	 *
	 * @param int $order_id Order id.
	 */
	public static function on_refund( $order_id ) {
		if ( 'yes' !== get_option( 'efacturiplus_auto_storno', 'no' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::storno( $order );
		}
	}

	/**
	 * Reverse the invoice of an order.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function storno( WC_Order $order ) {
		$invoice_id = (int) $order->get_meta( '_efacturiplus_invoice_id' );
		if ( ! $invoice_id || $order->get_meta( '_efacturiplus_storno_id' ) ) {
			return false;
		}
		$result = ( new EFacturiPlus_Client() )->storno( $invoice_id );
		if ( ! $result['ok'] ) {
			self::record_error( $order, $result['error'] );
			return false;
		}
		$order->update_meta_data( '_efacturiplus_storno_id', (int) $result['body']['invoice_id'] );
		$order->update_meta_data( '_efacturiplus_storno_label', (string) $result['body']['invoice']['label'] );
		/* translators: %s: reversing invoice number. */
		$order->add_order_note( sprintf( __( 'Factura a fost stornată prin %s.', 'efacturiplus' ), $result['body']['invoice']['label'] ) );
		$order->save();
		if ( 'yes' === get_option( 'efacturiplus_send_spv', 'no' ) ) {
			( new EFacturiPlus_Client() )->send_to_spv( (int) $result['body']['invoice_id'] );
		}
		return true;
	}

	/**
	 * Register the order box for classic and HPOS order screens.
	 */
	public static function meta_box() {
		$hpos   = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();
		$screen = $hpos ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'efacturiplus-order', 'eFacturiPlus', array( __CLASS__, 'render_box' ), $screen, 'side', 'default' );
	}

	/**
	 * Admin action link.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $task  Task.
	 * @return string
	 */
	private static function url( WC_Order $order, $task ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'efacturiplus_order',
					'task'   => $task,
					'order'  => $order->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			'efacturiplus_' . $task . '_' . $order->get_id()
		);
	}

	/**
	 * Order box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Screen object.
	 */
	public static function render_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$label = (string) $order->get_meta( '_efacturiplus_invoice_label' );
		$error = (string) $order->get_meta( '_efacturiplus_error' );

		if ( $error ) {
			echo '<p style="color:#b32d2e">' . esc_html( $error ) . '</p>';
		}
		if ( ! $label ) {
			echo '<p>' . esc_html__( 'Comanda nu are factură.', 'efacturiplus' ) . '</p>';
			echo '<a class="button button-primary" href="' . esc_url( self::url( $order, 'issue' ) ) . '">' . esc_html__( 'Emite factura', 'efacturiplus' ) . '</a>';
			return;
		}

		/* translators: %s: invoice number. */
		echo '<p><strong>' . esc_html( sprintf( __( 'Factura %s', 'efacturiplus' ), $label ) ) . '</strong><br>' . esc_html( (string) $order->get_meta( '_efacturiplus_spv' ) ) . '</p>';
		$storno = (string) $order->get_meta( '_efacturiplus_storno_label' );
		if ( $storno ) {
			/* translators: %s: reversing invoice number. */
			echo '<p>' . esc_html( sprintf( __( 'Stornată prin %s', 'efacturiplus' ), $storno ) ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( self::url( $order, 'spv_storno' ) ) . '">' . esc_html__( 'Trimite stornarea în SPV', 'efacturiplus' ) . '</a></p>';
		}
		echo '<p><a class="button" href="' . esc_url( self::url( $order, 'pdf' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Descarcă PDF', 'efacturiplus' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( self::url( $order, 'refresh' ) ) . '">' . esc_html__( 'Actualizează', 'efacturiplus' ) . '</a></p>';
		echo '<p><a class="button" href="' . esc_url( self::url( $order, 'spv' ) ) . '">' . esc_html__( 'Trimite în SPV', 'efacturiplus' ) . '</a></p>';
		if ( ! $storno ) {
			echo '<p><a class="button-link-delete" href="' . esc_url( self::url( $order, 'storno' ) ) . '">' . esc_html__( 'Stornează factura', 'efacturiplus' ) . '</a></p>';
		}
	}

	/**
	 * Buttons from the order box.
	 */
	public static function admin_action() {
		$task     = isset( $_GET['task'] ) ? sanitize_key( wp_unslash( $_GET['task'] ) ) : '';
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		check_admin_referer( 'efacturiplus_' . $task . '_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Nu ai dreptul să faci această acțiune.', 'efacturiplus' ), 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! in_array( $task, array( 'issue', 'storno', 'spv', 'spv_storno', 'refresh', 'pdf' ), true ) ) {
			wp_die( esc_html__( 'Comanda nu există.', 'efacturiplus' ), 404 );
		}

		$client = new EFacturiPlus_Client();
		switch ( $task ) {
			case 'issue':
				$order->delete_meta_data( '_efacturiplus_error' );
				$order->save();
				self::issue( $order_id, self::MAX_RETRIES );
				break;
			case 'storno':
				self::storno( $order );
				break;
			case 'spv':
				$result = $client->send_to_spv( (int) $order->get_meta( '_efacturiplus_invoice_id' ) );
				self::refresh_from( $order, $result );
				break;
			case 'spv_storno':
				$result = $client->send_to_spv( (int) $order->get_meta( '_efacturiplus_storno_id' ) );
				if ( ! $result['ok'] ) {
					self::record_error( $order, $result['error'] );
				}
				break;
			case 'refresh':
				self::refresh_from( $order, $client->invoice( (int) $order->get_meta( '_efacturiplus_invoice_id' ) ) );
				break;
			case 'pdf':
				self::redirect_to_pdf( $order );
				return;
		}
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Update the SPV state, or show why it failed.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $result Client result.
	 */
	private static function refresh_from( WC_Order $order, array $result ) {
		if ( ! $result['ok'] ) {
			self::record_error( $order, $result['error'] );
			return;
		}
		self::store( $order, $result['body'] );
		$order->save();
	}

	/**
	 * The PDF link from the API expires, so a fresh one is requested at every click.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function redirect_to_pdf( WC_Order $order ) {
		$result = ( new EFacturiPlus_Client() )->invoice( (int) $order->get_meta( '_efacturiplus_invoice_id' ) );
		if ( ! $result['ok'] || empty( $result['body']['pdf_url'] ) ) {
			wp_die( esc_html__( 'Factura nu este disponibilă acum.', 'efacturiplus' ) );
		}
		$url = esc_url_raw( (string) $result['body']['pdf_url'], array( 'https', 'http' ) );
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The PDF is hosted by the eFacturiPlus API.
		wp_redirect( $url );
		exit;
	}

	/**
	 * Warn once when the key is missing.
	 */
	public static function admin_notice() {
		$screen = get_current_screen();
		if ( '' !== get_option( 'efacturiplus_api_key', '' ) || ! $screen || false === strpos( (string) $screen->id, 'wc-orders' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'eFacturiPlus: completează cheia API ca să emiți facturi.', 'efacturiplus' ) . '</p></div>';
	}

	/**
	 * "Invoice" action in My account.
	 *
	 * @param array    $actions Order actions.
	 * @param WC_Order $order   Order.
	 * @return array
	 */
	public static function customer_action( $actions, $order ) {
		if ( $order->get_meta( '_efacturiplus_invoice_id' ) ) {
			$actions['efacturiplus_pdf'] = array(
				'url'  => wp_nonce_url( add_query_arg( array( 'action' => 'efacturiplus_pdf', 'order' => $order->get_id() ), admin_url( 'admin-post.php' ) ), 'efacturiplus_pdf_' . $order->get_id() ),
				'name' => __( 'Factură', 'efacturiplus' ),
			);
		}
		return $actions;
	}

	/**
	 * Customer download. Only the owner of the order (or staff) gets the link.
	 */
	public static function pdf() {
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		check_admin_referer( 'efacturiplus_pdf_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order || ! ( current_user_can( 'view_order', $order_id ) || current_user_can( 'edit_shop_orders' ) ) ) {
			wp_die( esc_html__( 'Nu ai dreptul să vezi această factură.', 'efacturiplus' ), 403 );
		}
		self::redirect_to_pdf( $order );
	}
}
