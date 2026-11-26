<?php
/**
 * Theme-native memberships & subscriptions (no paid plugins).
 *
 * Plans: XCIV Courier One, XCIV Courier Purple.
 * Cron: xciv_membership_daily_sweep via Action Scheduler / WP-Cron.
 *
 * @package XCIV_Courier
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const XCIV_META_MEMBERSHIP_PLAN    = 'xciv_membership_plan';
const XCIV_META_MEMBERSHIP_STATUS  = 'xciv_membership_status';
const XCIV_META_MEMBERSHIP_STARTED = 'xciv_membership_started_at';
const XCIV_META_MEMBERSHIP_EXPIRES = 'xciv_membership_expires_at';
const XCIV_META_MEMBERSHIP_ORDER   = 'xciv_membership_order_id';
const XCIV_PRODUCT_PLAN_META       = '_xciv_membership_plan';
const XCIV_CRON_MEMBERSHIP_SWEEP   = 'xciv_membership_daily_sweep';
const XCIV_CRON_MEMBERSHIP_EXPIRE  = 'xciv_membership_expire_user';
const XCIV_CRON_MEMBERSHIP_REMIND  = 'xciv_membership_renewal_reminder';

/**
 * Membership plan catalog — low-cost monthly / yearly for Courier One + Purple.
 *
 * @return array{
 *   default_interval:string,
 *   plans:array<int, array{
 *     slug:string,
 *     family:string,
 *     interval:string,
 *     name:string,
 *     price:string,
 *     amount_raw:float,
 *     cadence:string,
 *     term:string,
 *     term_months:int,
 *     note:string,
 *     product_slug:string,
 *     sku:string,
 *     role:string,
 *     accent:string,
 *     perks:array<int,string>
 *   }>
 * }
 */
function xciv_get_membership_plan_definitions(): array {
	$renewal = __( 'You’ll get a reminder before renewal.', 'xciv-courier' );

	$one_perks = array(
		__( 'Priority booking queue', 'xciv-courier' ),
		__( 'Standard lead delivery support', 'xciv-courier' ),
		__( 'Member checkout shortcuts', 'xciv-courier' ),
	);
	$purple_perks = array(
		__( 'Top-of-queue priority', 'xciv-courier' ),
		__( 'Live SMS updates included', 'xciv-courier' ),
		__( 'Dedicated rider preference', 'xciv-courier' ),
		__( 'Purchase-assist unlocks', 'xciv-courier' ),
	);

	$plans = array(
		array(
			'slug'         => 'courier-one-monthly',
			'family'       => 'courier-one',
			'interval'     => 'monthly',
			'name'         => __( 'XCIV Courier One', 'xciv-courier' ),
			'price'        => '$4.99',
			'amount_raw'   => 4.99,
			'cadence'      => __( '/mo', 'xciv-courier' ),
			'term'         => __( 'billed monthly', 'xciv-courier' ),
			'term_months'  => 1,
			'note'         => $renewal,
			'product_slug' => 'xciv-courier-one-monthly',
			'sku'          => 'xciv-courier-one-monthly',
			'role'         => 'customer',
			'accent'       => 'one',
			'perks'        => $one_perks,
		),
		array(
			'slug'         => 'courier-one-yearly',
			'family'       => 'courier-one',
			'interval'     => 'yearly',
			'name'         => __( 'XCIV Courier One', 'xciv-courier' ),
			'price'        => '$39',
			'amount_raw'   => 39.0,
			'cadence'      => __( '/yr', 'xciv-courier' ),
			'term'         => __( 'billed yearly · save vs monthly', 'xciv-courier' ),
			'term_months'  => 12,
			'note'         => $renewal,
			'product_slug' => 'xciv-courier-one-yearly',
			'sku'          => 'xciv-courier-one-yearly',
			'role'         => 'customer',
			'accent'       => 'one',
			'perks'        => $one_perks,
		),
		array(
			'slug'         => 'courier-purple-monthly',
			'family'       => 'courier-purple',
			'interval'     => 'monthly',
			'name'         => __( 'XCIV Courier Purple', 'xciv-courier' ),
			'price'        => '$9.99',
			'amount_raw'   => 9.99,
			'cadence'      => __( '/mo', 'xciv-courier' ),
			'term'         => __( 'billed monthly', 'xciv-courier' ),
			'term_months'  => 1,
			'note'         => $renewal,
			'product_slug' => 'xciv-courier-purple-monthly',
			'sku'          => 'xciv-courier-purple-monthly',
			'role'         => 'customer',
			'accent'       => 'purple',
			'perks'        => $purple_perks,
		),
		array(
			'slug'         => 'courier-purple-yearly',
			'family'       => 'courier-purple',
			'interval'     => 'yearly',
			'name'         => __( 'XCIV Courier Purple', 'xciv-courier' ),
			'price'        => '$79',
			'amount_raw'   => 79.0,
			'cadence'      => __( '/yr', 'xciv-courier' ),
			'term'         => __( 'billed yearly · save vs monthly', 'xciv-courier' ),
			'term_months'  => 12,
			'note'         => $renewal,
			'product_slug' => 'xciv-courier-purple-yearly',
			'sku'          => 'xciv-courier-purple-yearly',
			'role'         => 'customer',
			'accent'       => 'purple',
			'perks'        => $purple_perks,
		),
	);

	/**
	 * Filter membership plan definitions.
	 *
	 * @param array{default_interval:string,plans:array<int,array<string,mixed>>} $defs Definitions.
	 */
	return apply_filters(
		'xciv_membership_plan_definitions',
		array(
			'default_interval' => 'monthly',
			'plans'            => $plans,
		)
	);
}

