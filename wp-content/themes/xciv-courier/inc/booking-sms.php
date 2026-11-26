<?php
/**
 * SMS Alert (smsalert.co.in) alerts for queue management bookings.
 *
 * Uses the official SMS Alert plugin when available (`sa_send_sms`),
 * otherwise falls back to the HTTP API: https://www.smsalert.co.in/api/push.json
 *
 * Credentials (preferred via wp-config.php):
 *   define( 'XCIV_SMSALERT_API_KEY', 'xxxx' );
 *   define( 'XCIV_SMSALERT_SENDER', 'XXXXXX' ); // approved sender ID
 *   define( 'XCIV_SMS_ADMIN_PHONE', '9198XXXXXXXX' );
 *
 * Or configure under Settings → XCIV SMS.
 * Plugin UI: WooCommerce → SMS Alert (account login + order templates).
 *
 * @package XCIV_Courier
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const XCIV_SMS_OPTIONS = 'xciv_sms_settings';

/**
 * Default SMS settings.
 *
 * @return array{enabled:bool, api_key:string, sender:string, admin_phone:string, notify_admin:bool, notify_customer:bool}
 */
function xciv_sms_default_settings(): array {
	return array(
		'enabled'         => true,
		'api_key'         => '',
		'sender'          => '',
		'admin_phone'     => '',
		'notify_admin'    => true,
		'notify_customer' => true,
	);
}

/**
 * Merged SMS settings (constants override stored options).
 *
 * @return array{enabled:bool, api_key:string, sender:string, admin_phone:string, notify_admin:bool, notify_customer:bool}
 */
function xciv_sms_settings(): array {
	$stored = wp_parse_args( (array) get_option( XCIV_SMS_OPTIONS, array() ), xciv_sms_default_settings() );

	// Migrate leftover Twilio option keys if present.
	if ( $stored['api_key'] === '' && ! empty( $stored['auth_token'] ) ) {
		$stored['api_key'] = (string) $stored['auth_token'];
	}
	if ( $stored['sender'] === '' && ! empty( $stored['from_number'] ) ) {
		$stored['sender'] = (string) $stored['from_number'];
	}

	if ( defined( 'XCIV_SMSALERT_API_KEY' ) && XCIV_SMSALERT_API_KEY ) {
		$stored['api_key'] = (string) XCIV_SMSALERT_API_KEY;
	}
	if ( defined( 'XCIV_SMSALERT_SENDER' ) && XCIV_SMSALERT_SENDER ) {
		$stored['sender'] = (string) XCIV_SMSALERT_SENDER;
	}
	if ( defined( 'XCIV_SMS_ADMIN_PHONE' ) && XCIV_SMS_ADMIN_PHONE ) {
		$stored['admin_phone'] = (string) XCIV_SMS_ADMIN_PHONE;
	}

	return $stored;
}

/**
 * Whether the official SMS Alert plugin is loaded.
 */
function xciv_sms_plugin_ready(): bool {
	return has_action( 'sa_send_sms' ) || class_exists( 'SMSAlert_WC_Order_SMS', false ) || defined( 'SMSALERT_PLUGIN_NAME' );
}

/**
 * Whether API credentials (or the plugin) can send.
 */
function xciv_sms_is_configured(): bool {
	if ( xciv_sms_plugin_ready() ) {
		return true;
	}
	$s = xciv_sms_settings();
	return $s['api_key'] !== '' && $s['sender'] !== '';
}

/**
 * Normalize a phone number for SMS Alert (digits; keep leading country code).
 */
function xciv_sms_normalize_phone( string $phone ): string {
	$phone = trim( $phone );
	if ( $phone === '' ) {
		return '';
	}

	$digits = preg_replace( '/\D+/', '', $phone );
	if ( ! is_string( $digits ) || $digits === '' ) {
		return '';
	}

	// US 10-digit → assume +1.
	if ( strlen( $digits ) === 10 ) {
		return '1' . $digits;
	}

	return $digits;
}

/**
 * Send an SMS via SMS Alert plugin hook or HTTP API.
 *
 * @return true|WP_Error
 */
