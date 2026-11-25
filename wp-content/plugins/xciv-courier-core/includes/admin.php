<?php
/**
 * XCIV Courier Core admin dashboard.
 *
 * @package XCIV_Courier_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register admin menu.
 */
function xciv_core_admin_menu(): void {
	add_menu_page(
		__( 'XCIV Courier', 'xciv-courier-core' ),
		__( 'XCIV Courier', 'xciv-courier-core' ),
		'manage_options',
		'xciv-courier',
		'xciv_core_render_dashboard',
		'dashicons-location-alt',
		56
	);

	add_submenu_page(
		'xciv-courier',
		__( 'Dashboard', 'xciv-courier-core' ),
		__( 'Dashboard', 'xciv-courier-core' ),
		'manage_options',
		'xciv-courier',
		'xciv_core_render_dashboard'
	);

	add_submenu_page(
		'xciv-courier',
		__( 'Lead Rider', 'xciv-courier-core' ),
		__( 'Lead Rider', 'xciv-courier-core' ),
		'manage_options',
		'xciv-courier-rider',
		'xciv_core_render_rider_page'
	);

	add_submenu_page(
		'xciv-courier',
		__( 'Map', 'xciv-courier-core' ),
		__( 'Map', 'xciv-courier-core' ),
		'manage_options',
		'xciv-courier-map',
		'xciv_core_render_map_page'
	);
}
add_action( 'admin_menu', 'xciv_core_admin_menu' );

/**
 * Admin assets on plugin screens.
 *
 * @param string $hook Hook suffix.
 */
function xciv_core_admin_assets( string $hook ): void {
	if ( strpos( $hook, 'xciv-courier' ) === false ) {
		return;
	}

	wp_enqueue_style(
		'xciv-core-admin',
		XCIV_CORE_URI . 'assets/css/admin.css',
		array(),
		XCIV_CORE_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'xciv_core_admin_assets' );

/**
 * Lightweight ops stats for the dashboard.
 *
 * @return array{open_bookings:int,processing:int,completed_week:int,sms_ready:bool,map_provider:string}
 */
function xciv_core_dashboard_stats(): array {
	$open = 0;
	$proc = 0;
	$done = 0;

	if ( function_exists( 'wc_get_orders' ) ) {
		$open = (int) count(
			wc_get_orders(
				array(
					'status' => array( 'wc-on-hold', 'wc-pending' ),
					'limit'  => 50,
					'return' => 'ids',
				)
			)
		);
		$proc = (int) count(
			wc_get_orders(
				array(
					'status' => array( 'wc-processing' ),
					'limit'  => 50,
					'return' => 'ids',
				)
			)
		);
		$done = (int) count(
			wc_get_orders(
				array(
					'status'       => array( 'wc-completed' ),
					'limit'        => 100,
					'return'       => 'ids',
					'date_created' => '>' . gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
				)
			)
		);
	}

	$settings = xciv_core_settings();
	$token    = (string) ( $settings['map']['mapbox_token'] ?? '' );
	$provider = (string) ( $settings['map']['provider'] ?? 'mapbox' );
	if ( $provider === 'mapbox' && $token === '' ) {
		$provider = 'custom';
	}

	return array(
		'open_bookings'  => $open,
		'processing'     => $proc,
		'completed_week' => $done,
		'sms_ready'      => function_exists( 'xciv_sms_is_configured' ) ? xciv_sms_is_configured() : false,
		'map_provider'   => $provider,
	);
}

/**
 * Shell chrome for admin pages.
 *
 * @param string               $title Page title.
 * @param string               $active Active nav key.
 * @param callable             $body  Content callback.
 */
function xciv_core_admin_shell( string $title, string $active, callable $body ): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$nav = array(
		'dashboard' => array(
			'label' => __( 'Dashboard', 'xciv-courier-core' ),
			'url'   => admin_url( 'admin.php?page=xciv-courier' ),
		),
		'rider'     => array(
			'label' => __( 'Lead Rider', 'xciv-courier-core' ),
			'url'   => admin_url( 'admin.php?page=xciv-courier-rider' ),
		),
		'map'       => array(
			'label' => __( 'Map', 'xciv-courier-core' ),
			'url'   => admin_url( 'admin.php?page=xciv-courier-map' ),
		),
	);
	?>
	<div class="wrap xciv-admin">
		<header class="xciv-admin__hero">
			<div>
				<p class="xciv-admin__eyebrow"><?php esc_html_e( 'XCIV Courier Core', 'xciv-courier-core' ); ?></p>
				<h1><?php echo esc_html( $title ); ?></h1>
			</div>
			<nav class="xciv-admin__nav" aria-label="<?php esc_attr_e( 'XCIV Courier', 'xciv-courier-core' ); ?>">
				<?php foreach ( $nav as $key => $item ) : ?>
					<a
						class="xciv-admin__nav-link<?php echo $key === $active ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( $item['url'] ); ?>"
					><?php echo esc_html( $item['label'] ); ?></a>
				<?php endforeach; ?>
			</nav>
		</header>
		<?php $body(); ?>
	</div>
	<?php
}

