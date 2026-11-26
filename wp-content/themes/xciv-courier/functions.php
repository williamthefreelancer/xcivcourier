<?php
/**
 * XCIV Courier block theme — booking form block only.
 *
 * Layouts and marketing sections: Site Editor + Kadence Blocks.
 *
 * @package XCIV_Courier
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'XCIV_COURIER_VERSION', '0.9.13' );
define( 'XCIV_COURIER_DIR', get_template_directory() );
define( 'XCIV_COURIER_URI', get_template_directory_uri() );

require_once XCIV_COURIER_DIR . '/inc/scheduling.php';
require_once XCIV_COURIER_DIR . '/inc/memberships.php';
require_once XCIV_COURIER_DIR . '/inc/stripe.php';
require_once XCIV_COURIER_DIR . '/inc/booking-woocommerce.php';
require_once XCIV_COURIER_DIR . '/inc/booking-sms.php';
require_once XCIV_COURIER_DIR . '/inc/booking-dashboard.php';
require_once XCIV_COURIER_DIR . '/inc/shop-catalog.php';
require_once XCIV_COURIER_DIR . '/inc/service-listings.php';
require_once XCIV_COURIER_DIR . '/inc/auth.php';
require_once XCIV_COURIER_DIR . '/inc/header-profile.php';
require_once XCIV_COURIER_DIR . '/inc/lead-rider.php';
require_once XCIV_COURIER_DIR . '/inc/pickup-hubs.php';
require_once XCIV_COURIER_DIR . '/inc/breadcrumbs.php';

/**
 * Register the custom booking form block.
 */
function xciv_courier_register_blocks(): void {
	$block_dir = XCIV_COURIER_DIR . '/blocks/booking-form';
	if ( is_readable( $block_dir . '/block.json' ) ) {
		register_block_type( $block_dir );
	}
}
add_action( 'init', 'xciv_courier_register_blocks' );

/**
 * Editor + front fonts (minimal; Kadence owns section design).
 */
function xciv_courier_enqueue_assets(): void {
	wp_enqueue_style(
		'xciv-courier-fonts',
		'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap',
		array(),
		null
	);

	// Block themes do not always auto-load style.css — enqueue for header + breadcrumbs.
	wp_enqueue_style(
		'xciv-courier-style',
		get_stylesheet_uri(),
		array(),
		XCIV_COURIER_VERSION
	);

	if ( ! is_admin() ) {
		wp_enqueue_script(
			'xciv-courier-header-tabs',
			XCIV_COURIER_URI . '/assets/js/header-tabs.js',
			array(),
			XCIV_COURIER_VERSION,
			true
		);
	}

	if ( ! is_admin() && is_front_page() ) {
		wp_enqueue_style(
			'xciv-courier-front-page',
			XCIV_COURIER_URI . '/assets/css/front-page.css',
			array(),
			XCIV_COURIER_VERSION
		);
	}
}
add_action( 'wp_enqueue_scripts', 'xciv_courier_enqueue_assets' );
add_action( 'enqueue_block_editor_assets', 'xciv_courier_enqueue_assets' );

/**
 * Booking popup assets (form + modal) — available sitewide for CTA openers.
 */
function xciv_courier_enqueue_booking_modal(): void {
	if ( is_admin() ) {
		return;
	}

	wp_enqueue_style(
		'xciv-courier-booking-form',
		XCIV_COURIER_URI . '/blocks/booking-form/style.css',
		array(),
		XCIV_COURIER_VERSION
	);

	wp_enqueue_style(
		'xciv-courier-booking-modal',
		XCIV_COURIER_URI . '/assets/css/booking-modal.css',
		array( 'xciv-courier-booking-form' ),
		XCIV_COURIER_VERSION
	);

	wp_enqueue_script(
		'xciv-courier-booking-form',
		XCIV_COURIER_URI . '/assets/js/booking-form.js',
		array(),
		XCIV_COURIER_VERSION,
		true
	);

	wp_localize_script(
		'xciv-courier-booking-form',
		'xcivBooking',
		xciv_booking_js_config()
	);

	wp_enqueue_script(
		'xciv-courier-booking-modal',
		XCIV_COURIER_URI . '/assets/js/booking-modal.js',
		array( 'xciv-courier-booking-form' ),
		XCIV_COURIER_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'xciv_courier_enqueue_booking_modal' );

/**
 * Multi-step checkout assets (WooCommerce Blocks).
 */
function xciv_courier_enqueue_checkout(): void {
	if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}

	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		return;
	}

	wp_enqueue_style(
		'xciv-courier-checkout',
		XCIV_COURIER_URI . '/assets/css/checkout.css',
		array(),
		XCIV_COURIER_VERSION
	);

	wp_enqueue_script(
		'xciv-courier-checkout-steps',
		XCIV_COURIER_URI . '/assets/js/checkout-steps.js',
		array(),
		XCIV_COURIER_VERSION,
		true
	);

	wp_localize_script(
		'xciv-courier-checkout-steps',
		'xcivBookingCheckout',
		function_exists( 'xciv_booking_checkout_js_config' ) ? xciv_booking_checkout_js_config() : array( 'hasBooking' => false )
	);
}
add_action( 'wp_enqueue_scripts', 'xciv_courier_enqueue_checkout' );

/**
 * Inject booking popup markup before </body>.
 */
function xciv_courier_render_booking_modal(): void {
	$form = do_blocks( '<!-- wp:xciv-courier/booking-form {"heading":"Book a line sitter","submitLabel":"Continue to checkout"} /-->' );
	?>
	<div
		class="xciv-booking-modal"
		data-xciv-booking-modal
		aria-hidden="true"
		role="dialog"
		aria-modal="true"
		aria-label="<?php esc_attr_e( 'Booking form', 'xciv-courier' ); ?>"
	>
		<div class="xciv-booking-modal__panel" data-xciv-booking-panel>
			<button
				type="button"
				class="xciv-booking-modal__close"
				data-xciv-booking-close
				aria-label="<?php esc_attr_e( 'Close booking form', 'xciv-courier' ); ?>"
			>&times;</button>
			<?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted block render. ?>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'xciv_courier_render_booking_modal', 5 );
