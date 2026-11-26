<?php
/**
 * App user login / logout / registration.
 *
 * @package XCIV_Courier
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Account URLs for auth UI.
 *
 * @return array{account:string,login:string,register:string,lost:string,logout:string,home:string}
 */
function xciv_auth_urls(): array {
	$home = home_url( '/' );
	$account = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';
	if ( $account === '' ) {
		$account = wp_login_url( $home );
	}

	$lost = $account;
	if ( function_exists( 'wc_get_endpoint_url' ) ) {
		$lost = wc_get_endpoint_url( 'lost-password', '', $account );
	}

	$logout = function_exists( 'wc_logout_url' ) ? wc_logout_url( $home ) : wp_logout_url( $home );

	return array(
		'account'  => $account,
		'login'    => $account,
		'register' => add_query_arg( 'action', 'register', $account ),
		'lost'     => $lost,
		'logout'   => $logout,
		'home'     => $home,
	);
}

/**
 * Whether customer registration is allowed on the front end.
 *
 * Do not call get_option( 'woocommerce_enable_myaccount_registration' ) here —
 * that option is short-circuited by xciv_auth_force_registration_option() and
 * would recurse until PHP runs out of memory.
 */
function xciv_auth_registration_enabled(): bool {
	/**
	 * Allow themes/plugins to force registration on/off.
	 *
	 * @param bool $enabled Default true for XCIV app users.
	 */
	return (bool) apply_filters( 'xciv_auth_registration_enabled', true );
}

/**
 * Keep WooCommerce my-account registration available for app customers.
 *
 * @param mixed $value Option value.
 * @return string
 */
function xciv_auth_force_registration_option( $value ) {
	unset( $value );
	return xciv_auth_registration_enabled() ? 'yes' : 'no';
}
add_filter( 'pre_option_woocommerce_enable_myaccount_registration', 'xciv_auth_force_registration_option' );

/**
 * Resolve post-login destination (account dashboard by default).
 */
function xciv_auth_resolve_login_redirect( string $redirect, string $requested = '' ): string {
	$urls = xciv_auth_urls();

	if ( $requested !== '' && wp_validate_redirect( $requested, false ) ) {
		return $requested;
	}

	if ( isset( $_GET['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from_query = esc_url_raw( wp_unslash( (string) $_GET['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $from_query !== '' && wp_validate_redirect( $from_query, false ) ) {
			return $from_query;
		}
	}

	if ( $redirect !== '' && wp_validate_redirect( $redirect, false ) ) {
		// Keep checkout / pay redirects from Woo.
		$path = (string) wp_parse_url( $redirect, PHP_URL_PATH );
		if ( $path !== '' && ( str_contains( $path, 'checkout' ) || str_contains( $path, 'order-pay' ) ) ) {
			return $redirect;
		}
	}

	return $urls['account'];
}

/**
 * WooCommerce login redirect.
 *
 * Hook args are untyped — WordPress/Woo may pass null/false under edge cases.
 *
 * @param mixed            $redirect Redirect URL.
 * @param mixed            $user     User.
 */
function xciv_auth_wc_login_redirect( $redirect, $user = null ): string {
	unset( $user );
	return xciv_auth_resolve_login_redirect( is_string( $redirect ) ? $redirect : '' );
}
add_filter( 'woocommerce_login_redirect', 'xciv_auth_wc_login_redirect', 20, 2 );

/**
 * Core wp-login redirect.
 *
 * @param mixed            $redirect  Redirect URL.
 * @param mixed            $requested Requested redirect.
 * @param mixed            $user      User or error.
 */
function xciv_auth_wp_login_redirect( $redirect, $requested = '', $user = null ): string {
	unset( $user );
	return xciv_auth_resolve_login_redirect(
		is_string( $redirect ) ? $redirect : '',
		is_string( $requested ) ? $requested : ''
	);
}
add_filter( 'login_redirect', 'xciv_auth_wp_login_redirect', 20, 3 );

/**
 * After logout, return to the site home (not wp-login).
 *
 * @param mixed $redirect Redirect URL.
 * @return string
 */
function xciv_auth_logout_redirect( $redirect ): string {
	$urls     = xciv_auth_urls();
	$fallback = is_string( $redirect ) ? $redirect : home_url( '/' );
	return $urls['home'] !== '' ? $urls['home'] : $fallback;
}
add_filter( 'woocommerce_logout_default_redirect_url', 'xciv_auth_logout_redirect' );
add_filter(
	'logout_redirect',
	static function ( $redirect ) {
		return xciv_auth_logout_redirect( $redirect );
	},
	20
);

/**
 * Prefer email login UX copy for WooCommerce.
 *
 * @param mixed $label Field label.
 * @return string
 */
function xciv_auth_username_label( $label ): string {
	unset( $label );
	return __( 'Email or username', 'xciv-courier' );
}
add_filter( 'woocommerce_login_form_username_label', 'xciv_auth_username_label' );

/**
 * Body class for the auth screen.
 *
 * @param mixed $classes Body classes.
 * @return array<int, string>
 */
function xciv_auth_body_class( $classes ): array {
	$classes = is_array( $classes ) ? $classes : array();
	if ( function_exists( 'is_account_page' ) && is_account_page() && ! is_user_logged_in() ) {
		$classes[] = 'xciv-auth-screen';
	}
	return $classes;
}
add_filter( 'body_class', 'xciv_auth_body_class' );

/**
 * Block wp-admin for customers / subscribers (app users stay on the front end).
 */
function xciv_auth_block_admin_for_customers(): void {
	if ( ! is_admin() || wp_doing_ajax() || current_user_can( 'edit_posts' ) || current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		return;
	}

	$user = wp_get_current_user();
	$roles = (array) $user->roles;
	$app_roles = array( 'customer', 'subscriber' );
	if ( array_intersect( $app_roles, $roles ) === array() ) {
		return;
	}

	$urls = xciv_auth_urls();
	wp_safe_redirect( $urls['account'] );
	exit;
}
add_action( 'admin_init', 'xciv_auth_block_admin_for_customers' );

/**
 * Hide admin bar for app customers.
 *
 * @param mixed $show Whether to show the bar.
 * @return bool
 */
function xciv_auth_hide_admin_bar( $show ): bool {
	$show = (bool) $show;
	if ( ! is_user_logged_in() ) {
		return $show;
	}
	if ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_woocommerce' ) ) {
		return $show;
	}
	$user  = wp_get_current_user();
	$roles = (array) $user->roles;
	if ( array_intersect( array( 'customer', 'subscriber' ), $roles ) ) {
		return false;
	}
	return $show;
}
add_filter( 'show_admin_bar', 'xciv_auth_hide_admin_bar' );
