<?php
/**
 * Stripe Checkout + webhook for membership subscriptions.
 *
 * REST:
 * - POST /wp-json/xciv/v1/stripe/webhook
 * - POST /wp-json/xciv/v1/stripe/checkout  (logged-in)
 * - GET  /wp-json/xciv/v1/stripe/status    (admin)
 *
 * Configure keys via Settings → XCIV Stripe, or wp-config:
 * XCIV_STRIPE_SECRET_KEY, XCIV_STRIPE_PUBLISHABLE_KEY, XCIV_STRIPE_WEBHOOK_SECRET
 * (and XCIV_STRIPE_TEST_* / XCIV_STRIPE_TEST_WEBHOOK_SECRET for test mode).
 *
 * @package XCIV_Courier
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const XCIV_STRIPE_OPTION = 'xciv_stripe_settings';

/**
 * Default Stripe settings.
 *
 * @return array<string, string>
 */
function xciv_stripe_default_settings(): array {
	return array(
		'enabled'        => 'yes',
		'mode'           => 'test',
		'secret_key'     => '',
		'publishable'    => '',
		'webhook_secret' => '',
		'test_secret'    => '',
		'test_publishable' => '',
		'test_webhook'   => '',
	);
}

/**
 * Merged Stripe settings (constants win).
 *
 * @return array<string, string>
 */
function xciv_get_stripe_settings(): array {
	$stored = get_option( XCIV_STRIPE_OPTION, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	$s = array_merge( xciv_stripe_default_settings(), $stored );

	if ( defined( 'XCIV_STRIPE_SECRET_KEY' ) && XCIV_STRIPE_SECRET_KEY ) {
		$s['secret_key'] = (string) XCIV_STRIPE_SECRET_KEY;
	}
	if ( defined( 'XCIV_STRIPE_PUBLISHABLE_KEY' ) && XCIV_STRIPE_PUBLISHABLE_KEY ) {
		$s['publishable'] = (string) XCIV_STRIPE_PUBLISHABLE_KEY;
	}
	if ( defined( 'XCIV_STRIPE_WEBHOOK_SECRET' ) && XCIV_STRIPE_WEBHOOK_SECRET ) {
		$s['webhook_secret'] = (string) XCIV_STRIPE_WEBHOOK_SECRET;
	}
	if ( defined( 'XCIV_STRIPE_TEST_SECRET_KEY' ) && XCIV_STRIPE_TEST_SECRET_KEY ) {
		$s['test_secret'] = (string) XCIV_STRIPE_TEST_SECRET_KEY;
	}
	if ( defined( 'XCIV_STRIPE_TEST_PUBLISHABLE_KEY' ) && XCIV_STRIPE_TEST_PUBLISHABLE_KEY ) {
		$s['test_publishable'] = (string) XCIV_STRIPE_TEST_PUBLISHABLE_KEY;
	}
	if ( defined( 'XCIV_STRIPE_TEST_WEBHOOK_SECRET' ) && XCIV_STRIPE_TEST_WEBHOOK_SECRET ) {
		$s['test_webhook'] = (string) XCIV_STRIPE_TEST_WEBHOOK_SECRET;
	}

	return $s;
}

/**
 * Whether Stripe is ready for Checkout.
 */
function xciv_stripe_is_ready(): bool {
	$s = xciv_get_stripe_settings();
	if ( ( $s['enabled'] ?? '' ) !== 'yes' ) {
		return false;
	}
	return (bool) xciv_stripe_get_secret_key() && (bool) xciv_stripe_get_publishable_key();
}

/**
 * Active-mode secret key.
 */
function xciv_stripe_get_secret_key(): string {
	$s = xciv_get_stripe_settings();
	if ( ( $s['mode'] ?? 'test' ) === 'live' ) {
		return (string) $s['secret_key'];
	}
	return (string) ( $s['test_secret'] !== '' ? $s['test_secret'] : $s['secret_key'] );
}

/**
 * Active-mode publishable key.
 */
function xciv_stripe_get_publishable_key(): string {
	$s = xciv_get_stripe_settings();
	if ( ( $s['mode'] ?? 'test' ) === 'live' ) {
		return (string) $s['publishable'];
	}
	return (string) ( $s['test_publishable'] !== '' ? $s['test_publishable'] : $s['publishable'] );
}

/**
 * Active-mode webhook signing secret.
 */
function xciv_stripe_get_webhook_secret(): string {
	$s = xciv_get_stripe_settings();
	if ( ( $s['mode'] ?? 'test' ) === 'live' ) {
		return (string) $s['webhook_secret'];
	}
	return (string) ( $s['test_webhook'] !== '' ? $s['test_webhook'] : $s['webhook_secret'] );
}

/**
 * Public webhook URL.
 */
function xciv_stripe_webhook_url(): string {
	return rest_url( 'xciv/v1/stripe/webhook' );
}

/**
 * Expand nested arrays for Stripe form-encoded body.
 *
 * @param array<string, mixed> $body Body.
 * @param string               $prefix Prefix.
 * @return array<string, string>
 */
function xciv_stripe_expand_form_body( array $body, string $prefix = '' ): array {
	$out = array();
	foreach ( $body as $key => $value ) {
		$name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			foreach ( $value as $k => $v ) {
				$child = $is_list ? $name . '[' . $k . ']' : $name . '[' . $k . ']';
				if ( is_array( $v ) ) {
					$out = array_merge( $out, xciv_stripe_expand_form_body( $v, $is_list ? $name . '[' . $k . ']' : $child ) );
				} else {
					$out[ $is_list ? $name . '[' . $k . ']' : $child ] = (string) $v;
				}
			}
		} elseif ( is_bool( $value ) ) {
			$out[ $name ] = $value ? 'true' : 'false';
		} elseif ( $value !== null ) {
			$out[ $name ] = (string) $value;
		}
	}
	return $out;
}

