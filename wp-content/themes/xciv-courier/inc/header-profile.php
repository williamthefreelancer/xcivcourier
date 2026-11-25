<?php
/**
 * Header profile icon + dropdown.
 *
 * @package XCIV_Courier
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Account URL helpers with WooCommerce when available.
 *
 * @return array{home:string,account:string,orders:string,edit:string,logout:string}
 */
function xciv_header_profile_urls(): array {
	$home = home_url( '/' );
	$front_id = (int) get_option( 'page_on_front' );
	if ( get_option( 'show_on_front' ) === 'page' && $front_id > 0 ) {
		$front = get_permalink( $front_id );
		if ( is_string( $front ) && $front !== '' ) {
			$home = $front;
		}
	}

	$account = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : wp_login_url();
	if ( $account === '' ) {
		$account = wp_login_url();
	}

	$orders = $account;
	$edit   = $account;
	if ( function_exists( 'wc_get_endpoint_url' ) ) {
		$orders = wc_get_endpoint_url( 'orders', '', $account );
		$edit   = wc_get_endpoint_url( 'edit-account', '', $account );
	}

	$logout = function_exists( 'wc_logout_url' ) ? wc_logout_url( $account ) : wp_logout_url( $home );

	return array(
		'home'    => $home,
		'account' => $account,
		'orders'  => $orders,
		'edit'    => $edit,
		'logout'  => $logout,
	);
}

/**
 * Build 1–2 letter initials for the profile mark.
 */
function xciv_header_profile_initials( string $display_name, string $login = '', string $email = '' ): string {
	$source = trim( $display_name );
	if ( $source === '' ) {
		$source = trim( $login );
	}
	if ( $source === '' && $email !== '' ) {
		$source = (string) strstr( $email, '@', true );
	}
	$source = trim( (string) $source );
	if ( $source === '' ) {
		return '';
	}

	$parts = preg_split( '/[\s._\-]+/u', $source, -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $parts ) || $parts === array() ) {
		$parts = array( $source );
	}

	$letters = '';
	foreach ( array_slice( $parts, 0, 2 ) as $part ) {
		if ( function_exists( 'mb_substr' ) ) {
			$letters .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
		} else {
			$letters .= strtoupper( substr( $part, 0, 1 ) );
		}
	}

	return $letters;
}

/**
 * First name (or short handle) for the account chip label.
 */
function xciv_header_profile_first_name( string $display_name, string $login = '' ): string {
	$source = trim( $display_name );
	if ( $source === '' ) {
		$source = trim( $login );
	}
	if ( $source === '' ) {
		return '';
	}

	$parts = preg_split( '/[\s]+/u', $source, -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $parts ) || $parts === array() ) {
		return $source;
	}

	return (string) $parts[0];
}

/**
 * Inline SVG icon for menu rows.
 *
 * @param string $name Icon key: home|sits|account|logout|signin|chevron.
 */
