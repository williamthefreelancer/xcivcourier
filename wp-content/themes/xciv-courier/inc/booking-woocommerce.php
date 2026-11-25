<?php
/**
 * WooCommerce booking products + cart/checkout wiring for the line-sitting form.
 *
 * @package XCIV_Courier
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Meta key storing the seeded base product ID. */
const XCIV_BOOKING_PRODUCT_OPTION = 'xciv_booking_base_product_id';

/** Meta key storing seeded add-on product IDs keyed by slug. */
const XCIV_BOOKING_ADDONS_OPTION = 'xciv_booking_addon_product_ids';

/**
 * Service catalog used by the form + product seeder.
 *
 * @return array<string, array{label:string, description:string, fields:array<int, string>}>
 */
function xciv_booking_service_types(): array {
	return array(
		'hold_spot'     => array(
			'label'       => __( 'Hold your spot', 'xciv-courier' ),
			'description' => __( 'Limited drops and releases — we stand so you don’t have to.', 'xciv-courier' ),
			'fields'      => array( 'event_name', 'brand_item', 'door_time', 'claimant' ),
		),
		'queue_open'    => array(
			'label'       => __( 'Queue the open', 'xciv-courier' ),
			'description' => __( 'Arrive near the front for store openings and early access.', 'xciv-courier' ),
			'fields'      => array( 'store_name', 'open_time', 'early_access', 'claimant' ),
		),
		'secure_pickup' => array(
			'label'       => __( 'Secure pickup', 'xciv-courier' ),
			'description' => __( 'When stock moves fast, we keep your place until you claim.', 'xciv-courier' ),
			'fields'      => array( 'store_name', 'order_ref', 'pickup_window', 'claimant' ),
		),
		'personal_shop' => array(
			'label'       => __( 'Personal shopping', 'xciv-courier' ),
			'description' => __( 'Grocery and errand runs at stores near you — we shop your list.', 'xciv-courier' ),
			'fields'      => array( 'store_name', 'shopping_list', 'claimant' ),
		),
		'custom'        => array(
			'label'       => __( 'Custom / other queue', 'xciv-courier' ),
			'description' => __( 'Another allowed wait — describe the job and constraints.', 'xciv-courier' ),
			'fields'      => array( 'event_name', 'claimant', 'custom_details' ),
		),
	);
}

/**
 * Add-on catalog. Conditional visibility is driven by `when`.
 *
 * @return array<string, array{label:string, description:string, price:float, when:string}>
 */
function xciv_booking_addons(): array {
	return array(
		'live_updates'    => array(
			'label'       => __( 'Live SMS updates', 'xciv-courier' ),
			'description' => __( 'Status pings while you stay offline.', 'xciv-courier' ),
			'price'       => 15.0,
			'when'        => 'always',
		),
		'early_buffer'    => array(
			'label'       => __( 'Early arrival buffer (+1 hr)', 'xciv-courier' ),
			'description' => __( 'Extra hour before start for highly competitive lines.', 'xciv-courier' ),
			'price'       => 25.0,
			'when'        => 'hold_or_open',
		),
		'purchase_assist' => array(
			'label'       => __( 'Purchase assist', 'xciv-courier' ),
			'description' => __( 'Checkout help with your approved payment method on file.', 'xciv-courier' ),
			'price'       => 35.0,
			'when'        => 'shop_or_not_pickup',
		),
		'extended_cover'  => array(
			'label'       => __( 'Extended cover (4+ hrs)', 'xciv-courier' ),
			'description' => __( 'Priority scheduling for waits longer than four hours.', 'xciv-courier' ),
			'price'       => 40.0,
			'when'        => 'long_wait',
		),
	);
}

/**
 * Pricing constants (match site copy).
 */
function xciv_booking_base_price(): float {
	return 50.0;
}

/**
 * Extra hour rate after the 2-hour minimum.
 */
function xciv_booking_extra_hour_rate(): float {
	return 25.0;
}

/**
 * Ensure WooCommerce products exist for the base booking + add-ons.
 */
