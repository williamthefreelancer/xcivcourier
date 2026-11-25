(() => {
	const modal = document.querySelector('[data-xciv-booking-modal]');
	if (!modal) return;

	const panel = modal.querySelector('[data-xciv-booking-panel]');
	const openers = document.querySelectorAll('[data-xciv-booking-open]');
	const closers = modal.querySelectorAll('[data-xciv-booking-close]');

	const focusable = () =>
		panel
			? panel.querySelectorAll(
					'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
				)
			: [];

	const applyPrefill = (params) => {
		const form = modal.querySelector('[data-xciv-booking-form]');
		if (!form || !params) return;

		const service = params.get('xciv_service') || '';
		if (service) {
			const radio = form.querySelector(`[data-xciv-service][value="${CSS.escape(service)}"]`);
			if (radio) {
				radio.checked = true;
				radio.dispatchEvent(new Event('change', { bubbles: true }));
			}
		}

		const store = params.get('xciv_store') || '';
		if (store) {
			const storeInput = form.querySelector('[data-xciv-field="store_name"] input, input[name="store_name"]');
			if (storeInput) storeInput.value = store;
		}

		const loc = params.get('xciv_loc') || '';
		if (loc) {
			const locInput = form.querySelector('input[name="location"], textarea[name="location"]');
			if (locInput) locInput.value = loc;
		}

		const city = params.get('xciv_city') || '';
		if (city) {
			const cityInput = form.querySelector('input[name="city"]');
			if (cityInput) cityInput.value = city;
		}

		const venueId = params.get('xciv_venue') || '';
		const venueInput = form.querySelector('[data-xciv-venue-id], input[name="venue_id"]');
		if (venueInput) venueInput.value = venueId;

		form.dispatchEvent(
			new CustomEvent('xciv:venue-prefill', {
				bubbles: true,
				detail: { venueId },
			})
		);
	};

	const open = (params) => {
		applyPrefill(params);
		modal.classList.add('is-open');
		modal.setAttribute('aria-hidden', 'false');
		document.body.classList.add('xciv-booking-modal-open');
		const first = focusable()[0];
		if (first) first.focus();
	};

	const close = () => {
		modal.classList.remove('is-open');
		modal.setAttribute('aria-hidden', 'true');
		document.body.classList.remove('xciv-booking-modal-open');
	};

	openers.forEach((btn) => {
		btn.addEventListener('click', (event) => {
			event.preventDefault();
			let params = null;
			try {
				const href = btn.getAttribute('href') || '';
				if (href) params = new URL(href, window.location.origin).searchParams;
			} catch (err) {
				params = null;
			}
			open(params);
		});
	});

	closers.forEach((el) => {
		el.addEventListener('click', (event) => {
			if (el === modal && event.target !== modal) return;
			close();
		});
	});

	modal.addEventListener('click', (event) => {
		if (event.target === modal) close();
	});

	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && modal.classList.contains('is-open')) {
			close();
		}
	});

	if (window.location.hash === '#book') {
		open(new URLSearchParams(window.location.search));
	}
})();
