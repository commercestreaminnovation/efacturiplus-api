<?php
/**
 * Settings screen under WooCommerce.
 *
 * @package eFacturiPlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
class EFacturiPlus_Settings {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter(
			'option_page_capability_efacturiplus',
			static function () {
				return 'manage_woocommerce';
			}
		);
		add_filter( 'plugin_action_links_' . plugin_basename( EFACTURIPLUS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=efacturiplus' ) ) . '">' . esc_html__( 'Setări', 'efacturiplus' ) . '</a>' );
		return $links;
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', 'eFacturiPlus', 'eFacturiPlus', 'manage_woocommerce', 'efacturiplus', array( __CLASS__, 'page' ) );
	}

	/**
	 * Register options.
	 */
	public static function register() {
		$text = array( 'sanitize_callback' => 'sanitize_text_field' );
		register_setting( 'efacturiplus', 'efacturiplus_api_key', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_key_value' ) ) );
		register_setting( 'efacturiplus', 'efacturiplus_series', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_series' ) ) );
		register_setting( 'efacturiplus', 'efacturiplus_trigger_status', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_status' ) ) );
		register_setting( 'efacturiplus', 'efacturiplus_default_vat', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_vat' ) ) );
		register_setting( 'efacturiplus', 'efacturiplus_unit', $text );
		register_setting( 'efacturiplus', 'efacturiplus_shipping_name', $text );
		register_setting( 'efacturiplus', 'efacturiplus_send_spv', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_yes' ) ) );
		register_setting( 'efacturiplus', 'efacturiplus_auto_storno', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_yes' ) ) );
	}

	/**
	 * The saved key is never printed in the page: an empty field keeps it, and only a well-formed key replaces it.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function sanitize_key_value( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( preg_match( '/^efp_[0-9a-f]{48}$/', $value ) ) {
			return $value;
		}
		if ( '' !== $value ) {
			add_settings_error( 'efacturiplus_api_key', 'efacturiplus_bad_key', __( 'Cheia API nu are formatul corect.', 'efacturiplus' ) );
		}
		return (string) get_option( 'efacturiplus_api_key', '' );
	}

	/**
	 * Series: same characters the API accepts.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function sanitize_series( $value ) {
		$value = (string) preg_replace( '/[^A-Za-z0-9\-\/]/', '', (string) $value );
		return '' === $value ? 'WEB' : substr( $value, 0, 20 );
	}

	/**
	 * Default VAT rate: only rates that exist.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	public static function sanitize_vat( $value ) {
		return in_array( (int) $value, array( 21, 11 ), true ) ? (int) $value : 21;
	}

	/**
	 * Trigger status.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function sanitize_status( $value ) {
		return in_array( $value, array( 'processing', 'completed', 'manual' ), true ) ? $value : 'processing';
	}

	/**
	 * Checkbox.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function sanitize_yes( $value ) {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * Connection box: what the key can do right now.
	 */
	private static function connection() {
		if ( '' === get_option( 'efacturiplus_api_key', '' ) ) {
			echo '<div class="notice notice-info inline"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: link to eFacturiPlus. */
					__( 'Creează cheia în contul tău eFacturiPlus, la <a href="%s" target="_blank" rel="noopener">Firma mea → Integrări și API</a>.', 'efacturiplus' ),
					'https://www.efacturiplus.ro/app/integrari'
				)
			) . '</p></div>';
			return;
		}
		$result = ( new EFacturiPlus_Client() )->account();
		if ( ! $result['ok'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $result['error'] ) . '</p></div>';
			return;
		}
		$body    = $result['body'];
		$lines   = array();
		$lines[] = sprintf(
			/* translators: 1: company name, 2: tax code. */
			__( 'Conectat la %1$s (CUI %2$s).', 'efacturiplus' ),
			$body['company']['name'],
			$body['company']['cui']
		);
		$lines[] = $body['company']['platitor_tva'] ? __( 'Firma este plătitoare de TVA.', 'efacturiplus' ) : __( 'Firma nu este plătitoare de TVA: facturile pleacă fără TVA.', 'efacturiplus' );
		if ( ! $body['account']['can_invoice'] ) {
			$lines[] = __( 'Contul nu poate emite facturi: pornește sau prelungește abonamentul în eFacturiPlus.', 'efacturiplus' );
		}
		if ( ! $body['spv']['connected'] ) {
			$lines[] = __( 'Firma nu este conectată la SPV: facturile nu pot pleca la ANAF până nu autorizezi aplicația.', 'efacturiplus' );
		}
		$type = $body['account']['can_invoice'] ? 'success' : 'error';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . wp_kses_post( implode( '<br>', array_map( 'esc_html', $lines ) ) ) . '</p></div>';
	}

	/**
	 * Settings page.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$status = get_option( 'efacturiplus_trigger_status', 'processing' );
		?>
		<div class="wrap">
			<h1>eFacturiPlus</h1>
			<?php self::connection(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'efacturiplus' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="efacturiplus_api_key"><?php esc_html_e( 'Cheia API', 'efacturiplus' ); ?></label></th>
						<td><input type="password" autocomplete="new-password" class="regular-text" id="efacturiplus_api_key" name="efacturiplus_api_key" value="" placeholder="<?php echo '' === get_option( 'efacturiplus_api_key', '' ) ? '' : esc_attr__( 'Salvată. Completează doar ca să o schimbi.', 'efacturiplus' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="efacturiplus_series"><?php esc_html_e( 'Seria facturilor', 'efacturiplus' ); ?></label></th>
						<td><input class="small-text" id="efacturiplus_series" name="efacturiplus_series" value="<?php echo esc_attr( get_option( 'efacturiplus_series', 'WEB' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Dacă seria nu există în cont, se creează la prima factură. Numărul îl dă eFacturiPlus.', 'efacturiplus' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="efacturiplus_trigger_status"><?php esc_html_e( 'Emite factura când', 'efacturiplus' ); ?></label></th>
						<td><select id="efacturiplus_trigger_status" name="efacturiplus_trigger_status">
							<option value="processing" <?php selected( $status, 'processing' ); ?>><?php esc_html_e( 'comanda intră în procesare (plată primită)', 'efacturiplus' ); ?></option>
							<option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'comanda este finalizată', 'efacturiplus' ); ?></option>
							<option value="manual" <?php selected( $status, 'manual' ); ?>><?php esc_html_e( 'doar manual, din comandă', 'efacturiplus' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="efacturiplus_default_vat"><?php esc_html_e( 'Cota TVA implicită', 'efacturiplus' ); ?></label></th>
						<td><input type="number" class="small-text" id="efacturiplus_default_vat" name="efacturiplus_default_vat" value="<?php echo esc_attr( get_option( 'efacturiplus_default_vat', 21 ) ); ?>"> %
						<p class="description"><?php esc_html_e( 'Se folosește când taxele nu sunt activate în WooCommerce și prețurile conțin deja TVA.', 'efacturiplus' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="efacturiplus_unit"><?php esc_html_e( 'Unitate de măsură', 'efacturiplus' ); ?></label></th>
						<td><input class="small-text" id="efacturiplus_unit" name="efacturiplus_unit" value="<?php echo esc_attr( get_option( 'efacturiplus_unit', 'buc' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="efacturiplus_shipping_name"><?php esc_html_e( 'Nume linie transport', 'efacturiplus' ); ?></label></th>
						<td><input class="regular-text" id="efacturiplus_shipping_name" name="efacturiplus_shipping_name" value="<?php echo esc_attr( get_option( 'efacturiplus_shipping_name', 'Transport' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'SPV', 'efacturiplus' ); ?></th>
						<td><label><input type="checkbox" name="efacturiplus_send_spv" value="yes" <?php checked( get_option( 'efacturiplus_send_spv', 'no' ), 'yes' ); ?>> <?php esc_html_e( 'Trimite fiecare factură în SPV imediat după emitere', 'efacturiplus' ); ?></label>
						<p class="description"><?php esc_html_e( 'Trimiterea la ANAF nu se poate retrage. Lasă opțiunea oprită până verifici primele facturi.', 'efacturiplus' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Retururi', 'efacturiplus' ); ?></th>
						<td><label><input type="checkbox" name="efacturiplus_auto_storno" value="yes" <?php checked( get_option( 'efacturiplus_auto_storno', 'no' ), 'yes' ); ?>> <?php esc_html_e( 'Stornează factura când comanda este rambursată integral', 'efacturiplus' ); ?></label>
						<p class="description"><?php esc_html_e( 'Rambursările parțiale se tratează manual, în eFacturiPlus.', 'efacturiplus' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
