(() => {
	const cfg = window.xcivBooking || {};
	const forms = document.querySelectorAll('[data-xciv-booking-form]');
	if (!forms.length) return;

	const services = cfg.services || {};
	const addons = cfg.addons || {};
	const venues = cfg.venues || {};
	const hubs = cfg.hubs || {};
	const basePrice = Number(cfg.basePrice || 50);
	const extraRate = Number(cfg.extraHourRate || 25);
	const i18n = cfg.i18n || {};

	const requiredByService = {
		hold_spot: ['event_name'],
		queue_open: ['store_name'],
		secure_pickup: ['store_name', 'order_ref'],
		personal_shop: ['store_name', 'shopping_list'],
		custom: ['event_name', 'custom_details'],
	};

	const calcPrice = (startVal, endVal) => {
		if (!startVal || !endVal) {
			return { valid: false, hours: 0, billable: 0, price: 0, message: i18n.invalidWindow || 'Check your start and end times.' };
		}
		const start = new Date(startVal);
		const end = new Date(endVal);
		if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end <= start) {
			return { valid: false, hours: 0, billable: 0, price: 0, message: i18n.invalidWindow || 'Check your start and end times.' };
		}

		const hours = (end - start) / 3600000;
		const billable = Math.max(2, Math.ceil(hours * 2) / 2);
		const price = basePrice + Math.max(0, billable - 2) * extraRate;
		const dow = start.getDay(); // 0 Sun … 3 Wed
		if (dow < 0 || dow > 3) {
			return {
				valid: false,
				hours,
				billable,
				price,
				message: 'Bookings are available Sunday–Wednesday only.',
			};
		}
		return { valid: true, hours, billable, price, message: '' };
	};

	const addonAllowed = (when, service, billable) => {
		switch (when) {
			case 'always':
				return true;
			case 'hold_or_open':
				return service === 'hold_spot' || service === 'queue_open';
			case 'not_pickup':
				return service !== 'secure_pickup';
			case 'shop_or_not_pickup':
				return service === 'personal_shop' || service !== 'secure_pickup';
			case 'long_wait':
				return billable >= 4;
			default:
				return false;
		}
	};

	forms.forEach((form) => {
		if (form.dataset.xcivBound === '1') return;
		form.dataset.xcivBound = '1';

		const status = form.querySelector('[data-xciv-booking-status]');
		const estimate = form.querySelector('[data-xciv-estimate]');
		const estimateFinal = form.querySelector('[data-xciv-estimate-final]');
		const deliveryHint = form.querySelector('[data-xciv-delivery-hint]');
		const hubHint = form.querySelector('[data-xciv-hub-hint]');
		const venueIdInput = form.querySelector('[data-xciv-venue-id], input[name="venue_id"]');
		const fulfillmentInput = form.querySelector('[data-xciv-fulfillment-mode], input[name="fulfillment_mode"]');
		const hubIdInput = form.querySelector('[data-xciv-hub-id], input[name="hub_id"]');
		const locationInput = form.querySelector('[data-xciv-location], input[name="location"]');
		const cityInput = form.querySelector('[data-xciv-city], input[name="city"]');
		const serviceInputs = form.querySelectorAll('[data-xciv-service]');
		const startInput = form.querySelector('[data-xciv-start]');
		const endInput = form.querySelector('[data-xciv-end]');
		const claimantSelect = form.querySelector('[data-xciv-claimant]');
		const claimantFields = form.querySelector('[data-xciv-claimant-fields]');
		const claimantInputs = form.querySelectorAll('[data-xciv-claimant-input]');
		const phoneInput = form.querySelector('[data-xciv-phone]');
		const paymentAckWrap = form.querySelector('[data-xciv-payment-ack]');
		const paymentAckInput = paymentAckWrap
			? paymentAckWrap.querySelector('input[name="payment_ack"]')
			: null;
		const submitBtn = form.querySelector('[data-xciv-submit]');
		const nextBtn = form.querySelector('[data-xciv-next]');
		const prevBtn = form.querySelector('[data-xciv-prev]');
		const stepStatus = form.querySelector('[data-xciv-step-status]');
		const stepPanels = Array.from(form.querySelectorAll('[data-xciv-step-panel]'));
		const stepItems = Array.from(form.querySelectorAll('[data-xciv-step-item]'));
		const stepCount = Number(form.getAttribute('data-xciv-step-count') || stepPanels.length || 1);
		let currentStep = Number(form.getAttribute('data-xciv-step') || 1);

		const selectedVenue = () => {
			const id = venueIdInput ? String(venueIdInput.value || '').trim() : '';
			return id && venues[id] ? venues[id] : null;
		};

		const deliveryFeeAmount = () => {
			const venue = selectedVenue();
			return venue ? Number(venue.fee || 0) : 0;
		};

		const updateDeliveryHint = () => {
			if (!deliveryHint) return;
			const venue = selectedVenue();
			if (!venue) {
				deliveryHint.hidden = true;
				deliveryHint.textContent = '';
				return;
			}
			const fee = Number(venue.fee || 0);
			const miles = Number(venue.miles || 0);
			const mins = Number(venue.travelMins || 0);
			if (fee > 0) {
				const tpl = i18n.deliveryHint || '+$%1$s delivery · %2$s mi · ~%3$s min drive from your hubs';
				deliveryHint.textContent = tpl
					.replace('%1$s', String(Math.round(fee)))
					.replace('%2$s', miles.toFixed(1))
					.replace('%3$s', String(mins));
			} else {
				deliveryHint.textContent = i18n.deliveryFree || 'Within hub range · no delivery fee';
			}
			deliveryHint.hidden = false;
		};

		const syncHubHint = (mode, hub) => {
			if (!hubHint) return;
			if (!hub) {
				hubHint.hidden = true;
				hubHint.textContent = '';
				return;
			}
			const label = hub.label || hub.id || '';
			const zip = hub.zip ? ` (${hub.zip})` : '';
			const tpl =
				mode === 'pickup'
					? i18n.hubPickup || 'Pickup hub: %s — meet / collect near this area (ZIP shown publicly).'
					: i18n.hubDelivery || 'Lead delivery from %s hub area.';
			hubHint.textContent = tpl.replace('%s', `${label}${zip}`);
			hubHint.hidden = false;
		};

		const applyFulfillment = (mode) => {
			const next = mode === 'pickup' ? 'pickup' : 'delivery';
			if (fulfillmentInput) fulfillmentInput.value = next;
			const hubId = hubIdInput ? String(hubIdInput.value || '') : '';
			syncHubHint(next, hubs[hubId] || null);

			// Pickup prefers secure_pickup when user hasn't moved off the default hold_spot.
			if (next === 'pickup') {
				const checked = form.querySelector('[data-xciv-service]:checked');
				if (checked && checked.value === 'hold_spot') {
					const pickupRadio = form.querySelector('[data-xciv-service][value="secure_pickup"]');
					if (pickupRadio) {
						pickupRadio.checked = true;
						refresh();
					}
				}
			}
		};

		const applyHub = (hubId, hub) => {
			const resolved = hub || hubs[hubId] || null;
			if (hubIdInput) hubIdInput.value = hubId || '';
			const mode = fulfillmentInput ? fulfillmentInput.value : 'delivery';
			syncHubHint(mode, resolved);

			if (resolved) {
				if (cityInput && !cityInput.value.trim()) {
					cityInput.value = resolved.label || '';
				}
				if (
					mode === 'pickup' &&
					locationInput &&
					!locationInput.value.trim() &&
					!venueIdInput?.value
				) {
					locationInput.value = resolved.zip
						? `${resolved.label} hub · ${resolved.zip}`
						: `${resolved.label} hub`;
				}
			}
		};

		const setStatus = (message, isError = false) => {
			if (!status) return;
			status.hidden = !message;
			status.textContent = message;
			status.dataset.error = isError ? 'true' : 'false';
		};

		const selectedService = () => {
			const checked = form.querySelector('[data-xciv-service]:checked');
			return checked ? checked.value : '';
		};

		const syncServiceFields = () => {
			const service = selectedService();
			const def = services[service] || { fields: [] };
			const visible = new Set(def.fields || []);
			const required = new Set(requiredByService[service] || []);

			form.querySelectorAll('[data-xciv-field]').forEach((row) => {
				const key = row.getAttribute('data-xciv-field');
				if (key === 'claimant') {
					row.hidden = !visible.has('claimant');
					return;
				}
				const show = visible.has(key);
				row.hidden = !show;
				const input = row.querySelector('input, textarea, select');
				if (!input || !input.hasAttribute('data-xciv-conditional')) return;
				input.required = show && required.has(key);
				if (!show) {
					input.value = '';
				}
			});
		};

		const syncClaimant = () => {
			const other = claimantSelect && claimantSelect.value === 'other';
			if (claimantFields) claimantFields.hidden = !other;
			claimantInputs.forEach((input) => {
				input.required = Boolean(other);
				if (!other) input.value = '';
			});
		};

		const getCalc = () =>
			calcPrice(startInput ? startInput.value : '', endInput ? endInput.value : '');

		const renderEstimate = (el, calc, addonTotal) => {
			if (!el) return;
			const delivery = deliveryFeeAmount();
			if (!startInput?.value || !endInput?.value) {
				let hint = i18n.minHint || '$50 / 2-hour minimum · $25 each extra hour · Sun–Wed';
				if (delivery > 0) {
					hint += ` · +$${delivery.toFixed(0)} ${i18n.deliveryFee || 'delivery fee'}`;
				}
				el.textContent = hint;
				el.dataset.error = 'false';
				return;
			}
			if (!calc.valid) {
				el.textContent = calc.message;
				el.dataset.error = 'true';
				return;
			}
			const total = calc.price + addonTotal + delivery;
			el.dataset.error = 'false';
			let text = `${i18n.estimate || 'Estimated total'}: $${total.toFixed(0)} · ${calc.billable} billable hr${calc.billable === 1 ? '' : 's'}`;
			if (delivery > 0) {
				text += ` · +$${delivery.toFixed(0)} ${i18n.deliveryFee || 'delivery fee'}`;
			}
			el.textContent = text;
		};

		const syncAddons = () => {
			const service = selectedService();
			const calc = getCalc();
			let addonTotal = 0;
			let liveSelected = false;
			let purchaseSelected = false;

			form.querySelectorAll('[data-xciv-addon]').forEach((row) => {
				const when = row.getAttribute('data-xciv-addon-when') || 'always';
				const slug = row.getAttribute('data-xciv-addon');
				const allowed = addonAllowed(when, service, calc.billable || 0);
				const input = row.querySelector('[data-xciv-addon-input]');
				row.hidden = !allowed;
				row.classList.toggle('is-disabled', !allowed);
				if (!allowed && input) {
					input.checked = false;
				}
				if (allowed && input && input.checked) {
					const price = addons[slug] ? Number(addons[slug].price || 0) : 0;
					addonTotal += price;
					if (slug === 'live_updates') liveSelected = true;
					if (slug === 'purchase_assist') purchaseSelected = true;
				}
			});

			if (phoneInput) {
				phoneInput.required = liveSelected;
			}

			if (paymentAckWrap) {
				paymentAckWrap.hidden = !purchaseSelected;
			}
			if (paymentAckInput) {
				paymentAckInput.required = purchaseSelected;
				if (!purchaseSelected) paymentAckInput.checked = false;
			}

			renderEstimate(estimate, calc, addonTotal);
			renderEstimate(estimateFinal, calc, addonTotal);
		};

		const refresh = () => {
			syncServiceFields();
			syncClaimant();
			updateDeliveryHint();
			syncAddons();
		};

		form.addEventListener('xciv:venue-prefill', () => {
			updateDeliveryHint();
			syncAddons();
		});

		window.addEventListener('xciv:fulfillment', (event) => {
			const mode = event.detail && event.detail.mode;
			if (mode) applyFulfillment(mode);
		});

		window.addEventListener('xciv:hub', (event) => {
			const detail = event.detail || {};
			if (detail.hubId) applyHub(detail.hubId, detail.hub);
		});

		// Seed from header / admin defaults.
		try {
			const ffAttr = document.documentElement.getAttribute('data-xciv-fulfillment');
			const hubAttr = document.documentElement.getAttribute('data-xciv-hub');
			const ff = ffAttr || (cfg.fulfillment && cfg.fulfillment.default_mode) || 'delivery';
			const hubId =
				hubAttr || (cfg.fulfillment && cfg.fulfillment.default_hub) || Object.keys(hubs)[0] || '';
			applyFulfillment(ff);
			if (hubId) applyHub(hubId, hubs[hubId]);
		} catch (e) {
			/* ignore */
		}

		const showStep = (step) => {
			currentStep = Math.min(Math.max(1, step), stepCount);
			form.setAttribute('data-xciv-step', String(currentStep));

			stepPanels.forEach((panel) => {
				const n = Number(panel.getAttribute('data-xciv-step-panel'));
				panel.hidden = n !== currentStep;
			});

			stepItems.forEach((item) => {
				const n = Number(item.getAttribute('data-xciv-step-item'));
				item.classList.toggle('is-active', n === currentStep);
				item.classList.toggle('is-done', n < currentStep);
				item.setAttribute('aria-current', n === currentStep ? 'step' : 'false');
			});

			if (stepStatus) {
				const template = i18n.stepOf || 'Step %1$s of %2$s';
				stepStatus.textContent = template
					.replace('%1$s', String(currentStep))
					.replace('%2$s', String(stepCount));
			}

			if (prevBtn) prevBtn.hidden = currentStep <= 1;
			if (nextBtn) nextBtn.hidden = currentStep >= stepCount;
			if (submitBtn) submitBtn.hidden = currentStep < stepCount;

			setStatus('');
			const firstField = form.querySelector(
				`[data-xciv-step-panel="${currentStep}"] input:not([type="hidden"]):not([disabled]), [data-xciv-step-panel="${currentStep}"] textarea, [data-xciv-step-panel="${currentStep}"] select`
			);
			if (firstField && typeof firstField.focus === 'function') {
				try {
					firstField.focus({ preventScroll: true });
				} catch (e) {
					firstField.focus();
				}
			}
		};

		const validateStep = (step) => {
			const panel = form.querySelector(`[data-xciv-step-panel="${step}"]`);
			if (!panel) return true;

			if (step === 3) {
				const calc = getCalc();
				if (!calc.valid) {
					setStatus(calc.message || i18n.invalidWindow || 'Check your start and end times.', true);
					return false;
				}
			}

			if (step === 5) {
				const liveChecked = form.querySelector('[data-xciv-addon="live_updates"] [data-xciv-addon-input]:checked');
				if (liveChecked && phoneInput && !phoneInput.value.trim()) {
					// Phone required when Live SMS is selected; selection is on last step,
					// so re-check on submit. Here only enforce if already required.
				}
			}

			const fields = panel.querySelectorAll('input, textarea, select');
			for (const field of fields) {
				if (field.disabled || field.closest('[hidden]')) continue;
				if (!field.checkValidity()) {
					field.reportValidity();
					setStatus(i18n.required || 'Please complete the required fields.', true);
					return false;
				}
			}

			setStatus('');
			return true;
		};

		serviceInputs.forEach((input) => input.addEventListener('change', refresh));
		if (startInput) startInput.addEventListener('change', refresh);
		if (endInput) endInput.addEventListener('change', refresh);
		if (claimantSelect) claimantSelect.addEventListener('change', refresh);
		form.querySelectorAll('[data-xciv-addon-input]').forEach((input) => {
			input.addEventListener('change', refresh);
		});

		if (nextBtn) {
			nextBtn.addEventListener('click', () => {
				if (!validateStep(currentStep)) return;
				showStep(currentStep + 1);
			});
		}

		if (prevBtn) {
			prevBtn.addEventListener('click', () => {
				showStep(currentStep - 1);
			});
		}

		refresh();
		showStep(currentStep);

		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			setStatus('');

			if (currentStep < stepCount) {
				if (!validateStep(currentStep)) return;
				showStep(currentStep + 1);
				return;
			}

			for (let step = 1; step <= stepCount; step += 1) {
				if (!validateStep(step)) {
					showStep(step);
					return;
				}
			}

			const calc = getCalc();
			if (!calc.valid) {
				showStep(3);
				setStatus(calc.message || i18n.invalidWindow || 'Check your start and end times.', true);
				return;
			}

			const agree = form.querySelector('input[name="agreement"]');
			if (agree && !agree.checked) {
				setStatus(i18n.agree || 'You must agree to the booking terms.', true);
				return;
			}

			const liveSelected = Boolean(
				form.querySelector('[data-xciv-addon="live_updates"] [data-xciv-addon-input]:checked')
			);
			if (liveSelected && phoneInput && !phoneInput.value.trim()) {
				showStep(5);
				setStatus(i18n.smsPhone || 'Live SMS updates require a phone number.', true);
				phoneInput.required = true;
				phoneInput.reportValidity();
				return;
			}

			if (!cfg.wooReady || !cfg.ajaxUrl) {
				setStatus(i18n.error || 'Could not start checkout. Please try again.', true);
				return;
			}

			const data = new FormData(form);
			data.set('action', 'xciv_booking_add_to_cart');
			data.set('nonce', cfg.nonce || '');

			if (submitBtn) {
				submitBtn.disabled = true;
			}
			if (nextBtn) nextBtn.disabled = true;
			setStatus(i18n.submitting || 'Adding booking to cart…');

			try {
				const res = await fetch(cfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data,
				});
				const json = await res.json();
				if (!json || !json.success) {
					const message =
						(json && json.data && json.data.message) ||
						i18n.error ||
						'Could not start checkout. Please try again.';
					setStatus(message, true);
					return;
				}
				window.location.href = json.data.checkoutUrl || cfg.checkoutUrl;
			} catch (err) {
				setStatus(i18n.error || 'Could not start checkout. Please try again.', true);
			} finally {
				if (submitBtn) submitBtn.disabled = false;
				if (nextBtn) nextBtn.disabled = false;
			}
		});
	});
})();