/**
 * Stripe API request.
 *
 * @param string               $method Method.
 * @param string               $path   Path without leading slash (e.g. checkout/sessions).
 * @param array<string, mixed> $body   Body.
 * @return array<string, mixed>|\WP_Error
 */
function xciv_stripe_request( string $method, string $path, array $body = array() ) {
	$secret = xciv_stripe_get_secret_key();
	if ( $secret === '' ) {
		return new WP_Error( 'xciv_stripe_no_key', __( 'Stripe secret key is not configured.', 'xciv-courier' ), array( 'status' => 500 ) );
	}

	$url  = 'https://api.stripe.com/v1/' . ltrim( $path, '/' );
	$args = array(
		'method'  => strtoupper( $method ),
		'timeout' => 30,
		'headers' => array(
			'Authorization' => 'Bearer ' . $secret,
		),
	);

	if ( ! empty( $body ) ) {
		$args['body'] = xciv_stripe_expand_form_body( $body );
	}

	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'xciv_stripe_bad_json', __( 'Invalid Stripe response.', 'xciv-courier' ), array( 'status' => 502 ) );
	}
	if ( $code >= 400 ) {
		$msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Stripe request failed.', 'xciv-courier' );
		return new WP_Error( 'xciv_stripe_api', $msg, array( 'status' => $code ) );
	}

	return $data;
}

/**
 * Verify Stripe-Signature header.
 *
 * @return true|\WP_Error
 */
