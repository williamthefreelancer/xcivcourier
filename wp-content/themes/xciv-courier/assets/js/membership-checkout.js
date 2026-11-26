(() => {
	const roots = [...document.querySelectorAll('[data-xciv-membership]')];
	if (!roots.length) return;

	const cfg = window.xcivMembership || {};
	const i18n = cfg.i18n || {};

	const currentPlanSlug = (card, interval) => {
		const key = interval === 'yearly' ? 'planYearly' : 'planMonthly';
		const attr = interval === 'yearly' ? 'data-plan-yearly' : 'data-plan-monthly';
		return card.dataset[key] || card.getAttribute(attr) || '';
	};

	const applyInterval = (root, interval) => {
		root.dataset.interval = interval;
		root.querySelectorAll('[data-xciv-interval]').forEach((btn) => {
			btn.classList.toggle('is-active', btn.getAttribute('data-xciv-interval') === interval);
		});

		root.querySelectorAll('[data-xciv-family]').forEach((card) => {
			const price =
				interval === 'yearly'
					? card.getAttribute('data-price-yearly')
					: card.getAttribute('data-price-monthly');
			const cadence =
				interval === 'yearly'
					? card.getAttribute('data-cadence-yearly')
					: card.getAttribute('data-cadence-monthly');
			const term =
				interval === 'yearly'
					? card.getAttribute('data-term-yearly')
					: card.getAttribute('data-term-monthly');

			const priceEl = card.querySelector('[data-xciv-price]');
			const cadenceEl = card.querySelector('[data-xciv-cadence]');
			const termEl = card.querySelector('[data-xciv-term]');
			if (priceEl && price) priceEl.textContent = price;
			if (cadenceEl && cadence) cadenceEl.textContent = cadence;
			if (termEl && term) termEl.textContent = term;
		});
	};

	const setStatus = (root, message, isError = false) => {
		const el = root.querySelector('[data-xciv-membership-status]');
		if (!el) return;
		el.hidden = !message;
		el.textContent = message || '';
		el.classList.toggle('is-error', Boolean(isError));
	};

	const disableActions = (root, disabled) => {
		root.querySelectorAll('button[data-xciv-membership-stripe], button[data-xciv-membership-woo]').forEach((btn) => {
			btn.disabled = disabled;
		});
	};

	const resolvePlan = (root, card) => {
		const interval = root.dataset.interval || root.getAttribute('data-default-interval') || 'monthly';
		return currentPlanSlug(card, interval);
	};

	const checkoutStripe = async (root, plan) => {
		if (!cfg.restCheckout) {
			setStatus(root, i18n.stripeMissing || 'Stripe checkout is not available.', true);
			return;
		}
		if (!cfg.loggedIn) {
			window.location.href = cfg.loginUrl || '/my-account/';
			return;
		}

		disableActions(root, true);
		setStatus(root, i18n.startingStripe || 'Starting Stripe Checkout…');

		try {
			const res = await fetch(cfg.restCheckout, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.restNonce || '',
				},
				body: JSON.stringify({ plan }),
			});
			const json = await res.json();
			if (!res.ok || !json || !json.url) {
				const message =
					(json && json.message) ||
					i18n.stripeError ||
					'Could not start Stripe Checkout.';
				setStatus(root, message, true);
				return;
			}
			window.location.href = json.url;
		} catch (err) {
			setStatus(root, i18n.stripeError || 'Could not start Stripe Checkout.', true);
		} finally {
			disableActions(root, false);
		}
	};

	const checkoutWoo = async (root, plan) => {
		if (!cfg.ajaxUrl) {
			setStatus(root, i18n.wooError || 'Could not start checkout.', true);
			return;
		}

		disableActions(root, true);
		setStatus(root, i18n.startingWoo || 'Adding membership to cart…');

		try {
			const data = new FormData();
			data.set('action', 'xciv_membership_add_to_cart');
			data.set('nonce', cfg.ajaxNonce || '');
			data.set('plan', plan);

			const res = await fetch(cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: data,
			});
			const json = await res.json();
			if (!json || !json.success) {
				const message =
					(json && json.data && json.data.message) ||
					i18n.wooError ||
					'Could not start checkout.';
				setStatus(root, message, true);
				return;
			}
			window.location.href = json.data.checkoutUrl || cfg.checkoutUrl || '/checkout/';
		} catch (err) {
			setStatus(root, i18n.wooError || 'Could not start checkout.', true);
		} finally {
			disableActions(root, false);
		}
	};

	roots.forEach((root) => {
		const initial = root.getAttribute('data-default-interval') || 'monthly';
		applyInterval(root, initial);

		root.querySelectorAll('[data-xciv-interval]').forEach((btn) => {
			btn.addEventListener('click', () => {
				applyInterval(root, btn.getAttribute('data-xciv-interval') || 'monthly');
			});
		});

		root.addEventListener('click', (event) => {
			const stripeBtn = event.target.closest('[data-xciv-membership-stripe]');
			const wooBtn = event.target.closest('[data-xciv-membership-woo]');
			if (!stripeBtn && !wooBtn) return;

			const card = event.target.closest('[data-xciv-family]');
			if (!card) return;
			const plan = resolvePlan(root, card);
			if (!plan) {
				setStatus(root, i18n.badPlan || 'Pick a membership plan.', true);
				return;
			}

			if (stripeBtn) {
				checkoutStripe(root, plan);
			} else {
				checkoutWoo(root, plan);
			}
		});
	});
})();
