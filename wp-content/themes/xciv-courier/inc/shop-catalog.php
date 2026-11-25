<?php
/**
 * Shop catalog — listing / booking / services using My Account card + panel language.
 *
 * @package XCIV_Courier
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current request is the WooCommerce shop archive.
 */
function xciv_shop_is_catalog(): bool {
	return function_exists( 'is_shop' ) && is_shop() && ! is_search();
}

/**
 * Enqueue My Account feed styles on the shop so cards/panels match.
 */
function xciv_shop_catalog_assets(): void {
	if ( is_admin() || ! xciv_shop_is_catalog() ) {
		return;
	}

	wp_enqueue_style(
		'xciv-courier-account-dashboard',
		XCIV_COURIER_URI . '/assets/css/account-dashboard.css',
		array(),
		XCIV_COURIER_VERSION
	);

	wp_enqueue_style(
		'xciv-courier-shop-catalog',
		XCIV_COURIER_URI . '/assets/css/shop-catalog.css',
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
}
add_action( 'wp_enqueue_scripts', 'xciv_shop_catalog_assets' );

/**
 * Mark shop for the shared feed shell.
 *
 * @param array<int, string> $classes Body classes.
 * @return array<int, string>
 */
function xciv_shop_body_class( array $classes ): array {
	if ( xciv_shop_is_catalog() ) {
		$classes[] = 'xciv-shop-catalog';
		$classes[] = 'xciv-account-feed';
	}
	return $classes;
}
add_filter( 'body_class', 'xciv_shop_body_class' );

/**
 * Book URL with optional service / venue prefill query args.
 *
 * @param array<string, string> $args Query args.
 */
function xciv_shop_book_url( array $args = array() ): string {
	return add_query_arg( $args, home_url( '/#book' ) );
}

/**
 * Render booking product panel (matches account panel chrome).
 */
function xciv_shop_render_booking_panel(): void {
	$price = function_exists( 'xciv_booking_base_price' ) ? xciv_booking_base_price() : 50.0;
	$extra = function_exists( 'xciv_booking_extra_hour_rate' ) ? xciv_booking_extra_hour_rate() : 25.0;
	$url   = xciv_shop_book_url();
	?>
	<section class="xciv-panel xciv-panel--mission xciv-shop-panel xciv-shop-panel--booking">
		<header class="xciv-panel__head">
			<p class="xciv-panel__eyebrow"><?php esc_html_e( 'Booking', 'xciv-courier' ); ?></p>
			<h4 class="xciv-panel__title"><?php esc_html_e( 'Line sitting booking', 'xciv-courier' ); ?></h4>
			<p class="xciv-panel__lede"><?php esc_html_e( 'Professional wait cover — book a sit and track it on your account feed.', 'xciv-courier' ); ?></p>
		</header>
		<dl class="xciv-panel__rows">
			<div class="xciv-panel__row">
				<dt><?php esc_html_e( 'Base cover', 'xciv-courier' ); ?></dt>
				<dd>
					<?php
					printf(
						/* translators: %s: formatted price */
						esc_html__( '%s / 2-hour minimum', 'xciv-courier' ),
						wp_strip_all_tags( wc_price( $price ) )
					);
					?>
				</dd>
			</div>
			<div class="xciv-panel__row">
				<dt><?php esc_html_e( 'Overtime', 'xciv-courier' ); ?></dt>
				<dd>
					<?php
					printf(
						/* translators: %s: formatted price */
						esc_html__( '%s each extra hour', 'xciv-courier' ),
						wp_strip_all_tags( wc_price( $extra ) )
					);
					?>
				</dd>
			</div>
			<div class="xciv-panel__row">
				<dt><?php esc_html_e( 'Schedule', 'xciv-courier' ); ?></dt>
				<dd><?php esc_html_e( 'Sunday–Wednesday · Charlotte & Harrisburg, NC', 'xciv-courier' ); ?></dd>
			</div>
		</dl>
		<a class="xciv-shop-panel__cta" href="<?php echo esc_url( $url ); ?>" data-xciv-booking-open>
			<?php esc_html_e( 'Start booking', 'xciv-courier' ); ?>
		</a>
	</section>
	<?php
}

/**
 * Render service-type panels (My Account panel language).
 */
function xciv_shop_render_service_panels(): void {
	if ( ! function_exists( 'xciv_booking_service_types' ) ) {
		return;
	}

	$mods = array(
		'hold_spot'     => 'mission',
		'queue_open'    => 'location',
		'secure_pickup' => 'cover',
		'personal_shop' => 'claim',
		'custom'        => 'claim',
	);

	$meta = array(
		'hold_spot'     => array(
			'eyebrow' => __( 'Service', 'xciv-courier' ),
			'fit'     => __( 'Drops & limited releases', 'xciv-courier' ),
			'window'  => __( 'Arrive early · hold until claim', 'xciv-courier' ),
		),
		'queue_open'    => array(
			'eyebrow' => __( 'Service', 'xciv-courier' ),
			'fit'     => __( 'Store opens & early access', 'xciv-courier' ),
			'window'  => __( 'Door-time stand · front-of-line handoff', 'xciv-courier' ),
		),
		'secure_pickup' => array(
			'eyebrow' => __( 'Service', 'xciv-courier' ),
			'fit'     => __( 'Fast-moving pickup counters', 'xciv-courier' ),
			'window'  => __( 'Hold place until you claim', 'xciv-courier' ),
		),
		'personal_shop' => array(
			'eyebrow' => __( 'Service', 'xciv-courier' ),
			'fit'     => __( 'Grocery, sneakers & tailored errands', 'xciv-courier' ),
			'window'  => __( 'Send your list · we shop the store', 'xciv-courier' ),
		),
		'custom'        => array(
			'eyebrow' => __( 'Service', 'xciv-courier' ),
			'fit'     => __( 'Allowed waits outside the presets', 'xciv-courier' ),
			'window'  => __( 'Describe the job · we confirm fit', 'xciv-courier' ),
		),
	);
	?>
	<div class="xciv-panels xciv-shop-panels" aria-label="<?php esc_attr_e( 'Line sitting services', 'xciv-courier' ); ?>">
		<?php foreach ( xciv_booking_service_types() as $slug => $service ) : ?>
			<?php
			$mod  = $mods[ $slug ] ?? 'mission';
			$info = $meta[ $slug ] ?? array(
				'eyebrow' => __( 'Service', 'xciv-courier' ),
				'fit'     => '',
				'window'  => '',
			);
			$url  = xciv_shop_book_url( array( 'xciv_service' => (string) $slug ) );
			?>
			<section class="xciv-panel xciv-panel--<?php echo esc_attr( $mod ); ?> xciv-shop-panel">
				<header class="xciv-panel__head">
					<p class="xciv-panel__eyebrow"><?php echo esc_html( (string) $info['eyebrow'] ); ?></p>
					<h4 class="xciv-panel__title"><?php echo esc_html( (string) $service['label'] ); ?></h4>
					<p class="xciv-panel__lede"><?php echo esc_html( (string) $service['description'] ); ?></p>
				</header>
				<dl class="xciv-panel__rows">
					<?php if ( (string) $info['fit'] !== '' ) : ?>
						<div class="xciv-panel__row">
							<dt><?php esc_html_e( 'Best for', 'xciv-courier' ); ?></dt>
							<dd><?php echo esc_html( (string) $info['fit'] ); ?></dd>
						</div>
					<?php endif; ?>
					<?php if ( (string) $info['window'] !== '' ) : ?>
						<div class="xciv-panel__row">
							<dt><?php esc_html_e( 'How it runs', 'xciv-courier' ); ?></dt>
							<dd><?php echo esc_html( (string) $info['window'] ); ?></dd>
						</div>
					<?php endif; ?>
				</dl>
				<a class="xciv-shop-panel__cta xciv-shop-panel__cta--ghost" href="<?php echo esc_url( $url ); ?>" data-xciv-booking-open>
					<?php esc_html_e( 'Book this service', 'xciv-courier' ); ?>
				</a>
			</section>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Render add-on products as listing-style cards.
 */
function xciv_shop_render_addon_cards(): void {
	if ( ! function_exists( 'xciv_booking_addons' ) ) {
		return;
	}

	$addons = xciv_booking_addons();
	if ( ! $addons ) {
		return;
	}
	?>
	<section class="xciv-feed__section" data-xciv-section="addons" aria-labelledby="xciv-shop-addons">
		<div class="xciv-feed__section-head">
			<h3 id="xciv-shop-addons"><?php esc_html_e( 'Add-ons', 'xciv-courier' ); ?></h3>
			<span class="xciv-feed__section-note"><?php esc_html_e( 'Optional extras on a sit', 'xciv-courier' ); ?></span>
			<div class="xciv-feed__scroll-btns">
				<button type="button" class="xciv-feed__scroll-btn" data-xciv-scroll="prev" aria-label="<?php esc_attr_e( 'Previous', 'xciv-courier' ); ?>">‹</button>
				<button type="button" class="xciv-feed__scroll-btn" data-xciv-scroll="next" aria-label="<?php esc_attr_e( 'Next', 'xciv-courier' ); ?>">›</button>
			</div>
		</div>
		<div class="xciv-feed__carousel" data-xciv-carousel>
			<?php foreach ( $addons as $slug => $addon ) : ?>
				<?php
				$url = xciv_shop_book_url( array( 'xciv_addon' => (string) $slug ) );
				$art = $slug === 'live_updates' ? 'queue_open' : ( $slug === 'purchase_assist' ? 'secure_pickup' : 'hold_spot' );
				?>
				<article
					class="xciv-card"
					data-xciv-card
					data-status="addon"
					data-service="<?php echo esc_attr( (string) $slug ); ?>"
					data-live="1"
					data-sms="<?php echo $slug === 'live_updates' ? '1' : '0'; ?>"
					data-search="<?php echo esc_attr( strtolower( (string) $addon['label'] . ' ' . (string) $addon['description'] ) ); ?>"
				>
					<a class="xciv-card__link" href="<?php echo esc_url( $url ); ?>" data-xciv-booking-open>
						<div class="xciv-card__media" aria-hidden="true">
							<div class="xciv-card__art xciv-card__art--<?php echo esc_attr( $art ); ?>">
								<span class="xciv-card__grid"></span>
								<span class="xciv-card__pin"></span>
							</div>
							<span class="xciv-card__badge is-confirmed">
								<?php echo esc_html( wp_strip_all_tags( wc_price( (float) $addon['price'] ) ) ); ?>
							</span>
						</div>
						<div class="xciv-card__body">
							<div class="xciv-card__title-row">
								<h4 class="xciv-card__title"><?php echo esc_html( (string) $addon['label'] ); ?></h4>
							</div>
							<p class="xciv-card__meta"><?php esc_html_e( 'Add to a booking', 'xciv-courier' ); ?></p>
							<p class="xciv-card__location"><?php echo esc_html( (string) $addon['description'] ); ?></p>
							<div class="xciv-card__tags">
								<span class="xciv-card__tag xciv-card__tag--accent"><?php esc_html_e( 'Optional', 'xciv-courier' ); ?></span>
							</div>
						</div>
					</a>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
	<?php
}

/**
 * Full shop catalog markup (venues + booking + services + add-ons).
 */
function xciv_shop_render_catalog(): void {
	?>
	<div class="xciv-feed xciv-shop-feed" data-xciv-feed>
		<header class="xciv-feed__top xciv-shop-feed__top">
			<div class="xciv-feed__place">
				<span class="xciv-feed__place-label"><?php esc_html_e( 'Serving hubs', 'xciv-courier' ); ?></span>
				<strong><?php esc_html_e( '28262 · 28213 · 28027', 'xciv-courier' ); ?></strong>
			</div>
			<label class="xciv-feed__search">
				<span class="screen-reader-text"><?php esc_html_e( 'Search shop', 'xciv-courier' ); ?></span>
				<input type="search" data-xciv-search placeholder="<?php esc_attr_e( 'Search venues, groceries, sneakers…', 'xciv-courier' ); ?>">
			</label>
		</header>

		<?php
		if ( function_exists( 'xciv_booking_render_venue_section' ) ) {
			xciv_booking_render_venue_section(
				'venues',
				__( 'Listings near you', 'xciv-courier' ),
				__( 'Within ~10 mi free · farther listings add travel fee', 'xciv-courier' ),
				function_exists( 'xciv_booking_busy_venues' ) ? xciv_booking_busy_venues() : array()
			);
			xciv_booking_render_venue_section(
				'grocery',
				__( 'Personal shopping', 'xciv-courier' ),
				__( 'University City · Harrisburg · Concord hubs', 'xciv-courier' ),
				function_exists( 'xciv_booking_shopping_venues' ) ? xciv_booking_shopping_venues() : array()
			);
			xciv_booking_render_venue_section(
				'sneakers',
				__( 'Jordan & Nike consignment', 'xciv-courier' ),
				__( 'Near hubs free · SouthPark adds delivery fee', 'xciv-courier' ),
				function_exists( 'xciv_booking_sneaker_venues' ) ? xciv_booking_sneaker_venues() : array()
			);
		}
		?>

		<section class="xciv-feed__section" data-xciv-section="booking" aria-labelledby="xciv-shop-booking">
			<div class="xciv-feed__section-head">
				<h3 id="xciv-shop-booking"><?php esc_html_e( 'Booking', 'xciv-courier' ); ?></h3>
				<span class="xciv-feed__section-note"><?php esc_html_e( 'Core line-sitting cover', 'xciv-courier' ); ?></span>
			</div>
			<div class="xciv-panels xciv-shop-panels xciv-shop-panels--booking">
				<?php xciv_shop_render_booking_panel(); ?>
			</div>
		</section>

		<section class="xciv-feed__section" data-xciv-section="services" aria-labelledby="xciv-shop-services">
			<div class="xciv-feed__section-head">
				<h3 id="xciv-shop-services"><?php esc_html_e( 'Services', 'xciv-courier' ); ?></h3>
				<span class="xciv-feed__section-note"><?php esc_html_e( 'Pick how the sit should run', 'xciv-courier' ); ?></span>
			</div>
			<?php xciv_shop_render_service_panels(); ?>
		</section>

		<?php xciv_shop_render_addon_cards(); ?>
	</div>
	<?php
}

/**
 * Replace the empty Woo product collection with the XCIV shop catalog.
 *
 * @param string               $block_content Block HTML.
 * @param array<string, mixed> $block         Parsed block.
 */
function xciv_shop_replace_product_collection( string $block_content, array $block ): string {
	if ( ! xciv_shop_is_catalog() ) {
		return $block_content;
	}

	ob_start();
	xciv_shop_render_catalog();
	return (string) ob_get_clean();
}
add_filter( 'render_block_woocommerce/product-collection', 'xciv_shop_replace_product_collection', 10, 2 );

/**
 * Soften the default shop title copy for the catalog feed.
 *
 * @param string $title Query title.
 */
function xciv_shop_query_title( string $title ): string {
	if ( xciv_shop_is_catalog() ) {
		return __( 'Shop', 'xciv-courier' );
	}
	return $title;
}
add_filter( 'woocommerce_page_title', 'xciv_shop_query_title' );