/**
 * Unique plan families for upsell cards (One / Purple).
 *
 * @return array<string, array{family:string,name:string,accent:string,perks:array<int,string>,intervals:array<string,array<string,mixed>>}>
 */
function xciv_get_membership_plan_families(): array {
	$families = array();
	foreach ( xciv_get_membership_plan_definitions()['plans'] as $plan ) {
		$family = (string) ( $plan['family'] ?? $plan['slug'] );
		if ( ! isset( $families[ $family ] ) ) {
			$families[ $family ] = array(
				'family'    => $family,
				'name'      => (string) $plan['name'],
				'accent'    => (string) $plan['accent'],
				'perks'     => is_array( $plan['perks'] ?? null ) ? $plan['perks'] : array(),
				'intervals' => array(),
			);
		}
		$families[ $family ]['intervals'][ (string) $plan['interval'] ] = $plan;
	}
	return $families;
}

/**
 * Plan row by slug.
 *
 * @param string $slug Plan slug.
 * @return array<string, mixed>|null
 */
function xciv_get_membership_plan_by_slug( string $slug ): ?array {
	$slug = sanitize_title( $slug );
	foreach ( xciv_get_membership_plan_definitions()['plans'] as $plan ) {
		if ( (string) $plan['slug'] === $slug ) {
			return $plan;
		}
	}
	return null;
}

/**
 * Current membership snapshot for a user.
 *
 * @param int $user_id User ID.
 * @return array{plan:string,status:string,started:string,expires:string,order_id:int,active:bool,label:string}
 */
function xciv_get_user_membership( int $user_id ): array {
	$plan    = (string) get_user_meta( $user_id, XCIV_META_MEMBERSHIP_PLAN, true );
	$status  = (string) get_user_meta( $user_id, XCIV_META_MEMBERSHIP_STATUS, true );
	$started = (string) get_user_meta( $user_id, XCIV_META_MEMBERSHIP_STARTED, true );
	$expires = (string) get_user_meta( $user_id, XCIV_META_MEMBERSHIP_EXPIRES, true );
	$order   = (int) get_user_meta( $user_id, XCIV_META_MEMBERSHIP_ORDER, true );
	$row     = $plan !== '' ? xciv_get_membership_plan_by_slug( $plan ) : null;

	$active = ( $status === 'active' );
	if ( $active && $expires !== '' ) {
		$ts = strtotime( $expires );
		if ( $ts && $ts < time() ) {
			$active = false;
		}
	}

	return array(
		'plan'     => $plan,
		'status'   => $status !== '' ? $status : 'none',
		'started'  => $started,
		'expires'  => $expires,
		'order_id' => $order,
		'active'   => $active,
		'label'    => $row ? (string) $row['name'] : '',
	);
}