function xciv_stripe_verify_webhook_signature( string $payload, string $sig_header, string $secret, int $tolerance = 300 ) {
	if ( $secret === '' ) {
		return new WP_Error( 'xciv_stripe_no_whsec', __( 'Webhook signing secret is not configured.', 'xciv-courier' ), array( 'status' => 500 ) );
	}
	if ( $sig_header === '' ) {
		return new WP_Error( 'xciv_stripe_no_sig', __( 'Missing Stripe-Signature header.', 'xciv-courier' ), array( 'status' => 400 ) );
	}

	$parts = array();
	foreach ( explode( ',', $sig_header ) as $piece ) {
		$kv = explode( '=', trim( $piece ), 2 );
		if ( count( $kv ) === 2 ) {
			$parts[ $kv[0] ][] = $kv[1];
		}
	}

	if ( empty( $parts['t'][0] ) || empty( $parts['v1'] ) ) {
		return new WP_Error( 'xciv_stripe_bad_sig', __( 'Malformed Stripe-Signature header.', 'xciv-courier' ), array( 'status' => 400 ) );
	}

	$timestamp = (int) $parts['t'][0];
	if ( abs( time() - $timestamp ) > $tolerance ) {
		return new WP_Error( 'xciv_stripe_stale', __( 'Stripe webhook timestamp outside tolerance.', 'xciv-courier' ), array( 'status' => 400 ) );
	}

	$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
	foreach ( $parts['v1'] as $candidate ) {
		if ( hash_equals( $expected, $candidate ) ) {
			return true;
		}
	}

	return new WP_Error( 'xciv_stripe_sig_mismatch', __( 'Stripe webhook signature mismatch.', 'xciv-courier' ), array( 'status' => 400 ) );
}

/**
 * Register REST routes.
 */
