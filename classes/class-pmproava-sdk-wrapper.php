<?php

class PMProava_SDK_Wrapper {

	// Singlton class.
	private static $instance = null;
	private $AvaTaxClient    = null;

	/**
	 * Connect to AvaTax.
	 *
	 * @since 0.1
	 */
	public function __construct() {
		// Load libraries...
		require_once PMPROAVA_DIR . '/lib/vendor/autoload.php';
		require_once PMPROAVA_DIR . '/lib/vendor/avalara/avataxclient/src/AvaTaxClient.php';

		// Set up AvaTaxClient instance...
		$pmproava_options = pmproava_get_options();
		$account_number = $pmproava_options['account_number'];
		$license_key = $pmproava_options['license_key'];
		$environment = $pmproava_options['environment'];
		$this->AvaTaxClient = new Avalara\AvaTaxClient( 'PMPro Avalara', '1.0', get_bloginfo( 'name' ), $environment );
		$this->AvaTaxClient->withLicenseKey( $account_number, $license_key );
	}

	public static function get_instance() {
		if ( self::$instance == null ) {
			self::$instance = new PMProava_SDK_Wrapper();
		}
		return self::$instance;
	}

	/**
	 * Check whether the user's Avalara credentials are valid.
	 *
	 * @return bool
	 */
	public function check_credentials() {
		static $credentials_valid = null;
		if ( $credentials_valid === null ) {
			$credentials_valid = $this->AvaTaxClient->ping()->authenticated;
		}
		return $credentials_valid;
	}

	/**
	 * Validate an address.
	 *
	 * @param object $address of buyer
	 * @return object
	 */
	public function validate_address( $address ) {
		$response = $this->AvaTaxClient->resolveAddress(
			isset( $address->line1 ) ? $address->line1 : '',
			isset( $address->line2 ) ? $address->line2 : '',
			isset( $address->line3 ) ? $address->line3 : '',
			isset( $address->city ) ? $address->city : '',
			isset( $address->region ) ? $address->region : '',
			isset( $address->postalCode ) ? $address->postalCode : '',
			isset( $address->country ) ? $address->country : '',
			'Mixed' // Text case.
		);
		if ( ! empty( $response->messages ) ) {
			// Invalid address.
			return null;
		}
		return $response->validatedAddresses[0];
	}

	/**
	 * Create a Avalara\TransactionMode object.
	 *
	 * @param float  $price to calculate tax for
	 * @param string $product_category being purchased
	 * @param string $product_address_model being purchased
	 * @param object $billing_address of buyer
	 * @param string $document_type of transaction
	 * @param string $customer_code of buyer
	 * @param bool   $retroactive_tax if tax is included in $price
	 * @return Avalara\TransactionMode|null
	 */
	private function get_transaction_mode( $price, $product_category, $product_address_model, $billing_address = null, $document_type = Avalara\DocumentType::C_SALESORDER, $customer_code = '0', $retroactive_tax = false ) {
		if ( ! $this->check_credentials() ) {
			return null;
		}

		// Make sure we have a valid company address.
		$pmproava_options = pmproava_get_options();
		$validated_company_address = $this->validate_address( $pmproava_options['company_address'] );
		if ( empty( $validated_company_address ) ) {
			// Invalid company address.
			return null;
		}

		// Create a transaction in Avalara.
		$transaction_builder = new Avalara\TransactionBuilder(
			$this->AvaTaxClient,
			$pmproava_options['company_code'],
			$document_type,
			$customer_code
		);

		// Set addresses for transaction.
		switch ( $product_address_model ) {
			case 'singleLocation':
				$transaction_builder->withAddress(
					'SingleLocation',
					$validated_company_address->line1,
					$validated_company_address->line2,
					$validated_company_address->line3,
					$validated_company_address->city,
					$validated_company_address->region,
					$validated_company_address->postalCode,
					$validated_company_address->country
				);
				break;
			case 'shipToFrom':
				$validated_billing_address = $this->validate_address( $billing_address );
				if ( empty( $validated_billing_address ) ) {
					// Invalid address.
					return null;
				}
				$transaction_builder->withAddress(
					'shipTo',
					$validated_billing_address->line1,
					$validated_billing_address->line2,
					$validated_billing_address->line3,
					$validated_billing_address->city,
					$validated_billing_address->region,
					$validated_billing_address->postalCode,
					$validated_billing_address->country
				);
				$transaction_builder->withAddress(
					'shipFrom',
					$validated_company_address->line1,
					$validated_company_address->line2,
					$validated_company_address->line3,
					$validated_company_address->city,
					$validated_company_address->region,
					$validated_company_address->postalCode,
					$validated_company_address->country
				);
		}
		// Add product to transaction.
		$transaction_builder->withLine(
			$price,             // $amount
			1,                   // $quantity
			null,                // $itemCode
			$product_category    // $taxCode
		);

		// Make tax retroactive if needed.
		if ( $retroactive_tax ) {
			$transaction_builder->withLineTaxIncluded();
		}

		return $transaction_builder->create();
	}

	/**
	 * Calculate tax amount without creating a transaction in Avalara.
	 *
	 * @param float  $price to calculate tax for
	 * @param string $product_category being purchased
	 * @param string $product_address_model being purchased
	 * @param object $billing_address of buyer
	 * @param bool   $retroactive_tax if tax is included in $price
	 * @return float|null
	 */
	public function calculate_tax( $price, $product_category, $product_address_model, $billing_address = null, $retroactive_tax = false ) {
		$transaction_mode = $this->get_transaction_mode( $price, $product_category, $product_address_model, $billing_address, Avalara\DocumentType::C_SALESORDER, '0', $retroactive_tax );
		if ( empty( $transaction_mode ) ) {
			return null;
		}
		return $transaction_mode->totalTax;
	}
}