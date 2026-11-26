<?php
/**
 * App login / register — My Account (guests).
 *
 * @package XCIV_Courier
 * @version 0.9.6
 */

defined( 'ABSPATH' ) || exit;

$urls             = function_exists( 'xciv_auth_urls' ) ? xciv_auth_urls() : array();
$registration_on  = function_exists( 'xciv_auth_registration_enabled' ) ? xciv_auth_registration_enabled() : ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) );
$show_register    = $registration_on && isset( $_GET['action'] ) && 'register' === sanitize_key( wp_unslash( (string) $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$logo             = defined( 'XCIV_COURIER_URI' ) ? XCIV_COURIER_URI . '/assets/img/xciv-logo.svg' : '';

do_action( 'woocommerce_before_customer_login_form' );
?>

<div class="xciv-auth" data-xciv-auth="<?php echo $show_register ? 'register' : 'login'; ?>">
	<div class="xciv-auth__card">
		<header class="xciv-auth__header">
			<?php if ( $logo !== '' ) : ?>
				<img class="xciv-auth__logo" src="<?php echo esc_url( $logo ); ?>" width="72" height="78" alt="<?php esc_attr_e( 'XCIV Courier', 'xciv-courier' ); ?>" decoding="async">
			<?php endif; ?>
			<h1 class="xciv-auth__title">
				<?php
				echo $show_register
					? esc_html__( 'Create account', 'xciv-courier' )
					: esc_html__( 'Sign in', 'xciv-courier' );
				?>
			</h1>
			<p class="xciv-auth__lead">
				<?php
				echo $show_register
					? esc_html__( 'Book sits, track live ETA, and manage pickups from your XCIV account.', 'xciv-courier' )
					: esc_html__( 'Welcome back. Sign in to view sits, track riders, and manage pickups.', 'xciv-courier' );
				?>
			</p>
		</header>

		<?php if ( $registration_on ) : ?>
			<nav class="xciv-auth__tabs" aria-label="<?php esc_attr_e( 'Account access', 'xciv-courier' ); ?>">
				<a
					class="xciv-auth__tab<?php echo ! $show_register ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( $urls['login'] ?? wc_get_page_permalink( 'myaccount' ) ); ?>"
				><?php esc_html_e( 'Sign in', 'xciv-courier' ); ?></a>
				<a
					class="xciv-auth__tab<?php echo $show_register ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( $urls['register'] ?? add_query_arg( 'action', 'register', wc_get_page_permalink( 'myaccount' ) ) ); ?>"
				><?php esc_html_e( 'Create account', 'xciv-courier' ); ?></a>
			</nav>
		<?php endif; ?>

		<?php if ( ! $show_register ) : ?>
			<form class="woocommerce-form woocommerce-form-login login xciv-auth__form" method="post" novalidate>
				<?php do_action( 'woocommerce_login_form_start' ); ?>

				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide xciv-auth__row">
					<label for="username"><?php esc_html_e( 'Email or username', 'xciv-courier' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
					<input
						type="text"
						class="woocommerce-Input woocommerce-Input--text input-text"
						name="username"
						id="username"
						autocomplete="username"
						required
						value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( (string) $_POST['username'] ) ) : ''; ?>"
					/>
				</p>

				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide xciv-auth__row">
					<label for="password"><?php esc_html_e( 'Password', 'xciv-courier' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
					<input
						class="woocommerce-Input woocommerce-Input--text input-text"
						type="password"
						name="password"
						id="password"
						autocomplete="current-password"
						required
					/>
				</p>

				<?php do_action( 'woocommerce_login_form' ); ?>

				<p class="form-row xciv-auth__row xciv-auth__row--inline">
					<label class="woocommerce-form__label woocommerce-form__label-for-checkbox woocommerce-form-login__rememberme xciv-auth__check">
						<input class="woocommerce-form__input woocommerce-form__input-checkbox" name="rememberme" type="checkbox" id="rememberme" value="forever" />
						<span><?php esc_html_e( 'Keep me signed in', 'xciv-courier' ); ?></span>
					</label>
					<a class="xciv-auth__link" href="<?php echo esc_url( $urls['lost'] ?? wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgot password?', 'xciv-courier' ); ?></a>
				</p>

				<p class="form-row xciv-auth__actions">
					<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
					<button type="submit" class="woocommerce-button button woocommerce-form-login__submit xciv-auth__submit" name="login" value="<?php esc_attr_e( 'Sign in', 'xciv-courier' ); ?>">
						<?php esc_html_e( 'Sign in', 'xciv-courier' ); ?>
					</button>
				</p>

				<?php do_action( 'woocommerce_login_form_end' ); ?>
			</form>
		<?php else : ?>
			<form method="post" class="woocommerce-form woocommerce-form-register register xciv-auth__form" <?php do_action( 'woocommerce_register_form_tag' ); ?> novalidate>
				<?php do_action( 'woocommerce_register_form_start' ); ?>

				<?php if ( 'no' === get_option( 'woocommerce_registration_generate_username' ) ) : ?>
					<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide xciv-auth__row">
						<label for="reg_username"><?php esc_html_e( 'Username', 'xciv-courier' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
						<input
							type="text"
							class="woocommerce-Input woocommerce-Input--text input-text"
							name="username"
							id="reg_username"
							autocomplete="username"
							required
							value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( (string) $_POST['username'] ) ) : ''; ?>"
						/>
					</p>
				<?php endif; ?>

				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide xciv-auth__row">
					<label for="reg_email"><?php esc_html_e( 'Email address', 'xciv-courier' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
					<input
						type="email"
						class="woocommerce-Input woocommerce-Input--text input-text"
						name="email"
						id="reg_email"
						autocomplete="email"
						required
						value="<?php echo ( ! empty( $_POST['email'] ) ) ? esc_attr( wp_unslash( (string) $_POST['email'] ) ) : ''; ?>"
					/>
				</p>

				<?php if ( 'no' === get_option( 'woocommerce_registration_generate_password' ) ) : ?>
					<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide xciv-auth__row">
						<label for="reg_password"><?php esc_html_e( 'Password', 'xciv-courier' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
						<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password" id="reg_password" autocomplete="new-password" required />
					</p>
				<?php else : ?>
					<p class="xciv-auth__note"><?php esc_html_e( 'A link to set your password will be sent to your email.', 'xciv-courier' ); ?></p>
				<?php endif; ?>

				<?php do_action( 'woocommerce_register_form' ); ?>

				<p class="woocommerce-form-row form-row xciv-auth__actions">
					<?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
					<button type="submit" class="woocommerce-Button woocommerce-button button woocommerce-form-register__submit xciv-auth__submit" name="register" value="<?php esc_attr_e( 'Create account', 'xciv-courier' ); ?>">
						<?php esc_html_e( 'Create account', 'xciv-courier' ); ?>
					</button>
				</p>

				<?php do_action( 'woocommerce_register_form_end' ); ?>
			</form>
		<?php endif; ?>
	</div>
</div>

<?php
do_action( 'woocommerce_after_customer_login_form' );
