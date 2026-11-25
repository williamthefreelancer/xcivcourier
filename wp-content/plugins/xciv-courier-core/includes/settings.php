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
		),
		'map'        => array(
			'provider'     => 'mapbox', // mapbox | custom
			'mapbox_token' => '',
			'mapbox_style' => 'mapbox://styles/mapbox/dark-v11',
			'center_lat'   => 35.312,
			'center_lng'   => -80.744,
			'zoom'         => 11,
			'show_hubs'    => true,
			'show_venues'  => true,
			'show_radius'  => true,
		),
		'hubs'       => array(
			array(
				'id'   => 'ucity',
				'label'=> 'University City',
				'zip'  => '28262',
				'lat'  => 35.312,
				'lng'  => -80.744,
			),
			array(
				'id'   => 'eastland',
				'label'=> 'Eastland / 28213',
				'zip'  => '28213',
				'lat'  => 35.268,
				'lng'  => -80.766,
			),
			array(
				'id'   => 'concord',
				'label'=> 'Concord',
				'zip'  => '28027',
				'lat'  => 35.409,
				'lng'  => -80.58,
			),
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

	if ( defined( 'XCIV_MAPBOX_TOKEN' ) && XCIV_MAPBOX_TOKEN ) {
		$settings['map']['mapbox_token'] = (string) XCIV_MAPBOX_TOKEN;
	}

	return $settings;
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

	$map = isset( $input['map'] ) && is_array( $input['map'] ) ? $input['map'] : array();
	$provider = sanitize_key( (string) ( $map['provider'] ?? 'mapbox' ) );
	$out['map']['provider']     = in_array( $provider, array( 'mapbox', 'custom' ), true ) ? $provider : 'mapbox';
	$out['map']['mapbox_token'] = sanitize_text_field( (string) ( $map['mapbox_token'] ?? '' ) );
	$out['map']['mapbox_style'] = sanitize_text_field( (string) ( $map['mapbox_style'] ?? 'mapbox://styles/mapbox/dark-v11' ) );
	$out['map']['center_lat']   = (float) ( $map['center_lat'] ?? 35.312 );
	$out['map']['center_lng']   = (float) ( $map['center_lng'] ?? -80.744 );
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
			'id'    => $id,
			'label' => sanitize_text_field( (string) ( $hub['label'] ?? $id ) ),
			'zip'   => sanitize_text_field( (string) ( $hub['zip'] ?? '' ) ),
			'lat'   => (float) ( $hub['lat'] ?? 0 ),
			'lng'   => (float) ( $hub['lng'] ?? 0 ),
		);
	}
	$out['hubs'] = $hubs !== array() ? $hubs : $defaults['hubs'];

	return $out;
}
