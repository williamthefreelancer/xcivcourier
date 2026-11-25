<?php
/**
 * My Account dashboard — XCIV live booking tracker.
 *
 * @package XCIV_Courier
 * @version 0.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'xciv_booking_render_dashboard' ) ) {
	xciv_booking_render_dashboard();
	return;
}

/**
 * Fallback: default WooCommerce intro if the theme helper is missing.
 */
$allowed_html = array(
	'a' => array(
		'href' => true,
	),
);
?>
<p>
	<?php
	printf(
		/* translators: 1: user display name 2: logout url */
		wp_kses( __( 'Hello %1$s (not %1$s? <a href="%2$s">Log out</a>)', 'woocommerce' ), $allowed_html ),
		'<strong>' . esc_html( wp_get_current_user()->display_name ) . '</strong>',
		esc_url( wc_logout_url() )
	);
	?>
</p>

<p>
	<?php
	printf(
		wp_kses(
			__( 'From your account dashboard you can view your <a href="%1$s">recent orders</a>, manage your <a href="%2$s">billing address</a>, and <a href="%3$s">edit your password and account details</a>.', 'woocommerce' ),
			$allowed_html
		),
		esc_url( wc_get_endpoint_url( 'orders' ) ),
		esc_url( wc_get_endpoint_url( 'edit-address' ) ),
		esc_url( wc_get_endpoint_url( 'edit-account' ) )
	);
	?>
</p>

<?php
do_action( 'woocommerce_account_dashboard' );
do_action( 'woocommerce_before_my_account' );
do_action( 'woocommerce_after_my_account' );
