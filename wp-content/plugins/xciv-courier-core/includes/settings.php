<?php
/**
 * Core settings store.
 *
 * @package XCIV_Courier_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default plugin settings.
 *
 * @return array<string, mixed>
 */
function xciv_core_default_settings(): array {
	return array(
		'lead_rider' => array(
			'modes'                => array(
				'car'       => true,
				'tricycle'  => true,
				'rideshare' => true,
			),
			'default_mode'         => 'car',
			'tricycle_max_kg'      => 25,
			'tricycle_max_liters'  => 80,
			'tricycle_max_miles'   => 8,
			'tricycle_helmet'      => true,
			'tricycle_rain_pause'  => true,
			'rideshare_max_l'      => 45,
			'rideshare_max_w'      => 35,
			'rideshare_max_h'      => 25,
			'rideshare_surge_min'  => 12,
			'rideshare_cabin_only' => true,
			'rideshare_no_drive'   => true,
			'car_max_miles'        => 18,
			'notes_tricycle'       => 'Cargo fits rear basket / trunk box. Prefer flat routes under max range.',
			'notes_rideshare'      => 'You are a passenger — no personal vehicle. Keep parcels cabin-safe; no oversized trunk loads.',
			// Private lead-rider base — street address is admin / wp-config only; never localize to the front end.
			'base_address'         => '',
			'base_lat'             => 35.3186178,
			'base_lng'             => -80.6997960,
			'base_public_label'    => 'University City',
			'base_public_zip'      => '28262',
		),
		'map'        => array(
			'provider'     => 'custom', // custom (Leaflet) | mapbox
			'mapbox_token' => '',
			'mapbox_style' => 'mapbox://styles/mapbox/dark-v11',
			'center_lat'   => 35.3186178,
			'center_lng'   => -80.6997960,
			'zoom'         => 11,
			'show_hubs'    => true,
			'show_venues'  => true,
			'show_radius'  => true,
		),
		'hubs'         => array(
			array(
				'id'              => 'ucity',
				'label'           => 'University City',
				'zip'             => '28262',
				'lat'             => 35.3186178,
				'lng'             => -80.6997960,
				'pickup_enabled'  => true,
			),
			array(
				'id'              => 'eastland',
				'label'           => 'Eastland / 28213',
				'zip'             => '28213',
				'lat'             => 35.268,
				'lng'             => -80.766,
				'pickup_enabled'  => true,
			),
			array(
				'id'              => 'concord',
				'label'           => 'Concord',
				'zip'             => '28027',
				'lat'             => 35.409,
				'lng'             => -80.58,
				'pickup_enabled'  => true,
			),
		),
		'fulfillment'  => array(
			'default_mode' => 'delivery', // delivery | pickup
			'default_hub'  => 'ucity',
		),
	);
}

/**
 * Merged settings (wp-config Mapbox token wins).
 *
 * @return array<string, mixed>
 */
