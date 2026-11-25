<?php
/**
 * Front-end render for queue management booking form (multi-step).
 *
 * @package XCIV_Courier
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

declare(strict_types=1);

$heading      = isset( $attributes['heading'] ) ? (string) $attributes['heading'] : __( 'Book a line sitter', 'xciv-courier' );
$submit_label = isset( $attributes['submitLabel'] ) ? (string) $attributes['submitLabel'] : __( 'Continue to checkout', 'xciv-courier' );
$uid          = wp_unique_id( 'xciv-booking-' );
$wrapper      = get_block_wrapper_attributes( array( 'class' => 'xciv-booking' ) );
$services     = function_exists( 'xciv_booking_service_types' ) ? xciv_booking_service_types() : array();
$addons       = function_exists( 'xciv_booking_addons' ) ? xciv_booking_addons() : array();

$steps = array(
	1 => __( 'Service', 'xciv-courier' ),
	2 => __( 'Location', 'xciv-courier' ),
	3 => __( 'Schedule', 'xciv-courier' ),
	4 => __( 'Details', 'xciv-courier' ),
	5 => __( 'Contact', 'xciv-courier' ),
	6 => __( 'Confirm', 'xciv-courier' ),
);
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $heading !== '' ) : ?>
		<h2 class="xciv-booking__title" id="<?php echo esc_attr( $uid ); ?>-title"><?php echo esc_html( $heading ); ?></h2>
	<?php endif; ?>

	<form
		class="xciv-booking__form"
		method="post"
		action="#"
		novalidate
		data-xciv-booking-form
		data-xciv-step="1"
		data-xciv-step-count="<?php echo esc_attr( (string) count( $steps ) ); ?>"
	>
		<div class="xciv-booking__progress" data-xciv-progress role="navigation" aria-label="<?php esc_attr_e( 'Booking steps', 'xciv-courier' ); ?>">
			<ol class="xciv-booking__steps">
				<?php foreach ( $steps as $n => $label ) : ?>
					<li
						class="xciv-booking__step-item<?php echo $n === 1 ? ' is-active' : ''; ?>"
						data-xciv-step-item="<?php echo esc_attr( (string) $n ); ?>"
						aria-current="<?php echo $n === 1 ? 'step' : 'false'; ?>"
					>
						<span class="xciv-booking__step-num"><?php echo esc_html( (string) $n ); ?></span>
						<span class="xciv-booking__step-label"><?php echo esc_html( $label ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
			<p class="xciv-booking__step-status" data-xciv-step-status aria-live="polite">
				<?php
				printf(
					/* translators: 1: current step, 2: total steps */
					esc_html__( 'Step %1$s of %2$s', 'xciv-courier' ),
					'1',
					(string) count( $steps )
				);
				?>
			</p>
		</div>

		<fieldset class="xciv-booking__section" data-xciv-step-panel="1">
			<legend class="xciv-booking__legend"><?php esc_html_e( '1 · Service type', 'xciv-courier' ); ?></legend>
			<div class="xciv-booking__choices" role="radiogroup" aria-labelledby="<?php echo esc_attr( $uid ); ?>-title">
				<?php foreach ( $services as $slug => $service ) : ?>
					<label class="xciv-booking__choice">
						<input
							type="radio"
							name="service_type"
							value="<?php echo esc_attr( $slug ); ?>"
							required
							data-xciv-service
							<?php checked( $slug, 'hold_spot' ); ?>
						>
						<span class="xciv-booking__choice-body">
							<span class="xciv-booking__choice-label"><?php echo esc_html( $service['label'] ); ?></span>
							<span class="xciv-booking__choice-desc"><?php echo esc_html( $service['description'] ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>

		<fieldset class="xciv-booking__section" data-xciv-step-panel="2" hidden>
			<legend class="xciv-booking__legend"><?php esc_html_e( '2 · Location', 'xciv-courier' ); ?></legend>
			<input type="hidden" name="venue_id" value="" data-xciv-venue-id>
			<div class="xciv-booking__row">
				<label for="<?php echo esc_attr( $uid ); ?>-location"><?php esc_html_e( 'Address / venue', 'xciv-courier' ); ?></label>
				<input id="<?php echo esc_attr( $uid ); ?>-location" name="location" type="text" required placeholder="<?php esc_attr_e( 'Where should William wait?', 'xciv-courier' ); ?>">
			</div>
			<div class="xciv-booking__grid">
				<div class="xciv-booking__row">
					<label for="<?php echo esc_attr( $uid ); ?>-city"><?php esc_html_e( 'City / area', 'xciv-courier' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-city" name="city" type="text" placeholder="<?php esc_attr_e( 'Charlotte, Harrisburg…', 'xciv-courier' ); ?>">
				</div>
				<div class="xciv-booking__row">
					<label for="<?php echo esc_attr( $uid ); ?>-parking"><?php esc_html_e( 'Parking / access', 'xciv-courier' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-parking" name="parking" type="text" placeholder="<?php esc_attr_e( 'Lot, entrance, gate code…', 'xciv-courier' ); ?>">
				</div>
			</div>
			<p class="xciv-booking__hint" data-xciv-delivery-hint hidden></p>
		</fieldset>

		<fieldset class="xciv-booking__section" data-xciv-step-panel="3" hidden>
			<legend class="xciv-booking__legend"><?php esc_html_e( '3 · Start & end', 'xciv-courier' ); ?></legend>
			<div class="xciv-booking__grid">
				<div class="xciv-booking__row">
					<label for="<?php echo esc_attr( $uid ); ?>-start"><?php esc_html_e( 'Start', 'xciv-courier' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-start" name="start_time" type="datetime-local" required data-xciv-start>
				</div>
				<div class="xciv-booking__row">
					<label for="<?php echo esc_attr( $uid ); ?>-end"><?php esc_html_e( 'End', 'xciv-courier' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-end" name="end_time" type="datetime-local" required data-xciv-end>
				</div>
			</div>
			<p class="xciv-booking__hint" data-xciv-estimate><?php esc_html_e( '$50 / 2-hour minimum · $25 each extra hour · Sun–Wed', 'xciv-courier' ); ?></p>
		</fieldset>

		<fieldset class="xciv-booking__section" data-xciv-step-panel="4" data-xciv-panel="service-fields" hidden>
			<legend class="xciv-booking__legend"><?php esc_html_e( '4 · Queue details', 'xciv-courier' ); ?></legend>

			<div class="xciv-booking__row" data-xciv-field="event_name" hidden>
				<label for="<?php echo esc_attr( $uid ); ?>-event"><?php esc_html_e( 'Event / drop name', 'xciv-courier' ); ?></label>
				<input id="<?php echo esc_attr( $uid ); ?>-event" name="event_name" type="text" data-xciv-conditional>
			</div>

			<div class="xciv-booking__row" data-xciv-field="brand_item" hidden>
				<label for="<?php echo esc_attr( $uid ); ?>-brand"><?php esc_html_e( 'Brand / item', 'xciv-courier' ); ?></label>
				<input id="<?php echo esc_attr( $uid ); ?>-brand" name="brand_item" type="text" data-xciv-conditional placeholder="<?php esc_attr_e( 'What are you waiting for?', 'xciv-courier' ); ?>">
			</div>

			<div class="xciv-booking__row" data-xciv-field="door_time" hidden>
				<label for="<?php echo esc_attr( $uid ); ?>-door"><?php esc_html_e( 'Expected door / release time', 'xciv-courier' ); ?></label>
				<input id="<?php echo esc_attr( $uid ); ?>-door" name="door_time" type="text" data-xciv-conditional placeholder="<?php esc_attr_e( 'e.g. 10:00 AM door', 'xciv-courier' ); ?>">
			</div>

			<div class="xciv-booking__row" data-xciv-field="store_name" hidden>
				<label for="<?php echo esc_attr( $uid ); ?>-store"><?php esc_html_e( 'Store name', 'xciv-courier' ); ?></label>
				<input id="<?php echo esc_attr( $uid ); ?>-store" name="store_name" type="text" data-xciv-conditional>
			</div>

			<div class="xciv-booking__row" data-xciv-field="open_time" hidden>
				<label for="<?php echo esc_attr( $uid ); ?>-open"><?php esc_html_e( 'Store open time', 'xciv-courier' ); ?></label>
				<input id="<?php echo esc_attr( $uid ); ?>-open" name="open_time" type="text" data-xciv-conditional>
			</div>

			<div class="xciv-booking__row" data-xciv-field="early_access" hidden>
				<label for="<?php echo esc_attr( $uid ); ?>-early"><?php esc_html_e( 'Early access notes', 'xciv-courier' ); ?></label>
				<input id="<?php echo esc_attr( $uid ); ?>-early" name="early_access" type="text" data-xciv-conditional placeholder="<?php esc_attr_e( 'Member hours, raffle, wristband…', 'xciv-courier' ); ?>">
			</div>

			<div class="xciv-booking__row" data-xciv-field="order_ref" hidden>
				<label for="<?php echo esc_attr( $uid ); ?>-order"><?php esc_html_e( 'Order / confirmation #', 'xciv-courier' ); ?></label>
				<input id="<?php echo esc_attr( $uid ); ?>-order" name="order_ref" type="text" data-xciv-conditional>
			</div>

			<div class="xciv-booking__row" data-xciv-field="pickup_window" hidden>
				<label for="<?php echo esc_attr( $uid ); ?>-pickup"><?php esc_html_e( 'Pickup window', 'xciv-courier' ); ?></label>
				<input id="<?php echo esc_attr( $uid ); ?>-pickup" name="pickup_window" type="text" data-xciv-conditional>
			</div>

			<div class="xciv-booking__row" data-xciv-field="shopping_list" hidden>
				<label for="<?php echo esc_attr( $uid ); ?>-list"><?php esc_html_e( 'Shopping list', 'xciv-courier' ); ?></label>
				<textarea id="<?php echo esc_attr( $uid ); ?>-list" name="shopping_list" rows="4" data-xciv-conditional placeholder="<?php esc_attr_e( 'Items, brands, quantities, substitutions…', 'xciv-courier' ); ?>"></textarea>
			</div>

			<div class="xciv-booking__row" data-xciv-field="custom_details" hidden>
				<label for="<?php echo esc_attr( $uid ); ?>-custom"><?php esc_html_e( 'Describe the queue', 'xciv-courier' ); ?></label>
				<textarea id="<?php echo esc_attr( $uid ); ?>-custom" name="custom_details" rows="3" data-xciv-conditional></textarea>
			</div>

			<div class="xciv-booking__row" data-xciv-field="claimant">
				<label for="<?php echo esc_attr( $uid ); ?>-claimant"><?php esc_html_e( 'Who claims at the front?', 'xciv-courier' ); ?></label>
				<select id="<?php echo esc_attr( $uid ); ?>-claimant" name="claimant" data-xciv-claimant>
					<option value="self"><?php esc_html_e( 'I will claim', 'xciv-courier' ); ?></option>
					<option value="other"><?php esc_html_e( 'Someone else will claim', 'xciv-courier' ); ?></option>
				</select>
			</div>

			<div class="xciv-booking__grid" data-xciv-claimant-fields hidden>
				<div class="xciv-booking__row">
					<label for="<?php echo esc_attr( $uid ); ?>-claim-name"><?php esc_html_e( 'Claimant name', 'xciv-courier' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-claim-name" name="claimant_name" type="text" data-xciv-claimant-input>
				</div>
				<div class="xciv-booking__row">
					<label for="<?php echo esc_attr( $uid ); ?>-claim-phone"><?php esc_html_e( 'Claimant phone', 'xciv-courier' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-claim-phone" name="claimant_phone" type="tel" data-xciv-claimant-input>
				</div>
			</div>
		</fieldset>

		<fieldset class="xciv-booking__section" data-xciv-step-panel="5" hidden>
			<legend class="xciv-booking__legend"><?php esc_html_e( '5 · Contact', 'xciv-courier' ); ?></legend>
			<div class="xciv-booking__row">
				<label for="<?php echo esc_attr( $uid ); ?>-name"><?php esc_html_e( 'Full name', 'xciv-courier' ); ?></label>
				<input id="<?php echo esc_attr( $uid ); ?>-name" name="name" type="text" autocomplete="name" required>
			</div>
			<div class="xciv-booking__grid">
				<div class="xciv-booking__row">
					<label for="<?php echo esc_attr( $uid ); ?>-email"><?php esc_html_e( 'Email', 'xciv-courier' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-email" name="email" type="email" autocomplete="email" required>
				</div>
				<div class="xciv-booking__row">
					<label for="<?php echo esc_attr( $uid ); ?>-phone"><?php esc_html_e( 'Phone', 'xciv-courier' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-phone" name="phone" type="tel" autocomplete="tel" data-xciv-phone>
				</div>
			</div>
			<div class="xciv-booking__row">
				<label for="<?php echo esc_attr( $uid ); ?>-notes"><?php esc_html_e( 'Standing instructions', 'xciv-courier' ); ?></label>
				<textarea id="<?php echo esc_attr( $uid ); ?>-notes" name="instructions" rows="3" placeholder="<?php esc_attr_e( 'Where to stand, what to say, item priorities…', 'xciv-courier' ); ?>"></textarea>
			</div>
		</fieldset>

		<fieldset class="xciv-booking__section" data-xciv-step-panel="6" hidden>
			<legend class="xciv-booking__legend"><?php esc_html_e( '6 · Add-ons & agreement', 'xciv-courier' ); ?></legend>
			<div class="xciv-booking__addons">
				<?php foreach ( $addons as $slug => $addon ) : ?>
					<label class="xciv-booking__addon" data-xciv-addon="<?php echo esc_attr( $slug ); ?>" data-xciv-addon-when="<?php echo esc_attr( $addon['when'] ); ?>">
						<input type="checkbox" name="addons[]" value="<?php echo esc_attr( $slug ); ?>" data-xciv-addon-input>
						<span class="xciv-booking__addon-body">
							<span class="xciv-booking__addon-label">
								<?php echo esc_html( $addon['label'] ); ?>
								<span class="xciv-booking__addon-price">+$<?php echo esc_html( number_format( (float) $addon['price'], 0 ) ); ?></span>
							</span>
							<span class="xciv-booking__addon-desc"><?php echo esc_html( $addon['description'] ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="xciv-booking__row" data-xciv-payment-ack hidden>
				<label class="xciv-booking__check">
					<input type="checkbox" name="payment_ack" value="1" data-xciv-payment-ack>
					<span><?php esc_html_e( 'I will provide an approved payment method before purchase assist — no blind buys.', 'xciv-courier' ); ?></span>
				</label>
			</div>

			<label class="xciv-booking__check">
				<input type="checkbox" name="agreement" value="1" required>
				<span><?php esc_html_e( 'I agree: no line cutting, no stock guarantee, waiting only where allowed, and I (or my claimant) will show for checkout.', 'xciv-courier' ); ?></span>
			</label>

			<p class="xciv-booking__hint" data-xciv-estimate-final></p>
		</fieldset>

		<p class="xciv-booking__status" data-xciv-booking-status hidden></p>

		<div class="xciv-booking__nav">
			<button class="xciv-booking__nav-btn xciv-booking__nav-btn--ghost" type="button" data-xciv-prev hidden>
				<?php esc_html_e( 'Back', 'xciv-courier' ); ?>
			</button>
			<button class="xciv-booking__nav-btn" type="button" data-xciv-next>
				<?php esc_html_e( 'Continue', 'xciv-courier' ); ?>
			</button>
			<button class="xciv-booking__submit" type="submit" data-xciv-submit hidden>
				<?php echo esc_html( $submit_label ); ?>
			</button>
		</div>
	</form>
</div>