function xciv_booking_ensure_products(): void {
	if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
		return;
	}

	$base_id = (int) get_option( XCIV_BOOKING_PRODUCT_OPTION, 0 );
	$base    = $base_id ? wc_get_product( $base_id ) : false;

	if ( ! $base ) {
		$product = new WC_Product_Simple();
		$product->set_name( __( 'Line sitting booking', 'xciv-courier' ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_regular_price( (string) xciv_booking_base_price() );
		$product->set_price( (string) xciv_booking_base_price() );
		$product->set_short_description( __( 'Professional line sitting — 2-hour minimum.', 'xciv-courier' ) );
		$base_id = $product->save();
		update_option( XCIV_BOOKING_PRODUCT_OPTION, $base_id, false );
	}

	$addon_ids = (array) get_option( XCIV_BOOKING_ADDONS_OPTION, array() );
	foreach ( xciv_booking_addons() as $slug => $addon ) {
		$existing_id = isset( $addon_ids[ $slug ] ) ? (int) $addon_ids[ $slug ] : 0;
		$existing    = $existing_id ? wc_get_product( $existing_id ) : false;
		if ( $existing ) {
			continue;
		}

		$product = new WC_Product_Simple();
		$product->set_name( $addon['label'] );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_regular_price( (string) $addon['price'] );
		$product->set_price( (string) $addon['price'] );
		$product->set_short_description( $addon['description'] );
		$addon_ids[ $slug ] = $product->save();
	}
	update_option( XCIV_BOOKING_ADDONS_OPTION, $addon_ids, false );
}
add_action( 'init', 'xciv_booking_ensure_products', 20 );

/**
 * Calculate billable hours and price from start/end datetimes.
 *
 * @param string $start_iso Local datetime-local value.
 * @param string $end_iso   Local datetime-local value.
 * @return array{hours:float, billable:float, price:float, valid:bool, message:string}
 */
function xciv_booking_calc_price( string $start_iso, string $end_iso ): array {
	$start = strtotime( $start_iso );
	$end   = strtotime( $end_iso );

	if ( ! $start || ! $end ) {
		return array(
			'hours'    => 0,
			'billable' => 0,
			'price'    => 0,
			'valid'    => false,
			'message'  => __( 'Enter a valid start and end time.', 'xciv-courier' ),
		);
	}

	if ( $end <= $start ) {
		return array(
			'hours'    => 0,
			'billable' => 0,
			'price'    => 0,
			'valid'    => false,
			'message'  => __( 'End time must be after start time.', 'xciv-courier' ),
		);
	}

	$hours    = ( $end - $start ) / HOUR_IN_SECONDS;
	$billable = max( 2.0, ceil( $hours * 2 ) / 2 ); // round up to nearest 0.5, min 2.
	$extra    = max( 0, $billable - 2 );
	$price    = xciv_booking_base_price() + ( $extra * xciv_booking_extra_hour_rate() );

	$dow = (int) gmdate( 'w', $start ); // 0=Sun … 6=Sat (UTC-ish; validated client-side too).
	// Prefer local day via DateTime if possible.
	try {
		$dt  = new DateTimeImmutable( $start_iso );
		$dow = (int) $dt->format( 'w' );
	} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// Keep gmdate fallback.
	}

	// Available Sunday (0) through Wednesday (3).
	if ( $dow < 0 || $dow > 3 ) {
		return array(
			'hours'    => $hours,
			'billable' => $billable,
			'price'    => $price,
			'valid'    => false,
			'message'  => __( 'Bookings are available Sunday–Wednesday only.', 'xciv-courier' ),
		);
	}

	return array(
		'hours'    => round( $hours, 2 ),
		'billable' => $billable,
		'price'    => $price,
		'valid'    => true,
		'message'  => '',
	);
}

/**
 * Localize booking config for the front-end form.
 *
 * @return array<string, mixed>
 */