/**
 * Clear per-user membership schedules.
 */
function xciv_membership_clear_user_schedules( int $user_id ): void {
	$hooks = array( XCIV_CRON_MEMBERSHIP_EXPIRE, XCIV_CRON_MEMBERSHIP_REMIND );
	foreach ( $hooks as $hook ) {
		xciv_unschedule_all( $hook, array( $user_id ), 'xciv-memberships' );
	}
}

/**
 * Activate or renew membership.
 *
 * @param array<string, mixed> $args Optional: order_id, term_months.
 */
function xciv_activate_membership( int $user_id, string $plan_slug, array $args = array() ): bool {
	$plan_slug = sanitize_title( $plan_slug );
	$plan      = xciv_get_membership_plan_by_slug( $plan_slug );
	if ( $user_id <= 0 || ! $plan ) {
		return false;
	}

	$term_months = isset( $args['term_months'] )
		? max( 1, (int) $args['term_months'] )
		: max( 1, (int) ( $plan['term_months'] ?? 1 ) );
	$order_id    = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
	$tz          = wp_timezone();
	$now         = new DateTimeImmutable( 'now', $tz );

	$existing_expires = (string) get_user_meta( $user_id, XCIV_META_MEMBERSHIP_EXPIRES, true );
	$existing_status  = (string) get_user_meta( $user_id, XCIV_META_MEMBERSHIP_STATUS, true );
	$base             = $now;
	if ( $existing_status === 'active' && $existing_expires !== '' ) {
		$existing_ts = strtotime( $existing_expires );
		if ( $existing_ts && $existing_ts > time() ) {
			$base = ( new DateTimeImmutable( '@' . $existing_ts ) )->setTimezone( $tz );
		}
	}

	$expires  = $base->modify( '+' . $term_months . ' months' );
	$reminder = $expires->modify( '-3 days' );

	update_user_meta( $user_id, XCIV_META_MEMBERSHIP_PLAN, $plan_slug );
	update_user_meta( $user_id, XCIV_META_MEMBERSHIP_STATUS, 'active' );
	update_user_meta( $user_id, XCIV_META_MEMBERSHIP_STARTED, $now->format( 'Y-m-d H:i:s' ) );
	update_user_meta( $user_id, XCIV_META_MEMBERSHIP_EXPIRES, $expires->format( 'Y-m-d H:i:s' ) );
	if ( $order_id > 0 ) {
		update_user_meta( $user_id, XCIV_META_MEMBERSHIP_ORDER, $order_id );
	}

	xciv_membership_clear_user_schedules( $user_id );
	xciv_schedule_once( XCIV_CRON_MEMBERSHIP_EXPIRE, $expires->getTimestamp(), array( $user_id ), 'xciv-memberships' );
	if ( $reminder->getTimestamp() > time() ) {
		xciv_schedule_once( XCIV_CRON_MEMBERSHIP_REMIND, $reminder->getTimestamp(), array( $user_id ), 'xciv-memberships' );
	}

	/**
	 * Fires after membership activation.
	 *
	 * @param int                  $user_id   User ID.
	 * @param string               $plan_slug Plan slug.
	 * @param array<string, mixed> $args      Args.
	 */
	do_action( 'xciv_membership_activated', $user_id, $plan_slug, $args );

	return true;
}

/**
 * End membership.
 */