function xciv_core_settings(): array {
	$stored = get_option( XCIV_CORE_OPTION, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	$settings = wp_parse_args( $stored, xciv_core_default_settings() );

	if ( ! isset( $settings['lead_rider'] ) || ! is_array( $settings['lead_rider'] ) ) {
		$settings['lead_rider'] = xciv_core_default_settings()['lead_rider'];
	} else {
		$settings['lead_rider'] = wp_parse_args( $settings['lead_rider'], xciv_core_default_settings()['lead_rider'] );
	}

	if ( ! isset( $settings['map'] ) || ! is_array( $settings['map'] ) ) {
		$settings['map'] = xciv_core_default_settings()['map'];
	} else {
		$settings['map'] = wp_parse_args( $settings['map'], xciv_core_default_settings()['map'] );
	}

	if ( ! isset( $settings['fulfillment'] ) || ! is_array( $settings['fulfillment'] ) ) {
		$settings['fulfillment'] = xciv_core_default_settings()['fulfillment'];
	} else {
		$settings['fulfillment'] = wp_parse_args( $settings['fulfillment'], xciv_core_default_settings()['fulfillment'] );
	}

	if ( defined( 'XCIV_MAPBOX_TOKEN' ) && XCIV_MAPBOX_TOKEN ) {
		$settings['map']['mapbox_token'] = (string) XCIV_MAPBOX_TOKEN;
	}

	// Optional private street address from wp-config (never shipped in theme/plugin defaults).
	if ( defined( 'XCIV_LEAD_RIDER_BASE_ADDRESS' ) && XCIV_LEAD_RIDER_BASE_ADDRESS ) {
		$settings['lead_rider']['base_address'] = (string) XCIV_LEAD_RIDER_BASE_ADDRESS;
	}
	if ( defined( 'XCIV_LEAD_RIDER_BASE_LAT' ) ) {
		$settings['lead_rider']['base_lat'] = (float) XCIV_LEAD_RIDER_BASE_LAT;
	}
	if ( defined( 'XCIV_LEAD_RIDER_BASE_LNG' ) ) {
		$settings['lead_rider']['base_lng'] = (float) XCIV_LEAD_RIDER_BASE_LNG;
	}

	// Keep the primary public hub aligned with the private base (ZIP / area label only).
	$settings['hubs'] = xciv_core_public_hubs( $settings );

	return $settings;
}

/**
 * Public hub pins — ZIP / area labels only. Never includes the private street address.
 *
 * @param array<string, mixed>|null $settings Optional settings snapshot.
 * @return array<int, array{id:string,label:string,zip:string,lat:float,lng:float,pickup_enabled:bool}>
 */
function xciv_core_public_hubs( ?array $settings = null ): array {
	if ( $settings === null ) {
		// Avoid recursion: read raw option + defaults without calling xciv_core_settings().
		$stored = get_option( XCIV_CORE_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$settings = wp_parse_args( $stored, xciv_core_default_settings() );
		$settings['lead_rider'] = wp_parse_args(
			is_array( $settings['lead_rider'] ?? null ) ? $settings['lead_rider'] : array(),
			xciv_core_default_settings()['lead_rider']
		);
	}

	$rider = is_array( $settings['lead_rider'] ?? null ) ? $settings['lead_rider'] : array();
	$base_lat = (float) ( $rider['base_lat'] ?? 35.3186178 );
	$base_lng = (float) ( $rider['base_lng'] ?? -80.6997960 );
	$label    = (string) ( $rider['base_public_label'] ?? 'University City' );
	$zip      = (string) ( $rider['base_public_zip'] ?? '28262' );

	$hubs = is_array( $settings['hubs'] ?? null ) ? $settings['hubs'] : xciv_core_default_settings()['hubs'];
	$out  = array();
	$saw_primary = false;

	foreach ( $hubs as $hub ) {
		if ( ! is_array( $hub ) ) {
			continue;
		}
		$id = (string) ( $hub['id'] ?? '' );
		$pickup_enabled = ! isset( $hub['pickup_enabled'] ) || ! empty( $hub['pickup_enabled'] );
		if ( $id === 'ucity' ) {
			$saw_primary = true;
			$out[]       = array(
				'id'             => 'ucity',
				'label'          => $label,
				'zip'            => $zip,
				'lat'            => $base_lat,
				'lng'            => $base_lng,
				'pickup_enabled' => $pickup_enabled,
			);
			continue;
		}
		$out[] = array(
			'id'             => $id,
			'label'          => (string) ( $hub['label'] ?? $id ),
			'zip'            => (string) ( $hub['zip'] ?? '' ),
			'lat'            => (float) ( $hub['lat'] ?? 0 ),
			'lng'            => (float) ( $hub['lng'] ?? 0 ),
			'pickup_enabled' => $pickup_enabled,
		);
	}

	if ( ! $saw_primary ) {
		array_unshift(
			$out,
			array(
				'id'             => 'ucity',
				'label'          => $label,
				'zip'            => $zip,
				'lat'            => $base_lat,
				'lng'            => $base_lng,
				'pickup_enabled' => true,
			)
		);
	}

	return $out;
}

/**
 * Private lead-rider base (admin / server use only — do not localize address to the front end).
 *
 * @return array{address:string,lat:float,lng:float,public_label:string,public_zip:string}
 */
function xciv_core_private_base(): array {
	$settings = xciv_core_settings();
	$rider    = is_array( $settings['lead_rider'] ?? null ) ? $settings['lead_rider'] : array();

	return array(
		'address'      => (string) ( $rider['base_address'] ?? '' ),
		'lat'          => (float) ( $rider['base_lat'] ?? 35.3186178 ),
		'lng'          => (float) ( $rider['base_lng'] ?? -80.6997960 ),
		'public_label' => (string) ( $rider['base_public_label'] ?? 'University City' ),
		'public_zip'   => (string) ( $rider['base_public_zip'] ?? '28262' ),
	);
}

/**
 * Register option.
 */
function xciv_core_register_settings(): void {
	register_setting(
		'xciv_core',
		XCIV_CORE_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'xciv_core_sanitize_settings',
			'default'           => xciv_core_default_settings(),
		)
	);
}

/**
 * Sanitize posted settings.
 *
 * @param mixed $input Raw input.
 * @return array<string, mixed>
 */
function xciv_core_sanitize_settings( $input ): array {
	$defaults = xciv_core_default_settings();
	$input    = is_array( $input ) ? $input : array();
	$out      = $defaults;

	$lr = isset( $input['lead_rider'] ) && is_array( $input['lead_rider'] ) ? $input['lead_rider'] : array();
	$modes = isset( $lr['modes'] ) && is_array( $lr['modes'] ) ? $lr['modes'] : array();

	$out['lead_rider']['modes'] = array(
		'car'       => ! empty( $modes['car'] ),
		'tricycle'  => ! empty( $modes['tricycle'] ),
		'rideshare' => ! empty( $modes['rideshare'] ),
	);
	if ( ! array_filter( $out['lead_rider']['modes'] ) ) {
		$out['lead_rider']['modes']['car'] = true;
	}

	$default_mode = sanitize_key( (string) ( $lr['default_mode'] ?? 'car' ) );
	if ( empty( $out['lead_rider']['modes'][ $default_mode ] ) ) {
		$default_mode = 'car';
	}
	$out['lead_rider']['default_mode'] = $default_mode;

	$out['lead_rider']['tricycle_max_kg']      = max( 1, (float) ( $lr['tricycle_max_kg'] ?? 25 ) );
	$out['lead_rider']['tricycle_max_liters']  = max( 1, (float) ( $lr['tricycle_max_liters'] ?? 80 ) );
	$out['lead_rider']['tricycle_max_miles']   = max( 1, (float) ( $lr['tricycle_max_miles'] ?? 8 ) );
	$out['lead_rider']['tricycle_helmet']      = ! empty( $lr['tricycle_helmet'] );
	$out['lead_rider']['tricycle_rain_pause']  = ! empty( $lr['tricycle_rain_pause'] );
	$out['lead_rider']['rideshare_max_l']      = max( 1, (float) ( $lr['rideshare_max_l'] ?? 45 ) );
	$out['lead_rider']['rideshare_max_w']      = max( 1, (float) ( $lr['rideshare_max_w'] ?? 35 ) );
	$out['lead_rider']['rideshare_max_h']      = max( 1, (float) ( $lr['rideshare_max_h'] ?? 25 ) );
	$out['lead_rider']['rideshare_surge_min']  = max( 0, (int) ( $lr['rideshare_surge_min'] ?? 12 ) );
	$out['lead_rider']['rideshare_cabin_only'] = ! empty( $lr['rideshare_cabin_only'] );
	$out['lead_rider']['rideshare_no_drive']   = ! empty( $lr['rideshare_no_drive'] );
	$out['lead_rider']['car_max_miles']        = max( 1, (float) ( $lr['car_max_miles'] ?? 18 ) );
	$out['lead_rider']['notes_tricycle']       = sanitize_textarea_field( (string) ( $lr['notes_tricycle'] ?? '' ) );
	$out['lead_rider']['notes_rideshare']      = sanitize_textarea_field( (string) ( $lr['notes_rideshare'] ?? '' ) );
	$out['lead_rider']['base_address']         = sanitize_text_field( (string) ( $lr['base_address'] ?? $defaults['lead_rider']['base_address'] ) );
	$out['lead_rider']['base_lat']             = (float) ( $lr['base_lat'] ?? $defaults['lead_rider']['base_lat'] );
	$out['lead_rider']['base_lng']             = (float) ( $lr['base_lng'] ?? $defaults['lead_rider']['base_lng'] );
	$out['lead_rider']['base_public_label']    = sanitize_text_field( (string) ( $lr['base_public_label'] ?? 'University City' ) );
	$out['lead_rider']['base_public_zip']      = sanitize_text_field( (string) ( $lr['base_public_zip'] ?? '28262' ) );

	$map = isset( $input['map'] ) && is_array( $input['map'] ) ? $input['map'] : array();
	$provider = sanitize_key( (string) ( $map['provider'] ?? 'custom' ) );
	$out['map']['provider']     = in_array( $provider, array( 'mapbox', 'custom' ), true ) ? $provider : 'custom';
	$out['map']['mapbox_token'] = sanitize_text_field( (string) ( $map['mapbox_token'] ?? '' ) );
	$out['map']['mapbox_style'] = sanitize_text_field( (string) ( $map['mapbox_style'] ?? 'mapbox://styles/mapbox/dark-v11' ) );
	// Default map center to private base when not explicitly posted.
	$out['map']['center_lat']   = (float) ( $map['center_lat'] ?? $out['lead_rider']['base_lat'] );
	$out['map']['center_lng']   = (float) ( $map['center_lng'] ?? $out['lead_rider']['base_lng'] );
	$out['map']['zoom']         = max( 3, min( 18, (float) ( $map['zoom'] ?? 11 ) ) );
	$out['map']['show_hubs']    = ! empty( $map['show_hubs'] );
	$out['map']['show_venues']  = ! empty( $map['show_venues'] );
	$out['map']['show_radius']  = ! empty( $map['show_radius'] );

	$hubs_in = isset( $input['hubs'] ) && is_array( $input['hubs'] ) ? $input['hubs'] : $defaults['hubs'];
	$hubs    = array();
	foreach ( $hubs_in as $hub ) {
		if ( ! is_array( $hub ) ) {
			continue;
		}
		$id = sanitize_key( (string) ( $hub['id'] ?? '' ) );
		if ( $id === '' ) {
			continue;
		}
		$hubs[] = array(
			'id'             => $id,
			'label'          => sanitize_text_field( (string) ( $hub['label'] ?? $id ) ),
			'zip'            => sanitize_text_field( (string) ( $hub['zip'] ?? '' ) ),
			'lat'            => (float) ( $hub['lat'] ?? 0 ),
			'lng'            => (float) ( $hub['lng'] ?? 0 ),
			'pickup_enabled' => ! empty( $hub['pickup_enabled'] ),
		);
	}
	$out['hubs'] = $hubs !== array() ? $hubs : $defaults['hubs'];

	// Keep primary public hub + map center synced to private base (label/ZIP only publicly).
	foreach ( $out['hubs'] as $i => $hub ) {
		if ( ( $hub['id'] ?? '' ) === 'ucity' ) {
			$out['hubs'][ $i ]['label'] = $out['lead_rider']['base_public_label'];
			$out['hubs'][ $i ]['zip']   = $out['lead_rider']['base_public_zip'];
			$out['hubs'][ $i ]['lat']   = $out['lead_rider']['base_lat'];
			$out['hubs'][ $i ]['lng']   = $out['lead_rider']['base_lng'];
		}
	}
	$out['map']['center_lat'] = $out['lead_rider']['base_lat'];
	$out['map']['center_lng'] = $out['lead_rider']['base_lng'];

	$ff = isset( $input['fulfillment'] ) && is_array( $input['fulfillment'] ) ? $input['fulfillment'] : array();
	$ff_mode = sanitize_key( (string) ( $ff['default_mode'] ?? $defaults['fulfillment']['default_mode'] ) );
	if ( ! in_array( $ff_mode, array( 'delivery', 'pickup' ), true ) ) {
		$ff_mode = 'delivery';
	}
	$ff_hub = sanitize_key( (string) ( $ff['default_hub'] ?? $defaults['fulfillment']['default_hub'] ) );
	$hub_ids = array_column( $out['hubs'], 'id' );
	if ( $ff_hub === '' || ( $hub_ids && ! in_array( $ff_hub, $hub_ids, true ) ) ) {
		$ff_hub = (string) ( $hub_ids[0] ?? 'ucity' );
	}
	$out['fulfillment'] = array(
		'default_mode' => $ff_mode,
		'default_hub'  => $ff_hub,
	);

	return $out;
}