function xciv_booking_js_config(): array {
	$addon_ids = (array) get_option( XCIV_BOOKING_ADDONS_OPTION, array() );

	$addons = array();
	foreach ( xciv_booking_addons() as $slug => $addon ) {
		$addons[ $slug ] = array_merge(
			$addon,
			array(
				'productId' => isset( $addon_ids[ $slug ] ) ? (int) $addon_ids[ $slug ] : 0,
			)
		);
	}

	$services = array();
	foreach ( xciv_booking_service_types() as $slug => $service ) {
		$services[ $slug ] = $service;
	}

	$venues = array();
	if ( function_exists( 'xciv_booking_all_venues' ) ) {
		foreach ( xciv_booking_all_venues() as $venue ) {
			$id = (string) ( $venue['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			$venues[ $id ] = array(
				'fee'        => (float) ( $venue['delivery_fee'] ?? 0 ),
				'miles'      => (float) ( $venue['miles'] ?? 0 ),
				'travelMins' => (int) ( $venue['travel_mins'] ?? 0 ),
				'near'       => ! empty( $venue['near'] ),
				'name'       => (string) ( $venue['name'] ?? '' ),
			);
		}
	}

	return array(
		'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
		'nonce'         => wp_create_nonce( 'xciv_booking' ),
		'checkoutUrl'   => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' ),
		'baseProductId' => (int) get_option( XCIV_BOOKING_PRODUCT_OPTION, 0 ),
		'basePrice'     => xciv_booking_base_price(),
		'extraHourRate' => xciv_booking_extra_hour_rate(),
		'services'      => $services,
		'addons'        => $addons,
		'venues'        => $venues,
		'wooReady'      => class_exists( 'WooCommerce' ),
		'i18n'          => array(
			'required'      => __( 'Please complete the required fields.', 'xciv-courier' ),
			'agree'         => __( 'You must agree to the booking terms.', 'xciv-courier' ),
			'invalidWindow' => __( 'Check your start and end times.', 'xciv-courier' ),
			'submitting'    => __( 'Adding booking to cart…', 'xciv-courier' ),
			'error'         => __( 'Could not start checkout. Please try again.', 'xciv-courier' ),
			'estimate'      => __( 'Estimated total', 'xciv-courier' ),
			'minHint'       => __( '$50 / 2-hour minimum · $25 each extra hour · Sun–Wed', 'xciv-courier' ),
			'stepOf'        => __( 'Step %1$s of %2$s', 'xciv-courier' ),
			'smsPhone'      => __( 'Live SMS updates require a phone number.', 'xciv-courier' ),
			'deliveryFee'   => __( 'Delivery fee', 'xciv-courier' ),
			'deliveryFree'  => __( 'Within hub range · no delivery fee', 'xciv-courier' ),
			/* translators: 1: fee dollars, 2: miles, 3: minutes */
			'deliveryHint'  => __( '+$%1$s delivery · %2$s mi · ~%3$s min drive from your hubs', 'xciv-courier' ),
		),
	);
}

/**
 * AJAX: validate booking payload, empty cart, add base + add-ons, return checkout URL.
 */
function xciv_booking_ajax_add_to_cart(): void {
	check_ajax_referer( 'xciv_booking', 'nonce' );

	if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
		wp_send_json_error( array( 'message' => __( 'Checkout is not available yet.', 'xciv-courier' ) ), 503 );
	}

	$service   = sanitize_key( wp_unslash( (string) ( $_POST['service_type'] ?? '' ) ) );
	$services  = xciv_booking_service_types();
	$start     = sanitize_text_field( wp_unslash( (string) ( $_POST['start_time'] ?? '' ) ) );
	$end       = sanitize_text_field( wp_unslash( (string) ( $_POST['end_time'] ?? '' ) ) );
	$location  = sanitize_text_field( wp_unslash( (string) ( $_POST['location'] ?? '' ) ) );
	$name      = sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) );
	$email     = sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) );
	$phone     = sanitize_text_field( wp_unslash( (string) ( $_POST['phone'] ?? '' ) ) );
	$agree     = ! empty( $_POST['agreement'] );
	$addons_in = isset( $_POST['addons'] ) ? (array) wp_unslash( $_POST['addons'] ) : array();
	$addons_in = array_map( 'sanitize_key', $addons_in );

	if ( ! isset( $services[ $service ] ) || $location === '' || $name === '' || ! is_email( $email ) || ! $agree ) {
		wp_send_json_error( array( 'message' => __( 'Please complete the required fields.', 'xciv-courier' ) ), 400 );
	}

	$calc = xciv_booking_calc_price( $start, $end );
	if ( ! $calc['valid'] ) {
		wp_send_json_error( array( 'message' => $calc['message'] ), 400 );
	}

	$venue_id      = sanitize_key( wp_unslash( (string) ( $_POST['venue_id'] ?? '' ) ) );
	$delivery_fee  = 0.0;
	$delivery_meta = '';
	if ( $venue_id !== '' && function_exists( 'xciv_booking_venue_by_id' ) ) {
		$venue = xciv_booking_venue_by_id( $venue_id );
		if ( $venue ) {
			$delivery_fee = (float) ( $venue['delivery_fee'] ?? 0 );
			$miles        = (float) ( $venue['miles'] ?? 0 );
			$mins         = (int) ( $venue['travel_mins'] ?? 0 );
			if ( $delivery_fee > 0 ) {
				$delivery_meta = sprintf(
					/* translators: 1: fee, 2: miles, 3: minutes */
					__( '$%1$s · %2$s mi · ~%3$d min drive', 'xciv-courier' ),
					number_format_i18n( $delivery_fee, 0 ),
					number_format_i18n( $miles, 1 ),
					$mins
				);
			} else {
				$delivery_meta = __( 'Free · within hub range', 'xciv-courier' );
			}
		}
	}

	// Conditional required fields by service.
	$fields = $services[ $service ]['fields'];
	$meta   = array(
		'Service'        => $services[ $service ]['label'],
		'Location'       => $location,
		'Start'          => $start,
		'End'            => $end,
		'Billable hours' => (string) $calc['billable'],
		'Contact name'   => $name,
		'Contact email'  => $email,
		'Contact phone'  => $phone,
	);
	if ( $venue_id !== '' ) {
		$meta['Venue id'] = $venue_id;
	}
	if ( $delivery_meta !== '' ) {
		$meta['Delivery / travel'] = $delivery_meta;
	}

	$optional_map = array(
		'event_name'     => 'Event / drop',
		'brand_item'     => 'Brand / item',
		'door_time'      => 'Door / release time',
		'store_name'     => 'Store name',
		'open_time'      => 'Open time',
		'early_access'   => 'Early access notes',
		'order_ref'      => 'Order / confirmation #',
		'pickup_window'  => 'Pickup window',
		'shopping_list'  => 'Shopping list',
		'custom_details' => 'Custom details',
		'instructions'   => 'Instructions',
		'city'           => 'City / area',
		'parking'        => 'Parking / access',
	);

	foreach ( $optional_map as $key => $label ) {
		$value = sanitize_textarea_field( wp_unslash( (string) ( $_POST[ $key ] ?? '' ) ) );
		if ( in_array( $key, $fields, true ) && $value === '' && in_array( $key, array( 'event_name', 'store_name', 'order_ref', 'shopping_list', 'custom_details' ), true ) ) {
			wp_send_json_error(
				array(
					/* translators: %s: field label */
					'message' => sprintf( __( '%s is required for this service.', 'xciv-courier' ), $label ),
				),
				400
			);
		}
		if ( $value !== '' ) {
			$meta[ $label ] = $value;
		}
	}

	$claimant = sanitize_key( wp_unslash( (string) ( $_POST['claimant'] ?? 'self' ) ) );
	$meta['Who claims'] = $claimant === 'other' ? __( 'Someone else', 'xciv-courier' ) : __( 'I will claim', 'xciv-courier' );

	if ( $claimant === 'other' ) {
		$claim_name  = sanitize_text_field( wp_unslash( (string) ( $_POST['claimant_name'] ?? '' ) ) );
		$claim_phone = sanitize_text_field( wp_unslash( (string) ( $_POST['claimant_phone'] ?? '' ) ) );
		if ( $claim_name === '' || $claim_phone === '' ) {
			wp_send_json_error( array( 'message' => __( 'Claimant name and phone are required.', 'xciv-courier' ) ), 400 );
		}
		$meta['Claimant name']  = $claim_name;
		$meta['Claimant phone'] = $claim_phone;
	}

	$instructions = sanitize_textarea_field( wp_unslash( (string) ( $_POST['instructions'] ?? '' ) ) );
	if ( $instructions !== '' ) {
		$meta['Instructions'] = $instructions;
	}

	if ( in_array( 'purchase_assist', $addons_in, true ) ) {
		$payment_ack = ! empty( $_POST['payment_ack'] );
		if ( ! $payment_ack ) {
			wp_send_json_error( array( 'message' => __( 'Purchase assist requires payment-method acknowledgment.', 'xciv-courier' ) ), 400 );
		}
		$meta['Payment acknowledgment'] = 'Yes';
	}

	if ( in_array( 'live_updates', $addons_in, true ) && $phone === '' ) {
		wp_send_json_error( array( 'message' => __( 'Live SMS updates require a phone number.', 'xciv-courier' ) ), 400 );
	}

	$base_id = (int) get_option( XCIV_BOOKING_PRODUCT_OPTION, 0 );
	if ( ! $base_id || ! wc_get_product( $base_id ) ) {
		xciv_booking_ensure_products();
		$base_id = (int) get_option( XCIV_BOOKING_PRODUCT_OPTION, 0 );
	}

	if ( ! WC()->cart ) {
		wc_load_cart();
	}
	WC()->cart->empty_cart();

	// Prefill checkout customer from booking contact.
	if ( WC()->customer ) {
		$parts      = preg_split( '/\s+/', trim( $name ), 2 );
		$first_name = $parts[0] ?? '';
		$last_name  = $parts[1] ?? '';

		WC()->customer->set_billing_email( $email );
		WC()->customer->set_billing_first_name( $first_name );
		WC()->customer->set_billing_last_name( $last_name );
		if ( $phone !== '' ) {
			WC()->customer->set_billing_phone( $phone );
		}
		WC()->customer->save();
	}

	$cart_item_data = array(
		'xciv_booking' => $meta,
		'xciv_service' => $service,
		'xciv_price'   => $calc['price'] + $delivery_fee,
		'xciv_venue'   => $venue_id,
		'xciv_delivery_fee' => $delivery_fee,
		'unique_key'   => md5( wp_json_encode( $meta ) . wp_rand() ),
	);

	$added = WC()->cart->add_to_cart( $base_id, 1, 0, array(), $cart_item_data );
	if ( ! $added ) {
		wp_send_json_error( array( 'message' => __( 'Could not add booking to cart.', 'xciv-courier' ) ), 500 );
	}

	$addon_ids = (array) get_option( XCIV_BOOKING_ADDONS_OPTION, array() );
	$catalog   = xciv_booking_addons();
	foreach ( $addons_in as $slug ) {
		if ( ! isset( $catalog[ $slug ], $addon_ids[ $slug ] ) ) {
			continue;
		}
		// Enforce add-on conditionals server-side.
		if ( ! xciv_booking_addon_allowed( $slug, $service, $calc['billable'] ) ) {
			continue;
		}
		WC()->cart->add_to_cart(
			(int) $addon_ids[ $slug ],
			1,
			0,
			array(),
			array(
				'xciv_addon' => $slug,
				'unique_key' => md5( $slug . wp_rand() ),
			)
		);
	}

	wp_send_json_success(
		array(
			'checkoutUrl' => wc_get_checkout_url(),
			'total'       => WC()->cart->get_total( 'edit' ),
		)
	);
}
add_action( 'wp_ajax_xciv_booking_add_to_cart', 'xciv_booking_ajax_add_to_cart' );
add_action( 'wp_ajax_nopriv_xciv_booking_add_to_cart', 'xciv_booking_ajax_add_to_cart' );

