<?php
/**
 * Lead rider delivery modes — car, e-tricycle, rideshare (no personal car).
 *
 * Settings live in XCIV Courier Core when active; theme falls back to defaults.
 *
 * @package XCIV_Courier
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default lead-rider mode settings (mirrored by the Core plugin).
 *
 * @return array<string, mixed>
 */
function xciv_lead_rider_defaults(): array {
	return array(
		'modes'              => array(
			'car'       => true,
			'tricycle'  => true,
			'rideshare' => true,
		),
		'default_mode'       => 'car',
		'tricycle_max_kg'    => 25,
		'tricycle_max_liters'=> 80,
		'tricycle_max_miles' => 8,
		'tricycle_helmet'    => true,
		'tricycle_rain_pause'=> true,
		'rideshare_max_l'    => 45,
		'rideshare_max_w'    => 35,
		'rideshare_max_h'    => 25,
		'rideshare_surge_min'=> 12,
		'rideshare_cabin_only' => true,
		'rideshare_no_drive' => true,
		'car_max_miles'      => 18,
		'notes_tricycle'     => __( 'Cargo fits rear basket / trunk box. Prefer flat routes under max range.', 'xciv-courier' ),
		'notes_rideshare'    => __( 'You are a passenger — no personal vehicle. Keep parcels cabin-safe; no oversized trunk loads.', 'xciv-courier' ),
	);
}

/**
 * Merged lead-rider settings.
 *
 * @return array<string, mixed>
 */
function xciv_lead_rider_settings(): array {
	$defaults = xciv_lead_rider_defaults();

	if ( function_exists( 'xciv_core_settings' ) ) {
		$core = xciv_core_settings();
		if ( isset( $core['lead_rider'] ) && is_array( $core['lead_rider'] ) ) {
			return wp_parse_args( $core['lead_rider'], $defaults );
		}
	}

	$stored = get_option( 'xciv_lead_rider_settings', array() );
	if ( is_array( $stored ) && $stored !== array() ) {
		return wp_parse_args( $stored, $defaults );
	}

	return $defaults;
}

/**
 * Enabled rider mode definitions for the header / booking UI.
 *
 * @return array<string, array{label:string,short:string,hint:string}>
 */
function xciv_lead_rider_modes(): array {
	$settings = xciv_lead_rider_settings();
	$enabled  = is_array( $settings['modes'] ?? null ) ? $settings['modes'] : array();

	$all = array(
		'car'       => array(
			'label' => __( 'Car', 'xciv-courier' ),
			'short' => __( 'Car', 'xciv-courier' ),
			'hint'  => __( 'Self-drive lead delivery by car.', 'xciv-courier' ),
		),
		'tricycle'  => array(
			'label' => __( 'E-Tricycle', 'xciv-courier' ),
			'short' => __( 'Trike', 'xciv-courier' ),
			'hint'  => (string) ( $settings['notes_tricycle'] ?? '' ),
		),
		'rideshare' => array(
			'label' => __( 'Rideshare', 'xciv-courier' ),
			'short' => __( 'Uber', 'xciv-courier' ),
			'hint'  => (string) ( $settings['notes_rideshare'] ?? '' ),
		),
	);

	$out = array();
	foreach ( $all as $key => $meta ) {
		if ( ! empty( $enabled[ $key ] ) ) {
			$out[ $key ] = $meta;
		}
	}

	return $out !== array() ? $out : array( 'car' => $all['car'] );
}

/**
 * SVG icon for a rider mode.
 */
