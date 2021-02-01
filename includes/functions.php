<?php

/**
 * Get PMPro AvaTax options.
 */
function pmproava_get_options() {
	static $options = null;
	if ( $options === null ) {
		$set_options = get_option('pmproava_options');
		$set_options = is_array( $set_options ) ? $set_options : array();

		$default_address = new stdClass();
		$default_address->line1 = '';
		$default_address->line2 = '';
		$default_address->line3 = '';
		$default_address->city = '';
		$default_address->region = '';
		$default_address->postalCode = '';
		$default_address->country = '';

		$default_options = array(
			'account_number'  => '',
			'license_key'     => '',
			'environment'     => 'sandbox',
			'company_code'    => '',
			'company_address' => $default_address,
			'retroactive_tax' => 'yes',
			'site_prefix'     => 'PMPRO',
		);
		$options = array_merge( $default_options, $set_options );
	}
	return $options;
}

/**
 * Validate Avatax settings.
 */
function pmproava_options_validate($input) {
	$newinput = array();
	if ( isset($input['account_number'] ) ) {
		$newinput['account_number'] = trim( preg_replace("[^a-zA-Z0-9\-]", "", $input['account_number'] ) );
	}
	if ( isset($input['license_key'] ) ) {
		$newinput['license_key'] = trim( preg_replace("[^a-zA-Z0-9\-]", "", $input['license_key'] ) );
	}
	if ( isset($input['environment']) && $input['environment'] === 'production' ) {
		$newinput['environment'] = 'production';
	}
	if ( isset($input['company_code'] ) ) {
		$newinput['company_code'] = trim( preg_replace("[^a-zA-Z0-9\-]", "", $input['company_code'] ) );
	}
	if ( isset($input['company_address'] ) ) {
		$newinput['company_address'] = (object)$input['company_address'];
	}
	if ( isset($input['retroactive_tax']) && $input['retroactive_tax'] === 'no' ) {
		$newinput['retroactive_tax'] = 'no';
	}
	if ( isset($input['site_prefix'] ) ) {
		$newinput['site_prefix'] = trim( preg_replace("[^a-zA-Z0-9\-]", "", $input['site_prefix'] ) );
	}
	return $newinput;
}

define("PMPROAVA_GENERAL_MERCHANDISE", "P0000000");
/**
 * Get the Avalara product category for a particular level.
 *
 * @param int $level_id to get category for
 * @return string product category
 */
function pmproava_get_product_category( $level_id ) {
	$pmproava_product_category = get_pmpro_membership_level_meta( $level_id, 'pmproava_product_category', true);
	return $pmproava_product_category ?: PMPROAVA_GENERAL_MERCHANDISE;
}

/**
 * Get the Avalara address model for a particular level.
 *
 * @param int $level_id to get category for
 * @return string address model
 */
function pmproava_get_product_address_model( $level_id ) {
	$pmproava_address_model = get_pmpro_membership_level_meta( $level_id, 'pmproava_address_model', true);
	return $pmproava_address_model ?: 'shipToFrom';
}

/**
 * Get the Avalara customer code for a given user_id.
 *
 * @param int $user_id to get customer code for.
 * @return string
 */
function pmproava_get_customer_code( $user_id ) {
	$customer_code = get_user_meta( $user_id, 'pmproava_customer_code', true );
	if ( empty( $customer_code ) ) {
		$pmproava_options = pmproava_get_options();
		$customer_code    = $pmproava_options['site_prefix'] . '-' . str_pad( $user_id, 8, '0', STR_PAD_LEFT );
		update_user_meta( $user_id, 'pmproava_customer_code', $customer_code );
	}
	return $customer_code;
}

/**
 * Get the Avalara document code for a particular order.
 *
 * @param MemberOrder $order to get document code for.
 * @return string
 */
function pmproava_get_document_code( $order ) {
	$document_code = get_pmpro_membership_order_meta( $order->id, 'pmproava_document_code', true );
	if ( empty( $customer_code ) ) {
		$pmproava_options = pmproava_get_options();
		$document_code    = $pmproava_options['site_prefix'] . '-' . $order->code;
		update_pmpro_membership_order_meta( $order->id, 'pmproava_document_code', $document_code );
	}
	return $document_code;
}