/**
 * Whether an add-on is allowed for the current service + duration.
 */
function xciv_booking_addon_allowed( string $slug, string $service, float $billable_hours ): bool {
	$addons = xciv_booking_addons();
	if ( ! isset( $addons[ $slug ] ) ) {
		return false;
	}

	switch ( $addons[ $slug ]['when'] ) {
		case 'always':
			return true;
		case 'hold_or_open':
			return in_array( $service, array( 'hold_spot', 'queue_open' ), true );
		case 'not_pickup':
			return $service !== 'secure_pickup';
		case 'shop_or_not_pickup':
			return $service === 'personal_shop' || $service !== 'secure_pickup';
		case 'long_wait':
			return $billable_hours >= 4;
		default:
			return false;
	}
}

/**
 * Override cart item price for the dynamic booking fee.
 *
 * @param WC_Cart $cart Cart object.
 */
function xciv_booking_apply_dynamic_price( $cart ): void {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( ! $cart || $cart->is_empty() ) {
		return;
	}

	foreach ( $cart->get_cart() as $item ) {
		if ( empty( $item['xciv_price'] ) || ! isset( $item['data'] ) || ! is_object( $item['data'] ) ) {
			continue;
		}
		$item['data']->set_price( (float) $item['xciv_price'] );
	}
}
add_action( 'woocommerce_before_calculate_totals', 'xciv_booking_apply_dynamic_price', 20 );