function xciv_sms_send( string $to, string $body ) {
	$settings = xciv_sms_settings();

	if ( empty( $settings['enabled'] ) ) {
		return new WP_Error( 'xciv_sms_disabled', __( 'SMS alerts are disabled.', 'xciv-courier' ) );
	}

	$to = xciv_sms_normalize_phone( $to );
	if ( $to === '' ) {
		return new WP_Error( 'xciv_sms_bad_to', __( 'Invalid destination phone number.', 'xciv-courier' ) );
	}

	$body = trim( wp_strip_all_tags( $body ) );
	if ( $body === '' ) {
		return new WP_Error( 'xciv_sms_empty', __( 'SMS body is empty.', 'xciv-courier' ) );
	}

	if ( strlen( $body ) > 320 ) {
		$body = substr( $body, 0, 317 ) . '...';
	}

	// Prefer official plugin gateway (uses account logged in under WooCommerce → SMS Alert).
	if ( has_action( 'sa_send_sms' ) ) {
		/**
		 * SMS Alert plugin send action.
		 *
		 * @param string $to   Mobile number.
		 * @param string $body Message text.
		 */
		do_action( 'sa_send_sms', $to, $body );
		do_action( 'xciv_sms_sent', $to, $body, array( 'via' => 'sa_send_sms' ) );
		return true;
	}

	if ( ! xciv_sms_is_configured() ) {
		return new WP_Error(
			'xciv_sms_unconfigured',
			__( 'SMS Alert is not configured. Install/activate the SMS Alert plugin or set API key + sender ID.', 'xciv-courier' )
		);
	}

	$response = wp_remote_post(
		'https://www.smsalert.co.in/api/push.json',
		array(
			'timeout' => 20,
			'body'    => array(
				'apikey'   => $settings['api_key'],
				'sender'   => $settings['sender'],
				'mobileno' => $to,
				'text'     => $body,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = (string) wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );

	$status = '';
	if ( is_array( $data ) ) {
		if ( isset( $data['status'] ) ) {
			$status = strtolower( (string) $data['status'] );
		} elseif ( isset( $data['description']['status'] ) ) {
			$status = strtolower( (string) $data['description']['status'] );
		}
	}

	$ok = ( $code >= 200 && $code < 300 ) && ( $status === '' || in_array( $status, array( 'success', 'ok', '1' ), true ) );

	if ( ! $ok && is_array( $data ) && isset( $data['description']['desc'] ) ) {
		$desc = strtolower( (string) $data['description']['desc'] );
		if ( str_contains( $desc, 'success' ) || str_contains( $desc, 'submitted' ) ) {
			$ok = true;
		}
	}

	if ( ! $ok ) {
		$message = __( 'SMS Alert rejected the request.', 'xciv-courier' );
		if ( is_array( $data ) ) {
			if ( ! empty( $data['description']['desc'] ) ) {
				$message = (string) $data['description']['desc'];
			} elseif ( ! empty( $data['message'] ) ) {
				$message = (string) $data['message'];
			}
		}
		return new WP_Error( 'xciv_sms_api', $message, array( 'status' => $code, 'body' => $data ) );
	}

	do_action( 'xciv_sms_sent', $to, $body, is_array( $data ) ? $data : array() );

	return true;
}

/**
 * Pull booking meta from a WooCommerce order.
 *
 * @return array{meta:array<string,string>, has_live_sms:bool, customer_phone:string, order_id:int}
 */
function xciv_sms_order_booking_context( WC_Order $order ): array {
	$meta           = array();
	$has_live_sms   = false;
	$customer_phone = '';
	$addon_ids      = (array) get_option( XCIV_BOOKING_ADDONS_OPTION, array() );
	$live_product   = isset( $addon_ids['live_updates'] ) ? (int) $addon_ids['live_updates'] : 0;

	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}

		if ( $item->get_meta( 'xciv_addon', true ) === 'live_updates' ) {
			$has_live_sms = true;
		}
		if ( $live_product > 0 && (int) $item->get_product_id() === $live_product ) {
			$has_live_sms = true;
		}

		foreach ( $item->get_meta_data() as $meta_obj ) {
			$data = $meta_obj->get_data();
			$key  = (string) ( $data['key'] ?? '' );
			$val  = $data['value'] ?? '';
			if ( $key === '' || ! is_scalar( $val ) ) {
				continue;
			}
			if ( str_starts_with( $key, '_' ) || $key === 'xciv_addon' ) {
				continue;
			}
			$meta[ $key ] = (string) $val;
		}
	}

	if ( isset( $meta['Contact phone'] ) && $meta['Contact phone'] !== '' ) {
		$customer_phone = (string) $meta['Contact phone'];
	} elseif ( $order->get_billing_phone() ) {
		$customer_phone = (string) $order->get_billing_phone();
	}

	return array(
		'meta'           => $meta,
		'has_live_sms'   => $has_live_sms,
		'customer_phone' => $customer_phone,
		'order_id'       => $order->get_id(),
	);
}