/**
 * Dashboard page.
 */
function xciv_core_render_dashboard(): void {
	xciv_core_admin_shell(
		__( 'Ops dashboard', 'xciv-courier-core' ),
		'dashboard',
		static function (): void {
			$stats    = xciv_core_dashboard_stats();
			$settings = xciv_core_settings();
			$modes    = $settings['lead_rider']['modes'] ?? array();
			?>
			<section class="xciv-admin__stats">
				<article class="xciv-admin__stat">
					<span class="xciv-admin__stat-label"><?php esc_html_e( 'Open', 'xciv-courier-core' ); ?></span>
					<strong class="xciv-admin__stat-value"><?php echo esc_html( (string) $stats['open_bookings'] ); ?></strong>
					<span class="xciv-admin__stat-meta"><?php esc_html_e( 'Pending / on-hold sits', 'xciv-courier-core' ); ?></span>
				</article>
				<article class="xciv-admin__stat">
					<span class="xciv-admin__stat-label"><?php esc_html_e( 'Live', 'xciv-courier-core' ); ?></span>
					<strong class="xciv-admin__stat-value"><?php echo esc_html( (string) $stats['processing'] ); ?></strong>
					<span class="xciv-admin__stat-meta"><?php esc_html_e( 'Processing bookings', 'xciv-courier-core' ); ?></span>
				</article>
				<article class="xciv-admin__stat">
					<span class="xciv-admin__stat-label"><?php esc_html_e( 'Week', 'xciv-courier-core' ); ?></span>
					<strong class="xciv-admin__stat-value"><?php echo esc_html( (string) $stats['completed_week'] ); ?></strong>
					<span class="xciv-admin__stat-meta"><?php esc_html_e( 'Completed last 7 days', 'xciv-courier-core' ); ?></span>
				</article>
				<article class="xciv-admin__stat">
					<span class="xciv-admin__stat-label"><?php esc_html_e( 'Map', 'xciv-courier-core' ); ?></span>
					<strong class="xciv-admin__stat-value"><?php echo esc_html( strtoupper( (string) $stats['map_provider'] ) ); ?></strong>
					<span class="xciv-admin__stat-meta">
						<?php
						echo $stats['sms_ready']
							? esc_html__( 'SMS ready', 'xciv-courier-core' )
							: esc_html__( 'SMS needs setup', 'xciv-courier-core' );
						?>
					</span>
				</article>
			</section>

			<section class="xciv-admin__grid">
				<article class="xciv-admin__card">
					<h2><?php esc_html_e( 'Lead rider modes', 'xciv-courier-core' ); ?></h2>
					<p><?php esc_html_e( 'Header chips for car, e-tricycle, and rideshare (Uber as passenger — no personal car).', 'xciv-courier-core' ); ?></p>
					<ul class="xciv-admin__pills">
						<li class="<?php echo ! empty( $modes['car'] ) ? 'is-on' : 'is-off'; ?>"><?php esc_html_e( 'Car', 'xciv-courier-core' ); ?></li>
						<li class="<?php echo ! empty( $modes['tricycle'] ) ? 'is-on' : 'is-off'; ?>"><?php esc_html_e( 'E-Tricycle', 'xciv-courier-core' ); ?></li>
						<li class="<?php echo ! empty( $modes['rideshare'] ) ? 'is-on' : 'is-off'; ?>"><?php esc_html_e( 'Rideshare', 'xciv-courier-core' ); ?></li>
					</ul>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=xciv-courier-rider' ) ); ?>">
							<?php esc_html_e( 'Configure rider limits', 'xciv-courier-core' ); ?>
						</a>
					</p>
				</article>

				<article class="xciv-admin__card">
					<h2><?php esc_html_e( 'Lead rider map', 'xciv-courier-core' ); ?></h2>
					<p><?php esc_html_e( 'Mapbox when a token is set; otherwise a custom Leaflet / OSM map. Shortcode:', 'xciv-courier-core' ); ?> <code>[xciv_lead_rider_map]</code></p>
					<p>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=xciv-courier-map' ) ); ?>">
							<?php esc_html_e( 'Map settings', 'xciv-courier-core' ); ?>
						</a>
						<?php if ( function_exists( 'wc_get_page_permalink' ) ) : ?>
							<a class="button" href="<?php echo esc_url( (string) wc_get_page_permalink( 'myaccount' ) ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Open My Account', 'xciv-courier-core' ); ?>
							</a>
						<?php endif; ?>
					</p>
				</article>

				<article class="xciv-admin__card">
					<h2><?php esc_html_e( 'Service hubs', 'xciv-courier-core' ); ?></h2>
					<ul class="xciv-admin__hubs">
						<?php foreach ( (array) $settings['hubs'] as $hub ) : ?>
							<li>
								<strong><?php echo esc_html( (string) ( $hub['label'] ?? '' ) ); ?></strong>
								<span><?php echo esc_html( (string) ( $hub['zip'] ?? '' ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</article>

				<article class="xciv-admin__card">
					<h2><?php esc_html_e( 'Front header', 'xciv-courier-core' ); ?></h2>
					<p><?php esc_html_e( 'Logo only (site icon mark removed). Lead Delivery reveals Car / Trike / Uber chips driven by these settings.', 'xciv-courier-core' ); ?></p>
				</article>
			</section>
			<?php
		}
	);
}

/**
 * Lead rider settings page.
 */
function xciv_core_render_rider_page(): void {
	xciv_core_admin_shell(
		__( 'Lead rider', 'xciv-courier-core' ),
		'rider',
		static function (): void {
			$settings = xciv_core_settings();
			$lr       = $settings['lead_rider'];
			?>
			<form method="post" action="options.php" class="xciv-admin__form">
				<?php settings_fields( 'xciv_core' ); ?>
				<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][provider]" value="<?php echo esc_attr( (string) $settings['map']['provider'] ); ?>">
				<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][mapbox_token]" value="<?php echo esc_attr( (string) $settings['map']['mapbox_token'] ); ?>">
				<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][mapbox_style]" value="<?php echo esc_attr( (string) $settings['map']['mapbox_style'] ); ?>">
				<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][center_lat]" value="<?php echo esc_attr( (string) $settings['map']['center_lat'] ); ?>">
				<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][center_lng]" value="<?php echo esc_attr( (string) $settings['map']['center_lng'] ); ?>">
				<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][zoom]" value="<?php echo esc_attr( (string) $settings['map']['zoom'] ); ?>">
				<?php if ( ! empty( $settings['map']['show_hubs'] ) ) : ?>
					<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][show_hubs]" value="1">
				<?php endif; ?>
				<?php if ( ! empty( $settings['map']['show_venues'] ) ) : ?>
					<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][show_venues]" value="1">
				<?php endif; ?>
				<?php if ( ! empty( $settings['map']['show_radius'] ) ) : ?>
					<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][show_radius]" value="1">
				<?php endif; ?>
				<?php foreach ( (array) $settings['hubs'] as $i => $hub ) : ?>
					<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[hubs][<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( (string) $hub['id'] ); ?>">
					<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[hubs][<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( (string) $hub['label'] ); ?>">
					<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[hubs][<?php echo (int) $i; ?>][zip]" value="<?php echo esc_attr( (string) $hub['zip'] ); ?>">
					<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[hubs][<?php echo (int) $i; ?>][lat]" value="<?php echo esc_attr( (string) $hub['lat'] ); ?>">
					<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[hubs][<?php echo (int) $i; ?>][lng]" value="<?php echo esc_attr( (string) $hub['lng'] ); ?>">
				<?php endforeach; ?>

				<section class="xciv-admin__card">
					<h2><?php esc_html_e( 'Enabled modes', 'xciv-courier-core' ); ?></h2>
					<label class="xciv-admin__check"><input type="checkbox" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][modes][car]" value="1" <?php checked( ! empty( $lr['modes']['car'] ) ); ?>> <?php esc_html_e( 'Car (self-drive)', 'xciv-courier-core' ); ?></label>
					<label class="xciv-admin__check"><input type="checkbox" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][modes][tricycle]" value="1" <?php checked( ! empty( $lr['modes']['tricycle'] ) ); ?>> <?php esc_html_e( 'E-Tricycle', 'xciv-courier-core' ); ?></label>
					<label class="xciv-admin__check"><input type="checkbox" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][modes][rideshare]" value="1" <?php checked( ! empty( $lr['modes']['rideshare'] ) ); ?>> <?php esc_html_e( 'Rideshare / Uber (passenger, no personal car)', 'xciv-courier-core' ); ?></label>
					<p>
						<label for="xciv-default-mode"><?php esc_html_e( 'Default mode', 'xciv-courier-core' ); ?></label><br>
						<select id="xciv-default-mode" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][default_mode]">
							<option value="car" <?php selected( (string) $lr['default_mode'], 'car' ); ?>><?php esc_html_e( 'Car', 'xciv-courier-core' ); ?></option>
							<option value="tricycle" <?php selected( (string) $lr['default_mode'], 'tricycle' ); ?>><?php esc_html_e( 'E-Tricycle', 'xciv-courier-core' ); ?></option>
							<option value="rideshare" <?php selected( (string) $lr['default_mode'], 'rideshare' ); ?>><?php esc_html_e( 'Rideshare', 'xciv-courier-core' ); ?></option>
						</select>
					</p>
				</section>

				<section class="xciv-admin__grid">
					<article class="xciv-admin__card">
						<h2><?php esc_html_e( 'E-Tricycle limits', 'xciv-courier-core' ); ?></h2>
						<p><label><?php esc_html_e( 'Max cargo (kg)', 'xciv-courier-core' ); ?><br>
							<input type="number" step="0.1" min="1" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][tricycle_max_kg]" value="<?php echo esc_attr( (string) $lr['tricycle_max_kg'] ); ?>">
						</label></p>
						<p><label><?php esc_html_e( 'Max volume (liters)', 'xciv-courier-core' ); ?><br>
							<input type="number" step="1" min="1" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][tricycle_max_liters]" value="<?php echo esc_attr( (string) $lr['tricycle_max_liters'] ); ?>">
						</label></p>
						<p><label><?php esc_html_e( 'Max range (miles)', 'xciv-courier-core' ); ?><br>
							<input type="number" step="0.1" min="1" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][tricycle_max_miles]" value="<?php echo esc_attr( (string) $lr['tricycle_max_miles'] ); ?>">
						</label></p>
						<label class="xciv-admin__check"><input type="checkbox" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][tricycle_helmet]" value="1" <?php checked( ! empty( $lr['tricycle_helmet'] ) ); ?>> <?php esc_html_e( 'Helmet required', 'xciv-courier-core' ); ?></label>
						<label class="xciv-admin__check"><input type="checkbox" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][tricycle_rain_pause]" value="1" <?php checked( ! empty( $lr['tricycle_rain_pause'] ) ); ?>> <?php esc_html_e( 'Pause outdoor sits in heavy rain', 'xciv-courier-core' ); ?></label>
						<p><label><?php esc_html_e( 'Rider note', 'xciv-courier-core' ); ?><br>
							<textarea rows="3" class="large-text" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][notes_tricycle]"><?php echo esc_textarea( (string) $lr['notes_tricycle'] ); ?></textarea>
						</label></p>
					</article>

					<article class="xciv-admin__card">
						<h2><?php esc_html_e( 'Rideshare / Uber limits', 'xciv-courier-core' ); ?></h2>
						<p><?php esc_html_e( 'For lead riders who take Uber/Lyft instead of driving.', 'xciv-courier-core' ); ?></p>
						<p><label><?php esc_html_e( 'Max parcel L × W × H (cm)', 'xciv-courier-core' ); ?><br>
							<input type="number" step="1" min="1" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][rideshare_max_l]" value="<?php echo esc_attr( (string) $lr['rideshare_max_l'] ); ?>" style="width:5rem">
							×
							<input type="number" step="1" min="1" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][rideshare_max_w]" value="<?php echo esc_attr( (string) $lr['rideshare_max_w'] ); ?>" style="width:5rem">
							×
							<input type="number" step="1" min="1" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][rideshare_max_h]" value="<?php echo esc_attr( (string) $lr['rideshare_max_h'] ); ?>" style="width:5rem">
						</label></p>
						<p><label><?php esc_html_e( 'Surge buffer (minutes)', 'xciv-courier-core' ); ?><br>
							<input type="number" min="0" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][rideshare_surge_min]" value="<?php echo esc_attr( (string) $lr['rideshare_surge_min'] ); ?>">
						</label></p>
						<label class="xciv-admin__check"><input type="checkbox" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][rideshare_cabin_only]" value="1" <?php checked( ! empty( $lr['rideshare_cabin_only'] ) ); ?>> <?php esc_html_e( 'Cabin-only cargo (no trunk assumption)', 'xciv-courier-core' ); ?></label>
						<label class="xciv-admin__check"><input type="checkbox" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][rideshare_no_drive]" value="1" <?php checked( ! empty( $lr['rideshare_no_drive'] ) ); ?>> <?php esc_html_e( 'Confirm rider is passenger (not driving)', 'xciv-courier-core' ); ?></label>
						<p><label><?php esc_html_e( 'Rider note', 'xciv-courier-core' ); ?><br>
							<textarea rows="3" class="large-text" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][notes_rideshare]"><?php echo esc_textarea( (string) $lr['notes_rideshare'] ); ?></textarea>
						</label></p>
					</article>

					<article class="xciv-admin__card">
						<h2><?php esc_html_e( 'Car delivery', 'xciv-courier-core' ); ?></h2>
						<p><label><?php esc_html_e( 'Max service range (miles)', 'xciv-courier-core' ); ?><br>
							<input type="number" step="0.1" min="1" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][car_max_miles]" value="<?php echo esc_attr( (string) $lr['car_max_miles'] ); ?>">
						</label></p>
					</article>
				</section>

				<?php submit_button( __( 'Save lead rider settings', 'xciv-courier-core' ) ); ?>
			</form>
			<?php
		}
	);
}