/**
 * Show booking meta on cart / checkout / order.
 *
 * @param array<string, mixed> $item_data Existing item data.
 * @param array<string, mixed> $cart_item Cart item.
 * @return array<string, mixed>
 */
function xciv_booking_cart_item_data( array $item_data, array $cart_item ): array {
	if ( empty( $cart_item['xciv_booking'] ) || ! is_array( $cart_item['xciv_booking'] ) ) {
		return $item_data;
	}

	foreach ( $cart_item['xciv_booking'] as $label => $value ) {
		$item_data[] = array(
			'key'   => (string) $label,
			'value' => wp_kses_post( (string) $value ),
		);
	}

	return $item_data;
}
add_filter( 'woocommerce_get_item_data', 'xciv_booking_cart_item_data', 10, 2 );

/**
 * Persist booking meta onto the order line item.
 *
 * @param WC_Order_Item_Product $item          Order item.
 * @param string                $cart_item_key Cart key.
 * @param array<string, mixed>  $values        Cart values.
 */
function xciv_booking_checkout_create_order_line_item( $item, string $cart_item_key, array $values ): void {
	if ( ! empty( $values['xciv_booking'] ) && is_array( $values['xciv_booking'] ) ) {
		foreach ( $values['xciv_booking'] as $label => $value ) {
			$item->add_meta_data( (string) $label, (string) $value, true );
		}
	}

	if ( ! empty( $values['xciv_service'] ) ) {
		$item->add_meta_data( 'xciv_service', sanitize_key( (string) $values['xciv_service'] ), true );
	}

	if ( ! empty( $values['xciv_addon'] ) ) {
		$item->add_meta_data( 'xciv_addon', sanitize_key( (string) $values['xciv_addon'] ), true );
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'xciv_booking_checkout_create_order_line_item', 10, 3 );

/**
 * Machine key → human label for editable booking detail fields.
 *
 * @return array<string, string>
 */
function xciv_booking_detail_field_labels(): array {
	return array(
		'event_name'     => 'Event / drop',
		'brand_item'     => 'Brand / item',
		'door_time'      => 'Door / release time',
		'store_name'     => 'Store name',
		'open_time'      => 'Open time',
		'early_access'   => 'Early access notes',
		'order_ref'      => 'Order / confirmation #',
		'pickup_window'  => 'Pickup window',
		'shopping_list'  => 'Shopping list',
		'custom_details' => 'Custom details',
	);
}

/**
 * Required detail field keys by service (mirrors booking-form.js).
 *
 * @return array<string, array<int, string>>
 */
function xciv_booking_required_fields_by_service(): array {
	return array(
		'hold_spot'     => array( 'event_name' ),
		'queue_open'    => array( 'store_name' ),
		'secure_pickup' => array( 'store_name', 'order_ref' ),
		'personal_shop' => array( 'store_name', 'shopping_list' ),
		'custom'        => array( 'event_name', 'custom_details' ),
	);
}

/**
 * Field UI defs for checkout Details step.
 *
 * @return array<string, array{label:string, type:string, placeholder:string}>
 */
function xciv_booking_detail_field_defs(): array {
	return array(
		'event_name'     => array(
			'label'       => __( 'Event / drop name', 'xciv-courier' ),
			'type'        => 'text',
			'placeholder' => '',
		),
		'brand_item'     => array(
			'label'       => __( 'Brand / item', 'xciv-courier' ),
			'type'        => 'text',
			'placeholder' => __( 'What are you waiting for?', 'xciv-courier' ),
		),
		'door_time'      => array(
			'label'       => __( 'Expected door / release time', 'xciv-courier' ),
			'type'        => 'text',
			'placeholder' => __( 'e.g. 10:00 AM door', 'xciv-courier' ),
		),
		'store_name'     => array(
			'label'       => __( 'Store name', 'xciv-courier' ),
			'type'        => 'text',
			'placeholder' => '',
		),
		'open_time'      => array(
			'label'       => __( 'Store open time', 'xciv-courier' ),
			'type'        => 'text',
			'placeholder' => '',
		),
		'early_access'   => array(
			'label'       => __( 'Early access notes', 'xciv-courier' ),
			'type'        => 'text',
			'placeholder' => __( 'Member hours, raffle, wristband…', 'xciv-courier' ),
		),
		'order_ref'      => array(
			'label'       => __( 'Order / confirmation #', 'xciv-courier' ),
			'type'        => 'text',
			'placeholder' => '',
		),
		'pickup_window'  => array(
			'label'       => __( 'Pickup window', 'xciv-courier' ),
			'type'        => 'text',
			'placeholder' => '',
		),
		'shopping_list'  => array(
			'label'       => __( 'Shopping list', 'xciv-courier' ),
			'type'        => 'textarea',
			'placeholder' => __( 'Items, brands, quantities, substitutions…', 'xciv-courier' ),
		),
		'custom_details' => array(
			'label'       => __( 'Describe the queue', 'xciv-courier' ),
			'type'        => 'textarea',
			'placeholder' => '',
		),
	);
}

/**
 * Find the booking line in the current cart.
 *
 * @return array{key:string, item:array<string, mixed>}|null
 */
function xciv_booking_cart_booking_item(): ?array {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return null;
	}

	foreach ( WC()->cart->get_cart() as $key => $item ) {
		if ( empty( $item['xciv_booking'] ) || ! is_array( $item['xciv_booking'] ) ) {
			continue;
		}
		return array(
			'key'  => (string) $key,
			'item' => $item,
		);
	}

	return null;
}