/**
 * Build admin alert body.
 *
 * @param array<string, string> $meta Booking meta.
 */
function xciv_sms_admin_message( int $order_id, array $meta ): string {
	$service  = $meta['Service'] ?? 'Booking';
	$location = $meta['Location'] ?? '';
	$start    = $meta['Start'] ?? '';
	$name     = $meta['Contact name'] ?? '';

	return sprintf(
		'XCIV booking #%d: %s @ %s · %s · %s',
		$order_id,
		$service,
		$location !== '' ? $location : 'TBD',
		$start !== '' ? $start : 'time TBD',
		$name !== '' ? $name : 'guest'
	);
}

/**
 * Build customer confirmation body (Live SMS add-on).
 *
 * @param array<string, string> $meta Booking meta.
 */
function xciv_sms_customer_message( int $order_id, array $meta ): string {
	$service = $meta['Service'] ?? 'line sitting';
	$start   = $meta['Start'] ?? '';
	$loc     = $meta['Location'] ?? '';

	return sprintf(
		'XCIV Courier: booking #%d confirmed — %s at %s (%s). Live updates are on.',
		$order_id,
		$service,
		$loc !== '' ? $loc : 'your location',
		$start !== '' ? $start : 'scheduled window'
	);
}

/**
 * Send booking SMS alerts once per order.
 *
 * @param mixed $order_id Order ID.
 */
function xciv_sms_notify_order( $order_id ): void {
	$order_id = absint( $order_id );
	if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$ctx = xciv_sms_order_booking_context( $order );
	if ( empty( $ctx['meta'] ) ) {
		return;
	}

	if ( $order->get_meta( '_xciv_sms_sent' ) ) {
		return;
	}

	$settings = xciv_sms_settings();
	$sent_any = false;

	if ( ! empty( $settings['notify_admin'] ) && $settings['admin_phone'] !== '' ) {
		$result = xciv_sms_send( $settings['admin_phone'], xciv_sms_admin_message( $order_id, $ctx['meta'] ) );
		if ( ! is_wp_error( $result ) ) {
			$sent_any = true;
		} else {
			$order->add_order_note( 'XCIV admin SMS failed: ' . $result->get_error_message() );
		}
	}

	if ( ! empty( $settings['notify_customer'] ) && $ctx['has_live_sms'] && $ctx['customer_phone'] !== '' ) {
		$result = xciv_sms_send( $ctx['customer_phone'], xciv_sms_customer_message( $order_id, $ctx['meta'] ) );
		if ( ! is_wp_error( $result ) ) {
			$sent_any = true;
			$order->update_meta_data( '_xciv_live_sms', '1' );
		} else {
			$order->add_order_note( 'XCIV customer SMS failed: ' . $result->get_error_message() );
		}
	}

	if ( $sent_any || ( empty( $settings['notify_admin'] ) && ( empty( $settings['notify_customer'] ) || ! $ctx['has_live_sms'] ) ) ) {
		$order->update_meta_data( '_xciv_sms_sent', current_time( 'mysql' ) );
		$order->save();
	}
}
add_action( 'woocommerce_payment_complete', 'xciv_sms_notify_order', 20 );
add_action( 'woocommerce_order_status_processing', 'xciv_sms_notify_order', 20 );
add_action( 'woocommerce_order_status_completed', 'xciv_sms_notify_order', 20 );
add_action( 'woocommerce_order_status_on-hold', 'xciv_sms_notify_order', 20 );

/**
 * Admin settings page.
 */
function xciv_sms_register_settings(): void {
	register_setting(
		'xciv_sms',
		XCIV_SMS_OPTIONS,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'xciv_sms_sanitize_settings',
			'default'           => xciv_sms_default_settings(),
		)
	);
}
add_action( 'admin_init', 'xciv_sms_register_settings' );

/**
 * @param mixed $input Raw settings.
 * @return array<string, mixed>
 */