function xciv_end_membership( int $user_id, string $status = 'expired' ): void {
	if ( $user_id <= 0 ) {
		return;
	}
	$status = in_array( $status, array( 'expired', 'cancelled' ), true ) ? $status : 'expired';
	update_user_meta( $user_id, XCIV_META_MEMBERSHIP_STATUS, $status );
	xciv_membership_clear_user_schedules( $user_id );

	/**
	 * Fires after membership ends.
	 *
	 * @param int    $user_id User ID.
	 * @param string $status  Status.
	 */
	do_action( 'xciv_membership_ended', $user_id, $status );
}

/**
 * Resolve plan slug from a Woo product.
 *
 * @param int|\WC_Product $product Product.
 */
function xciv_get_plan_slug_from_product( $product ): string {
	if ( is_numeric( $product ) ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product ) : null;
	}
	if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
		return '';
	}

	$meta = (string) $product->get_meta( XCIV_PRODUCT_PLAN_META, true );
	if ( $meta !== '' ) {
		return sanitize_title( $meta );
	}

	$slug = $product->get_slug();
	$sku  = (string) $product->get_sku();
	foreach ( xciv_get_membership_plan_definitions()['plans'] as $plan ) {
		if ( $slug === $plan['product_slug'] || $sku === $plan['sku'] || false !== strpos( $slug, (string) $plan['slug'] ) ) {
			return (string) $plan['slug'];
		}
	}

	return '';
}

/**
 * Woo product ID for a plan slug (creates if missing).
 */
function xciv_get_membership_product_id( string $plan_slug, bool $create = true ): int {
	$plan = xciv_get_membership_plan_by_slug( $plan_slug );
	if ( ! $plan || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
		return 0;
	}

	$id = (int) wc_get_product_id_by_sku( (string) $plan['sku'] );
	if ( $id > 0 ) {
		return $id;
	}

	$existing = get_page_by_path( (string) $plan['product_slug'], OBJECT, 'product' );
	if ( $existing ) {
		return (int) $existing->ID;
	}

	if ( ! $create || ! class_exists( 'WC_Product_Simple' ) ) {
		return 0;
	}

	$product = new WC_Product_Simple();
	$product->set_name( (string) $plan['name'] );
	$product->set_slug( (string) $plan['product_slug'] );
	$product->set_sku( (string) $plan['sku'] );
	$product->set_regular_price( (string) $plan['amount_raw'] );
	$product->set_price( (string) $plan['amount_raw'] );
	$product->set_catalog_visibility( 'hidden' );
	$product->set_status( 'publish' );
	$product->set_virtual( true );
	$product->set_sold_individually( true );
	$product->update_meta_data( XCIV_PRODUCT_PLAN_META, (string) $plan['slug'] );
	$saved = $product->save();

	return $saved > 0 ? $saved : 0;
}

/**
 * Ensure Woo products exist for Courier One + Purple.
 */
function xciv_ensure_membership_products(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	foreach ( xciv_get_membership_plan_definitions()['plans'] as $plan ) {
		xciv_get_membership_product_id( (string) $plan['slug'], true );
	}
}
add_action( 'init', 'xciv_ensure_membership_products', 40 );

/**
 * Process paid Woo order for membership line items.
 *
 * @param mixed $order_id Order ID.
 */
function xciv_process_membership_order( $order_id ): void {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = wc_get_order( absint( $order_id ) );
	if ( ! $order ) {
		return;
	}

	$user_id = (int) $order->get_user_id();
	if ( $user_id <= 0 ) {
		return;
	}

	$activated = '';
	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$plan = xciv_get_plan_slug_from_product( (int) $item->get_product_id() );
		if ( $plan === '' ) {
			$plan = sanitize_title( (string) $item->get_meta( '_xciv_membership_plan', true ) );
		}
		if ( $plan === '' ) {
			continue;
		}
		if ( xciv_activate_membership( $user_id, $plan, array( 'order_id' => (int) $order->get_id() ) ) ) {
			$activated = $plan;
		}
	}

	if ( $activated !== '' ) {
		$order->update_meta_data( '_xciv_membership_plan_activated', $activated );
		$order->save();
	}
}
add_action( 'woocommerce_payment_complete', 'xciv_process_membership_order', 25 );
add_action( 'woocommerce_order_status_processing', 'xciv_process_membership_order', 25 );
add_action( 'woocommerce_order_status_completed', 'xciv_process_membership_order', 25 );