/**
 * Infer service slug from cart item (xciv_service or Service label).
 *
 * @param array<string, mixed> $cart_item Cart item.
 */
function xciv_booking_cart_service_slug( array $cart_item ): string {
	if ( ! empty( $cart_item['xciv_service'] ) ) {
		$slug = sanitize_key( (string) $cart_item['xciv_service'] );
		if ( isset( xciv_booking_service_types()[ $slug ] ) ) {
			return $slug;
		}
	}

	$meta  = isset( $cart_item['xciv_booking'] ) && is_array( $cart_item['xciv_booking'] ) ? $cart_item['xciv_booking'] : array();
	$label = isset( $meta['Service'] ) ? (string) $meta['Service'] : '';
	foreach ( xciv_booking_service_types() as $slug => $service ) {
		if ( $service['label'] === $label ) {
			return $slug;
		}
	}

	return '';
}

/**
 * Map cart booking meta labels back to machine field keys for the Details form.
 *
 * @param array<string, mixed> $meta Booking meta.
 * @return array<string, string>
 */
function xciv_booking_values_from_meta( array $meta ): array {
	$labels = xciv_booking_detail_field_labels();
	$values = array();

	foreach ( $labels as $key => $label ) {
		$values[ $key ] = isset( $meta[ $label ] ) ? (string) $meta[ $label ] : '';
	}

	$who = isset( $meta['Who claims'] ) ? (string) $meta['Who claims'] : '';
	$values['claimant']       = ( $who === __( 'Someone else', 'xciv-courier' ) || strcasecmp( $who, 'Someone else' ) === 0 ) ? 'other' : 'self';
	$values['claimant_name']  = isset( $meta['Claimant name'] ) ? (string) $meta['Claimant name'] : '';
	$values['claimant_phone'] = isset( $meta['Claimant phone'] ) ? (string) $meta['Claimant phone'] : '';

	return $values;
}

