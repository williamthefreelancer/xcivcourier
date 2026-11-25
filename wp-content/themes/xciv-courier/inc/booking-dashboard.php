<?php
/**
 * WooCommerce My Account booking dashboard (UberEats-style tracker).
 *
 * After checkout, customers land on My Account with live booking status.
 *
 * @package XCIV_Courier
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Order meta key for queue booking progress. */
const XCIV_BOOKING_STATUS_META = '_xciv_booking_status';

/**
 * Booking progress stages (customer-facing tracker).
 *
 * @return array<string, array{label:string, detail:string}>
 */
function xciv_booking_status_stages(): array {
	return array(
		'confirmed' => array(
			'label'  => __( 'Confirmed', 'xciv-courier' ),
			'detail' => __( 'We have your booking and are lining up a sitter.', 'xciv-courier' ),
		),
		'assigned'  => array(
			'label'  => __( 'Sitter assigned', 'xciv-courier' ),
			'detail' => __( 'Your line sitter is locked in for this window.', 'xciv-courier' ),
		),
		'en_route'  => array(
			'label'  => __( 'On the way', 'xciv-courier' ),
			'detail' => __( 'Your sitter is heading to the location.', 'xciv-courier' ),
		),
		'in_line'   => array(
			'label'  => __( 'In line', 'xciv-courier' ),
			'detail' => __( 'Holding your spot — stay close for claim time.', 'xciv-courier' ),
		),
		'ready'     => array(
			'label'  => __( 'Ready to claim', 'xciv-courier' ),
			'detail' => __( 'Your turn is up. Head over and claim your place.', 'xciv-courier' ),
		),
		'completed' => array(
			'label'  => __( 'Completed', 'xciv-courier' ),
			'detail' => __( 'This sit is wrapped. Thanks for booking XCIV.', 'xciv-courier' ),
		),
		'cancelled' => array(
			'label'  => __( 'Cancelled', 'xciv-courier' ),
			'detail' => __( 'This booking was cancelled.', 'xciv-courier' ),
		),
	);
}

/**
 * Whether an order is a line-sitting booking.
 */
function xciv_order_is_booking( WC_Order $order ): bool {
	$base_id = (int) get_option( XCIV_BOOKING_PRODUCT_OPTION, 0 );
	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		if ( $base_id > 0 && (int) $item->get_product_id() === $base_id ) {
			return true;
		}
		if ( $item->get_meta( 'Service', true ) !== '' ) {
			return true;
		}
	}
	return false;
}

/**
 * Pull booking meta from an order (reuses SMS helper when present).
 *
 * @return array{meta:array<string,string>,has_live_sms:bool,customer_phone:string,order_id:int}
 */
function xciv_dashboard_order_context( WC_Order $order ): array {
	if ( function_exists( 'xciv_sms_order_booking_context' ) ) {
		return xciv_sms_order_booking_context( $order );
	}

	$meta = array();
	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		foreach ( $item->get_meta_data() as $meta_obj ) {
			$data = $meta_obj->get_data();
			$key  = (string) ( $data['key'] ?? '' );
			$val  = $data['value'] ?? '';
			if ( $key === '' || ! is_scalar( $val ) || str_starts_with( $key, '_' ) || $key === 'xciv_addon' ) {
				continue;
			}
			$meta[ $key ] = (string) $val;
		}
	}

	return array(
		'meta'           => $meta,
		'has_live_sms'   => false,
		'customer_phone' => (string) ( $meta['Contact phone'] ?? $order->get_billing_phone() ),
		'order_id'       => $order->get_id(),
	);
}

/**
 * Current booking status slug for an order.
 */
function xciv_booking_get_status( WC_Order $order ): string {
	$status = (string) $order->get_meta( XCIV_BOOKING_STATUS_META, true );
	$stages = xciv_booking_status_stages();

	if ( $status !== '' && isset( $stages[ $status ] ) ) {
		return $status;
	}

	$wc = $order->get_status();
	if ( in_array( $wc, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
		return 'cancelled';
	}
	if ( $wc === 'completed' ) {
		return 'completed';
	}

	return 'confirmed';
}

/**
 * Persist booking status and sync completed Woo status when needed.
 */
function xciv_booking_set_status( WC_Order $order, string $status ): void {
	$stages = xciv_booking_status_stages();
	if ( ! isset( $stages[ $status ] ) ) {
		return;
	}

	$order->update_meta_data( XCIV_BOOKING_STATUS_META, $status );
	$order->save();

	if ( $status === 'completed' && ! $order->has_status( 'completed' ) ) {
		$order->update_status( 'completed', __( 'Booking marked completed from XCIV tracker.', 'xciv-courier' ) );
	}

	if ( $status === 'cancelled' && ! $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
		$order->update_status( 'cancelled', __( 'Booking cancelled from XCIV tracker.', 'xciv-courier' ) );
	}
}

/**
 * Seed confirmed status when a booking order is paid / processing.
 *
 * @param int $order_id Order ID.
 */
function xciv_booking_seed_status_on_order( int $order_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order || ! xciv_order_is_booking( $order ) ) {
		return;
	}

	$existing = (string) $order->get_meta( XCIV_BOOKING_STATUS_META, true );
	if ( $existing !== '' ) {
		return;
	}

	xciv_booking_set_status( $order, 'confirmed' );
}
add_action( 'woocommerce_payment_complete', 'xciv_booking_seed_status_on_order', 15 );
add_action( 'woocommerce_order_status_processing', 'xciv_booking_seed_status_on_order', 15 );
add_action( 'woocommerce_order_status_on-hold', 'xciv_booking_seed_status_on_order', 15 );
add_action( 'woocommerce_order_status_completed', 'xciv_booking_seed_status_on_order', 15 );

/**
 * Create + auto-login a customer account for guest booking checkouts.
 *
 * @param int      $order_id Order ID.
 * @param array    $data     Posted checkout data.
 * @param WC_Order $order    Order object.
 */
function xciv_booking_ensure_customer_account( int $order_id, array $data, $order ): void {
	if ( ! $order instanceof WC_Order || ! xciv_order_is_booking( $order ) ) {
		return;
	}

	if ( is_user_logged_in() ) {
		return;
	}

	$email = $order->get_billing_email();
	if ( ! is_email( $email ) ) {
		return;
	}

	$user_id = email_exists( $email );
	if ( ! $user_id ) {
		$username = wc_create_new_customer_username( $email, array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
		) );
		$password = wp_generate_password();
		$user_id  = wc_create_new_customer( $email, $username, $password );
		if ( is_wp_error( $user_id ) ) {
			return;
		}
	}

	$order->set_customer_id( (int) $user_id );
	$order->save();

	wc_set_customer_auth_cookie( (int) $user_id );
}
add_action( 'woocommerce_checkout_order_processed', 'xciv_booking_ensure_customer_account', 30, 3 );

/**
 * Send booking customers to My Account instead of the classic thank-you page.
 *
 * @param string   $return_url Default return URL.
 * @param WC_Order $order      Order.
 */
function xciv_booking_return_url( string $return_url, $order ): string {
	if ( ! $order instanceof WC_Order || ! xciv_order_is_booking( $order ) ) {
		return $return_url;
	}

	$account = wc_get_page_permalink( 'myaccount' );
	if ( ! $account ) {
		return $return_url;
	}

	return add_query_arg(
		array(
			'xciv_booking' => $order->get_id(),
			'key'          => $order->get_order_key(),
			'xciv_thanks'  => '1',
		),
		$account
	);
}
add_filter( 'woocommerce_get_return_url', 'xciv_booking_return_url', 20, 2 );

/**
 * Hard redirect order-received → My Account for booking orders (covers edge paths).
 */
function xciv_booking_redirect_thankyou_to_account(): void {
	if ( is_admin() || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
		return;
	}

	global $wp;
	$order_id = absint( $wp->query_vars['order-received'] ?? 0 );
	if ( ! $order_id ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order || ! xciv_order_is_booking( $order ) ) {
		return;
	}

	$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $key !== '' && ! hash_equals( $order->get_order_key(), $key ) ) {
		return;
	}

	$account = wc_get_page_permalink( 'myaccount' );
	if ( ! $account ) {
		return;
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'xciv_booking' => $order_id,
				'key'          => $order->get_order_key(),
				'xciv_thanks'  => '1',
			),
			$account
		)
	);
	exit;
}
add_action( 'template_redirect', 'xciv_booking_redirect_thankyou_to_account', 5 );

/**
 * Enqueue dashboard assets on My Account.
 */