/**
 * Cron: expire one user.
 *
 * @param mixed $user_id User ID.
 */
function xciv_membership_expire_user_action( $user_id ): void {
	$user_id = absint( $user_id );
	if ( $user_id <= 0 ) {
		return;
	}
	$membership = xciv_get_user_membership( $user_id );
	if ( $membership['status'] !== 'active' ) {
		return;
	}
	xciv_end_membership( $user_id, 'expired' );
}
add_action( XCIV_CRON_MEMBERSHIP_EXPIRE, 'xciv_membership_expire_user_action' );

/**
 * Cron: renewal reminder (email if possible).
 *
 * @param mixed $user_id User ID.
 */
function xciv_membership_renewal_reminder_action( $user_id ): void {
	$user_id = absint( $user_id );
	$user    = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}
	$membership = xciv_get_user_membership( $user_id );
	if ( ! $membership['active'] ) {
		return;
	}

	$subject = sprintf(
		/* translators: %s: plan name */
		__( 'Your %s membership renews soon', 'xciv-courier' ),
		$membership['label'] !== '' ? $membership['label'] : __( 'XCIV Courier', 'xciv-courier' )
	);
	$body = sprintf(
		/* translators: 1: display name, 2: plan, 3: expiry datetime */
		__( "Hi %1\$s,\n\nYour %2\$s membership is set to expire on %3\$s. Renew anytime from your account.\n\n— XCIV Courier", 'xciv-courier' ),
		$user->display_name,
		$membership['label'] !== '' ? $membership['label'] : __( 'XCIV Courier', 'xciv-courier' ),
		$membership['expires']
	);
	wp_mail( $user->user_email, $subject, $body );

	do_action( 'xciv_membership_renewal_reminder_sent', $user_id, $membership );
}
add_action( XCIV_CRON_MEMBERSHIP_REMIND, 'xciv_membership_renewal_reminder_action' );

/**
 * Daily sweep for lapsed memberships.
 */
function xciv_membership_daily_sweep(): void {
	$query = new WP_User_Query(
		array(
			'meta_key'     => XCIV_META_MEMBERSHIP_STATUS,
			'meta_value'   => 'active',
			'fields'       => 'ID',
			'number'       => 500,
			'count_total'  => false,
		)
	);

	foreach ( (array) $query->get_results() as $user_id ) {
		$user_id    = (int) $user_id;
		$membership = xciv_get_user_membership( $user_id );
		if ( $membership['status'] !== 'active' ) {
			continue;
		}
		if ( $membership['expires'] === '' ) {
			continue;
		}
		$ts = strtotime( $membership['expires'] );
		if ( $ts && $ts < time() ) {
			xciv_end_membership( $user_id, 'expired' );
		}
	}
}
add_action( XCIV_CRON_MEMBERSHIP_SWEEP, 'xciv_membership_daily_sweep' );

/**
 * Schedule daily membership sweep.
 */
function xciv_membership_schedule_cron(): void {
	xciv_schedule_recurring(
		XCIV_CRON_MEMBERSHIP_SWEEP,
		DAY_IN_SECONDS,
		array(),
		'xciv-memberships',
		time() + HOUR_IN_SECONDS
	);
}
add_action( 'init', 'xciv_membership_schedule_cron', 30 );

/**
 * AJAX: add membership plan to Woo cart → existing vanilla checkout.
 */