function xciv_lead_rider_icon( string $mode ): string {
	switch ( $mode ) {
		case 'tricycle':
			return '<svg class="xciv-rider-modes__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><circle cx="6.5" cy="16.5" r="2.4" stroke="currentColor" stroke-width="1.7"/><circle cx="17.5" cy="16.5" r="2.4" stroke="currentColor" stroke-width="1.7"/><path d="M9 16.5h6.2M8.2 16.5 10.5 8.5h5.2L18 13" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M10.5 8.5H7.8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
		case 'rideshare':
			return '<svg class="xciv-rider-modes__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><circle cx="8.2" cy="7.2" r="2.1" stroke="currentColor" stroke-width="1.6"/><path d="M5.2 13.2c.7-2 2.2-3.1 4.2-3.1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M12.2 14.8 13.8 10.6h5.6l1.7 4.2" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M11.6 14.8h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="14.4" cy="16.7" r="1.2" fill="currentColor"/><circle cx="19.2" cy="16.7" r="1.2" fill="currentColor"/></svg>';
		case 'car':
		default:
			return '<svg class="xciv-rider-modes__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M4 14.5 6.2 9.2c.25-.6.85-1 1.5-1h8.6c.65 0 1.25.4 1.5 1L20 14.5" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M3.5 14.5h17" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M5.2 14.5v2.2M18.8 14.5v2.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><circle cx="7.4" cy="16.9" r="1.35" fill="currentColor"/><circle cx="16.6" cy="16.9" r="1.35" fill="currentColor"/><path d="M9 11.2h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
	}
}

/**
 * Render lead-rider mode chips (shown with Lead Delivery).
 */
function xciv_lead_rider_controls_render(): string {
	$modes    = xciv_lead_rider_modes();
	$settings = xciv_lead_rider_settings();
	$default  = (string) ( $settings['default_mode'] ?? 'car' );
	if ( ! isset( $modes[ $default ] ) ) {
		$default = (string) array_key_first( $modes );
	}

	ob_start();
	?>
	<div
		class="xciv-rider-modes"
		data-xciv-rider-modes
		role="group"
		aria-label="<?php echo esc_attr__( 'Lead rider vehicle', 'xciv-courier' ); ?>"
	>
		<span class="xciv-rider-modes__label"><?php esc_html_e( 'Rider', 'xciv-courier' ); ?></span>
		<div class="xciv-rider-modes__list">
			<?php foreach ( $modes as $key => $meta ) : ?>
				<button
					type="button"
					class="xciv-rider-modes__btn<?php echo $key === $default ? ' is-active' : ''; ?>"
					data-xciv-rider="<?php echo esc_attr( $key ); ?>"
					aria-pressed="<?php echo $key === $default ? 'true' : 'false'; ?>"
					title="<?php echo esc_attr( (string) $meta['hint'] ); ?>"
				>
					<?php echo xciv_lead_rider_icon( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="xciv-rider-modes__text"><?php echo esc_html( (string) $meta['short'] ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>
		<span class="screen-reader-text xciv-rider-modes__hint" data-xciv-rider-hint><?php echo esc_html( (string) ( $modes[ $default ]['hint'] ?? '' ) ); ?></span>
		<script type="application/json" data-xciv-rider-config><?php echo wp_json_encode(
			array(
				'default' => $default,
				'modes'   => $modes,
				'limits'  => array(
					'tricycle'  => array(
						'max_kg'     => (float) ( $settings['tricycle_max_kg'] ?? 25 ),
						'max_liters' => (float) ( $settings['tricycle_max_liters'] ?? 80 ),
						'max_miles'  => (float) ( $settings['tricycle_max_miles'] ?? 8 ),
						'helmet'     => ! empty( $settings['tricycle_helmet'] ),
						'rain_pause' => ! empty( $settings['tricycle_rain_pause'] ),
					),
					'rideshare' => array(
						'max_l'       => (float) ( $settings['rideshare_max_l'] ?? 45 ),
						'max_w'       => (float) ( $settings['rideshare_max_w'] ?? 35 ),
						'max_h'       => (float) ( $settings['rideshare_max_h'] ?? 25 ),
						'surge_min'   => (int) ( $settings['rideshare_surge_min'] ?? 12 ),
						'cabin_only'  => ! empty( $settings['rideshare_cabin_only'] ),
						'no_drive'    => ! empty( $settings['rideshare_no_drive'] ),
					),
					'car'       => array(
						'max_miles' => (float) ( $settings['car_max_miles'] ?? 18 ),
					),
				),
			)
		); ?></script>
	</div>
	<?php

	return (string) ob_get_clean();
}
add_shortcode( 'xciv_lead_rider_controls', 'xciv_lead_rider_controls_render' );
