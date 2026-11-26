<?php
/**
 * Pickup & Location hub controls for the header.
 *
 * @package XCIV_Courier
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public hubs available for pickup selection (pickup-enabled only).
 *
 * @return array<int, array{id:string,label:string,zip:string,lat:float,lng:float,short:string}>
 */
function xciv_pickup_hubs(): array {
	$hubs = array();

	if ( function_exists( 'xciv_core_public_hubs' ) ) {
		foreach ( xciv_core_public_hubs() as $hub ) {
			if ( ! is_array( $hub ) || empty( $hub['id'] ) ) {
				continue;
			}
			if ( isset( $hub['pickup_enabled'] ) && empty( $hub['pickup_enabled'] ) ) {
				continue;
			}
			$id    = (string) $hub['id'];
			$label = (string) ( $hub['label'] ?? $id );
			$hubs[] = array(
				'id'    => $id,
				'label' => $label,
				'zip'   => (string) ( $hub['zip'] ?? '' ),
				'lat'   => (float) ( $hub['lat'] ?? 0 ),
				'lng'   => (float) ( $hub['lng'] ?? 0 ),
				'short' => xciv_pickup_hub_short_label( $label, (string) ( $hub['zip'] ?? '' ) ),
			);
		}
	}

	if ( $hubs === array() ) {
		$hubs = array(
			array(
				'id'    => 'ucity',
				'label' => 'University City',
				'zip'   => '28262',
				'lat'   => 35.3186178,
				'lng'   => -80.6997960,
				'short' => 'UCity',
			),
			array(
				'id'    => 'eastland',
				'label' => 'Eastland / 28213',
				'zip'   => '28213',
				'lat'   => 35.268,
				'lng'   => -80.766,
				'short' => 'East',
			),
			array(
				'id'    => 'concord',
				'label' => 'Concord',
				'zip'   => '28027',
				'lat'   => 35.409,
				'lng'   => -80.58,
				'short' => 'Concord',
			),
		);
	}

	return $hubs;
}

/**
 * Compact chip label for a hub.
 */
function xciv_pickup_hub_short_label( string $label, string $zip ): string {
	$label = trim( $label );
	if ( $label === '' ) {
		return $zip !== '' ? $zip : 'Hub';
	}
	// Prefer first word / area name under ~10 chars.
	$parts = preg_split( '/[\s\/]+/', $label );
	$first = is_array( $parts ) && isset( $parts[0] ) ? (string) $parts[0] : $label;
	if ( strlen( $first ) <= 10 ) {
		return $first;
	}
	return $zip !== '' ? $zip : substr( $first, 0, 8 );
}

/**
 * Fulfillment defaults from Core (or theme fallback).
 *
 * @return array{default_mode:string,default_hub:string}
 */
function xciv_fulfillment_defaults(): array {
	$defaults = array(
		'default_mode' => 'delivery',
		'default_hub'  => 'ucity',
	);

	if ( function_exists( 'xciv_core_settings' ) ) {
		$settings = xciv_core_settings();
		$ff       = is_array( $settings['fulfillment'] ?? null ) ? $settings['fulfillment'] : array();
		$mode     = sanitize_key( (string) ( $ff['default_mode'] ?? 'delivery' ) );
		$hub      = sanitize_key( (string) ( $ff['default_hub'] ?? 'ucity' ) );
		if ( ! in_array( $mode, array( 'delivery', 'pickup' ), true ) ) {
			$mode = 'delivery';
		}
		$defaults['default_mode'] = $mode;
		$defaults['default_hub']  = $hub !== '' ? $hub : 'ucity';
	}

	$hubs = xciv_pickup_hubs();
	$ids  = array_column( $hubs, 'id' );
	if ( $ids && ! in_array( $defaults['default_hub'], $ids, true ) ) {
		$defaults['default_hub'] = (string) $ids[0];
	}

	return $defaults;
}

/**
 * Render pickup hub chips (shown with Pickup & Location).
 */
function xciv_pickup_hub_controls_render(): string {
	$hubs     = xciv_pickup_hubs();
	$defaults = xciv_fulfillment_defaults();
	$default  = $defaults['default_hub'];
	$by_id    = array();
	foreach ( $hubs as $hub ) {
		$by_id[ $hub['id'] ] = $hub;
	}
	if ( ! isset( $by_id[ $default ] ) && $hubs ) {
		$default = (string) $hubs[0]['id'];
	}

	ob_start();
	?>
	<div
		class="xciv-hub-modes"
		data-xciv-hub-modes
		hidden
		aria-hidden="true"
		role="group"
		aria-label="<?php echo esc_attr__( 'Pickup hub', 'xciv-courier' ); ?>"
	>
		<span class="xciv-hub-modes__label"><?php esc_html_e( 'Hub', 'xciv-courier' ); ?></span>
		<div class="xciv-hub-modes__list">
			<button
				type="button"
				class="xciv-hub-modes__btn xciv-hub-modes__btn--locate"
				data-xciv-hub-locate
				title="<?php echo esc_attr__( 'Use my location — nearest hub', 'xciv-courier' ); ?>"
				aria-label="<?php echo esc_attr__( 'Use my location', 'xciv-courier' ); ?>"
			>
				<svg class="xciv-hub-modes__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
					<circle cx="12" cy="12" r="3.2" stroke="currentColor" stroke-width="1.7"/>
					<path d="M12 3.5v2.2M12 18.3v2.2M3.5 12h2.2M18.3 12h2.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
					<circle cx="12" cy="12" r="7.2" stroke="currentColor" stroke-width="1.5" opacity="0.55"/>
				</svg>
				<span class="xciv-hub-modes__text"><?php esc_html_e( 'Near me', 'xciv-courier' ); ?></span>
			</button>
			<?php foreach ( $hubs as $hub ) : ?>
				<button
					type="button"
					class="xciv-hub-modes__btn<?php echo $hub['id'] === $default ? ' is-active' : ''; ?>"
					data-xciv-hub="<?php echo esc_attr( $hub['id'] ); ?>"
					aria-pressed="<?php echo $hub['id'] === $default ? 'true' : 'false'; ?>"
					title="<?php echo esc_attr( $hub['label'] . ( $hub['zip'] !== '' ? ' · ' . $hub['zip'] : '' ) ); ?>"
				>
					<svg class="xciv-hub-modes__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
						<path d="M12 21s5.5-5.6 5.5-9.5a5.5 5.5 0 1 0-11 0C6.5 15.4 12 21 12 21Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
						<circle cx="12" cy="11.5" r="1.9" fill="currentColor"/>
					</svg>
					<span class="xciv-hub-modes__text"><?php echo esc_html( $hub['short'] ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>
		<span class="screen-reader-text xciv-hub-modes__hint" data-xciv-hub-hint>
			<?php echo esc_html( (string) ( $by_id[ $default ]['label'] ?? '' ) ); ?>
		</span>
		<script type="application/json" data-xciv-hub-config><?php
			echo wp_json_encode(
				array(
					'defaultMode' => $defaults['default_mode'],
					'defaultHub'  => $default,
					'hubs'        => $by_id,
					'i18n'        => array(
						'locateFail' => __( 'Could not get your location. Pick a hub manually.', 'xciv-courier' ),
						'locateOk'   => __( 'Nearest hub selected.', 'xciv-courier' ),
					),
				)
			);
		?></script>
	</div>
	<?php

	$html = (string) ob_get_clean();
	return function_exists( 'xciv_compact_chrome_html' ) ? xciv_compact_chrome_html( $html ) : $html;
}
add_shortcode( 'xciv_pickup_hub_controls', 'xciv_pickup_hub_controls_render' );