/**
 * Config localized to the multi-step checkout script.
 *
 * @return array<string, mixed>
 */
function xciv_booking_checkout_js_config(): array {
	$found = xciv_booking_cart_booking_item();
	if ( ! $found ) {
		return array(
			'hasBooking' => false,
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'xciv_booking' ),
			'requirePhone' => true,
			'i18n'       => array(
				'required'     => __( 'Please complete the required fields.', 'xciv-courier' ),
				'phone'        => __( 'Phone is required.', 'xciv-courier' ),
				'saving'       => __( 'Saving booking details…', 'xciv-courier' ),
				'saveError'    => __( 'Could not save booking details. Please try again.', 'xciv-courier' ),
				'stepOf'       => __( 'Step %1$s of %2$s', 'xciv-courier' ),
				'detailsTitle' => __( '1 · Queue details', 'xciv-courier' ),
			),
		);
	}

	$item    = $found['item'];
	$service = xciv_booking_cart_service_slug( $item );
	$meta    = is_array( $item['xciv_booking'] ) ? $item['xciv_booking'] : array();
	$fields  = ( $service !== '' && isset( xciv_booking_service_types()[ $service ] ) )
		? xciv_booking_service_types()[ $service ]['fields']
		: array();

	return array(
		'hasBooking'     => true,
		'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
		'nonce'          => wp_create_nonce( 'xciv_booking' ),
		'cartItemKey'    => $found['key'],
		'service'        => $service,
		'fields'         => $fields,
		'required'       => xciv_booking_required_fields_by_service(),
		'fieldDefs'      => xciv_booking_detail_field_defs(),
		'values'         => xciv_booking_values_from_meta( $meta ),
		'requirePhone'   => true,
		'i18n'           => array(
			'required'     => __( 'Please complete the required fields.', 'xciv-courier' ),
			'phone'        => __( 'Phone is required.', 'xciv-courier' ),
			'saving'       => __( 'Saving booking details…', 'xciv-courier' ),
			'saveError'    => __( 'Could not save booking details. Please try again.', 'xciv-courier' ),
			'stepOf'       => __( 'Step %1$s of %2$s', 'xciv-courier' ),
			'detailsTitle' => __( '1 · Queue details', 'xciv-courier' ),
			'claimant'     => __( 'Who claims at the front?', 'xciv-courier' ),
			'claimSelf'    => __( 'I will claim', 'xciv-courier' ),
			'claimOther'   => __( 'Someone else will claim', 'xciv-courier' ),
			'claimName'    => __( 'Claimant name', 'xciv-courier' ),
			'claimPhone'   => __( 'Claimant phone', 'xciv-courier' ),
		),
	);
}

/**
 * AJAX: update editable booking details on the cart line before place order.
 */