function xciv_membership_ajax_add_to_cart(): void {
	check_ajax_referer( 'xciv_membership', 'nonce' );

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		wp_send_json_error( array( 'message' => __( 'Checkout is unavailable.', 'xciv-courier' ) ), 503 );
	}

	$plan_slug = sanitize_title( (string) ( $_POST['plan'] ?? '' ) );
	$plan      = xciv_get_membership_plan_by_slug( $plan_slug );
	if ( ! $plan ) {
		wp_send_json_error( array( 'message' => __( 'Unknown membership plan.', 'xciv-courier' ) ), 400 );
	}

	$product_id = xciv_get_membership_product_id( $plan_slug, true );
	if ( $product_id <= 0 ) {
		wp_send_json_error( array( 'message' => __( 'Membership product is missing.', 'xciv-courier' ) ), 500 );
	}

	WC()->cart->empty_cart();
	$added = WC()->cart->add_to_cart( $product_id, 1, 0, array(), array( 'xciv_membership_plan' => $plan_slug ) );
	if ( ! $added ) {
		wp_send_json_error( array( 'message' => __( 'Could not add membership to cart.', 'xciv-courier' ) ), 500 );
	}

	wp_send_json_success(
		array(
			'plan'        => $plan_slug,
			'checkoutUrl' => wc_get_checkout_url(),
			'mode'        => 'woo',
		)
	);
}
add_action( 'wp_ajax_xciv_membership_add_to_cart', 'xciv_membership_ajax_add_to_cart' );
add_action( 'wp_ajax_nopriv_xciv_membership_add_to_cart', 'xciv_membership_ajax_add_to_cart' );

/**
 * Render membership upsell cards (dashboard + shortcode).
 *
 * @param array<string, mixed> $args Optional: context (dashboard|page), show_intro.
 */