function xciv_booking_dashboard_assets(): void {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}

	wp_enqueue_style(
		'xciv-courier-account-dashboard',
		XCIV_COURIER_URI . '/assets/css/account-dashboard.css',
		array(),
		XCIV_COURIER_VERSION
	);

	wp_enqueue_script(
		'xciv-courier-account-dashboard',
		XCIV_COURIER_URI . '/assets/js/account-dashboard.js',
		array(),
		XCIV_COURIER_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'xciv_booking_dashboard_assets' );

/**
 * Mark account pages for the feed shell.
 *
 * @param array<int, string> $classes Body classes.
 * @return array<int, string>
 */
function xciv_booking_account_body_class( array $classes ): array {
	if ( function_exists( 'is_account_page' ) && is_account_page() ) {
		$classes[] = 'xciv-account-feed';
	}
	return $classes;
}
add_filter( 'body_class', 'xciv_booking_account_body_class' );

/**
 * Slim account nav for a service-app feel.
 *
 * @param array<string, string> $items Menu items.
 * @return array<string, string>
 */
function xciv_booking_account_menu_items( array $items ): array {
	$keep = array( 'dashboard', 'orders', 'edit-account', 'customer-logout' );
	$out  = array();
	foreach ( $keep as $key ) {
		if ( isset( $items[ $key ] ) ) {
			$out[ $key ] = $items[ $key ];
		}
	}

	if ( isset( $out['dashboard'] ) ) {
		$out['dashboard'] = __( 'Home', 'xciv-courier' );
	}
	if ( isset( $out['orders'] ) ) {
		$out['orders'] = __( 'Sits', 'xciv-courier' );
	}
	if ( isset( $out['edit-account'] ) ) {
		$out['edit-account'] = __( 'Account', 'xciv-courier' );
	}
	if ( isset( $out['customer-logout'] ) ) {
		$out['customer-logout'] = __( 'Log out', 'xciv-courier' );
	}

	return $out ?: $items;
}
add_filter( 'woocommerce_account_menu_items', 'xciv_booking_account_menu_items', 40 );

/**
 * Customer booking orders, newest first.
 *
 * @return WC_Order[]
 */
function xciv_booking_customer_orders( int $user_id, int $limit = 12 ): array {
	if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}

	$orders = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'limit'       => $limit,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'return'      => 'objects',
		)
	);

	$bookings = array();
	foreach ( $orders as $order ) {
		if ( $order instanceof WC_Order && xciv_order_is_booking( $order ) ) {
			$bookings[] = $order;
		}
	}

	return $bookings;
}

/**
 * Resolve the booking to spotlight on the dashboard.
 */