function xciv_header_profile_icon( string $name ): string {
	$icons = array(
		'home'    => '<path d="M4.5 10.8 12 4.5l7.5 6.3V19a1.5 1.5 0 0 1-1.5 1.5h-3.75v-5.25h-4.5V20.5H6A1.5 1.5 0 0 1 4.5 19v-8.2Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" fill="none"/>',
		'sits'    => '<rect x="4.5" y="5.5" width="15" height="13" rx="2" stroke="currentColor" stroke-width="1.7" fill="none"/><path d="M8 9.5h8M8 13h5.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>',
		'account' => '<circle cx="12" cy="8.2" r="3.1" stroke="currentColor" stroke-width="1.7" fill="none"/><path d="M5.4 18.6c1-2.9 3.4-4.5 6.6-4.5s5.6 1.6 6.6 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" fill="none"/>',
		'logout'  => '<path d="M10 5.5H7.5A2 2 0 0 0 5.5 7.5v9A2 2 0 0 0 7.5 18.5H10M13.5 15.5 17.5 12 13.5 8.5M17.2 12H10" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
		'signin'  => '<path d="M14 5.5h2.5A2 2 0 0 1 18.5 7.5v9a2 2 0 0 1-2 2H14M10.5 15.5 14.5 12 10.5 8.5M14.2 12H6.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
	);

	$path = $icons[ $name ] ?? '';
	if ( $path === '' ) {
		return '';
	}

	return '<svg class="xciv-header-profile__item-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

/**
 * Render the header profile control.
 */
function xciv_header_profile_render(): string {
	$urls      = xciv_header_profile_urls();
	$logged_in = is_user_logged_in();
	$user      = $logged_in ? wp_get_current_user() : null;
	$label     = $logged_in
		? __( 'Account menu', 'xciv-courier' )
		: __( 'Sign in', 'xciv-courier' );

	$display_name = '';
	$first_name   = '';
	$email        = '';
	$initials     = '';
	if ( $user instanceof WP_User ) {
		$display_name = (string) ( $user->display_name ?: $user->user_login );
		$first_name   = xciv_header_profile_first_name( $display_name, (string) $user->user_login );
		$email        = (string) $user->user_email;
		$initials     = xciv_header_profile_initials( $display_name, (string) $user->user_login, $email );
	}

	$trigger_label = $logged_in
		? ( $first_name !== '' ? $first_name : __( 'Account', 'xciv-courier' ) )
		: __( 'Sign in', 'xciv-courier' );

	ob_start();

	// Guests: direct Sign in link only — no account menu / endpoints.
	if ( ! $logged_in ) {
		?>
		<div class="xciv-header-profile xciv-header-profile--guest">
			<a
				class="xciv-header-profile__trigger"
				href="<?php echo esc_url( $urls['account'] ); ?>"
				aria-label="<?php echo esc_attr( $label ); ?>"
			>
				<span class="xciv-header-profile__avatar" aria-hidden="true">
					<svg class="xciv-header-profile__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" focusable="false">
						<circle cx="12" cy="8.15" r="3.35" fill="currentColor"/>
						<path
							fill="currentColor"
							d="M5.2 18.7c1.05-3.2 3.7-5.05 6.8-5.05s5.75 1.85 6.8 5.05c.2.6-.25 1.25-.9 1.25H6.1c-.65 0-1.1-.65-.9-1.25Z"
						/>
					</svg>
				</span>
				<span class="xciv-header-profile__label"><?php echo esc_html( $trigger_label ); ?></span>
			</a>
		</div>
		<?php
		return (string) ob_get_clean();
	}
	?>
	<div class="xciv-header-profile" data-xciv-header-profile>
		<button
			type="button"
			class="xciv-header-profile__trigger xciv-header-profile__trigger--user"
			data-xciv-header-profile-trigger
			aria-expanded="false"
			aria-haspopup="true"
			aria-controls="xciv-header-profile-menu"
			aria-label="<?php echo esc_attr( $label ); ?>"
		>
			<span class="xciv-header-profile__avatar" aria-hidden="true">
				<?php if ( $initials !== '' ) : ?>
					<span class="xciv-header-profile__initials"><?php echo esc_html( $initials ); ?></span>
				<?php else : ?>
					<svg class="xciv-header-profile__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" focusable="false">
						<circle cx="12" cy="8.15" r="3.35" fill="currentColor"/>
						<path
							fill="currentColor"
							d="M5.2 18.7c1.05-3.2 3.7-5.05 6.8-5.05s5.75 1.85 6.8 5.05c.2.6-.25 1.25-.9 1.25H6.1c-.65 0-1.1-.65-.9-1.25Z"
						/>
					</svg>
				<?php endif; ?>
			</span>
			<span class="xciv-header-profile__label"><?php echo esc_html( $trigger_label ); ?></span>
			<svg class="xciv-header-profile__chevron" width="12" height="12" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
				<path d="M6.5 9.25 12 14.5l5.5-5.25" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
			</svg>
		</button>

		<div
			id="xciv-header-profile-menu"
			class="xciv-header-profile__menu"
			data-xciv-header-profile-menu
			hidden
			role="menu"
			aria-label="<?php echo esc_attr__( 'Account', 'xciv-courier' ); ?>"
		>
			<div class="xciv-header-profile__meta" role="presentation">
				<span class="xciv-header-profile__meta-avatar" aria-hidden="true">
					<?php if ( $initials !== '' ) : ?>
						<span class="xciv-header-profile__initials"><?php echo esc_html( $initials ); ?></span>
					<?php else : ?>
						<svg class="xciv-header-profile__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" focusable="false">
							<circle cx="12" cy="8.15" r="3.35" fill="currentColor"/>
							<path
								fill="currentColor"
								d="M5.2 18.7c1.05-3.2 3.7-5.05 6.8-5.05s5.75 1.85 6.8 5.05c.2.6-.25 1.25-.9 1.25H6.1c-.65 0-1.1-.65-.9-1.25Z"
							/>
						</svg>
					<?php endif; ?>
				</span>
				<span class="xciv-header-profile__meta-text">
					<span class="xciv-header-profile__name"><?php echo esc_html( $display_name ); ?></span>
					<?php if ( $email !== '' ) : ?>
						<span class="xciv-header-profile__email"><?php echo esc_html( $email ); ?></span>
					<?php endif; ?>
				</span>
			</div>
			<a class="xciv-header-profile__item" role="menuitem" href="<?php echo esc_url( $urls['home'] ); ?>">
				<?php echo xciv_header_profile_icon( 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( 'Home', 'xciv-courier' ); ?></span>
			</a>
			<a class="xciv-header-profile__item" role="menuitem" href="<?php echo esc_url( $urls['orders'] ); ?>">
				<?php echo xciv_header_profile_icon( 'sits' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( 'Sits', 'xciv-courier' ); ?></span>
			</a>
			<a class="xciv-header-profile__item" role="menuitem" href="<?php echo esc_url( $urls['account'] ); ?>">
				<?php echo xciv_header_profile_icon( 'account' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( 'Account', 'xciv-courier' ); ?></span>
			</a>
			<div class="xciv-header-profile__divider" role="separator"></div>
			<a class="xciv-header-profile__item xciv-header-profile__item--danger" role="menuitem" href="<?php echo esc_url( $urls['logout'] ); ?>">
				<?php echo xciv_header_profile_icon( 'logout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( 'Log out', 'xciv-courier' ); ?></span>
			</a>
		</div>
	</div>
	<?php

	return (string) ob_get_clean();
}
add_shortcode( 'xciv_header_profile', 'xciv_header_profile_render' );

/**
 * Theme brand asset URLs (logo only in header).
 *
 * @return array{logo:string,home:string}
 */
function xciv_header_brand_assets(): array {
	$urls = xciv_header_profile_urls();

	return array(
		'logo' => XCIV_COURIER_URI . '/assets/img/xciv-logo.svg',
		'home' => $urls['home'],
	);
}

/**
 * Header logo (site icon mark removed — favicon still uses site-icon.jpg).
 */
function xciv_header_brand_render(): string {
	$assets = xciv_header_brand_assets();

	ob_start();
	?>
	<a class="xciv-header-brand" href="<?php echo esc_url( $assets['home'] ); ?>" aria-label="<?php esc_attr_e( 'XCIV Courier home', 'xciv-courier' ); ?>">
		<img
			class="xciv-header-brand__logo"
			src="<?php echo esc_url( $assets['logo'] ); ?>"
			width="120"
			height="131"
			alt="<?php esc_attr_e( 'SUPR XCIV Platform Holdings', 'xciv-courier' ); ?>"
			decoding="async"
		>
	</a>
	<?php

	return (string) ob_get_clean();
}
add_shortcode( 'xciv_header_brand', 'xciv_header_brand_render' );

/**
 * Prefer theme site icon over Customizer / empty default.
 *
 * @param string          $url     Current icon URL.
 * @param int             $size    Requested size.
 * @param int|string|null $blog_id Blog id (unused).
 */
function xciv_courier_site_icon_url( string $url, int $size = 512, $blog_id = 0 ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	unset( $blog_id );
	$path = XCIV_COURIER_DIR . '/assets/img/site-icon.jpg';
	if ( ! is_readable( $path ) ) {
		return $url;
	}
	return XCIV_COURIER_URI . '/assets/img/site-icon.jpg';
}
add_filter( 'get_site_icon_url', 'xciv_courier_site_icon_url', 10, 3 );

/**
 * Ensure favicon / apple-touch links always point at the theme mark.
 */
function xciv_courier_site_icon_head(): void {
	$icon = XCIV_COURIER_URI . '/assets/img/site-icon.jpg';
	$ver  = XCIV_COURIER_VERSION;
	printf(
		'<link rel="icon" href="%1$s?v=%2$s" sizes="32x32" />' . "\n",
		esc_url( $icon ),
		esc_attr( $ver )
	);
	printf(
		'<link rel="icon" href="%1$s?v=%2$s" sizes="192x192" />' . "\n",
		esc_url( $icon ),
		esc_attr( $ver )
	);
	printf(
		'<link rel="apple-touch-icon" href="%1$s?v=%2$s" />' . "\n",
		esc_url( $icon ),
		esc_attr( $ver )
	);
}
add_action( 'wp_head', 'xciv_courier_site_icon_head', 2 );
add_action( 'admin_head', 'xciv_courier_site_icon_head', 2 );