function xciv_sms_sanitize_settings( $input ): array {
	$defaults = xciv_sms_default_settings();
	$input    = is_array( $input ) ? $input : array();
	$out      = $defaults;

	$out['enabled']         = ! empty( $input['enabled'] );
	$out['notify_admin']    = ! empty( $input['notify_admin'] );
	$out['notify_customer'] = ! empty( $input['notify_customer'] );
	$out['sender']          = sanitize_text_field( (string) ( $input['sender'] ?? '' ) );
	$out['admin_phone']     = sanitize_text_field( (string) ( $input['admin_phone'] ?? '' ) );

	$key = (string) ( $input['api_key'] ?? '' );
	if ( $key !== '' && $key !== '********' ) {
		$out['api_key'] = sanitize_text_field( $key );
	} else {
		$existing       = (array) get_option( XCIV_SMS_OPTIONS, array() );
		$out['api_key'] = (string) ( $existing['api_key'] ?? '' );
	}

	return $out;
}

/**
 * Add Settings → XCIV SMS.
 */
function xciv_sms_admin_menu(): void {
	add_options_page(
		__( 'XCIV SMS Alerts', 'xciv-courier' ),
		__( 'XCIV SMS', 'xciv-courier' ),
		'manage_options',
		'xciv-sms',
		'xciv_sms_render_settings_page'
	);
}
add_action( 'admin_menu', 'xciv_sms_admin_menu' );

/**
 * Handle test SMS from settings.
 */
function xciv_sms_handle_test(): void {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( empty( $_POST['xciv_sms_send_test'] ) ) {
		return;
	}
	check_admin_referer( 'xciv_sms_test' );

	$to = sanitize_text_field( wp_unslash( (string) ( $_POST['xciv_sms_test_to'] ?? '' ) ) );
	if ( $to === '' ) {
		$settings = xciv_sms_settings();
		$to       = $settings['admin_phone'];
	}

	$result   = xciv_sms_send( $to, 'XCIV Courier test SMS — queue alerts via SMS Alert are working.' );
	$redirect = add_query_arg(
		array(
			'page'            => 'xciv-sms',
			'xciv_sms_tested' => is_wp_error( $result ) ? '0' : '1',
			'xciv_sms_msg'    => is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : '',
		),
		admin_url( 'options-general.php' )
	);
	wp_safe_redirect( $redirect );
	exit;
}
add_action( 'admin_init', 'xciv_sms_handle_test' );

/**
 * Settings page markup.
 */
