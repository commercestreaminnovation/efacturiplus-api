<?php
/**
 * Removes the plugin options. Order data stays: it documents invoices already issued.
 *
 * @package eFacturiPlus
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'api_key', 'series', 'trigger_status', 'default_vat', 'unit', 'shipping_name', 'send_spv', 'auto_storno' ) as $efacturiplus_option ) {
	delete_option( 'efacturiplus_' . $efacturiplus_option );
}