function xciv_stripe_register_rest_routes(): void {
	register_rest_route(
		'xciv/v1',
		'/stripe/webhook',
		array(
			'methods'             => 'POST',
			'callback'            => 'xciv_stripe_rest_webhook',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'xciv/v1',
		'/stripe/checkout',
		array(
			'methods'             => 'POST',
			'callback'            => 'xciv_stripe_rest_create_checkout',
			'permission_callback' => static function (): bool {
				return is_user_logged_in();
			},
			'args'                => array(
				'plan' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_title',
				),
			),
		)
	);

	register_rest_route(
		'xciv/v1',
		'/stripe/status',
		array(
			'methods'             => 'GET',
			'callback'            => 'xciv_stripe_rest_status',
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
add_action( 'rest_api_init', 'xciv_stripe_register_rest_routes' );

/**
 * Admin status payload.
 */
function xciv_stripe_rest_status(): WP_REST_Response {
	$s = xciv_get_stripe_settings();
	return rest_ensure_response(
		array(
			'enabled'         => ( $s['enabled'] === 'yes' ),
			'mode'            => $s['mode'],
			'ready'           => xciv_stripe_is_ready(),
			'has_secret'      => (bool) xciv_stripe_get_secret_key(),
			'has_publishable' => (bool) xciv_stripe_get_publishable_key(),
			'has_webhook'     => (bool) xciv_stripe_get_webhook_secret(),
			'webhook_url'     => xciv_stripe_webhook_url(),
		)
	);
}

/**
 * Webhook receiver.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|\WP_Error
 */
function xciv_stripe_rest_webhook( WP_REST_Request $request ) {
	$payload    = $request->get_body();
	$sig_header = (string) $request->get_header( 'stripe_signature' );
	if ( $sig_header === '' && isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) {
		$sig_header = (string) $_SERVER['HTTP_STRIPE_SIGNATURE'];
	}

	$verified = xciv_stripe_verify_webhook_signature( $payload, $sig_header, xciv_stripe_get_webhook_secret() );
	if ( is_wp_error( $verified ) ) {
		return $verified;
	}

	$event = json_decode( $payload, true );
	if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) ) {
		return new WP_Error( 'xciv_stripe_bad_event', __( 'Invalid Stripe event payload.', 'xciv-courier' ), array( 'status' => 400 ) );
	}

	$event_id = sanitize_text_field( (string) $event['id'] );
	if ( get_transient( 'xciv_stripe_evt_' . $event_id ) ) {
		return rest_ensure_response( array( 'received' => true, 'duplicate' => true ) );
	}

	$result = xciv_stripe_handle_event( $event );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	set_transient( 'xciv_stripe_evt_' . $event_id, 1, WEEK_IN_SECONDS );
	do_action( 'xciv_stripe_webhook_handled', $event, $result );

	return rest_ensure_response(
		array(
			'received' => true,
			'type'     => $event['type'],
			'result'   => $result,
		)
	);
}

/**
 * Dispatch Stripe events.
 *
 * @param array<string, mixed> $event Event.
 * @return array<string, mixed>|\WP_Error
 */
function xciv_stripe_handle_event( array $event ) {
	$type = (string) $event['type'];
	$obj  = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();

	switch ( $type ) {
		case 'checkout.session.completed':
		case 'checkout.session.async_payment_succeeded':
			return xciv_stripe_handle_checkout_session_completed( $obj );
		case 'payment_intent.succeeded':
			return xciv_stripe_handle_payment_intent_succeeded( $obj );
		case 'charge.refunded':
			return xciv_stripe_handle_charge_refunded( $obj );
		default:
			return array( 'ignored' => true, 'type' => $type );
	}
}

/**
 * Bridge Checkout Session → Woo order → membership hooks.
 *
 * @param array<string, mixed> $session Session.
 * @return array<string, mixed>
 */
function xciv_stripe_handle_checkout_session_completed( array $session ): array {
	$meta    = isset( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : array();
	$user_id = isset( $meta['user_id'] ) ? (int) $meta['user_id'] : 0;
	$plan    = isset( $meta['plan'] ) ? sanitize_title( (string) $meta['plan'] ) : '';

	if ( $user_id <= 0 && ! empty( $session['client_reference_id'] ) ) {
		$user_id = (int) $session['client_reference_id'];
	}
	if ( $user_id <= 0 && ! empty( $session['customer_email'] ) ) {
		$user = get_user_by( 'email', sanitize_email( (string) $session['customer_email'] ) );
		if ( $user ) {
			$user_id = (int) $user->ID;
		}
	}

	$out = array(
		'user_id'  => $user_id,
		'plan'     => $plan,
		'actions'  => array(),
		'order_id' => 0,
	);

	if ( $user_id <= 0 ) {
		$out['actions'][] = 'no_user';
		return $out;
	}

	if ( ! empty( $meta['order_id'] ) && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( (int) $meta['order_id'] );
		if ( $order && ! $order->is_paid() ) {
			$order->payment_complete( sanitize_text_field( (string) ( $session['payment_intent'] ?? $session['id'] ?? '' ) ) );
			$order->add_order_note( __( 'XCIV: Stripe Checkout session completed via webhook.', 'xciv-courier' ) );
			$out['actions'][] = 'order_paid';
			$out['order_id']  = (int) $order->get_id();
			return $out;
		}
		if ( $order && $order->is_paid() ) {
			$out['actions'][] = 'order_already_paid';
			$out['order_id']  = (int) $order->get_id();
			return $out;
		}
	}

	$bridged = xciv_stripe_bridge_create_wc_order( $user_id, $meta, $session );
	if ( is_wp_error( $bridged ) ) {
		// Fallback: activate membership directly if Woo bridge fails.
		if ( $plan && function_exists( 'xciv_activate_membership' ) ) {
			$plan_row = xciv_get_membership_plan_by_slug( $plan );
			xciv_activate_membership(
				$user_id,
				$plan,
				array(
					'term_months' => (int) ( $plan_row['term_months'] ?? 1 ),
				)
			);
			$out['actions'][] = 'membership_direct';
			$out['error']     = $bridged->get_error_message();
			return $out;
		}
		$out['actions'][] = 'bridge_failed';
		$out['error']     = $bridged->get_error_message();
		return $out;
	}

	$out['actions'][] = 'order_bridged';
	$out['order_id']  = (int) $bridged;
	return $out;
}

/**
 * Create Woo order from Stripe membership session.
 *
 * @param array<string, mixed> $meta    Metadata.
 * @param array<string, mixed> $session Session.
 * @return int|\WP_Error
 */
function xciv_stripe_bridge_create_wc_order( int $user_id, array $meta, array $session ) {
	if ( ! function_exists( 'wc_create_order' ) ) {
		return new WP_Error( 'xciv_stripe_no_woo', __( 'WooCommerce is required to bridge Stripe payments.', 'xciv-courier' ) );
	}

	$plan = isset( $meta['plan'] ) ? sanitize_title( (string) $meta['plan'] ) : '';
	if ( $plan === '' || ! function_exists( 'xciv_get_membership_product_id' ) ) {
		return new WP_Error( 'xciv_stripe_no_plan', __( 'Missing membership plan on Stripe session.', 'xciv-courier' ) );
	}

	$product_id = xciv_get_membership_product_id( $plan, true );
	if ( $product_id <= 0 ) {
		return new WP_Error( 'xciv_stripe_no_product', __( 'Membership product missing.', 'xciv-courier' ) );
	}

	$order = wc_create_order(
		array(
			'customer_id' => $user_id,
			'created_via' => 'xciv_stripe_bridge',
		)
	);
	if ( is_wp_error( $order ) || ! $order ) {
		return is_wp_error( $order ) ? $order : new WP_Error( 'xciv_stripe_order_failed', __( 'Could not create WooCommerce order.', 'xciv-courier' ) );
	}

	$product = wc_get_product( $product_id );
	if ( $product ) {
		$item_id = $order->add_product( $product, 1 );
		if ( $item_id ) {
			$item = $order->get_item( $item_id );
			if ( $item ) {
				$item->add_meta_data( '_xciv_membership_plan', $plan, true );
				$item->save();
			}
		}
	}

	$order->update_meta_data( '_xciv_membership_plan', $plan );
	$order->update_meta_data( '_xciv_stripe_checkout_session', sanitize_text_field( (string) ( $session['id'] ?? '' ) ) );
	$order->update_meta_data( '_xciv_stripe_bridged', 'yes' );

	$user = get_userdata( $user_id );
	if ( $user ) {
		$order->set_billing_email( $user->user_email );
		$order->set_billing_first_name( $user->first_name ? $user->first_name : $user->display_name );
	} elseif ( ! empty( $session['customer_email'] ) ) {
		$order->set_billing_email( sanitize_email( (string) $session['customer_email'] ) );
	}

	$order->set_payment_method( 'xciv_stripe' );
	$order->set_payment_method_title( __( 'Stripe (XCIV membership)', 'xciv-courier' ) );
	$order->calculate_totals( false );
	$order->save();

	$txn = sanitize_text_field( (string) ( $session['payment_intent'] ?? $session['id'] ?? '' ) );
	$order->payment_complete( $txn );
	$order->add_order_note( __( 'XCIV: Stripe Checkout bridged to WooCommerce; membership activated via WC hooks.', 'xciv-courier' ) );

	do_action( 'xciv_stripe_order_bridged', $order->get_id(), $user_id, $session, $meta );

	return (int) $order->get_id();
}

/**
 * PaymentIntent fallback.
 *
 * @param array<string, mixed> $intent Intent.
 * @return array<string, mixed>
 */
function xciv_stripe_handle_payment_intent_succeeded( array $intent ): array {
	$meta = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
	if ( empty( $meta['user_id'] ) ) {
		return array( 'ignored' => true, 'reason' => 'no_metadata' );
	}
	return xciv_stripe_handle_checkout_session_completed(
		array(
			'id'             => $intent['id'] ?? '',
			'payment_intent' => $intent['id'] ?? '',
			'metadata'       => $meta,
		)
	);
}

/**
 * Full refund → cancel membership.
 *
 * @param array<string, mixed> $charge Charge.
 * @return array<string, mixed>
 */
function xciv_stripe_handle_charge_refunded( array $charge ): array {
	if ( empty( $charge['refunded'] ) ) {
		return array( 'ignored' => true, 'reason' => 'partial_or_not_refunded' );
	}
	$meta    = isset( $charge['metadata'] ) && is_array( $charge['metadata'] ) ? $charge['metadata'] : array();
	$user_id = isset( $meta['user_id'] ) ? (int) $meta['user_id'] : 0;
	if ( $user_id <= 0 ) {
		return array( 'ignored' => true, 'reason' => 'no_user' );
	}
	if ( function_exists( 'xciv_end_membership' ) ) {
		xciv_end_membership( $user_id, 'cancelled' );
		return array( 'user_id' => $user_id, 'actions' => array( 'membership_cancelled' ) );
	}
	return array( 'ignored' => true, 'reason' => 'no_end_helper' );
}

/**
 * Create Checkout Session for a membership plan.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|\WP_Error
 */
function xciv_stripe_rest_create_checkout( WP_REST_Request $request ) {
	if ( ! xciv_stripe_is_ready() ) {
		return new WP_Error( 'xciv_stripe_not_ready', __( 'Stripe is not connected yet.', 'xciv-courier' ), array( 'status' => 503 ) );
	}

	$plan_slug = sanitize_title( (string) $request->get_param( 'plan' ) );
	$plan      = function_exists( 'xciv_get_membership_plan_by_slug' ) ? xciv_get_membership_plan_by_slug( $plan_slug ) : null;
	if ( ! $plan ) {
		return new WP_Error( 'xciv_stripe_bad_plan', __( 'Unknown membership plan.', 'xciv-courier' ), array( 'status' => 400 ) );
	}

	$user_id  = get_current_user_id();
	$user     = wp_get_current_user();
	$currency = function_exists( 'get_woocommerce_currency' ) ? strtolower( (string) get_woocommerce_currency() ) : 'usd';
	$account  = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
	$success  = add_query_arg(
		array(
			'xciv_stripe' => 'success',
			'xciv_plan'   => $plan_slug,
		),
		$account
	);
	$cancel = add_query_arg( 'xciv_stripe', 'cancel', $account );

	$body = array(
		'mode'                => 'payment',
		'success_url'         => $success,
		'cancel_url'          => $cancel,
		'client_reference_id' => (string) $user_id,
		'customer_email'      => (string) $user->user_email,
		'line_items'          => array(
			array(
				'quantity'   => 1,
				'price_data' => array(
					'currency'     => $currency,
					'unit_amount'  => (int) round( ( (float) $plan['amount_raw'] ) * 100 ),
					'product_data' => array(
						'name' => (string) $plan['name'] . ' (' . (string) $plan['interval'] . ')',
					),
				),
			),
		),
		'metadata'            => array(
			'user_id'      => (string) $user_id,
			'plan'         => $plan_slug,
			'family'       => (string) ( $plan['family'] ?? '' ),
			'interval'     => (string) ( $plan['interval'] ?? '' ),
			'term_months'  => (string) ( $plan['term_months'] ?? 1 ),
			'source'       => 'xciv_membership',
		),
	);

	$session = xciv_stripe_request( 'POST', 'checkout/sessions', $body );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	return rest_ensure_response(
		array(
			'id'  => $session['id'] ?? '',
			'url' => $session['url'] ?? '',
		)
	);
}

/**
 * Settings page.
 */
function xciv_stripe_admin_menu(): void {
	add_options_page(
		__( 'XCIV Stripe', 'xciv-courier' ),
		__( 'XCIV Stripe', 'xciv-courier' ),
		'manage_options',
		'xciv-stripe',
		'xciv_stripe_render_settings_page'
	);
}
add_action( 'admin_menu', 'xciv_stripe_admin_menu' );

/**
 * Save settings.
 */
function xciv_stripe_handle_settings_save(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'xciv_stripe_settings', 'xciv_stripe_nonce' );

	$current = xciv_get_stripe_settings();
	$posted  = isset( $_POST['xciv_stripe'] ) && is_array( $_POST['xciv_stripe'] ) ? wp_unslash( $_POST['xciv_stripe'] ) : array();

	$next = array(
		'enabled'          => ! empty( $posted['enabled'] ) ? 'yes' : 'no',
		'mode'             => ( isset( $posted['mode'] ) && $posted['mode'] === 'live' ) ? 'live' : 'test',
		'secret_key'       => isset( $posted['secret_key'] ) ? sanitize_text_field( (string) $posted['secret_key'] ) : '',
		'publishable'      => isset( $posted['publishable'] ) ? sanitize_text_field( (string) $posted['publishable'] ) : '',
		'webhook_secret'   => isset( $posted['webhook_secret'] ) ? sanitize_text_field( (string) $posted['webhook_secret'] ) : '',
		'test_secret'      => isset( $posted['test_secret'] ) ? sanitize_text_field( (string) $posted['test_secret'] ) : '',
		'test_publishable' => isset( $posted['test_publishable'] ) ? sanitize_text_field( (string) $posted['test_publishable'] ) : '',
		'test_webhook'     => isset( $posted['test_webhook'] ) ? sanitize_text_field( (string) $posted['test_webhook'] ) : '',
	);

	foreach ( array( 'secret_key', 'webhook_secret', 'test_secret', 'test_webhook' ) as $secret_field ) {
		if ( $next[ $secret_field ] === '' ) {
			$next[ $secret_field ] = (string) $current[ $secret_field ];
		}
	}

	update_option( XCIV_STRIPE_OPTION, $next, false );
	wp_safe_redirect( add_query_arg( array( 'page' => 'xciv-stripe', 'updated' => '1' ), admin_url( 'options-general.php' ) ) );
	exit;
}
add_action( 'admin_post_xciv_stripe_save', 'xciv_stripe_handle_settings_save' );

/**
 * Settings UI.
 */
function xciv_stripe_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s       = xciv_get_stripe_settings();
	$webhook = xciv_stripe_webhook_url();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'XCIV Stripe', 'xciv-courier' ); ?></h1>
		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'xciv-courier' ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Membership Checkout for XCIV Courier One / Purple. Point Stripe webhooks at the URL below.', 'xciv-courier' ); ?></p>
		<p><code><?php echo esc_html( $webhook ); ?></code></p>
		<p><?php esc_html_e( 'Events: checkout.session.completed, checkout.session.async_payment_succeeded, payment_intent.succeeded, charge.refunded', 'xciv-courier' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="xciv_stripe_save">
			<?php wp_nonce_field( 'xciv_stripe_settings', 'xciv_stripe_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled', 'xciv-courier' ); ?></th>
					<td><label><input type="checkbox" name="xciv_stripe[enabled]" value="1" <?php checked( $s['enabled'], 'yes' ); ?>> <?php esc_html_e( 'Accept Stripe Checkout for memberships', 'xciv-courier' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Mode', 'xciv-courier' ); ?></th>
					<td>
						<select name="xciv_stripe[mode]">
							<option value="test" <?php selected( $s['mode'], 'test' ); ?>><?php esc_html_e( 'Test', 'xciv-courier' ); ?></option>
							<option value="live" <?php selected( $s['mode'], 'live' ); ?>><?php esc_html_e( 'Live', 'xciv-courier' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test secret key', 'xciv-courier' ); ?></th>
					<td><input type="password" class="regular-text" name="xciv_stripe[test_secret]" value="" placeholder="<?php echo $s['test_secret'] ? esc_attr__( '•••• saved — leave blank to keep', 'xciv-courier' ) : ''; ?>" autocomplete="new-password"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test publishable key', 'xciv-courier' ); ?></th>
					<td><input type="text" class="regular-text" name="xciv_stripe[test_publishable]" value="<?php echo esc_attr( $s['test_publishable'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test webhook secret', 'xciv-courier' ); ?></th>
					<td><input type="password" class="regular-text" name="xciv_stripe[test_webhook]" value="" placeholder="<?php echo $s['test_webhook'] ? esc_attr__( '•••• saved — leave blank to keep', 'xciv-courier' ) : ''; ?>" autocomplete="new-password"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Live secret key', 'xciv-courier' ); ?></th>
					<td><input type="password" class="regular-text" name="xciv_stripe[secret_key]" value="" placeholder="<?php echo $s['secret_key'] ? esc_attr__( '•••• saved — leave blank to keep', 'xciv-courier' ) : ''; ?>" autocomplete="new-password"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Live publishable key', 'xciv-courier' ); ?></th>
					<td><input type="text" class="regular-text" name="xciv_stripe[publishable]" value="<?php echo esc_attr( $s['publishable'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Live webhook secret', 'xciv-courier' ); ?></th>
					<td><input type="password" class="regular-text" name="xciv_stripe[webhook_secret]" value="" placeholder="<?php echo $s['webhook_secret'] ? esc_attr__( '•••• saved — leave blank to keep', 'xciv-courier' ) : ''; ?>" autocomplete="new-password"></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Stripe settings', 'xciv-courier' ) ); ?>
		</form>
	</div>
	<?php
}
