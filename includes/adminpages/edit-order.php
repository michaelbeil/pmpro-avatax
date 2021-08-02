<?php

function pmproava_after_order_settings( $order ) {
	if ( empty( $order->id ) ) {
		// This is a new order.
		return;
	}

	$pmproava_options     = pmproava_get_options();
	$pmpro_avatax         = PMPro_AvaTax::get_instance();
	$transaction_code     = pmproava_get_transaction_code( $order );
	$transaction          = $pmpro_avatax->get_transaction_for_order( $order );
	$last_sync            = get_pmpro_membership_order_meta( $order->id, 'pmproava_last_sync', true );
	if ( empty( $last_sync ) ) {
		$last_sync = __( 'Never', 'pmpro-avatax' );
	}
	?>
	<tr>
		<th><?php esc_html_e( 'AvaTax', 'pmpro-avatax' ); ?></th>
		<td>
			<ul>
				<li>
					<strong><?php esc_html_e( 'Transaction Code', 'pmpro-avatax' ); ?>:</strong>
					<?php esc_html_e( $transaction_code ); ?>
				</li>
				<?php 
				$error = pmproava_get_order_error( $order );
				if ( empty( $error ) ) {
					?>
					<li>
						<strong><?php esc_html_e( 'Last Updated', 'pmpro-avatax' ); ?>:</strong>
						<?php esc_html_e( $last_sync ); ?>
					</li>
					<?php
				} else {
					?>
					<li>
						<strong><?php esc_html_e( 'Error', 'pmpro-avatax' ); ?>:</strong>
						<?php esc_html_e( $error ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Last Successful Update', 'pmpro-avatax' ); ?>:</strong>
						<?php esc_html_e( $last_sync ); ?>
					</li>
					<?php
				}
				if ( ! empty( $transaction ) && empty( $transaction->error ) ) {
					?>
					<li>
						<strong><?php esc_html_e( 'Customer Code', 'pmpro-avatax' ); ?>:</strong>
						<?php esc_html_e( $transaction->customerCode); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Locked', 'pmpro-avatax' ); ?>:</strong>
						<?php esc_html_e( $transaction->locked ? __( 'Yes', 'pmpro-avatax' ) : __( 'No', 'pmpro-avatax' ) ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'URL', 'pmpro-avatax' ); ?>:</strong>
						<?php
						$url = 'https://' . ( $pmproava_options['environment'] != 'production' ? 'sandbox.' : '' ) . 'admin.avalara.com/cup/a/' . $pmproava_options['account_number'] . '/c/' . $transaction->companyId . '/transactions/' . $transaction->id;
						?>
						<a href="<?php echo $url ?>" target="_blank"><?php echo $url ?></a>
					</li>
					<?php
					if ( ! empty( $transaction->businessIdentificationNo ) || $pmproava_options['vat_field'] === 'yes' ) {
						?>
						<li>
							<strong><?php esc_html_e( 'VAT Number', 'pmpro-avatax' ); ?>:</strong>
							<?php
								$vat_number = get_pmpro_membership_order_meta( $order->id, 'pmproava_vat_number', true );
							?>
							<input id="pmproava_vat_number" name="pmproava_vat_number" type="text" size="50" value="<?php echo esc_attr( $vat_number ); ?>"/>
						</li>
						<?php
					}
				}
				?>
			</ul>
		</td>
	</tr>
	<?php
}
add_action( 'pmpro_after_order_settings', 'pmproava_after_order_settings', 10, 1 );