/**
 * Map settings page.
 */
function xciv_core_render_map_page(): void {
	xciv_core_admin_shell(
		__( 'Lead rider map', 'xciv-courier-core' ),
		'map',
		static function (): void {
			$settings = xciv_core_settings();
			$map      = $settings['map'];
			$lr       = $settings['lead_rider'];
			?>
			<form method="post" action="options.php" class="xciv-admin__form">
				<?php settings_fields( 'xciv_core' ); ?>

				<?php // Preserve lead_rider when saving map-only form. ?>
				<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][default_mode]" value="<?php echo esc_attr( (string) $lr['default_mode'] ); ?>">
				<?php foreach ( array( 'car', 'tricycle', 'rideshare' ) as $mode ) : ?>
					<?php if ( ! empty( $lr['modes'][ $mode ] ) ) : ?>
						<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][modes][<?php echo esc_attr( $mode ); ?>]" value="1">
					<?php endif; ?>
				<?php endforeach; ?>
				<?php
				$preserve = array(
					'tricycle_max_kg',
					'tricycle_max_liters',
					'tricycle_max_miles',
					'rideshare_max_l',
					'rideshare_max_w',
					'rideshare_max_h',
					'rideshare_surge_min',
					'car_max_miles',
					'notes_tricycle',
					'notes_rideshare',
				);
				foreach ( $preserve as $key ) :
					?>
					<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) ( $lr[ $key ] ?? '' ) ); ?>">
				<?php endforeach; ?>
				<?php foreach ( array( 'tricycle_helmet', 'tricycle_rain_pause', 'rideshare_cabin_only', 'rideshare_no_drive' ) as $flag ) : ?>
					<?php if ( ! empty( $lr[ $flag ] ) ) : ?>
						<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[lead_rider][<?php echo esc_attr( $flag ); ?>]" value="1">
					<?php endif; ?>
				<?php endforeach; ?>

				<section class="xciv-admin__card">
					<h2><?php esc_html_e( 'Provider', 'xciv-courier-core' ); ?></h2>
					<p><?php esc_html_e( 'Choose Mapbox for styled GL maps, or Custom for Leaflet + OpenStreetMap (no token required).', 'xciv-courier-core' ); ?></p>
					<label class="xciv-admin__check"><input type="radio" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][provider]" value="mapbox" <?php checked( (string) $map['provider'], 'mapbox' ); ?>> <?php esc_html_e( 'Mapbox', 'xciv-courier-core' ); ?></label>
					<label class="xciv-admin__check"><input type="radio" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][provider]" value="custom" <?php checked( (string) $map['provider'], 'custom' ); ?>> <?php esc_html_e( 'Custom (Leaflet / OSM)', 'xciv-courier-core' ); ?></label>
					<p><label><?php esc_html_e( 'Mapbox access token', 'xciv-courier-core' ); ?><br>
						<input type="text" class="large-text" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][mapbox_token]" value="<?php echo esc_attr( (string) $map['mapbox_token'] ); ?>" placeholder="pk.…" autocomplete="off">
					</label></p>
					<p class="description"><?php esc_html_e( 'Or define XCIV_MAPBOX_TOKEN in wp-config.php. If Mapbox is selected without a token, the custom map is used automatically.', 'xciv-courier-core' ); ?></p>
					<p><label><?php esc_html_e( 'Mapbox style URL', 'xciv-courier-core' ); ?><br>
						<input type="text" class="large-text" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][mapbox_style]" value="<?php echo esc_attr( (string) $map['mapbox_style'] ); ?>">
					</label></p>
				</section>

				<section class="xciv-admin__card">
					<h2><?php esc_html_e( 'View', 'xciv-courier-core' ); ?></h2>
					<p><label><?php esc_html_e( 'Center lat', 'xciv-courier-core' ); ?>
						<input type="number" step="0.0001" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][center_lat]" value="<?php echo esc_attr( (string) $map['center_lat'] ); ?>">
					</label>
					<label><?php esc_html_e( 'lng', 'xciv-courier-core' ); ?>
						<input type="number" step="0.0001" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][center_lng]" value="<?php echo esc_attr( (string) $map['center_lng'] ); ?>">
					</label>
					<label><?php esc_html_e( 'Zoom', 'xciv-courier-core' ); ?>
						<input type="number" step="0.1" min="3" max="18" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][zoom]" value="<?php echo esc_attr( (string) $map['zoom'] ); ?>">
					</label></p>
					<label class="xciv-admin__check"><input type="checkbox" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][show_hubs]" value="1" <?php checked( ! empty( $map['show_hubs'] ) ); ?>> <?php esc_html_e( 'Show hubs', 'xciv-courier-core' ); ?></label>
					<label class="xciv-admin__check"><input type="checkbox" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][show_venues]" value="1" <?php checked( ! empty( $map['show_venues'] ) ); ?>> <?php esc_html_e( 'Show venue pins', 'xciv-courier-core' ); ?></label>
					<label class="xciv-admin__check"><input type="checkbox" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[map][show_radius]" value="1" <?php checked( ! empty( $map['show_radius'] ) ); ?>> <?php esc_html_e( 'Show rider / free radius rings', 'xciv-courier-core' ); ?></label>
				</section>

				<section class="xciv-admin__card">
					<h2><?php esc_html_e( 'Hubs', 'xciv-courier-core' ); ?></h2>
					<?php foreach ( (array) $settings['hubs'] as $i => $hub ) : ?>
						<div class="xciv-admin__hub-row">
							<input type="hidden" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[hubs][<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( (string) $hub['id'] ); ?>">
							<label><?php esc_html_e( 'Label', 'xciv-courier-core' ); ?>
								<input type="text" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[hubs][<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( (string) $hub['label'] ); ?>">
							</label>
							<label><?php esc_html_e( 'ZIP', 'xciv-courier-core' ); ?>
								<input type="text" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[hubs][<?php echo (int) $i; ?>][zip]" value="<?php echo esc_attr( (string) $hub['zip'] ); ?>">
							</label>
							<label><?php esc_html_e( 'Lat', 'xciv-courier-core' ); ?>
								<input type="number" step="0.0001" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[hubs][<?php echo (int) $i; ?>][lat]" value="<?php echo esc_attr( (string) $hub['lat'] ); ?>">
							</label>
							<label><?php esc_html_e( 'Lng', 'xciv-courier-core' ); ?>
								<input type="number" step="0.0001" name="<?php echo esc_attr( XCIV_CORE_OPTION ); ?>[hubs][<?php echo (int) $i; ?>][lng]" value="<?php echo esc_attr( (string) $hub['lng'] ); ?>">
							</label>
						</div>
					<?php endforeach; ?>
				</section>

				<section class="xciv-admin__card">
					<h2><?php esc_html_e( 'Embed', 'xciv-courier-core' ); ?></h2>
					<p><?php esc_html_e( 'Add this shortcode to any page, booking template, or account panel:', 'xciv-courier-core' ); ?></p>
					<code>[xciv_lead_rider_map]</code>
					<p class="description"><?php esc_html_e( 'Optional: [xciv_lead_rider_map height="520px"]', 'xciv-courier-core' ); ?></p>
				</section>

				<?php submit_button( __( 'Save map settings', 'xciv-courier-core' ) ); ?>
			</form>
			<?php
		}
	);
}
