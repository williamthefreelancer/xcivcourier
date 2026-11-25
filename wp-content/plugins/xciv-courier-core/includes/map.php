<?php
/**
 * Lead rider map — Mapbox GL or custom Leaflet/OSM fallback.
 *
 * Shortcode: [xciv_lead_rider_map]
 *
 * @package XCIV_Courier_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Venue pins for the map (from theme catalog when available).
 *
 * @return array<int, array{id:string,name:string,address:string,lat?:float,lng?:float,miles?:float}>
 */
function xciv_core_map_venues(): array {
	if ( ! function_exists( 'xciv_booking_all_venues' ) ) {
		return array();
	}

	$out = array();
	foreach ( xciv_booking_all_venues() as $venue ) {
		$out[] = array(
			'id'      => (string) ( $venue['id'] ?? '' ),
			'name'    => (string) ( $venue['name'] ?? '' ),
			'address' => (string) ( $venue['address'] ?? '' ),
			'area'    => (string) ( $venue['area'] ?? '' ),
			'miles'   => (float) ( $venue['miles'] ?? 0 ),
			'heat'    => (string) ( $venue['heat'] ?? '' ),
			'lat'     => isset( $venue['lat'] ) ? (float) $venue['lat'] : null,
			'lng'     => isset( $venue['lng'] ) ? (float) $venue['lng'] : null,
		);
	}
	return $out;
}

/**
 * Public map bootstrap payload.
 *
 * @return array<string, mixed>
 */
function xciv_core_map_config(): array {
	$settings = xciv_core_settings();
	$map      = $settings['map'];
	$rider    = $settings['lead_rider'];
	$token    = (string) ( $map['mapbox_token'] ?? '' );
	$provider = (string) ( $map['provider'] ?? 'mapbox' );

	// Fall back to custom OSM map when Mapbox is selected but unconfigured.
	if ( $provider === 'mapbox' && $token === '' ) {
		$provider = 'custom';
	}

	$radii = array(
		'car'       => (float) ( $rider['car_max_miles'] ?? 18 ),
		'tricycle'  => (float) ( $rider['tricycle_max_miles'] ?? 8 ),
		'rideshare' => (float) ( $rider['car_max_miles'] ?? 18 ),
		'free'      => function_exists( 'xciv_booking_free_radius_miles' ) ? xciv_booking_free_radius_miles() : 10.0,
	);

	return array(
		'provider'    => $provider,
		'token'       => $token,
		'style'       => (string) ( $map['mapbox_style'] ?? 'mapbox://styles/mapbox/dark-v11' ),
		'center'      => array(
			(float) ( $map['center_lng'] ?? -80.744 ),
			(float) ( $map['center_lat'] ?? 35.312 ),
		),
		'zoom'        => (float) ( $map['zoom'] ?? 11 ),
		'showHubs'    => ! empty( $map['show_hubs'] ),
		'showVenues'  => ! empty( $map['show_venues'] ),
		'showRadius'  => ! empty( $map['show_radius'] ),
		'hubs'        => $settings['hubs'],
		'venues'      => xciv_core_map_venues(),
		'radii'       => $radii,
		'riderMode'   => (string) ( $rider['default_mode'] ?? 'car' ),
		'i18n'        => array(
			'hub'     => __( 'Hub', 'xciv-courier-core' ),
			'venue'   => __( 'Venue', 'xciv-courier-core' ),
			'range'   => __( 'Rider range', 'xciv-courier-core' ),
			'free'    => __( 'Free delivery radius', 'xciv-courier-core' ),
			'locate'  => __( 'Use my location', 'xciv-courier-core' ),
			'loading' => __( 'Loading map…', 'xciv-courier-core' ),
			'noToken' => __( 'Using open-street map (add a Mapbox token in XCIV Courier for Mapbox).', 'xciv-courier-core' ),
		),
	);
}

/**
 * Enqueue map assets when shortcode is present (or admin preview).
 */