function xciv_render_membership_upsells( array $args = array() ): void {
	$context      = (string) ( $args['context'] ?? 'page' );
	$show_intro   = array_key_exists( 'show_intro', $args ) ? (bool) $args['show_intro'] : true;
	$defs         = xciv_get_membership_plan_definitions();
	$families     = xciv_get_membership_plan_families();
	$interval     = (string) ( $defs['default_interval'] ?? 'monthly' );
	$membership   = is_user_logged_in() ? xciv_get_user_membership( get_current_user_id() ) : null;
	$stripe_ready = function_exists( 'xciv_stripe_is_ready' ) && xciv_stripe_is_ready();
	$active_family = '';

	if ( $membership && $membership['active'] && $membership['plan'] !== '' ) {
		$active_plan = xciv_get_membership_plan_by_slug( $membership['plan'] );
		$active_family = $active_plan ? (string) ( $active_plan['family'] ?? '' ) : '';
		// Hide upsell when already on Purple.
		if ( $context === 'dashboard' && $active_family === 'courier-purple' ) {
			return;
		}
	}
	?>
	<section
		class="xciv-membership xciv-membership--<?php echo esc_attr( $context ); ?>"
		data-xciv-membership
		data-default-interval="<?php echo esc_attr( $interval ); ?>"
	>
		<?php if ( $show_intro ) : ?>
			<header class="xciv-membership__intro">
				<p class="xciv-membership__kicker"><?php esc_html_e( 'Member upsells', 'xciv-courier' ); ?></p>
				<h2 class="xciv-membership__title">
					<?php
					echo $context === 'dashboard'
						? esc_html__( 'Upgrade your courier pass', 'xciv-courier' )
						: esc_html__( 'Courier memberships', 'xciv-courier' );
					?>
				</h2>
				<p class="xciv-membership__lede">
					<?php esc_html_e( 'Low-cost XCIV Courier One or Purple — monthly or yearly. Stripe or your existing checkout.', 'xciv-courier' ); ?>
				</p>
				<?php if ( $membership && $membership['active'] ) : ?>
					<p class="xciv-membership__current">
						<?php
						printf(
							/* translators: 1: plan label, 2: expiry */
							esc_html__( 'Active: %1$s · renews/expires %2$s', 'xciv-courier' ),
							esc_html( $membership['label'] ),
							esc_html( $membership['expires'] )
						);
						?>
					</p>
				<?php endif; ?>
			</header>
		<?php endif; ?>

		<div class="xciv-membership__billing" role="group" aria-label="<?php esc_attr_e( 'Billing interval', 'xciv-courier' ); ?>">
			<button type="button" class="xciv-membership__interval is-active" data-xciv-interval="monthly"><?php esc_html_e( 'Monthly', 'xciv-courier' ); ?></button>
			<button type="button" class="xciv-membership__interval" data-xciv-interval="yearly"><?php esc_html_e( 'Yearly', 'xciv-courier' ); ?></button>
		</div>

		<div class="xciv-membership__grid" role="list">
			<?php foreach ( $families as $family ) : ?>
				<?php
				if ( $context === 'dashboard' && $active_family !== '' && $active_family === $family['family'] ) {
					continue;
				}
				$monthly = $family['intervals']['monthly'] ?? null;
				$yearly  = $family['intervals']['yearly'] ?? null;
				if ( ! $monthly && ! $yearly ) {
					continue;
				}
				$default = $monthly ?: $yearly;
				?>
				<article
					class="xciv-membership__card xciv-membership__card--<?php echo esc_attr( (string) $family['accent'] ); ?>"
					role="listitem"
					data-xciv-family="<?php echo esc_attr( (string) $family['family'] ); ?>"
					data-plan-monthly="<?php echo esc_attr( (string) ( $monthly['slug'] ?? '' ) ); ?>"
					data-plan-yearly="<?php echo esc_attr( (string) ( $yearly['slug'] ?? '' ) ); ?>"
					data-price-monthly="<?php echo esc_attr( (string) ( $monthly['price'] ?? '' ) ); ?>"
					data-cadence-monthly="<?php echo esc_attr( (string) ( $monthly['cadence'] ?? '' ) ); ?>"
					data-term-monthly="<?php echo esc_attr( (string) ( $monthly['term'] ?? '' ) ); ?>"
					data-price-yearly="<?php echo esc_attr( (string) ( $yearly['price'] ?? '' ) ); ?>"
					data-cadence-yearly="<?php echo esc_attr( (string) ( $yearly['cadence'] ?? '' ) ); ?>"
					data-term-yearly="<?php echo esc_attr( (string) ( $yearly['term'] ?? '' ) ); ?>"
				>
					<h3 class="xciv-membership__name"><?php echo esc_html( (string) $family['name'] ); ?></h3>
					<p class="xciv-membership__price">
						<span data-xciv-price><?php echo esc_html( (string) $default['price'] ); ?></span>
						<small data-xciv-cadence><?php echo esc_html( (string) $default['cadence'] ); ?></small>
					</p>
					<p class="xciv-membership__term" data-xciv-term><?php echo esc_html( (string) $default['term'] ); ?></p>
					<?php if ( ! empty( $family['perks'] ) ) : ?>
						<ul class="xciv-membership__perks">
							<?php foreach ( $family['perks'] as $perk ) : ?>
								<li><?php echo esc_html( (string) $perk ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<div class="xciv-membership__actions">
						<?php if ( $stripe_ready ) : ?>
							<button
								type="button"
								class="xciv-membership__btn xciv-membership__btn--stripe"
								data-xciv-membership-stripe
							>
								<?php esc_html_e( 'Pay with Stripe', 'xciv-courier' ); ?>
							</button>
						<?php endif; ?>
						<button
							type="button"
							class="xciv-membership__btn xciv-membership__btn--woo"
							data-xciv-membership-woo
						>
							<?php esc_html_e( 'Continue to checkout', 'xciv-courier' ); ?>
						</button>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<p class="xciv-membership__status" data-xciv-membership-status hidden></p>
	</section>
	<?php
}

/**
 * Shortcode: plan picker UI for vanilla.js.
 */
function xciv_membership_plans_shortcode(): string {
	ob_start();
	xciv_render_membership_upsells( array( 'context' => 'page', 'show_intro' => true ) );
	return (string) ob_get_clean();
}
add_shortcode( 'xciv_membership_plans', 'xciv_membership_plans_shortcode' );