function xciv_sms_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings     = xciv_sms_settings();
	$configured   = xciv_sms_is_configured();
	$plugin_ready = xciv_sms_plugin_ready();
	$const_key    = defined( 'XCIV_SMSALERT_API_KEY' ) && XCIV_SMSALERT_API_KEY;
	$const_sender = defined( 'XCIV_SMSALERT_SENDER' ) && XCIV_SMSALERT_SENDER;
	$const_adm    = defined( 'XCIV_SMS_ADMIN_PHONE' ) && XCIV_SMS_ADMIN_PHONE;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'XCIV SMS Alerts', 'xciv-courier' ); ?></h1>
		<p>
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %s: SMS Alert website URL */
					__( 'Powered by <a href="%s" target="_blank" rel="noopener noreferrer">SMS Alert</a>. Admin gets a text on every new booking; customers with the Live SMS updates add-on get a confirmation.', 'xciv-courier' ),
					'https://www.smsalert.co.in/'
				)
			);
			?>
		</p>

		<?php if ( $plugin_ready ) : ?>
			<div class="notice notice-info inline"><p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: WooCommerce SMS Alert admin URL */
						__( 'SMS Alert plugin is active. Log in under <a href="%s">WooCommerce → SMS Alert</a> for account credits, DLT templates, and standard order notifications. Booking-specific texts below still use the plugin gateway.', 'xciv-courier' ),
						esc_url( admin_url( 'admin.php?page=sms-alert' ) )
					)
				);
				?>
			</p></div>
		<?php else : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'SMS Alert plugin is not active. Install it from WordPress.org (sms-alert), or enter API key + sender ID below for direct API sends.', 'xciv-courier' ); ?>
			</p></div>
		<?php endif; ?>

		<?php if ( isset( $_GET['xciv_sms_tested'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( (string) $_GET['xciv_sms_tested'] === '1' ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Test SMS sent.', 'xciv-courier' ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-error"><p>
					<?php
					echo esc_html(
						isset( $_GET['xciv_sms_msg'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
							? rawurldecode( (string) wp_unslash( $_GET['xciv_sms_msg'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
							: __( 'Test SMS failed.', 'xciv-courier' )
					);
					?>
				</p></div>
			<?php endif; ?>
		<?php endif; ?>

		<p>
			<strong><?php esc_html_e( 'Status:', 'xciv-courier' ); ?></strong>
			<?php
			if ( $plugin_ready ) {
				esc_html_e( 'Ready via SMS Alert plugin', 'xciv-courier' );
			} elseif ( $configured ) {
				esc_html_e( 'Configured via API key', 'xciv-courier' );
			} else {
				esc_html_e( 'Not configured', 'xciv-courier' );
			}
			?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'xciv_sms' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable SMS', 'xciv-courier' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( XCIV_SMS_OPTIONS ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
							<?php esc_html_e( 'Send SMS alerts when bookings are placed', 'xciv-courier' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="xciv-apikey"><?php esc_html_e( 'SMS Alert API key', 'xciv-courier' ); ?></label></th>
					<td>
						<input class="regular-text" type="password" id="xciv-apikey" name="<?php echo esc_attr( XCIV_SMS_OPTIONS ); ?>[api_key]" value="<?php echo $settings['api_key'] !== '' ? '********' : ''; ?>" autocomplete="new-password" <?php disabled( $const_key ); ?>>
						<p class="description">
							<?php
							echo $plugin_ready
								? esc_html__( 'Optional when the plugin is logged in. Used only for direct API fallback.', 'xciv-courier' )
								: esc_html__( 'Generate from your SMS Alert dashboard → API Key.', 'xciv-courier' );
							?>
						</p>
						<?php if ( $const_key ) : ?>
							<p class="description"><?php esc_html_e( 'Locked by XCIV_SMSALERT_API_KEY in wp-config.php.', 'xciv-courier' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="xciv-sender"><?php esc_html_e( 'Sender ID', 'xciv-courier' ); ?></label></th>
					<td>
						<input class="regular-text" type="text" id="xciv-sender" name="<?php echo esc_attr( XCIV_SMS_OPTIONS ); ?>[sender]" value="<?php echo esc_attr( $settings['sender'] ); ?>" placeholder="SMSALT" maxlength="6" <?php disabled( $const_sender ); ?>>
						<?php if ( $const_sender ) : ?>
							<p class="description"><?php esc_html_e( 'Locked by XCIV_SMSALERT_SENDER in wp-config.php.', 'xciv-courier' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="xciv-admin"><?php esc_html_e( 'Admin alert phone', 'xciv-courier' ); ?></label></th>
					<td>
						<input class="regular-text" type="text" id="xciv-admin" name="<?php echo esc_attr( XCIV_SMS_OPTIONS ); ?>[admin_phone]" value="<?php echo esc_attr( $settings['admin_phone'] ); ?>" placeholder="9198XXXXXXXX or 15551234567" <?php disabled( $const_adm ); ?>>
						<?php if ( $const_adm ) : ?>
							<p class="description"><?php esc_html_e( 'Locked by XCIV_SMS_ADMIN_PHONE in wp-config.php.', 'xciv-courier' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Notify admin', 'xciv-courier' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( XCIV_SMS_OPTIONS ); ?>[notify_admin]" value="1" <?php checked( ! empty( $settings['notify_admin'] ) ); ?>>
							<?php esc_html_e( 'Text the admin phone on every new booking order', 'xciv-courier' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Notify customer', 'xciv-courier' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( XCIV_SMS_OPTIONS ); ?>[notify_customer]" value="1" <?php checked( ! empty( $settings['notify_customer'] ) ); ?>>
							<?php esc_html_e( 'Text the customer when they purchased Live SMS updates', 'xciv-courier' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save SMS settings', 'xciv-courier' ) ); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Send test SMS', 'xciv-courier' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'xciv_sms_test' ); ?>
			<p>
				<label for="xciv-test-to"><?php esc_html_e( 'To', 'xciv-courier' ); ?></label>
				<input class="regular-text" type="text" id="xciv-test-to" name="xciv_sms_test_to" value="<?php echo esc_attr( $settings['admin_phone'] ); ?>" placeholder="9198XXXXXXXX">
			</p>
			<?php submit_button( __( 'Send test', 'xciv-courier' ), 'secondary', 'xciv_sms_send_test', false ); ?>
		</form>
	</div>
	<?php
}