function xciv_core_enqueue_map_assets(): void {
	if ( is_admin() ) {
		return;
	}

	$config   = xciv_core_map_config();
	$provider = (string) $config['provider'];

	wp_register_style(
		'xciv-core-map',
		XCIV_CORE_URI . 'assets/css/map.css',
		array(),
		XCIV_CORE_VERSION
	);

	wp_register_script(
		'xciv-core-map',
		XCIV_CORE_URI . 'assets/js/lead-rider-map.js',
		array(),
		XCIV_CORE_VERSION,
		true
	);

	if ( $provider === 'mapbox' ) {
		wp_enqueue_style(
			'mapbox-gl',
			'https://api.mapbox.com/mapbox-gl-js/v3.7.0/mapbox-gl.css',
			array(),
			'3.7.0'
		);
		wp_enqueue_script(
			'mapbox-gl',
			'https://api.mapbox.com/mapbox-gl-js/v3.7.0/mapbox-gl.js',
			array(),
			'3.7.0',
			true
		);
		wp_enqueue_script( 'xciv-core-map', XCIV_CORE_URI . 'assets/js/lead-rider-map.js', array( 'mapbox-gl' ), XCIV_CORE_VERSION, true );
	} else {
		wp_enqueue_style(
			'leaflet',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
			array(),
			'1.9.4'
		);
		wp_enqueue_script(
			'leaflet',
			'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
			array(),
			'1.9.4',
			true
		);
		wp_enqueue_script( 'xciv-core-map', XCIV_CORE_URI . 'assets/js/lead-rider-map.js', array( 'leaflet' ), XCIV_CORE_VERSION, true );
	}

	wp_enqueue_style( 'xciv-core-map' );
	wp_localize_script( 'xciv-core-map', 'xcivLeadRiderMap', $config );
}

/**
 * Shortcode renderer.
 *
 * @param array<string, string>|string $atts Attributes.
 */
function xciv_core_lead_rider_map_shortcode( $atts = array() ): string {
	$atts = shortcode_atts(
		array(
			'height' => '420px',
			'class'  => '',
		),
		is_array( $atts ) ? $atts : array(),
		'xciv_lead_rider_map'
	);

	xciv_core_enqueue_map_assets();

	$height = preg_match( '/^\d+(px|rem|vh|%)$/', (string) $atts['height'] ) ? (string) $atts['height'] : '420px';
	$class  = trim( 'xciv-lead-map ' . sanitize_html_class( (string) $atts['class'] ) );
	$config = xciv_core_map_config();

	ob_start();
	?>
	<section class="<?php echo esc_attr( $class ); ?>" data-xciv-lead-map>
		<header class="xciv-lead-map__bar">
			<div class="xciv-lead-map__titles">
				<strong><?php esc_html_e( 'Lead rider map', 'xciv-courier-core' ); ?></strong>
				<span data-xciv-map-provider-label>
					<?php
					echo esc_html(
						$config['provider'] === 'mapbox'
							? __( 'Mapbox', 'xciv-courier-core' )
							: __( 'Custom map', 'xciv-courier-core' )
					);
					?>
				</span>
			</div>
			<div class="xciv-lead-map__actions">
				<button type="button" class="xciv-lead-map__btn" data-xciv-map-locate>
					<?php esc_html_e( 'Use my location', 'xciv-courier-core' ); ?>
				</button>
			</div>
		</header>
		<div
			class="xciv-lead-map__canvas"
			data-xciv-map-canvas
			style="height:<?php echo esc_attr( $height ); ?>"
			role="region"
			aria-label="<?php esc_attr_e( 'Lead rider service map', 'xciv-courier-core' ); ?>"
		>
			<p class="xciv-lead-map__loading"><?php esc_html_e( 'Loading map…', 'xciv-courier-core' ); ?></p>
		</div>
		<ul class="xciv-lead-map__legend" aria-label="<?php esc_attr_e( 'Map legend', 'xciv-courier-core' ); ?>">
			<li><span class="xciv-lead-map__swatch xciv-lead-map__swatch--hub"></span> <?php esc_html_e( 'Hubs', 'xciv-courier-core' ); ?></li>
			<li><span class="xciv-lead-map__swatch xciv-lead-map__swatch--venue"></span> <?php esc_html_e( 'Venues', 'xciv-courier-core' ); ?></li>
			<li><span class="xciv-lead-map__swatch xciv-lead-map__swatch--range"></span> <?php esc_html_e( 'Rider range', 'xciv-courier-core' ); ?></li>
			<li><span class="xciv-lead-map__swatch xciv-lead-map__swatch--free"></span> <?php esc_html_e( 'Free radius', 'xciv-courier-core' ); ?></li>
		</ul>
	</section>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'xciv_lead_rider_map', 'xciv_core_lead_rider_map_shortcode' );