function xciv_booking_ajax_update_checkout_details(): void {
	check_ajax_referer( 'xciv_booking', 'nonce' );

	if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
		wp_send_json_error( array( 'message' => __( 'Checkout is not available yet.', 'xciv-courier' ) ), 503 );
	}

	$found = xciv_booking_cart_booking_item();
	if ( ! $found ) {
		wp_send_json_error( array( 'message' => __( 'No booking in cart.', 'xciv-courier' ) ), 400 );
	}

	$key     = $found['key'];
	$item    = $found['item'];
	$service = xciv_booking_cart_service_slug( $item );
	$services = xciv_booking_service_types();

	if ( $service === '' || ! isset( $services[ $service ] ) ) {
		wp_send_json_error( array( 'message' => __( 'Unknown booking service.', 'xciv-courier' ) ), 400 );
	}

	$meta   = is_array( $item['xciv_booking'] ) ? $item['xciv_booking'] : array();
	$fields = $services[ $service ]['fields'];
	$labels = xciv_booking_detail_field_labels();
	$required_map = xciv_booking_required_fields_by_service();
	$required     = $required_map[ $service ] ?? array();

	foreach ( $labels as $field_key => $label ) {
		if ( ! in_array( $field_key, $fields, true ) ) {
			unset( $meta[ $label ] );
			continue;
		}

		$value = sanitize_textarea_field( wp_unslash( (string) ( $_POST[ $field_key ] ?? '' ) ) );
		if ( in_array( $field_key, $required, true ) && $value === '' ) {
			wp_send_json_error(
				array(
					/* translators: %s: field label */
					'message' => sprintf( __( '%s is required for this service.', 'xciv-courier' ), $label ),
				),
				400
			);
		}

		if ( $value !== '' ) {
			$meta[ $label ] = $value;
		} else {
			unset( $meta[ $label ] );
		}
	}

	$claimant = sanitize_key( wp_unslash( (string) ( $_POST['claimant'] ?? 'self' ) ) );
	if ( ! in_array( $claimant, array( 'self', 'other' ), true ) ) {
		$claimant = 'self';
	}

	$meta['Who claims'] = $claimant === 'other'
		? __( 'Someone else', 'xciv-courier' )
		: __( 'I will claim', 'xciv-courier' );

	unset( $meta['Claimant name'], $meta['Claimant phone'] );

	if ( $claimant === 'other' ) {
		$claim_name  = sanitize_text_field( wp_unslash( (string) ( $_POST['claimant_name'] ?? '' ) ) );
		$claim_phone = sanitize_text_field( wp_unslash( (string) ( $_POST['claimant_phone'] ?? '' ) ) );
		if ( $claim_name === '' || $claim_phone === '' ) {
			wp_send_json_error( array( 'message' => __( 'Claimant name and phone are required.', 'xciv-courier' ) ), 400 );
		}
		$meta['Claimant name']  = $claim_name;
		$meta['Claimant phone'] = $claim_phone;
	}

	$cart = WC()->cart->get_cart();
	if ( ! isset( $cart[ $key ] ) ) {
		wp_send_json_error( array( 'message' => __( 'Booking cart item missing.', 'xciv-courier' ) ), 400 );
	}

	WC()->cart->cart_contents[ $key ]['xciv_booking'] = $meta;
	WC()->cart->cart_contents[ $key ]['xciv_service'] = $service;
	WC()->cart->set_session();

	wp_send_json_success(
		array(
			'meta'   => $meta,
			'values' => xciv_booking_values_from_meta( $meta ),
		)
	);
}
add_action( 'wp_ajax_xciv_booking_update_checkout_details', 'xciv_booking_ajax_update_checkout_details' );
add_action( 'wp_ajax_nopriv_xciv_booking_update_checkout_details', 'xciv_booking_ajax_update_checkout_details' );

/**
 * Prefer phone required on billing for booking checkouts (classic + locale).
 *
 * @param array<string, mixed> $fields Billing fields.
 * @return array<string, mixed>
 */
function xciv_booking_require_billing_phone( array $fields ): array {
	if ( isset( $fields['billing_phone'] ) ) {
		$fields['billing_phone']['required'] = true;
	}
	return $fields;
}
add_filter( 'woocommerce_billing_fields', 'xciv_booking_require_billing_phone', 20 );

/**
 * Mark phone required in country locale data (helps Blocks address forms).
 *
 * @param array<string, mixed> $locale Locale fields.
 * @return array<string, mixed>
 */
function xciv_booking_require_phone_locale( array $locale ): array {
	foreach ( $locale as $country => $fields ) {
		if ( ! is_array( $fields ) ) {
			continue;
		}
		if ( isset( $fields['phone'] ) && is_array( $fields['phone'] ) ) {
			$locale[ $country ]['phone']['required'] = true;
		} else {
			$locale[ $country ]['phone'] = array( 'required' => true );
		}
	}
	return $locale;
}
add_filter( 'woocommerce_get_country_locale', 'xciv_booking_require_phone_locale', 20 );

/**
 * If billing phone is empty at order create, copy Contact phone from booking cart meta.
 *
 * @param WC_Order $order Order.
 */
function xciv_booking_fill_phone_from_cart_meta( $order ): void {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	if ( $order->get_billing_phone() !== '' ) {
		return;
	}

	$found = xciv_booking_cart_booking_item();
	if ( ! $found ) {
		return;
	}

	$meta  = is_array( $found['item']['xciv_booking'] ) ? $found['item']['xciv_booking'] : array();
	$phone = isset( $meta['Contact phone'] ) ? sanitize_text_field( (string) $meta['Contact phone'] ) : '';
	if ( $phone !== '' ) {
		$order->set_billing_phone( $phone );
	}
}
add_action( 'woocommerce_checkout_create_order', 'xciv_booking_fill_phone_from_cart_meta', 20 );