function xciv_booking_dashboard_focus_order( int $user_id ): ?WC_Order {
	$requested = isset( $_GET['xciv_booking'] ) ? absint( $_GET['xciv_booking'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$key       = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( $requested > 0 ) {
		$order = wc_get_order( $requested );
		if ( $order instanceof WC_Order && xciv_order_is_booking( $order ) ) {
			$owns = (int) $order->get_customer_id() === $user_id;
			$key_ok = $key !== '' && hash_equals( $order->get_order_key(), $key );
			if ( $owns || $key_ok || current_user_can( 'manage_woocommerce' ) ) {
				return $order;
			}
		}
	}

	$bookings = xciv_booking_customer_orders( $user_id, 20 );
	foreach ( $bookings as $order ) {
		$status = xciv_booking_get_status( $order );
		if ( ! in_array( $status, array( 'completed', 'cancelled' ), true ) ) {
			return $order;
		}
	}

	return $bookings[0] ?? null;
}

/**
 * Format a datetime-local / ISO-ish string for display.
 */
function xciv_booking_format_when( string $raw ): string {
	if ( $raw === '' ) {
		return '';
	}
	$ts = strtotime( $raw );
	if ( ! $ts ) {
		return $raw;
	}
	return wp_date( 'D, M j · g:i A', $ts );
}

/**
 * Active tracker stages (exclude cancelled from the rail).
 *
 * @return array<string, array{label:string, detail:string}>
 */
function xciv_booking_tracker_stages(): array {
	$stages = xciv_booking_status_stages();
	unset( $stages['cancelled'] );
	return $stages;
}

/**
 * Short window string for cards (start only, compact).
 */
function xciv_booking_format_when_short( string $raw ): string {
	if ( $raw === '' ) {
		return '';
	}
	$ts = strtotime( $raw );
	if ( ! $ts ) {
		return $raw;
	}
	return wp_date( 'M j · g:i A', $ts );
}

/**
 * Collect add-on product names on a booking order.
 *
 * @return array<int, string>
 */
function xciv_booking_order_addon_labels( WC_Order $order ): array {
	$labels  = array();
	$base_id = (int) get_option( XCIV_BOOKING_PRODUCT_OPTION, 0 );
	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$is_addon = (string) $item->get_meta( 'xciv_addon', true ) !== '';
		$is_base  = $base_id > 0 && (int) $item->get_product_id() === $base_id;
		if ( ! $is_addon && ( $is_base || $item->get_meta( 'Service', true ) !== '' ) ) {
			continue;
		}
		$name = $item->get_name();
		if ( $name !== '' ) {
			$labels[] = $name;
		}
	}
	return array_values( array_unique( $labels ) );
}

/**
 * Structured line-sitting task snapshot for cards + panels.
 *
 * @return array{
 *   order_id:int,
 *   status:string,
 *   status_label:string,
 *   status_detail:string,
 *   service:string,
 *   mission_title:string,
 *   location:string,
 *   city:string,
 *   parking:string,
 *   start_raw:string,
 *   end_raw:string,
 *   start:string,
 *   end:string,
 *   start_short:string,
 *   hours:string,
 *   claim_who:string,
 *   claim_name:string,
 *   claim_phone:string,
 *   instructions:string,
 *   has_live_sms:bool,
 *   addons:array<int,string>,
 *   total_html:string,
 *   mission_rows:array<int, array{label:string, value:string}>,
 *   location_rows:array<int, array{label:string, value:string}>,
 *   claim_rows:array<int, array{label:string, value:string}>,
 *   cover_rows:array<int, array{label:string, value:string}>,
 *   is_done:bool,
 *   link:string
 * }
 */
function xciv_booking_task_snapshot( WC_Order $order ): array {
	$ctx    = xciv_dashboard_order_context( $order );
	$meta   = $ctx['meta'];
	$status = xciv_booking_get_status( $order );
	$stages = xciv_booking_status_stages();
	$info   = $stages[ $status ] ?? $stages['confirmed'];

	$service      = (string) ( $meta['Service'] ?? __( 'Line sitting', 'xciv-courier' ) );
	$service_slug = '';
	if ( function_exists( 'xciv_booking_service_types' ) ) {
		foreach ( xciv_booking_service_types() as $slug => $def ) {
			if ( isset( $def['label'] ) && (string) $def['label'] === $service ) {
				$service_slug = (string) $slug;
				break;
			}
		}
	}
	$location = (string) ( $meta['Location'] ?? '' );
	$city     = (string) ( $meta['City / area'] ?? '' );
	$parking  = (string) ( $meta['Parking / access'] ?? '' );
	$start_r  = (string) ( $meta['Start'] ?? '' );
	$end_r    = (string) ( $meta['End'] ?? '' );
	$hours    = (string) ( $meta['Billable hours'] ?? '' );
	$claim    = (string) ( $meta['Who claims'] ?? '' );
	$claim_n  = (string) ( $meta['Claimant name'] ?? '' );
	$claim_p  = (string) ( $meta['Claimant phone'] ?? '' );
	$notes    = (string) ( $meta['Instructions'] ?? '' );

	$mission_keys = array(
		'Event / drop',
		'Brand / item',
		'Door / release time',
		'Store name',
		'Open time',
		'Early access notes',
		'Order / confirmation #',
		'Pickup window',
		'Custom details',
	);

	$mission_title = $service;
	foreach ( array( 'Event / drop', 'Store name', 'Brand / item', 'Custom details' ) as $key ) {
		if ( ! empty( $meta[ $key ] ) ) {
			$mission_title = (string) $meta[ $key ];
			break;
		}
	}

	$mission_rows = array();
	foreach ( $mission_keys as $key ) {
		if ( empty( $meta[ $key ] ) ) {
			continue;
		}
		$mission_rows[] = array(
			'label' => $key,
			'value' => (string) $meta[ $key ],
		);
	}
	if ( $notes !== '' ) {
		$mission_rows[] = array(
			'label' => __( 'Standing instructions', 'xciv-courier' ),
			'value' => $notes,
		);
	}

	$location_rows = array();
	if ( $location !== '' ) {
		$location_rows[] = array(
			'label' => __( 'Address / venue', 'xciv-courier' ),
			'value' => $location,
		);
	}
	if ( $city !== '' ) {
		$location_rows[] = array(
			'label' => __( 'City / area', 'xciv-courier' ),
			'value' => $city,
		);
	}
	if ( $parking !== '' ) {
		$location_rows[] = array(
			'label' => __( 'Parking / access', 'xciv-courier' ),
			'value' => $parking,
		);
	}

	$claim_rows = array();
	if ( $claim !== '' ) {
		$claim_rows[] = array(
			'label' => __( 'Who claims', 'xciv-courier' ),
			'value' => $claim,
		);
	}
	if ( $claim_n !== '' ) {
		$claim_rows[] = array(
			'label' => __( 'Claimant name', 'xciv-courier' ),
			'value' => $claim_n,
		);
	}
	if ( $claim_p !== '' ) {
		$claim_rows[] = array(
			'label' => __( 'Claimant phone', 'xciv-courier' ),
			'value' => $claim_p,
		);
	}
	if ( ! empty( $meta['Contact phone'] ) ) {
		$claim_rows[] = array(
			'label' => __( 'Your phone', 'xciv-courier' ),
			'value' => (string) $meta['Contact phone'],
		);
	}

	$addons = xciv_booking_order_addon_labels( $order );
	$cover_rows = array();
	$start = xciv_booking_format_when( $start_r );
	$end   = xciv_booking_format_when( $end_r );
	if ( $start !== '' ) {
		$cover_rows[] = array(
			'label' => __( 'Start', 'xciv-courier' ),
			'value' => $start,
		);
	}
	if ( $end !== '' ) {
		$cover_rows[] = array(
			'label' => __( 'End', 'xciv-courier' ),
			'value' => $end,
		);
	}
	if ( $hours !== '' ) {
		$cover_rows[] = array(
			'label' => __( 'Billable hours', 'xciv-courier' ),
			'value' => $hours,
		);
	}
	if ( ! empty( $ctx['has_live_sms'] ) ) {
		$cover_rows[] = array(
			'label' => __( 'Live SMS', 'xciv-courier' ),
			'value' => __( 'Status pings on', 'xciv-courier' ),
		);
	}
	if ( $addons ) {
		$cover_rows[] = array(
			'label' => __( 'Add-ons', 'xciv-courier' ),
			'value' => implode( ' · ', $addons ),
		);
	}
	$cover_rows[] = array(
		'label' => __( 'Total', 'xciv-courier' ),
		'value' => wp_strip_all_tags( $order->get_formatted_order_total() ),
	);

	return array(
		'order_id'       => $order->get_id(),
		'status'         => $status,
		'status_label'   => $info['label'],
		'status_detail'  => $info['detail'],
		'service'        => $service,
		'service_slug'   => $service_slug,
		'mission_title'  => $mission_title,
		'location'       => $location,
		'city'           => $city,
		'parking'        => $parking,
		'start_raw'      => $start_r,
		'end_raw'        => $end_r,
		'start'          => $start,
		'end'            => $end,
		'start_short'    => xciv_booking_format_when_short( $start_r ),
		'hours'          => $hours,
		'claim_who'      => $claim,
		'claim_name'     => $claim_n,
		'claim_phone'    => $claim_p,
		'instructions'   => $notes,
		'has_live_sms'   => ! empty( $ctx['has_live_sms'] ),
		'addons'         => $addons,
		'total_html'     => $order->get_formatted_order_total(),
		'mission_rows'   => $mission_rows,
		'location_rows'  => $location_rows,
		'claim_rows'     => $claim_rows,
		'cover_rows'     => $cover_rows,
		'is_done'        => in_array( $status, array( 'completed', 'cancelled' ), true ),
		'link'           => add_query_arg(
			array(
				'xciv_booking' => $order->get_id(),
				'key'          => $order->get_order_key(),
			),
			wc_get_page_permalink( 'myaccount' )
		),
	);
}

/**
 * Courier hub ZIP codes (University City + Concord).
 *
 * @return array<int, string>
 */
function xciv_booking_service_hubs(): array {
	return array( '28262', '28213', '28027' );
}

/**
 * Free delivery radius (miles) from any hub ZIP.
 */
function xciv_booking_free_radius_miles(): float {
	return 10.0;
}

/**
 * Hard service cutoff — farther venues are removed from listings.
 */
function xciv_booking_max_radius_miles(): float {
	return 18.0;
}

/**
 * Delivery fee from road miles to the nearest hub.
 * Free ≤10 mi; $5 + $1.50/mi over 10 (min $8); out of range returns -1.
 */
function xciv_booking_calc_delivery_fee( float $miles ): float {
	if ( $miles <= xciv_booking_free_radius_miles() ) {
		return 0.0;
	}
	if ( $miles > xciv_booking_max_radius_miles() ) {
		return -1.0;
	}
	$fee = 5.0 + ( ( $miles - xciv_booking_free_radius_miles() ) * 1.5 );
	return (float) max( 8.0, round( $fee ) );
}

/**
 * Attach delivery fee / near flag and drop out-of-range venues.
 *
 * @param array<int, array<string, mixed>> $venues Venue rows with miles + travel_mins.
 * @return array<int, array<string, mixed>>
 */
function xciv_booking_enrich_venues( array $venues ): array {
	$out = array();
	foreach ( $venues as $venue ) {
		$miles = (float) ( $venue['miles'] ?? 0 );
		if ( $miles <= 0 || $miles > xciv_booking_max_radius_miles() ) {
			continue;
		}
		$fee = xciv_booking_calc_delivery_fee( $miles );
		if ( $fee < 0 ) {
			continue;
		}
		$venue['miles']        = round( $miles, 1 );
		$venue['travel_mins']  = max( 1, (int) ( $venue['travel_mins'] ?? (int) round( $miles / 25 * 60 ) ) );
		$venue['delivery_fee'] = $fee;
		$venue['near']         = $fee <= 0.0;
		$out[]                 = $venue;
	}
	return $out;
}

/**
 * Flat catalog of every listable venue (after enrich / filter).
 *
 * @return array<int, array<string, mixed>>
 */
function xciv_booking_all_venues(): array {
	return array_merge(
		xciv_booking_busy_venues(),
		xciv_booking_shopping_venues(),
		xciv_booking_sneaker_venues()
	);
}

/**
 * Look up a venue row by id.
 *
 * @return array<string, mixed>|null
 */
function xciv_booking_venue_by_id( string $id ): ?array {
	$id = sanitize_key( $id );
	if ( $id === '' ) {
		return null;
	}
	foreach ( xciv_booking_all_venues() as $venue ) {
		if ( (string) ( $venue['id'] ?? '' ) === $id ) {
			return $venue;
		}
	}
	return null;
}

/**
 * Server-trusted delivery fee for a venue id (0 if unknown / local).
 */
function xciv_booking_delivery_fee_for_venue( string $id ): float {
	$venue = xciv_booking_venue_by_id( $id );
	if ( ! $venue ) {
		return 0.0;
	}
	return (float) ( $venue['delivery_fee'] ?? 0.0 );
}

/**
 * Busiest Charlotte-metro venues for line sits (wait / crowd proven).
 * Scoped to ≤18 mi of hubs 28262 / 28213 / 28027; farther DMVs removed.
 *
 * @return array<int, array{
 *   id:string,
 *   name:string,
 *   area:string,
 *   address:string,
 *   service:string,
 *   wait:string,
 *   note:string,
 *   heat:string,
 *   miles:float,
 *   travel_mins:int,
 *   delivery_fee?:float,
 *   near?:bool
 * }>
 */
function xciv_booking_busy_venues(): array {
	return xciv_booking_enrich_venues(
		array(
			array(
				'id'          => 'dmv-concord',
				'name'        => 'NCDMV Concord',
				'area'        => 'Concord, NC',
				'address'     => '2192 Kannapolis Hwy, Concord, NC 28027',
				'service'     => 'queue_open',
				'wait'        => '~3h 15m avg',
				'note'        => 'Metro’s longest DMV waits — walk-in lobby fills fast.',
				'heat'        => 'extreme',
				'miles'       => 3.4,
				'travel_mins' => 8,
			),
			array(
				'id'          => 'apple-southpark',
				'name'        => 'Apple SouthPark',
				'area'        => 'SouthPark, Charlotte',
				'address'     => '4400 Sharon Rd, Charlotte, NC 28211',
				'service'     => 'secure_pickup',
				'wait'        => 'Launch camps',
				'note'        => 'Day-one iPhone lines start overnight; ~100 by open.',
				'heat'        => 'high',
				'miles'       => 13.3,
				'travel_mins' => 32,
			),
			array(
				'id'          => 'swatch-southpark',
				'name'        => 'Swatch SouthPark',
				'area'        => 'SouthPark, Charlotte',
				'address'     => 'SouthPark Mall, Sharon Rd',
				'service'     => 'hold_spot',
				'wait'        => 'Drop frenzy',
				'note'        => 'AP×Swatch drops wrapped the mall; police managed doors.',
				'heat'        => 'high',
				'miles'       => 13.3,
				'travel_mins' => 32,
			),
		)
	);
}

/**
 * Grocery / errand stores for personal shopping (Charlotte & Harrisburg corridor).
 *
 * @return array<int, array{
 *   id:string,
 *   name:string,
 *   area:string,
 *   address:string,
 *   service:string,
 *   wait:string,
 *   note:string,
 *   heat:string,
 *   kind?:string,
 *   accent?:string,
 *   miles:float,
 *   travel_mins:int,
 *   delivery_fee?:float,
 *   near?:bool
 * }>
 */
function xciv_booking_shopping_venues(): array {
	return xciv_booking_enrich_venues(
		array(
			array(
				'id'          => 'ht-brookdale',
				'name'        => 'Harris Teeter Brookdale',
				'area'        => 'Harrisburg, NC',
				'address'     => '9641 Brookdale Dr, Harrisburg, NC 28215',
				'service'     => 'personal_shop',
				'wait'        => 'List run',
				'note'        => 'Go-to Harrisburg Teeter — VIC deals, deli, full weekly shop.',
				'heat'        => 'high',
				'kind'        => 'grocery',
				'accent'      => __( 'Local favorite', 'xciv-courier' ),
				'miles'       => 6.5,
				'travel_mins' => 16,
			),
			array(
				'id'          => 'publix-harris-square',
				'name'        => 'Publix Harris Square',
				'area'        => 'Harrisburg, NC',
				'address'     => '4055 Harris Square Dr, Harrisburg, NC 28075',
				'service'     => 'personal_shop',
				'wait'        => 'List run',
				'note'        => 'Pub Subs, bakery, and BOGO runs — strong personal-shop pick.',
				'heat'        => 'high',
				'kind'        => 'grocery',
				'accent'      => __( 'Crowd pick', 'xciv-courier' ),
				'miles'       => 6.8,
				'travel_mins' => 16,
			),
			array(
				'id'          => 'food-lion-hwy49',
				'name'        => 'Food Lion Hwy 49',
				'area'        => 'Harrisburg, NC',
				'address'     => '4226 Hwy 49, Harrisburg, NC 28075',
				'service'     => 'personal_shop',
				'wait'        => 'Staples',
				'note'        => 'Everyday staples and produce for the Harrisburg corridor.',
				'heat'        => 'med',
				'kind'        => 'grocery',
				'accent'      => __( 'Everyday', 'xciv-courier' ),
				'miles'       => 6.5,
				'travel_mins' => 16,
			),
			array(
				'id'          => 'lowes-foods-harrisburg',
				'name'        => 'Lowes Foods Harrisburg',
				'area'        => 'Harrisburg, NC',
				'address'     => '4445 Highway 49 S, Harrisburg, NC 28075',
				'service'     => 'personal_shop',
				'wait'        => 'List run',
				'note'        => 'Regional grocer with prepared foods and full cart runs.',
				'heat'        => 'med',
				'kind'        => 'grocery',
				'accent'      => __( 'Regional', 'xciv-courier' ),
				'miles'       => 6.2,
				'travel_mins' => 15,
			),
			array(
				'id'          => 'costco-university',
				'name'        => 'Costco Wholesale University',
				'area'        => 'University City, Charlotte',
				'address'     => '8510 University City Blvd, Charlotte, NC 28213',
				'service'     => 'personal_shop',
				'wait'        => 'Bulk run',
				'note'        => 'Bulk meat, pantry, and household — membership shop on your list.',
				'heat'        => 'high',
				'kind'        => 'grocery',
				'accent'      => __( 'Bulk', 'xciv-courier' ),
				'miles'       => 1.8,
				'travel_mins' => 4,
			),
			array(
				'id'          => 'trader-joes-north',
				'name'        => 'Trader Joe’s Charlotte North',
				'area'        => 'University City, Charlotte',
				'address'     => '1820 E Arbors Dr, Charlotte, NC 28262',
				'service'     => 'personal_shop',
				'wait'        => 'Specialty',
				'note'        => 'Seasonal snacks, frozen favorites, and weekly produce runs.',
				'heat'        => 'high',
				'kind'        => 'grocery',
				'accent'      => __( 'Specialty', 'xciv-courier' ),
				'miles'       => 0.7,
				'travel_mins' => 2,
			),
			array(
				'id'          => 'publix-weddington-concord',
				'name'        => 'Publix Weddington Rd',
				'area'        => 'Concord, NC',
				'address'     => '5015 Weddington Rd, Concord, NC 28027',
				'service'     => 'personal_shop',
				'wait'        => 'List run',
				'note'        => 'Concord Publix for full grocery + deli personal shops.',
				'heat'        => 'med',
				'kind'        => 'grocery',
				'accent'      => __( 'Concord', 'xciv-courier' ),
				'miles'       => 3.3,
				'travel_mins' => 8,
			),
		)
	);
}

/**
 * Sneaker / consignment spots for Jordans, Nike, and tailored size hunts.
 * Ballantyne removed (>18 mi). SouthPark keeps a delivery fee for the drive.
 *
 * @return array<int, array{
 *   id:string,
 *   name:string,
 *   area:string,
 *   address:string,
 *   service:string,
 *   wait:string,
 *   note:string,
 *   heat:string,
 *   kind?:string,
 *   accent?:string,
 *   miles:float,
 *   travel_mins:int,
 *   delivery_fee?:float,
 *   near?:bool
 * }>
 */
function xciv_booking_sneaker_venues(): array {
	return xciv_booking_enrich_venues(
		array(
			array(
				'id'          => 'request-concord-mills',
				'name'        => 'Request Boutique Concord Mills',
				'area'        => 'Concord Mills, NC',
				'address'     => '8111 Concord Mills Blvd #519, Concord, NC 28027',
				'service'     => 'personal_shop',
				'wait'        => 'Size hunt',
				'note'        => 'Buy / sell / trade — Jordans, Nike, Yeezy walls for the pair you want.',
				'heat'        => 'extreme',
				'kind'        => 'sneakers',
				'accent'      => __( 'Jordan · Nike', 'xciv-courier' ),
				'miles'       => 4.2,
				'travel_mins' => 10,
			),
			array(
				'id'          => 'legacy-university',
				'name'        => 'Legacy',
				'area'        => 'University City, Charlotte',
				'address'     => '8925 J M Keynes Dr #4, Charlotte, NC 28262',
				'service'     => 'personal_shop',
				'wait'        => 'Size hunt',
				'note'        => 'University City sneaker shop — tailored Jordan / Nike size pulls near Harrisburg.',
				'heat'        => 'high',
				'kind'        => 'sneakers',
				'accent'      => __( 'Near you', 'xciv-courier' ),
				'miles'       => 0.8,
				'travel_mins' => 2,
			),
			array(
				'id'          => 'sole-station-ucity',
				'name'        => 'Sole Station',
				'area'        => 'University City, Charlotte',
				'address'     => '9510 University City Blvd, Charlotte, NC 28213',
				'service'     => 'personal_shop',
				'wait'        => 'Size hunt',
				'note'        => 'Local sneaker boutique for Nike, Jordan, and size-specific hunts.',
				'heat'        => 'high',
				'kind'        => 'sneakers',
				'accent'      => __( 'Nike · Jordan', 'xciv-courier' ),
				'miles'       => 2.0,
				'travel_mins' => 5,
			),
			array(
				'id'          => 'social-status-midwood',
				'name'        => 'Social Status',
				'area'        => 'Plaza Midwood, Charlotte',
				'address'     => '1519 Central Ave, Charlotte, NC 28205',
				'service'     => 'hold_spot',
				'wait'        => 'Drop / collab',
				'note'        => 'Upscale Jordan & Nike boutique — collabs and appointment drops.',
				'heat'        => 'extreme',
				'kind'        => 'sneakers',
				'accent'      => __( 'Collabs', 'xciv-courier' ),
				'miles'       => 7.2,
				'travel_mins' => 17,
			),
			array(
				'id'          => 'request-cherry',
				'name'        => 'Request Boutique Cherry',
				'area'        => 'Cherry, Charlotte',
				'address'     => '209 S Kings Dr #201, Charlotte, NC 28204',
				'service'     => 'personal_shop',
				'wait'        => 'Size hunt',
				'note'        => 'Midtown Request — deep consignment wall for sold-out Jordans and Nike.',
				'heat'        => 'high',
				'kind'        => 'sneakers',
				'accent'      => __( 'Consignment', 'xciv-courier' ),
				'miles'       => 9.4,
				'travel_mins' => 23,
			),
			array(
				'id'          => 'elite-boutique-midwood',
				'name'        => 'Elite Boutique',
				'area'        => 'Plaza Midwood, Charlotte',
				'address'     => '2600 Central Ave Suite H, Charlotte, NC 28205',
				'service'     => 'personal_shop',
				'wait'        => 'Size hunt',
				'note'        => 'Nike, Jordan, New Balance selections for style-matched shoe runs.',
				'heat'        => 'med',
				'kind'        => 'sneakers',
				'accent'      => __( 'Streetwear', 'xciv-courier' ),
				'miles'       => 7.2,
				'travel_mins' => 17,
			),
			array(
				'id'          => 'house-of-hoops-southpark',
				'name'        => 'House of Hoops SouthPark',
				'area'        => 'SouthPark, Charlotte',
				'address'     => '4400 Sharon Rd E20, Charlotte, NC 28211',
				'service'     => 'hold_spot',
				'wait'        => 'Launch line',
				'note'        => 'Foot Locker Hoops — Nike Basketball, Jordan, and launch queues.',
				'heat'        => 'high',
				'kind'        => 'sneakers',
				'accent'      => __( 'Jordan Brand', 'xciv-courier' ),
				'miles'       => 13.3,
				'travel_mins' => 32,
			),
		)
	);
}

/**
 * Human travel / delivery line for a venue card.
 *
 * @param array<string, mixed> $venue Venue row.
 */
function xciv_booking_venue_travel_label( array $venue ): string {
	$miles  = (float) ( $venue['miles'] ?? 0 );
	$mins   = (int) ( $venue['travel_mins'] ?? 0 );
	$fee    = (float) ( $venue['delivery_fee'] ?? 0 );
	$near   = ! empty( $venue['near'] ) || $fee <= 0.0;
	$miles_s = $miles > 0 ? number_format_i18n( $miles, 1 ) : '';
	$drive   = $mins > 0
		? sprintf(
			/* translators: %d: estimated drive minutes */
			__( '~%d min drive', 'xciv-courier' ),
			$mins
		)
		: '';

	if ( $near ) {
		if ( $miles_s !== '' && $drive !== '' ) {
			return sprintf(
				/* translators: 1: miles, 2: drive estimate */
				__( '%1$s mi · %2$s · Free', 'xciv-courier' ),
				$miles_s,
				$drive
			);
		}
		return __( 'Near you · Free delivery', 'xciv-courier' );
	}

	$fee_s = function_exists( 'wc_price' )
		? wp_strip_all_tags( wc_price( $fee ) )
		: '$' . number_format_i18n( $fee, 0 );

	if ( $miles_s !== '' && $drive !== '' ) {
		return sprintf(
			/* translators: 1: fee, 2: miles, 3: drive estimate */
			__( '%1$s delivery · %2$s mi · %3$s', 'xciv-courier' ),
			$fee_s,
			$miles_s,
			$drive
		);
	}

	return sprintf(
		/* translators: %s: formatted delivery fee */
		__( '%s delivery fee', 'xciv-courier' ),
		$fee_s
	);
}

/**
 * Render a busy-venue store card (Uber Eats listing style).
 *
 * @param array<string, mixed> $venue Venue row.
 */
function xciv_booking_render_venue_card( array $venue ): void {
	$fee  = (float) ( $venue['delivery_fee'] ?? 0 );
	$near = ! empty( $venue['near'] ) || $fee <= 0.0;
	$book = add_query_arg(
		array(
			'xciv_venue' => (string) $venue['id'],
			'xciv_service' => (string) $venue['service'],
			'xciv_store' => (string) $venue['name'],
			'xciv_loc'   => (string) $venue['address'],
			'xciv_city' => (string) $venue['area'],
		),
		home_url( '/#book' )
	);
	$service_label = '';
	if ( function_exists( 'xciv_booking_service_types' ) ) {
		$types = xciv_booking_service_types();
		$slug  = (string) $venue['service'];
		if ( isset( $types[ $slug ]['label'] ) ) {
			$service_label = (string) $types[ $slug ]['label'];
		}
	}
	$heat   = (string) ( $venue['heat'] ?? 'high' );
	$kind   = (string) ( $venue['kind'] ?? 'line' );
	$accent = (string) ( $venue['accent'] ?? '' );
	if ( $accent === '' ) {
		$accent = $kind === 'sneakers' || $kind === 'grocery'
			? __( 'Selected', 'xciv-courier' )
			: __( 'Busiest', 'xciv-courier' );
	}
	$cta = ( (string) $venue['service'] === 'personal_shop' )
		? __( 'Book a shop', 'xciv-courier' )
		: __( 'Book a sit', 'xciv-courier' );
	$travel = xciv_booking_venue_travel_label( $venue );
	$card_class = 'xciv-card xciv-card--venue is-heat-' . $heat;
	if ( ! $near ) {
		$card_class .= ' has-delivery-fee';
	}
	?>
	<article
		class="<?php echo esc_attr( $card_class ); ?>"
		data-xciv-card
		data-xciv-venue
		data-status="venue"
		data-service="<?php echo esc_attr( (string) $venue['service'] ); ?>"
		data-kind="<?php echo esc_attr( $kind ); ?>"
		data-live="1"
		data-sms="0"
		data-miles="<?php echo esc_attr( (string) ( $venue['miles'] ?? '' ) ); ?>"
		data-fee="<?php echo esc_attr( (string) $fee ); ?>"
		data-search="<?php echo esc_attr( strtolower( trim( (string) $venue['name'] . ' ' . (string) $venue['area'] . ' ' . (string) $venue['address'] . ' ' . (string) $venue['note'] . ' ' . $kind . ' ' . $accent . ' ' . $travel ) ) ); ?>"
	>
		<a class="xciv-card__link" href="<?php echo esc_url( $book ); ?>" data-xciv-booking-open>
			<div class="xciv-card__media" aria-hidden="true">
				<div class="xciv-card__art xciv-card__art--<?php echo esc_attr( (string) $venue['service'] ); ?>">
					<span class="xciv-card__grid"></span>
					<span class="xciv-card__pin"></span>
				</div>
				<span class="xciv-card__badge is-venue"><?php echo esc_html( (string) $venue['wait'] ); ?></span>
				<?php if ( ! $near ) : ?>
					<span class="xciv-card__badge is-fee">
						<?php
						echo esc_html(
							function_exists( 'wc_price' )
								? sprintf(
									/* translators: %s: fee amount */
									__( '+%s delivery', 'xciv-courier' ),
									wp_strip_all_tags( wc_price( $fee ) )
								)
								: sprintf( '+$%s delivery', number_format_i18n( $fee, 0 ) )
						);
						?>
					</span>
				<?php endif; ?>
			</div>
			<div class="xciv-card__body">
				<div class="xciv-card__title-row">
					<h4 class="xciv-card__title"><?php echo esc_html( (string) $venue['name'] ); ?></h4>
				</div>
				<p class="xciv-card__meta"><?php echo esc_html( (string) $venue['area'] ); ?> · <?php echo esc_html( $cta ); ?></p>
				<p class="xciv-card__location"><?php echo esc_html( (string) $venue['address'] ); ?></p>
				<p class="xciv-card__travel<?php echo $near ? '' : ' is-fee'; ?>"><?php echo esc_html( $travel ); ?></p>
				<div class="xciv-card__tags">
					<?php if ( $service_label !== '' ) : ?>
						<span class="xciv-card__tag"><?php echo esc_html( $service_label ); ?></span>
					<?php endif; ?>
					<span class="xciv-card__tag xciv-card__tag--accent"><?php echo esc_html( $accent ); ?></span>
					<?php if ( $near ) : ?>
						<span class="xciv-card__tag xciv-card__tag--near"><?php esc_html_e( 'Near hubs', 'xciv-courier' ); ?></span>
					<?php else : ?>
						<span class="xciv-card__tag xciv-card__tag--fee"><?php esc_html_e( 'Travel fee', 'xciv-courier' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</a>
	</article>
	<?php
}

/**
 * Render a horizontal venue carousel section.
 *
 * @param array<int, array<string, mixed>> $venues Venue rows.
 */
function xciv_booking_render_venue_section( string $section_id, string $title, string $note, array $venues ): void {
	if ( ! $venues ) {
		return;
	}
	?>
	<section class="xciv-feed__section xciv-feed__section--venues" data-xciv-section="<?php echo esc_attr( $section_id ); ?>" aria-labelledby="<?php echo esc_attr( $section_id ); ?>-title">
		<div class="xciv-feed__section-head">
			<h3 id="<?php echo esc_attr( $section_id ); ?>-title"><?php echo esc_html( $title ); ?></h3>
			<span class="xciv-feed__section-note"><?php echo esc_html( $note ); ?></span>
			<div class="xciv-feed__scroll-btns">
				<button type="button" class="xciv-feed__scroll-btn" data-xciv-scroll="prev" aria-label="<?php esc_attr_e( 'Previous', 'xciv-courier' ); ?>">‹</button>
				<button type="button" class="xciv-feed__scroll-btn" data-xciv-scroll="next" aria-label="<?php esc_attr_e( 'Next', 'xciv-courier' ); ?>">›</button>
			</div>
		</div>
		<div class="xciv-feed__carousel" data-xciv-carousel>
			<?php foreach ( $venues as $venue ) : ?>
				<?php xciv_booking_render_venue_card( $venue ); ?>
			<?php endforeach; ?>
		</div>
	</section>
	<?php
}

/**
 * Render a line-sitting booking listing card (Uber Eats feed style).
 *
 * @param array<string, mixed> $task Task snapshot.
 */
function xciv_booking_render_listing_card( array $task, bool $is_active = false ): void {
	$classes = 'xciv-card';
	if ( $is_active ) {
		$classes .= ' is-active';
	}
	if ( ! empty( $task['is_done'] ) ) {
		$classes .= ' is-done';
	} else {
		$classes .= ' is-live';
	}

	$meta_bits = array_filter(
		array(
			(string) $task['status_label'],
			(string) $task['hours'] !== '' ? (string) $task['hours'] . ' hrs' : '',
			(string) $task['start_short'],
		)
	);
	$art = (string) $task['service_slug'] !== '' ? (string) $task['service_slug'] : 'custom';
	?>
	<article
		class="<?php echo esc_attr( $classes ); ?>"
		data-xciv-card
		data-status="<?php echo esc_attr( (string) $task['status'] ); ?>"
		data-service="<?php echo esc_attr( (string) $task['service_slug'] ); ?>"
		data-live="<?php echo empty( $task['is_done'] ) ? '1' : '0'; ?>"
		data-sms="<?php echo ! empty( $task['has_live_sms'] ) ? '1' : '0'; ?>"
		data-search="<?php echo esc_attr( strtolower( trim( (string) $task['mission_title'] . ' ' . (string) $task['location'] . ' ' . (string) $task['service'] . ' #' . (string) $task['order_id'] ) ) ); ?>"
	>
		<a class="xciv-card__link" href="<?php echo esc_url( (string) $task['link'] ); ?>">
			<div class="xciv-card__media" aria-hidden="true">
				<div class="xciv-card__art xciv-card__art--<?php echo esc_attr( $art ); ?>">
					<span class="xciv-card__grid"></span>
					<span class="xciv-card__pin"></span>
				</div>
				<span class="xciv-card__badge is-<?php echo esc_attr( (string) $task['status'] ); ?>">
					<?php echo esc_html( (string) $task['status_label'] ); ?>
				</span>
			</div>
			<div class="xciv-card__body">
				<div class="xciv-card__title-row">
					<h4 class="xciv-card__title"><?php echo esc_html( (string) $task['mission_title'] ); ?></h4>
				</div>
				<p class="xciv-card__meta"><?php echo esc_html( implode( ' · ', $meta_bits ) ); ?></p>
				<?php if ( (string) $task['location'] !== '' ) : ?>
					<p class="xciv-card__location"><?php echo esc_html( (string) $task['location'] ); ?></p>
				<?php endif; ?>
				<div class="xciv-card__tags">
					<span class="xciv-card__tag"><?php echo esc_html( (string) $task['service'] ); ?></span>
					<?php if ( ! empty( $task['has_live_sms'] ) ) : ?>
						<span class="xciv-card__tag xciv-card__tag--accent"><?php esc_html_e( 'Live SMS', 'xciv-courier' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</a>
	</article>
	<?php
}

/**
 * Render one dashboard design panel.
 *
 * @param string                              $eyebrow Small label.
 * @param string                              $title   Panel title.
 * @param string                              $lede    Supporting sentence.
 * @param array<int, array{label:string,value:string}> $rows Fact rows.
 * @param string                              $mod     Optional modifier class suffix.
 */
function xciv_booking_render_panel( string $eyebrow, string $title, string $lede, array $rows, string $mod = '' ): void {
	if ( ! $rows ) {
		return;
	}
	$class = 'xciv-panel';
	if ( $mod !== '' ) {
		$class .= ' xciv-panel--' . sanitize_html_class( $mod );
	}
	?>
	<section class="<?php echo esc_attr( $class ); ?>">
		<header class="xciv-panel__head">
			<p class="xciv-panel__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
			<h4 class="xciv-panel__title"><?php echo esc_html( $title ); ?></h4>
			<?php if ( $lede !== '' ) : ?>
				<p class="xciv-panel__lede"><?php echo esc_html( $lede ); ?></p>
			<?php endif; ?>
		</header>
		<dl class="xciv-panel__rows">
			<?php foreach ( $rows as $row ) : ?>
				<div class="xciv-panel__row">
					<dt><?php echo esc_html( (string) $row['label'] ); ?></dt>
					<dd><?php echo esc_html( (string) $row['value'] ); ?></dd>
				</div>
			<?php endforeach; ?>
		</dl>
	</section>
	<?php
}

/**
 * Task panels for the focused line sit.
 *
 * @param array<string, mixed> $task Task snapshot.
 */
function xciv_booking_render_task_panels( array $task ): void {
	$status = (string) $task['status'];

	$mission_lede = __( 'What the sitter is holding the line for — drop, store, or custom queue.', 'xciv-courier' );
	$location_lede = __( 'Where to stand and how to get in without guessing.', 'xciv-courier' );
	$claim_lede    = __( 'Who steps up when the front of the line opens.', 'xciv-courier' );
	$cover_lede    = __( 'Paid window, hours on the clock, and extras on this sit.', 'xciv-courier' );

	if ( $status === 'ready' ) {
		$claim_lede = __( 'Claim window is open — head over and take your place.', 'xciv-courier' );
	} elseif ( $status === 'in_line' ) {
		$claim_lede = __( 'Spot is held. Stay reachable for the handoff.', 'xciv-courier' );
	} elseif ( $status === 'en_route' ) {
		$location_lede = __( 'Sitter is moving to this pin — confirm access notes if anything changed.', 'xciv-courier' );
	}
	?>
	<div class="xciv-panels" aria-label="<?php esc_attr_e( 'Line sit task panels', 'xciv-courier' ); ?>">
		<?php
		xciv_booking_render_panel(
			__( 'Mission', 'xciv-courier' ),
			(string) $task['mission_title'],
			$mission_lede,
			array_merge(
				array(
					array(
						'label' => __( 'Service', 'xciv-courier' ),
						'value' => (string) $task['service'],
					),
				),
				(array) $task['mission_rows']
			),
			'mission'
		);

		xciv_booking_render_panel(
			__( 'Stand point', 'xciv-courier' ),
			__( 'Location & access', 'xciv-courier' ),
			$location_lede,
			(array) $task['location_rows'],
			'location'
		);

		xciv_booking_render_panel(
			__( 'Handoff', 'xciv-courier' ),
			__( 'Claim protocol', 'xciv-courier' ),
			$claim_lede,
			(array) $task['claim_rows'],
			'claim'
		);

		xciv_booking_render_panel(
			__( 'Cover', 'xciv-courier' ),
			__( 'Window & extras', 'xciv-courier' ),
			$cover_lede,
			(array) $task['cover_rows'],
			'cover'
		);
		?>
	</div>
	<?php
}

/**
 * Render the Uber Eats-style booking feed dashboard.
 */
function xciv_booking_render_dashboard(): void {
	$user_id  = get_current_user_id();
	$current  = wp_get_current_user();
	$focus    = xciv_booking_dashboard_focus_order( $user_id );
	$all      = xciv_booking_customer_orders( $user_id, 12 );
	$thanks   = isset( $_GET['xciv_thanks'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$name     = $current->first_name !== '' ? $current->first_name : $current->display_name;
	$book_url = home_url( '/#book' );
	$orders_url = wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) );

	$stages     = xciv_booking_tracker_stages();
	$stage_keys = array_keys( $stages );
	$focus_task = $focus ? xciv_booking_task_snapshot( $focus ) : null;
	$services   = function_exists( 'xciv_booking_service_types' ) ? xciv_booking_service_types() : array();

	$live_tasks = array();
	$past_tasks = array();
	foreach ( $all as $order ) {
		$task = xciv_booking_task_snapshot( $order );
		if ( ! empty( $task['is_done'] ) ) {
			$past_tasks[] = $task;
		} else {
			$live_tasks[] = $task;
		}
	}

	$area = '';
	if ( $focus_task && (string) $focus_task['city'] !== '' ) {
		$area = (string) $focus_task['city'];
	} elseif ( $focus_task && (string) $focus_task['location'] !== '' ) {
		$area = (string) $focus_task['location'];
	} else {
		foreach ( array_merge( $live_tasks, $past_tasks ) as $task ) {
			if ( (string) $task['city'] !== '' ) {
				$area = (string) $task['city'];
				break;
			}
		}
	}
	if ( $area === '' ) {
		$area = __( '28262 · 28213 · 28027', 'xciv-courier' );
	}
	?>
	<div class="xciv-feed" data-xciv-feed>
		<?php if ( $thanks && $focus_task ) : ?>
			<div class="xciv-feed__toast" role="status">
				<span class="xciv-feed__toast-label"><?php esc_html_e( 'Booking placed', 'xciv-courier' ); ?></span>
				<span class="xciv-feed__toast-copy">
					<?php
					printf(
						/* translators: %d: order number */
						esc_html__( 'Tracking booking #%d — status updates live on this feed.', 'xciv-courier' ),
						(int) $focus_task['order_id']
					);
					?>
				</span>
			</div>
		<?php endif; ?>

		<header class="xciv-feed__top">
			<div class="xciv-feed__modes" role="group" aria-label="<?php esc_attr_e( 'Sit mode', 'xciv-courier' ); ?>">
				<button type="button" class="xciv-feed__mode is-active" data-xciv-mode="live"><?php esc_html_e( 'Live', 'xciv-courier' ); ?></button>
				<button type="button" class="xciv-feed__mode" data-xciv-mode="past"><?php esc_html_e( 'Past', 'xciv-courier' ); ?></button>
			</div>
			<div class="xciv-feed__place">
				<span class="xciv-feed__place-label"><?php esc_html_e( 'Standing near', 'xciv-courier' ); ?></span>
				<strong><?php echo esc_html( $area ); ?></strong>
			</div>
			<label class="xciv-feed__search">
				<span class="screen-reader-text"><?php esc_html_e( 'Search sits', 'xciv-courier' ); ?></span>
				<input type="search" data-xciv-search placeholder="<?php esc_attr_e( 'Search sits, venues, drops…', 'xciv-courier' ); ?>">
			</label>
		</header>

		<nav class="xciv-feed__cats" aria-label="<?php esc_attr_e( 'Service types', 'xciv-courier' ); ?>">
			<button type="button" class="xciv-feed__cat is-active" data-xciv-cat="all">
				<span class="xciv-feed__cat-icon" aria-hidden="true">◎</span>
				<span><?php esc_html_e( 'All', 'xciv-courier' ); ?></span>
			</button>
			<?php foreach ( $services as $slug => $service ) : ?>
				<button type="button" class="xciv-feed__cat" data-xciv-cat="<?php echo esc_attr( (string) $slug ); ?>">
					<span class="xciv-feed__cat-icon xciv-feed__cat-icon--<?php echo esc_attr( (string) $slug ); ?>" aria-hidden="true"></span>
					<span><?php echo esc_html( (string) $service['label'] ); ?></span>
				</button>
			<?php endforeach; ?>
		</nav>

		<div class="xciv-feed__filters" role="toolbar" aria-label="<?php esc_attr_e( 'Filters', 'xciv-courier' ); ?>">
			<button type="button" class="xciv-feed__chip is-active" data-xciv-filter="all"><?php esc_html_e( 'All sits', 'xciv-courier' ); ?></button>
			<button type="button" class="xciv-feed__chip" data-xciv-filter="live"><?php esc_html_e( 'In progress', 'xciv-courier' ); ?></button>
			<button type="button" class="xciv-feed__chip" data-xciv-filter="ready"><?php esc_html_e( 'Ready to claim', 'xciv-courier' ); ?></button>
			<button type="button" class="xciv-feed__chip" data-xciv-filter="sms"><?php esc_html_e( 'Live SMS', 'xciv-courier' ); ?></button>
			<button type="button" class="xciv-feed__chip" data-xciv-filter="completed"><?php esc_html_e( 'Completed', 'xciv-courier' ); ?></button>
		</div>

		<?php
		xciv_booking_render_venue_section(
			'venues',
			__( 'Busiest near you', 'xciv-courier' ),
			__( 'Within ~10 mi free · farther listings add travel fee', 'xciv-courier' ),
			xciv_booking_busy_venues()
		);
		xciv_booking_render_venue_section(
			'grocery',
			__( 'Personal shopping', 'xciv-courier' ),
			__( 'University City · Harrisburg · Concord hubs', 'xciv-courier' ),
			xciv_booking_shopping_venues()
		);
		xciv_booking_render_venue_section(
			'sneakers',
			__( 'Jordan & Nike consignment', 'xciv-courier' ),
			__( 'Near hubs free · SouthPark adds delivery fee', 'xciv-courier' ),
			xciv_booking_sneaker_venues()
		);
		?>

		<div class="xciv-feed__promos">
			<?php if ( $focus_task && empty( $focus_task['is_done'] ) ) : ?>
				<a class="xciv-promo xciv-promo--live" href="<?php echo esc_url( (string) $focus_task['link'] ); ?>">
					<div class="xciv-promo__copy">
						<p class="xciv-promo__eyebrow"><?php esc_html_e( 'Active sit', 'xciv-courier' ); ?></p>
						<h3 class="xciv-promo__title"><?php echo esc_html( (string) $focus_task['status_label'] ); ?></h3>
						<p class="xciv-promo__text"><?php echo esc_html( (string) $focus_task['mission_title'] ); ?></p>
						<span class="xciv-promo__cta"><?php esc_html_e( 'Track now', 'xciv-courier' ); ?></span>
					</div>
					<div class="xciv-promo__visual" aria-hidden="true">
						<span class="xciv-promo__pulse"></span>
					</div>
				</a>
			<?php else : ?>
				<a class="xciv-promo xciv-promo--book" href="<?php echo esc_url( $book_url ); ?>">
					<div class="xciv-promo__copy">
						<p class="xciv-promo__eyebrow"><?php esc_html_e( 'Book a line sitter', 'xciv-courier' ); ?></p>
						<h3 class="xciv-promo__title"><?php esc_html_e( 'Hold the line without standing in it', 'xciv-courier' ); ?></h3>
						<p class="xciv-promo__text"><?php esc_html_e( '$50 / 2-hour minimum · live status on this feed.', 'xciv-courier' ); ?></p>
						<span class="xciv-promo__cta"><?php esc_html_e( 'Start booking', 'xciv-courier' ); ?></span>
					</div>
					<div class="xciv-promo__visual" aria-hidden="true"></div>
				</a>
			<?php endif; ?>

			<div class="xciv-promo xciv-promo--hint">
				<div class="xciv-promo__copy">
					<p class="xciv-promo__eyebrow"><?php esc_html_e( 'How tracking works', 'xciv-courier' ); ?></p>
					<h3 class="xciv-promo__title">
						<?php
						printf(
							/* translators: %s: first name */
							esc_html__( 'Hey %s — your sits land here', 'xciv-courier' ),
							esc_html( $name !== '' ? $name : __( 'there', 'xciv-courier' ) )
						);
						?>
					</h3>
					<p class="xciv-promo__text"><?php esc_html_e( 'Confirmed → assigned → on the way → in line → ready to claim.', 'xciv-courier' ); ?></p>
				</div>
			</div>
		</div>

		<?php if ( $focus && $focus_task ) : ?>
			<?php
			$status   = (string) $focus_task['status'];
			$active_i = array_search( $status, $stage_keys, true );
			if ( false === $active_i ) {
				$active_i = $status === 'cancelled' ? -1 : 0;
			}
			$is_done = ! empty( $focus_task['is_done'] );
			?>
			<section class="xciv-dash__track <?php echo $is_done ? 'is-done' : 'is-live'; ?>" aria-labelledby="xciv-dash-status">
				<div class="xciv-dash__track-top">
					<div class="xciv-dash__pulse" aria-hidden="true"></div>
					<div class="xciv-dash__track-copy">
						<p class="xciv-dash__track-kicker">
							<?php
							printf(
								/* translators: 1: booking id, 2: service label */
								esc_html__( 'Booking #%1$d · %2$s', 'xciv-courier' ),
								(int) $focus_task['order_id'],
								(string) $focus_task['service']
							);
							?>
						</p>
						<h3 id="xciv-dash-status" class="xciv-dash__status"><?php echo esc_html( (string) $focus_task['status_label'] ); ?></h3>
						<p class="xciv-dash__status-detail"><?php echo esc_html( (string) $focus_task['status_detail'] ); ?></p>
					</div>
					<div class="xciv-dash__eta">
						<span class="xciv-dash__eta-label"><?php esc_html_e( 'Window', 'xciv-courier' ); ?></span>
						<span class="xciv-dash__eta-value"><?php echo esc_html( (string) $focus_task['start'] !== '' ? (string) $focus_task['start'] : '—' ); ?></span>
						<?php if ( (string) $focus_task['end'] !== '' ) : ?>
							<span class="xciv-dash__eta-end"><?php echo esc_html( (string) $focus_task['end'] ); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( $status !== 'cancelled' ) : ?>
					<ol class="xciv-dash__rail" aria-label="<?php esc_attr_e( 'Booking progress', 'xciv-courier' ); ?>">
						<?php foreach ( $stage_keys as $i => $slug ) : ?>
							<?php
							$state = 'upcoming';
							if ( $i < $active_i ) {
								$state = 'done';
							} elseif ( $i === $active_i ) {
								$state = 'current';
							}
							?>
							<li class="xciv-dash__rail-item is-<?php echo esc_attr( $state ); ?>">
								<span class="xciv-dash__rail-dot"></span>
								<span class="xciv-dash__rail-label"><?php echo esc_html( $stages[ $slug ]['label'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>

				<div class="xciv-dash__map" aria-hidden="true">
					<div class="xciv-dash__map-grid"></div>
					<div class="xciv-dash__pin">
						<span class="xciv-dash__pin-core"></span>
						<span class="xciv-dash__pin-ring"></span>
					</div>
					<p class="xciv-dash__map-label">
						<?php echo esc_html( (string) $focus_task['location'] !== '' ? (string) $focus_task['location'] : __( 'Location set at booking', 'xciv-courier' ) ); ?>
					</p>
				</div>

				<div class="xciv-dash__actions">
					<a class="xciv-dash__btn xciv-dash__btn--primary" href="<?php echo esc_url( $book_url ); ?>">
						<?php esc_html_e( 'Book another sit', 'xciv-courier' ); ?>
					</a>
					<a class="xciv-dash__btn xciv-dash__btn--ghost" href="<?php echo esc_url( wc_get_endpoint_url( 'view-order', (int) $focus_task['order_id'], wc_get_page_permalink( 'myaccount' ) ) ); ?>">
						<?php esc_html_e( 'Full receipt', 'xciv-courier' ); ?>
					</a>
				</div>
			</section>

			<?php xciv_booking_render_task_panels( $focus_task ); ?>
		<?php endif; ?>

		<?php if ( $live_tasks ) : ?>
			<section class="xciv-feed__section" data-xciv-section="live" aria-labelledby="xciv-feed-live">
				<div class="xciv-feed__section-head">
					<h3 id="xciv-feed-live"><?php esc_html_e( 'Live now', 'xciv-courier' ); ?></h3>
					<a href="<?php echo esc_url( $orders_url ); ?>"><?php esc_html_e( 'See all', 'xciv-courier' ); ?></a>
					<div class="xciv-feed__scroll-btns">
						<button type="button" class="xciv-feed__scroll-btn" data-xciv-scroll="prev" aria-label="<?php esc_attr_e( 'Previous', 'xciv-courier' ); ?>">‹</button>
						<button type="button" class="xciv-feed__scroll-btn" data-xciv-scroll="next" aria-label="<?php esc_attr_e( 'Next', 'xciv-courier' ); ?>">›</button>
					</div>
				</div>
				<div class="xciv-feed__carousel" data-xciv-carousel>
					<?php foreach ( $live_tasks as $task ) : ?>
						<?php
						$is_focus = $focus && (int) $focus->get_id() === (int) $task['order_id'];
						xciv_booking_render_listing_card( $task, $is_focus );
						?>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $past_tasks ) : ?>
			<section class="xciv-feed__section" data-xciv-section="past" aria-labelledby="xciv-feed-past">
				<div class="xciv-feed__section-head">
					<h3 id="xciv-feed-past"><?php esc_html_e( 'Recent sits', 'xciv-courier' ); ?></h3>
					<a href="<?php echo esc_url( $orders_url ); ?>"><?php esc_html_e( 'See all', 'xciv-courier' ); ?></a>
					<div class="xciv-feed__scroll-btns">
						<button type="button" class="xciv-feed__scroll-btn" data-xciv-scroll="prev" aria-label="<?php esc_attr_e( 'Previous', 'xciv-courier' ); ?>">‹</button>
						<button type="button" class="xciv-feed__scroll-btn" data-xciv-scroll="next" aria-label="<?php esc_attr_e( 'Next', 'xciv-courier' ); ?>">›</button>
					</div>
				</div>
				<div class="xciv-feed__carousel" data-xciv-carousel>
					<?php foreach ( $past_tasks as $task ) : ?>
						<?php xciv_booking_render_listing_card( $task, false ); ?>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( ! $all ) : ?>
			<section class="xciv-dash__empty">
				<h3><?php esc_html_e( 'No sits on the feed yet', 'xciv-courier' ); ?></h3>
				<p><?php esc_html_e( 'Book a line sit and this home feed fills with live cards, mission brief, and claim handoff — same rhythm as a delivery board.', 'xciv-courier' ); ?></p>
				<a class="xciv-dash__btn xciv-dash__btn--primary" href="<?php echo esc_url( $book_url ); ?>">
					<?php esc_html_e( 'Book a line sitter', 'xciv-courier' ); ?>
				</a>
			</section>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Replace default dashboard content.
 */
function xciv_booking_override_dashboard(): void {
	remove_action( 'woocommerce_account_dashboard', 'woocommerce_account_dashboard' );
	add_action( 'woocommerce_account_dashboard', 'xciv_booking_render_dashboard', 5 );
}
add_action( 'init', 'xciv_booking_override_dashboard', 30 );

/**
 * Admin: booking status control on the order edit screen.
 *
 * @param WC_Order $order Order.
 */
function xciv_booking_admin_status_metabox( $order ): void {
	if ( ! $order instanceof WC_Order || ! xciv_order_is_booking( $order ) ) {
		return;
	}

	$current = xciv_booking_get_status( $order );
	$stages  = xciv_booking_status_stages();
	wp_nonce_field( 'xciv_booking_status', 'xciv_booking_status_nonce' );
	?>
	<p class="form-field form-field-wide">
		<label for="xciv_booking_status"><strong><?php esc_html_e( 'XCIV booking tracker', 'xciv-courier' ); ?></strong></label>
		<select id="xciv_booking_status" name="xciv_booking_status">
			<?php foreach ( $stages as $slug => $stage ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
					<?php echo esc_html( $stage['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<span class="description"><?php esc_html_e( 'Updates the customer My Account tracker (UberEats-style progress).', 'xciv-courier' ); ?></span>
	</p>
	<?php
}
add_action( 'woocommerce_admin_order_data_after_order_details', 'xciv_booking_admin_status_metabox', 20 );

/**
 * Save admin booking status.
 *
 * @param int $order_id Order ID.
 */
function xciv_booking_admin_save_status( int $order_id ): void {
	if ( ! isset( $_POST['xciv_booking_status_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['xciv_booking_status_nonce'] ) ), 'xciv_booking_status' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
		return;
	}

	$status = isset( $_POST['xciv_booking_status'] ) ? sanitize_key( wp_unslash( (string) $_POST['xciv_booking_status'] ) ) : '';
	$order  = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order || ! xciv_order_is_booking( $order ) ) {
		return;
	}

	xciv_booking_set_status( $order, $status );
}
add_action( 'woocommerce_process_shop_order_meta', 'xciv_booking_admin_save_status', 40 );

/**
 * Declare WooCommerce theme support.
 */
function xciv_booking_woocommerce_support(): void {
	add_theme_support( 'woocommerce' );
}
add_action( 'after_setup_theme', 'xciv_booking_woocommerce_support' );