// if not, we should probably return 0 to prevent other plugins for interfering.
function pmproava_tax_filter( $tax, $values, $order ) {
	$level_id              = $order->membership_id;
	$product_category      = pmproava_get_product_category( $level_id );
	$product_address_model = pmproava_get_product_address_model( $level_id );

	$retroactive_tax = true;
	$options = pmproava_get_options();
	if ( ! pmpro_is_checkout() || $options['retroactive_tax'] === 'yes' ) {
		// Not at checkout or tax is being calculated retroactively. Don't need to calculate tax right now.
		return 0;
	}

	if ( $product_address_model === 'singleLocation' ) {
		// Improves caching.
		$billing_address = null;
	} else {
		$billing_address = new stdClass();
		$billing_address->line1 = isset( $values['billing_street'] ) ? $values['billing_street'] : '';
		$billing_address->city = isset( $values['billing_city'] ) ? $values['billing_city'] : '';
		$billing_address->region = isset( $values['billing_state'] ) ? $values['billing_state'] : '';
		$billing_address->postalCode = isset( $values['billing_zip'] ) ? $values['billing_zip'] : '';
		$billing_address->country = isset( $values['billing_country'] ) ? $values['billing_country'] : '';
	}

	$cache_key = wp_hash( json_encode( array( $level_id, $product_category, $product_address_model, $billing_address ) ) );
	static $cache;
	if ( ! isset( $cache[ $cache_key ] ) ) {
		$pmproava_sdk_wrapper = PMProava_SDK_Wrapper::get_instance();
		$cache[ $cache_key ] = $pmproava_sdk_wrapper->calculate_tax( $values['price'], $product_category, $product_address_model, $billing_address ) ?: 0;
	}
	return $cache[ $cache_key ];
}
add_filter( 'pmpro_tax', 'pmproava_tax_filter', 100, 3 ); // Avalara should have the final say in taxes.

function pmproava_send_order_to_avatax( $order ) {
	$price                       = $order->total;
	$product_category            = pmproava_get_product_category( $order->membership_id );
	$product_address_model       = pmproava_get_product_address_model( $order->membership_id );
	$billing_address             = new stdClass();
	$billing_address->line1      = $order->billing->street;
	$billing_address->city       = $order->billing->city;
	$billing_address->region     = $order->billing->state;
	$billing_address->postalCode = $order->billing->zip;
	$billing_address->country    = $order->billing->country;
	$customer_code               = pmproava_get_customer_code( $order->user_id );
	$document_code               = pmproava_get_document_code( $order );
	$transaction_date            = ! empty( $order->timestamp ) ? $order->getTimestamp( true ) : null;

	$pmproava_sdk_wrapper = PMProava_SDK_Wrapper::get_instance();
	$success = $pmproava_sdk_wrapper->commit_new_transaction( $price, $product_category, $product_address_model, $billing_address, $customer_code, $document_code, $transaction_date );
	return $success;
}

function pmproava_updated_order( $order ) {
	// Check if gateway environments match for order and Avalara creds. If not, return.
	$pmproava_options = pmproava_get_options();
	$pmproava_environment = $pmproava_options['environment'] === 'sandbox' ? 'sandbox' : 'live' ;
	$gateway_environment = pmpro_getOption( 'gateway_environment' );
	if ( $pmproava_environment !== $gateway_environment ) {
		return false;
	}
	$document_code        = pmproava_get_document_code( $order );
	$pmproava_sdk_wrapper = PMProava_SDK_Wrapper::get_instance();
	switch( $order->status ) {
		case 'success':
		case 'cancelled':
			// Check if order has already been sent to Avatax.
			if ( ! $pmproava_sdk_wrapper->transaction_exists_for_code( $document_code ) ) {
				// Send order to Avalara.
				$success = pmproava_send_order_to_avatax( $order );
				if ( $success ) {
					$transaction = $pmproava_sdk_wrapper->get_transaction_by_code( $document_code );
					if ( ! empty( $transaction ) ) {
						$order->subtotal = $transaction->totalAmount;
						$order->tax      = $transaction->totalTax;
						$order->saveOrder();
					}
				} else {
					global $pmproava_error;
					echo( $pmproava_error );
				}
			}
			break;
		case 'refunded':
			// Check if order has already been sent to Avalara.
			// If so, set Avalara order to refunded.
			break;
		case 'pending': // Do we want this here?
		case 'review':  // Do we want this here?
		case 'error':
			// Check if order has already been sent to Avalara.
			// If so, void order in Avalara.
			break;
	}
}
add_filter( 'pmpro_added_order', 'pmproava_updated_order' );
add_filter( 'pmpro_updated_order', 'pmproava_updated_order' );