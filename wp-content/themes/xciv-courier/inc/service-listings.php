<?php
/**
 * Dynamic service listings page — search, filters, per-listing detail, live rider ETA map.
 *
 * Shortcode: [xciv_service_listings]
 * Template: page-service-listings (assign under Page → Template)
 *
 * @package XCIV_Courier
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Lead rider display name for live ETA / map tracking. */
const XCIV_LEAD_RIDER_NAME = 'William A. Johnson';

/**
 * Detect Service Listings FSE / classic template assignment.
 */
function xciv_listings_page_uses_template( int $post_id = 0 ): bool {
	if ( $post_id <= 0 ) {
		$post_id = get_queried_object_id();
	}
	if ( $post_id <= 0 ) {
		return false;
	}
	$slug = (string) get_page_template_slug( $post_id );
	return $slug === 'page-service-listings' || $slug === 'templates/page-service-listings.html';
}

/**
 * Whether the current view uses the Service Listings page template.
 */
function xciv_listings_is_template(): bool {
	if ( is_admin() ) {
		return false;
	}
	if ( xciv_listings_page_uses_template() ) {
		return true;
	}
	return (bool) get_query_var( 'xciv_listing', false )
		|| ( isset( $_GET['listing'] ) && is_singular( 'page' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * Public URL for the service listings page (falls back to home + hash).
 */
function xciv_listings_page_url( array $args = array() ): string {
	$page_id = (int) get_option( 'xciv_service_listings_page_id', 0 );
	if ( $page_id <= 0 ) {
		$found = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => '_wp_page_template',
				'meta_value'     => 'page-service-listings',
				'fields'         => 'ids',
			)
		);
		if ( $found ) {
			$page_id = (int) $found[0];
			update_option( 'xciv_service_listings_page_id', $page_id, false );
		}
	}

	$base = $page_id > 0 ? get_permalink( $page_id ) : home_url( '/listings/' );
	return $args ? add_query_arg( $args, (string) $base ) : (string) $base;
}

/**
 * Resolve active listing id from query.
 */
function xciv_listings_active_id(): string {
	$id = isset( $_GET['listing'] ) ? sanitize_title( wp_unslash( (string) $_GET['listing'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $id === '' && get_query_var( 'xciv_listing' ) ) {
		$id = sanitize_title( (string) get_query_var( 'xciv_listing' ) );
	}
	return $id;
}

/**
 * Fulfillment mode for a venue: delivery goods vs queue swap / line sit.
 *
 * @param array<string, mixed> $venue Venue row.
 */
function xciv_listings_fulfillment_mode( array $venue ): string {
	$service = (string) ( $venue['service'] ?? '' );
	$kind    = (string) ( $venue['kind'] ?? '' );

	if ( $service === 'personal_shop' || $kind === 'grocery' || $kind === 'sneakers' ) {
		return 'delivery';
	}
	return 'queue';
}

/**
 * Human ETA label from travel minutes.
 */
function xciv_listings_eta_label( int $mins ): string {
	if ( $mins <= 0 ) {
		return __( 'On site', 'xciv-courier' );
	}
	if ( $mins < 60 ) {
		return sprintf(
			/* translators: %d: minutes */
			__( '~%d min out', 'xciv-courier' ),
			$mins
		);
	}
	$h = (int) floor( $mins / 60 );
	$m = $mins % 60;
	if ( $m === 0 ) {
		return sprintf(
			/* translators: %d: hours */
			_n( '~%d hr out', '~%d hrs out', $h, 'xciv-courier' ),
			$h
		);
	}
	return sprintf(
		/* translators: 1: hours 2: minutes */
		__( '~%1$dh %2$dm out', 'xciv-courier' ),
		$h,
		$m
	);
}

/**
 * Payload for listings JS (venues + rider identity).
 *
 * @return array<string, mixed>
 */
function xciv_listings_js_config(): array {
	$venues = array();
	if ( function_exists( 'xciv_booking_all_venues' ) ) {
		foreach ( xciv_booking_all_venues() as $venue ) {
			$mode = xciv_listings_fulfillment_mode( $venue );
			$mins = (int) ( $venue['travel_mins'] ?? 0 );
			$venues[] = array(
				'id'          => (string) ( $venue['id'] ?? '' ),
				'name'        => (string) ( $venue['name'] ?? '' ),
				'area'        => (string) ( $venue['area'] ?? '' ),
				'address'     => (string) ( $venue['address'] ?? '' ),
				'lat'         => isset( $venue['lat'] ) ? (float) $venue['lat'] : null,
				'lng'         => isset( $venue['lng'] ) ? (float) $venue['lng'] : null,
				'service'     => (string) ( $venue['service'] ?? '' ),
				'kind'        => (string) ( $venue['kind'] ?? 'line' ),
				'heat'        => (string) ( $venue['heat'] ?? '' ),
				'wait'        => (string) ( $venue['wait'] ?? '' ),
				'note'        => (string) ( $venue['note'] ?? '' ),
				'miles'       => (float) ( $venue['miles'] ?? 0 ),
				'travelMins'  => $mins,
				'deliveryFee' => (float) ( $venue['delivery_fee'] ?? 0 ),
				'near'        => ! empty( $venue['near'] ),
				'mode'        => $mode,
				'etaLabel'    => xciv_listings_eta_label( $mins ),
				'detailUrl'   => xciv_listings_page_url( array( 'listing' => (string) ( $venue['id'] ?? '' ) ) ),
			);
		}
	}

	$hubs = array();
	if ( function_exists( 'xciv_core_public_hubs' ) ) {
		$hubs = xciv_core_public_hubs();
	} elseif ( function_exists( 'xciv_core_settings' ) ) {
		$settings = xciv_core_settings();
		$hubs     = is_array( $settings['hubs'] ?? null ) ? $settings['hubs'] : array();
	}
	if ( ! $hubs ) {
		// Public fallback only — never include the private street address.
		$hubs = array(
			array(
				'label' => 'University City',
				'zip'   => '28262',
				'lat'   => 35.3186178,
				'lng'   => -80.6997960,
			),
		);
	}

	$services = array();
	if ( function_exists( 'xciv_booking_service_types' ) ) {
		foreach ( xciv_booking_service_types() as $slug => $def ) {
			$services[ $slug ] = array(
				'label' => (string) ( $def['label'] ?? $slug ),
			);
		}
	}

	return array(
		'riderName'  => XCIV_LEAD_RIDER_NAME,
		'activeId'   => xciv_listings_active_id(),
		'venues'     => $venues,
		'hubs'       => $hubs,
		'services'   => $services,
		'listUrl'    => xciv_listings_page_url(),
		'pollMs'     => 4000,
		'i18n'       => array(
			'arriving'     => __( 'William A. Johnson en route', 'xciv-courier' ),
			'onSite'       => __( 'William A. Johnson on site', 'xciv-courier' ),
			'queueSwap'    => __( 'Queue swap · holding your place', 'xciv-courier' ),
			'delivery'     => __( 'Lead delivery · goods en route', 'xciv-courier' ),
			'select'       => __( 'Select a listing to track live ETA', 'xciv-courier' ),
			'results'      => __( '%d listings', 'xciv-courier' ),
			'noResults'    => __( 'No listings match your search.', 'xciv-courier' ),
			'etaPrefix'    => __( 'ETA', 'xciv-courier' ),
			'tracking'     => __( 'Live tracking', 'xciv-courier' ),
		),
	);
}

/**
 * Enqueue listings assets when the template / shortcode is active.
 */
function xciv_listings_enqueue_assets(): void {
	if ( is_admin() ) {
		return;
	}

	$force = (bool) apply_filters( 'xciv_listings_force_assets', false );
	if ( ! $force && ! xciv_listings_is_template() && ! has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'xciv_service_listings' ) ) {
		return;
	}

	wp_enqueue_style(
		'xciv-courier-account-dashboard',
		XCIV_COURIER_URI . '/assets/css/account-dashboard.css',
		array(),
		XCIV_COURIER_VERSION
	);

	wp_enqueue_style(
		'xciv-courier-service-listings',
		XCIV_COURIER_URI . '/assets/css/service-listings.css',
		array( 'xciv-courier-account-dashboard' ),
		XCIV_COURIER_VERSION
	);

	wp_enqueue_script(
		'xciv-courier-account-dashboard',
		XCIV_COURIER_URI . '/assets/js/account-dashboard.js',
		array(),
		XCIV_COURIER_VERSION,
		true
	);

	$deps = array( 'xciv-courier-account-dashboard' );
	if ( function_exists( 'xciv_core_enqueue_map_assets' ) ) {
		xciv_core_enqueue_map_assets();
		$deps[] = 'xciv-core-map';
	}

	wp_enqueue_script(
		'xciv-courier-service-listings',
		XCIV_COURIER_URI . '/assets/js/service-listings.js',
		$deps,
		XCIV_COURIER_VERSION,
		true
	);

	wp_localize_script( 'xciv-courier-service-listings', 'xcivServiceListings', xciv_listings_js_config() );
}
add_action( 'wp_enqueue_scripts', 'xciv_listings_enqueue_assets', 30 );

/**
 * Body classes for listings template.
 *
 * @param mixed $classes Body classes.
 * @return array<int, string>
 */
function xciv_listings_body_class( $classes ): array {
	$classes = is_array( $classes ) ? $classes : array();
	if ( xciv_listings_is_template() || has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'xciv_service_listings' ) ) {
		$classes[] = 'xciv-service-listings';
		$classes[] = 'xciv-account-feed';
	}
	return $classes;
}
add_filter( 'body_class', 'xciv_listings_body_class' );

/**
 * Render one listing card (catalog grid).
 *
 * @param array<string, mixed> $venue Venue row.
 * @param bool                 $active Whether this listing is selected.
 */
function xciv_listings_render_card( array $venue, bool $active = false ): void {
	$fee      = (float) ( $venue['delivery_fee'] ?? 0 );
	$near     = ! empty( $venue['near'] ) || $fee <= 0.0;
	$mode     = xciv_listings_fulfillment_mode( $venue );
	$mins     = (int) ( $venue['travel_mins'] ?? 0 );
	$detail   = xciv_listings_page_url( array( 'listing' => (string) $venue['id'] ) );
	$book     = add_query_arg(
		array(
			'xciv_venue'   => (string) $venue['id'],
			'xciv_service' => (string) $venue['service'],
			'xciv_store'   => (string) $venue['name'],
			'xciv_loc'     => (string) $venue['address'],
			'xciv_city'    => (string) $venue['area'],
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

	$mode_label = $mode === 'delivery'
		? __( 'Delivery', 'xciv-courier' )
		: __( 'Queue swap', 'xciv-courier' );

	$heat   = (string) ( $venue['heat'] ?? 'high' );
	$kind   = (string) ( $venue['kind'] ?? 'line' );
	$travel = function_exists( 'xciv_booking_venue_travel_label' )
		? xciv_booking_venue_travel_label( $venue )
		: '';

	$classes = 'xciv-card xciv-card--venue xciv-listing-card is-heat-' . $heat;
	if ( $active ) {
		$classes .= ' is-active';
	}
	if ( ! $near ) {
		$classes .= ' has-delivery-fee';
	}
	?>
	<article
		class="<?php echo esc_attr( $classes ); ?>"
		data-xciv-card
		data-xciv-venue
		data-xciv-listing="<?php echo esc_attr( (string) $venue['id'] ); ?>"
		data-status="venue"
		data-service="<?php echo esc_attr( (string) $venue['service'] ); ?>"
		data-kind="<?php echo esc_attr( $kind ); ?>"
		data-mode="<?php echo esc_attr( $mode ); ?>"
		data-near="<?php echo $near ? '1' : '0'; ?>"
		data-live="1"
		data-sms="0"
		data-miles="<?php echo esc_attr( (string) ( $venue['miles'] ?? '' ) ); ?>"
		data-fee="<?php echo esc_attr( (string) $fee ); ?>"
		data-travel-mins="<?php echo esc_attr( (string) $mins ); ?>"
		data-lat="<?php echo isset( $venue['lat'] ) ? esc_attr( (string) $venue['lat'] ) : ''; ?>"
		data-lng="<?php echo isset( $venue['lng'] ) ? esc_attr( (string) $venue['lng'] ) : ''; ?>"
		data-search="<?php echo esc_attr( strtolower( trim( (string) $venue['name'] . ' ' . (string) $venue['area'] . ' ' . (string) $venue['address'] . ' ' . (string) $venue['note'] . ' ' . $kind . ' ' . $mode_label . ' ' . $service_label . ' ' . $travel ) ) ); ?>"
	>
		<a class="xciv-card__link" href="<?php echo esc_url( $detail ); ?>" data-xciv-listing-open="<?php echo esc_attr( (string) $venue['id'] ); ?>">
			<div class="xciv-card__media" aria-hidden="true">
				<div class="xciv-card__art xciv-card__art--<?php echo esc_attr( (string) $venue['service'] ); ?>">
					<span class="xciv-card__grid"></span>
					<span class="xciv-card__pin"></span>
				</div>
				<span class="xciv-card__badge is-venue"><?php echo esc_html( (string) $venue['wait'] ); ?></span>
				<span class="xciv-card__badge is-eta" data-xciv-card-eta><?php echo esc_html( xciv_listings_eta_label( $mins ) ); ?></span>
			</div>
			<div class="xciv-card__body">
				<div class="xciv-card__title-row">
					<h4 class="xciv-card__title"><?php echo esc_html( (string) $venue['name'] ); ?></h4>
				</div>
				<p class="xciv-card__meta"><?php echo esc_html( (string) $venue['area'] ); ?> · <?php echo esc_html( $mode_label ); ?></p>
				<p class="xciv-card__location"><?php echo esc_html( (string) $venue['address'] ); ?></p>
				<?php if ( $travel !== '' ) : ?>
					<p class="xciv-card__travel<?php echo $near ? '' : ' is-fee'; ?>"><?php echo esc_html( $travel ); ?></p>
				<?php endif; ?>
				<div class="xciv-card__tags">
					<?php if ( $service_label !== '' ) : ?>
						<span class="xciv-card__tag"><?php echo esc_html( $service_label ); ?></span>
					<?php endif; ?>
					<span class="xciv-card__tag xciv-card__tag--accent"><?php echo esc_html( $mode_label ); ?></span>
					<?php if ( $near ) : ?>
						<span class="xciv-card__tag xciv-card__tag--near"><?php esc_html_e( 'Near hubs', 'xciv-courier' ); ?></span>
					<?php else : ?>
						<span class="xciv-card__tag xciv-card__tag--fee"><?php esc_html_e( 'Travel fee', 'xciv-courier' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</a>
		<div class="xciv-listing-card__actions">
			<a class="xciv-listing-card__book" href="<?php echo esc_url( $book ); ?>" data-xciv-booking-open>
				<?php echo $mode === 'delivery' ? esc_html__( 'Book delivery', 'xciv-courier' ) : esc_html__( 'Book queue swap', 'xciv-courier' ); ?>
			</a>
		</div>
	</article>
	<?php
}

/**
 * Detail panel for a single listing + live rider status.
 *
 * @param array<string, mixed> $venue Venue row.
 */
function xciv_listings_render_detail( array $venue ): void {
	$mode     = xciv_listings_fulfillment_mode( $venue );
	$mins     = (int) ( $venue['travel_mins'] ?? 0 );
	$fee      = (float) ( $venue['delivery_fee'] ?? 0 );
	$near     = ! empty( $venue['near'] ) || $fee <= 0.0;
	$book     = add_query_arg(
		array(
			'xciv_venue'   => (string) $venue['id'],
			'xciv_service' => (string) $venue['service'],
			'xciv_store'   => (string) $venue['name'],
			'xciv_loc'     => (string) $venue['address'],
			'xciv_city'    => (string) $venue['area'],
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

	$mission = $mode === 'delivery'
		? __( 'Lead delivery of consumer goods', 'xciv-courier' )
		: __( 'Queue swap — hold / trade place in line', 'xciv-courier' );
	?>
	<section
		class="xciv-listing-detail"
		data-xciv-listing-detail
		data-listing-id="<?php echo esc_attr( (string) $venue['id'] ); ?>"
		data-mode="<?php echo esc_attr( $mode ); ?>"
		data-travel-mins="<?php echo esc_attr( (string) $mins ); ?>"
		data-lat="<?php echo isset( $venue['lat'] ) ? esc_attr( (string) $venue['lat'] ) : ''; ?>"
		data-lng="<?php echo isset( $venue['lng'] ) ? esc_attr( (string) $venue['lng'] ) : ''; ?>"
		aria-labelledby="xciv-listing-detail-title"
	>
		<header class="xciv-listing-detail__head">
			<p class="xciv-listing-detail__eyebrow"><?php echo esc_html( $mission ); ?></p>
			<h2 id="xciv-listing-detail-title" class="xciv-listing-detail__title"><?php echo esc_html( (string) $venue['name'] ); ?></h2>
			<p class="xciv-listing-detail__meta"><?php echo esc_html( (string) $venue['area'] ); ?> · <?php echo esc_html( (string) $venue['address'] ); ?></p>
		</header>

		<div class="xciv-listing-live" data-xciv-listing-live role="status" aria-live="polite">
			<div class="xciv-listing-live__pulse" aria-hidden="true"></div>
			<div class="xciv-listing-live__copy">
				<p class="xciv-listing-live__rider"><?php echo esc_html( XCIV_LEAD_RIDER_NAME ); ?></p>
				<p class="xciv-listing-live__status" data-xciv-live-status>
					<?php
					echo $mode === 'delivery'
						? esc_html__( 'Lead delivery · goods en route', 'xciv-courier' )
						: esc_html__( 'Queue swap · holding your place', 'xciv-courier' );
					?>
				</p>
				<p class="xciv-listing-live__eta">
					<span class="xciv-listing-live__eta-label"><?php esc_html_e( 'ETA', 'xciv-courier' ); ?></span>
					<strong data-xciv-live-eta><?php echo esc_html( xciv_listings_eta_label( $mins ) ); ?></strong>
				</p>
			</div>
			<a class="xciv-listing-detail__back" href="<?php echo esc_url( xciv_listings_page_url() ); ?>">
				<?php esc_html_e( 'All listings', 'xciv-courier' ); ?>
			</a>
		</div>

		<dl class="xciv-panel__rows xciv-listing-detail__rows">
			<?php if ( $service_label !== '' ) : ?>
				<div class="xciv-panel__row">
					<dt><?php esc_html_e( 'Service', 'xciv-courier' ); ?></dt>
					<dd><?php echo esc_html( $service_label ); ?></dd>
				</div>
			<?php endif; ?>
			<div class="xciv-panel__row">
				<dt><?php esc_html_e( 'Typical wait', 'xciv-courier' ); ?></dt>
				<dd><?php echo esc_html( (string) $venue['wait'] ); ?></dd>
			</div>
			<div class="xciv-panel__row">
				<dt><?php esc_html_e( 'Travel', 'xciv-courier' ); ?></dt>
				<dd>
					<?php
					printf(
						/* translators: 1: miles 2: minutes */
						esc_html__( '%1$s mi · ~%2$d min from hub', 'xciv-courier' ),
						esc_html( (string) round( (float) ( $venue['miles'] ?? 0 ), 1 ) ),
						$mins
					);
					?>
				</dd>
			</div>
			<div class="xciv-panel__row">
				<dt><?php esc_html_e( 'Delivery fee', 'xciv-courier' ); ?></dt>
				<dd>
					<?php
					if ( $near ) {
						esc_html_e( 'Included near hubs', 'xciv-courier' );
					} elseif ( function_exists( 'wc_price' ) ) {
						echo wp_kses_post( wc_price( $fee ) );
					} else {
						echo esc_html( '$' . number_format_i18n( $fee, 0 ) );
					}
					?>
				</dd>
			</div>
			<?php if ( (string) ( $venue['note'] ?? '' ) !== '' ) : ?>
				<div class="xciv-panel__row">
					<dt><?php esc_html_e( 'Notes', 'xciv-courier' ); ?></dt>
					<dd><?php echo esc_html( (string) $venue['note'] ); ?></dd>
				</div>
			<?php endif; ?>
		</dl>

		<a class="xciv-shop-panel__cta" href="<?php echo esc_url( $book ); ?>" data-xciv-booking-open>
			<?php echo $mode === 'delivery' ? esc_html__( 'Book this delivery', 'xciv-courier' ) : esc_html__( 'Book this queue swap', 'xciv-courier' ); ?>
		</a>
	</section>
	<?php
}

/**
 * Full listings page markup.
 */
function xciv_listings_render(): void {
	$services  = function_exists( 'xciv_booking_service_types' ) ? xciv_booking_service_types() : array();
	$venues    = function_exists( 'xciv_booking_all_venues' ) ? xciv_booking_all_venues() : array();
	$active_id = xciv_listings_active_id();
	$active    = null;

	if ( $active_id !== '' && function_exists( 'xciv_booking_venue_by_id' ) ) {
		$active = xciv_booking_venue_by_id( $active_id );
	}

	add_filter( 'xciv_listings_force_assets', '__return_true' );
	xciv_listings_enqueue_assets();
	?>
	<div class="xciv-feed xciv-listings" data-xciv-feed data-xciv-listings>
		<header class="xciv-feed__top xciv-listings__top">
			<div class="xciv-feed__place">
				<span class="xciv-feed__place-label"><?php esc_html_e( 'Service listings', 'xciv-courier' ); ?></span>
				<strong><?php esc_html_e( 'Charlotte · Harrisburg · Concord', 'xciv-courier' ); ?></strong>
			</div>
			<label class="xciv-feed__search xciv-listings__search">
				<span class="screen-reader-text"><?php esc_html_e( 'Search listings', 'xciv-courier' ); ?></span>
				<input
					type="search"
					data-xciv-search
					data-xciv-listings-search
					placeholder="<?php esc_attr_e( 'Search venues, groceries, sneakers, queues…', 'xciv-courier' ); ?>"
					autocomplete="off"
				>
			</label>
			<p class="xciv-listings__count" data-xciv-listings-count aria-live="polite">
				<?php
				printf(
					/* translators: %d: listing count */
					esc_html( _n( '%d listing', '%d listings', count( $venues ), 'xciv-courier' ) ),
					count( $venues )
				);
				?>
			</p>
		</header>

		<nav class="xciv-feed__cats xciv-listings__modes" aria-label="<?php esc_attr_e( 'What clients need', 'xciv-courier' ); ?>">
			<button type="button" class="xciv-feed__cat is-active" data-xciv-listings-mode="all">
				<span class="xciv-feed__cat-icon" aria-hidden="true">◎</span>
				<span><?php esc_html_e( 'All', 'xciv-courier' ); ?></span>
			</button>
			<button type="button" class="xciv-feed__cat" data-xciv-listings-mode="queue">
				<span class="xciv-feed__cat-icon" aria-hidden="true">⇄</span>
				<span><?php esc_html_e( 'Queue swap', 'xciv-courier' ); ?></span>
			</button>
			<button type="button" class="xciv-feed__cat" data-xciv-listings-mode="delivery">
				<span class="xciv-feed__cat-icon" aria-hidden="true">▣</span>
				<span><?php esc_html_e( 'Delivery', 'xciv-courier' ); ?></span>
			</button>
		</nav>

		<nav class="xciv-feed__cats" aria-label="<?php esc_attr_e( 'Service types', 'xciv-courier' ); ?>">
			<button type="button" class="xciv-feed__cat is-active" data-xciv-cat="all">
				<span class="xciv-feed__cat-icon" aria-hidden="true">◎</span>
				<span><?php esc_html_e( 'All services', 'xciv-courier' ); ?></span>
			</button>
			<?php foreach ( $services as $slug => $service ) : ?>
				<button type="button" class="xciv-feed__cat" data-xciv-cat="<?php echo esc_attr( (string) $slug ); ?>">
					<span class="xciv-feed__cat-icon xciv-feed__cat-icon--<?php echo esc_attr( (string) $slug ); ?>" aria-hidden="true"></span>
					<span><?php echo esc_html( (string) $service['label'] ); ?></span>
				</button>
			<?php endforeach; ?>
		</nav>

		<div class="xciv-feed__filters" role="toolbar" aria-label="<?php esc_attr_e( 'Listing filters', 'xciv-courier' ); ?>">
			<button type="button" class="xciv-feed__chip is-active" data-xciv-listings-filter="all"><?php esc_html_e( 'All areas', 'xciv-courier' ); ?></button>
			<button type="button" class="xciv-feed__chip" data-xciv-listings-filter="near"><?php esc_html_e( 'Near hubs (free)', 'xciv-courier' ); ?></button>
			<button type="button" class="xciv-feed__chip" data-xciv-listings-filter="fee"><?php esc_html_e( 'Travel fee', 'xciv-courier' ); ?></button>
			<button type="button" class="xciv-feed__chip" data-xciv-listings-filter="grocery"><?php esc_html_e( 'Grocery', 'xciv-courier' ); ?></button>
			<button type="button" class="xciv-feed__chip" data-xciv-listings-filter="sneakers"><?php esc_html_e( 'Sneakers', 'xciv-courier' ); ?></button>
			<button type="button" class="xciv-feed__chip" data-xciv-listings-filter="line"><?php esc_html_e( 'Lines & drops', 'xciv-courier' ); ?></button>
		</div>

		<div class="xciv-listings__layout">
			<div class="xciv-listings__map-col">
				<div class="xciv-listings__map-head">
					<strong><?php esc_html_e( 'Live rider map', 'xciv-courier' ); ?></strong>
					<span data-xciv-map-live-label><?php echo esc_html( sprintf( /* translators: %s: rider name */ __( 'Tracking %s', 'xciv-courier' ), XCIV_LEAD_RIDER_NAME ) ); ?></span>
				</div>
				<?php if ( shortcode_exists( 'xciv_lead_rider_map' ) ) : ?>
					<div class="xciv-feed__map xciv-listings__map" data-xciv-listings-map>
						<?php echo do_shortcode( '[xciv_lead_rider_map height="420px" class="xciv-lead-map--listings"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php else : ?>
					<p class="xciv-listings__map-fallback"><?php esc_html_e( 'Activate XCIV Courier Core for the live map.', 'xciv-courier' ); ?></p>
				<?php endif; ?>

				<?php if ( $active ) : ?>
					<?php xciv_listings_render_detail( $active ); ?>
				<?php else : ?>
					<aside class="xciv-listing-live xciv-listing-live--idle" data-xciv-listing-live role="status">
						<div class="xciv-listing-live__pulse is-idle" aria-hidden="true"></div>
						<div class="xciv-listing-live__copy">
							<p class="xciv-listing-live__rider"><?php echo esc_html( XCIV_LEAD_RIDER_NAME ); ?></p>
							<p class="xciv-listing-live__status" data-xciv-live-status>
								<?php esc_html_e( 'Select a listing to track live ETA', 'xciv-courier' ); ?>
							</p>
							<p class="xciv-listing-live__eta">
								<span class="xciv-listing-live__eta-label"><?php esc_html_e( 'ETA', 'xciv-courier' ); ?></span>
								<strong data-xciv-live-eta>—</strong>
							</p>
						</div>
					</aside>
				<?php endif; ?>
			</div>

			<section class="xciv-listings__grid-wrap" data-xciv-section="listings" aria-labelledby="xciv-listings-grid-title">
				<div class="xciv-feed__section-head">
					<h3 id="xciv-listings-grid-title"><?php esc_html_e( 'Available listings', 'xciv-courier' ); ?></h3>
					<span class="xciv-feed__section-note"><?php esc_html_e( 'Filter by what clients & consumers need', 'xciv-courier' ); ?></span>
				</div>
				<div class="xciv-listings__grid" data-xciv-listings-grid>
					<?php if ( ! $venues ) : ?>
						<p class="xciv-listings__empty"><?php esc_html_e( 'No listings available yet.', 'xciv-courier' ); ?></p>
					<?php else : ?>
						<?php foreach ( $venues as $venue ) : ?>
							<?php
							xciv_listings_render_card(
								$venue,
								$active_id !== '' && (string) ( $venue['id'] ?? '' ) === $active_id
							);
							?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<p class="xciv-listings__empty" data-xciv-listings-empty hidden>
					<?php esc_html_e( 'No listings match your search.', 'xciv-courier' ); ?>
				</p>
			</section>
		</div>
	</div>
	<?php
}

/**
 * Shortcode entry.
 *
 * @param array<string, string>|string $atts Attributes.
 */
function xciv_listings_shortcode( $atts = array() ): string {
	unset( $atts );
	add_filter( 'xciv_listings_force_assets', '__return_true' );
	ob_start();
	xciv_listings_render();
	return (string) ob_get_clean();
}
add_shortcode( 'xciv_service_listings', 'xciv_listings_shortcode' );
