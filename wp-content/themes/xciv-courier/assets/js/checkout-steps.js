(() => {
	const cfg = window.xcivBookingCheckout || {};
	const i18n = cfg.i18n || {};
	const hasBooking = Boolean(cfg.hasBooking);
	const requirePhone = cfg.requirePhone !== false;

	const BASE_STEPS = [
		{ id: 'contact', label: 'Contact', selectors: ['.wc-block-checkout__contact-fields'] },
		{
			id: 'billing',
			label: 'Billing',
			selectors: [
				'.wc-block-checkout__billing-fields',
				'.wp-block-woocommerce-checkout-billing-address-block',
				'.wp-block-woocommerce-checkout-order-note-block',
				'.wc-block-checkout__add-note',
			],
		},
		{
			id: 'pay',
			label: 'Pay',
			selectors: [
				'.wc-block-checkout__payment-method',
				'#payment-method',
				'.wp-block-woocommerce-checkout-payment-block',
				'.wp-block-woocommerce-checkout-terms-block',
				'.wc-block-checkout__terms',
				'.wp-block-woocommerce-checkout-actions-block',
				'.wc-block-checkout__actions',
			],
		},
	];

	const STEP_OF = i18n.stepOf || 'Step %1$s of %2$s';

	const ready = (root) => {
		const form = root.querySelector('.wc-block-checkout__form');
		if (!form) return false;
		return !!form.querySelector(
			'.wc-block-checkout__contact-fields, .wc-block-checkout__billing-fields, .wc-block-checkout__payment-method'
		);
	};

	const collectPanels = (root) => {
		const used = new Set();
		return BASE_STEPS.map((step) => {
			const nodes = [];
			step.selectors.forEach((sel) => {
				root.querySelectorAll(sel).forEach((el) => {
					if (used.has(el)) return;
					used.add(el);
					nodes.push(el);
				});
			});
			return { ...step, nodes, custom: false };
		}).filter((step) => step.nodes.length > 0);
	};

	const formatStepStatus = (current, total) =>
		STEP_OF.replace('%1$s', String(current)).replace('%2$s', String(total));

	const isFieldVisible = (el) => {
		if (!(el instanceof HTMLElement)) return false;
		if (el.disabled) return false;
		if (el.type === 'hidden') return false;
		if (el.closest('[hidden]')) return false;
		const style = window.getComputedStyle(el);
		if (style.display === 'none' || style.visibility === 'hidden') return false;
		return el.getClientRects().length > 0;
	};

	const findPhoneInputs = (root) => {
		const selectors = [
			'#billing-phone',
			'input[id*="phone" i]',
			'input[name*="phone" i]',
			'input[autocomplete="tel"]',
			'input[type="tel"]',
		];
		const found = [];
		const seen = new Set();
		selectors.forEach((sel) => {
			root.querySelectorAll(sel).forEach((el) => {
				if (!(el instanceof HTMLInputElement) || seen.has(el)) return;
				seen.add(el);
				found.push(el);
			});
		});
		return found;
	};

	const enforcePhoneRequired = (root) => {
		if (!requirePhone) return;
		findPhoneInputs(root).forEach((input) => {
			input.required = true;
			input.setAttribute('aria-required', 'true');
		});
	};

	const validateStep = (step, root) => {
		const fields = [];
		step.nodes.forEach((node) => {
			node.querySelectorAll('input, select, textarea').forEach((el) => {
				if (!isFieldVisible(el)) return;
				if (el.willValidate === false) return;
				fields.push(el);
			});
		});

		if (step.id === 'contact' && requirePhone) {
			enforcePhoneRequired(root);
			const phones = findPhoneInputs(root).filter(isFieldVisible);
			for (const phone of phones) {
				if (!phone.value.trim()) {
					phone.setCustomValidity(i18n.phone || 'Phone is required.');
					phone.reportValidity();
					phone.focus({ preventScroll: false });
					return false;
				}
				phone.setCustomValidity('');
			}
		}

		for (const field of fields) {
			if (!field.checkValidity()) {
				field.reportValidity();
				field.focus({ preventScroll: false });
				return false;
			}
		}
		return true;
	};

	const buildProgress = (steps) => {
		const wrap = document.createElement('div');
		wrap.className = 'xciv-checkout-progress';
		wrap.setAttribute('data-xciv-checkout-progress', '');
		wrap.setAttribute('role', 'navigation');
		wrap.setAttribute('aria-label', 'Checkout steps');
		wrap.style.setProperty('--xciv-checkout-steps', String(steps.length));

		const list = document.createElement('ol');
		list.className = 'xciv-checkout-progress__steps';

		steps.forEach((step, index) => {
			const li = document.createElement('li');
			li.className = 'xciv-checkout-progress__item';
			li.setAttribute('data-xciv-checkout-step-item', String(index + 1));
			li.setAttribute('aria-current', 'false');

			const num = document.createElement('span');
			num.className = 'xciv-checkout-progress__num';
			num.textContent = String(index + 1);

			const label = document.createElement('span');
			label.className = 'xciv-checkout-progress__label';
			label.textContent = step.label;

			li.append(num, label);
			list.append(li);
		});

		const status = document.createElement('p');
		status.className = 'xciv-checkout-progress__status';
		status.setAttribute('data-xciv-checkout-step-status', '');
		status.setAttribute('aria-live', 'polite');
		status.textContent = formatStepStatus(1, steps.length);

		wrap.append(list, status);
		return wrap;
	};

	const buildNav = () => {
		const nav = document.createElement('div');
		nav.className = 'xciv-checkout-nav';
		nav.setAttribute('data-xciv-checkout-nav', '');

		const prev = document.createElement('button');
		prev.type = 'button';
		prev.className = 'xciv-checkout-nav__btn xciv-checkout-nav__btn--ghost';
		prev.setAttribute('data-xciv-checkout-prev', '');
		prev.textContent = 'Back';

		const next = document.createElement('button');
		next.type = 'button';
		next.className = 'xciv-checkout-nav__btn';
		next.setAttribute('data-xciv-checkout-next', '');
		next.textContent = 'Continue';

		nav.append(prev, next);
		return nav;
	};

	const syncClaimantFields = (panel) => {
		const select = panel.querySelector('[data-xciv-checkout-claimant]');
		const wrap = panel.querySelector('[data-xciv-checkout-claimant-fields]');
		if (!select || !wrap) return;
		const other = select.value === 'other';
		wrap.hidden = !other;
		wrap.querySelectorAll('input').forEach((input) => {
			input.required = other;
			if (!other) input.value = input.value; // keep values but not required
		});
	};

	const syncDetailVisibility = (panel) => {
		const service = cfg.service || '';
		const visible = new Set(cfg.fields || []);
		const requiredSet = new Set((cfg.required && cfg.required[service]) || []);

		panel.querySelectorAll('[data-xciv-checkout-field]').forEach((row) => {
			const key = row.getAttribute('data-xciv-checkout-field');
			const show = visible.has(key);
			row.hidden = !show;
			const input = row.querySelector('input, textarea, select');
			if (input) {
				input.required = show && requiredSet.has(key);
				input.disabled = !show;
			}
		});

		syncClaimantFields(panel);
	};

	const buildDetailsPanel = () => {
		const panel = document.createElement('fieldset');
		panel.className = 'xciv-checkout-details wc-block-components-checkout-step';
		panel.setAttribute('data-xciv-checkout-details', '');

		const legend = document.createElement('legend');
		legend.className = 'xciv-checkout-details__legend wc-block-components-checkout-step__title';
		legend.textContent = i18n.detailsTitle || '1 · Queue details';
		panel.append(legend);

		const fieldDefs = cfg.fieldDefs || {};
		const values = cfg.values || {};
		const uid = `xciv-co-${Date.now().toString(36)}`;

		Object.keys(fieldDefs).forEach((key) => {
			const def = fieldDefs[key];
			const row = document.createElement('div');
			row.className = 'xciv-checkout-details__row';
			row.setAttribute('data-xciv-checkout-field', key);
			row.hidden = true;

			const label = document.createElement('label');
			label.setAttribute('for', `${uid}-${key}`);
			label.textContent = def.label || key;

			let input;
			if (def.type === 'textarea') {
				input = document.createElement('textarea');
				input.rows = 3;
			} else {
				input = document.createElement('input');
				input.type = def.type || 'text';
			}
			input.id = `${uid}-${key}`;
			input.name = key;
			input.value = values[key] || '';
			if (def.placeholder) input.placeholder = def.placeholder;

			row.append(label, input);
			panel.append(row);
		});

		const claimantRow = document.createElement('div');
		claimantRow.className = 'xciv-checkout-details__row';
		claimantRow.setAttribute('data-xciv-checkout-field', 'claimant');

		const claimantLabel = document.createElement('label');
		claimantLabel.setAttribute('for', `${uid}-claimant`);
		claimantLabel.textContent = i18n.claimant || 'Who claims at the front?';

		const claimantSelect = document.createElement('select');
		claimantSelect.id = `${uid}-claimant`;
		claimantSelect.name = 'claimant';
		claimantSelect.setAttribute('data-xciv-checkout-claimant', '');

		const optSelf = document.createElement('option');
		optSelf.value = 'self';
		optSelf.textContent = i18n.claimSelf || 'I will claim';
		const optOther = document.createElement('option');
		optOther.value = 'other';
		optOther.textContent = i18n.claimOther || 'Someone else will claim';
		claimantSelect.append(optSelf, optOther);
		claimantSelect.value = values.claimant === 'other' ? 'other' : 'self';

		claimantRow.append(claimantLabel, claimantSelect);
		panel.append(claimantRow);

		const claimantGrid = document.createElement('div');
		claimantGrid.className = 'xciv-checkout-details__grid';
		claimantGrid.setAttribute('data-xciv-checkout-claimant-fields', '');
		claimantGrid.hidden = true;

		const nameRow = document.createElement('div');
		nameRow.className = 'xciv-checkout-details__row';
		const nameLabel = document.createElement('label');
		nameLabel.setAttribute('for', `${uid}-claim-name`);
		nameLabel.textContent = i18n.claimName || 'Claimant name';
		const nameInput = document.createElement('input');
		nameInput.type = 'text';
		nameInput.id = `${uid}-claim-name`;
		nameInput.name = 'claimant_name';
		nameInput.value = values.claimant_name || '';
		nameRow.append(nameLabel, nameInput);

		const phoneRow = document.createElement('div');
		phoneRow.className = 'xciv-checkout-details__row';
		const phoneLabel = document.createElement('label');
		phoneLabel.setAttribute('for', `${uid}-claim-phone`);
		phoneLabel.textContent = i18n.claimPhone || 'Claimant phone';
		const phoneInput = document.createElement('input');
		phoneInput.type = 'tel';
		phoneInput.id = `${uid}-claim-phone`;
		phoneInput.name = 'claimant_phone';
		phoneInput.value = values.claimant_phone || '';
		phoneRow.append(phoneLabel, phoneInput);

		claimantGrid.append(nameRow, phoneRow);
		panel.append(claimantGrid);

		const status = document.createElement('p');
		status.className = 'xciv-checkout-details__status';
		status.setAttribute('data-xciv-checkout-details-status', '');
		status.hidden = true;
		panel.append(status);

		claimantSelect.addEventListener('change', () => syncClaimantFields(panel));
		syncDetailVisibility(panel);

		return panel;
	};

	const collectDetailsPayload = (panel) => {
		const data = new FormData();
		data.append('action', 'xciv_booking_update_checkout_details');
		data.append('nonce', cfg.nonce || '');

		panel.querySelectorAll('input, textarea, select').forEach((el) => {
			if (el.disabled || el.closest('[hidden]')) return;
			if (!el.name) return;
			data.append(el.name, el.value);
		});

		const claimant = panel.querySelector('[data-xciv-checkout-claimant]');
		if (claimant) data.set('claimant', claimant.value);

		return data;
	};

	const saveDetails = async (panel) => {
		const status = panel.querySelector('[data-xciv-checkout-details-status]');
		if (status) {
			status.hidden = false;
			status.textContent = i18n.saving || 'Saving booking details…';
			status.classList.remove('is-error');
		}

		try {
			const res = await fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				body: collectDetailsPayload(panel),
			});
			const json = await res.json();
			if (!json || !json.success) {
				const message =
					(json && json.data && json.data.message) ||
					i18n.saveError ||
					'Could not save booking details. Please try again.';
				if (status) {
					status.textContent = message;
					status.classList.add('is-error');
				}
				return false;
			}
			if (status) status.hidden = true;
			if (json.data && json.data.values) {
				cfg.values = json.data.values;
			}
			return true;
		} catch (err) {
			if (status) {
				status.hidden = false;
				status.textContent = i18n.saveError || 'Could not save booking details. Please try again.';
				status.classList.add('is-error');
			}
			return false;
		}
	};

	const init = (root) => {
		if (root.dataset.xcivCheckoutBound === '1') return;
		const form = root.querySelector('.wc-block-checkout__form');
		if (!form) return;

		let steps = collectPanels(root);
		if (steps.length < 2) return;

		if (hasBooking) {
			form.querySelectorAll('[data-xciv-checkout-details]').forEach((el) => el.remove());
			const detailsPanel = buildDetailsPanel();
			form.prepend(detailsPanel);
			steps = [
				{ id: 'details', label: 'Details', nodes: [detailsPanel], custom: true },
				...steps,
			];
		}

		root.dataset.xcivCheckoutBound = '1';
		root.classList.add('xciv-checkout--stepped');
		if (hasBooking) root.classList.add('xciv-checkout--has-details');

		form.querySelectorAll('[data-xciv-checkout-progress], [data-xciv-checkout-nav]').forEach((el) =>
			el.remove()
		);

		const progress = buildProgress(steps);
		form.prepend(progress);

		const nav = buildNav();
		form.append(nav);

		const prevBtn = nav.querySelector('[data-xciv-checkout-prev]');
		const nextBtn = nav.querySelector('[data-xciv-checkout-next]');
		const statusEl = progress.querySelector('[data-xciv-checkout-step-status]');
		const stepItems = Array.from(progress.querySelectorAll('[data-xciv-checkout-step-item]'));

		let current = 1;
		let saving = false;

		const sync = () => {
			root.setAttribute('data-xciv-step', String(current));
			root.setAttribute('data-xciv-step-count', String(steps.length));

			steps.forEach((step, index) => {
				const active = index + 1 === current;
				step.nodes.forEach((node) => {
					node.setAttribute('data-xciv-checkout-panel', String(index + 1));
					node.classList.toggle('is-xciv-active', active);
					if (active) {
						node.removeAttribute('hidden');
					}
				});
			});

			stepItems.forEach((item, index) => {
				const n = index + 1;
				item.classList.toggle('is-active', n === current);
				item.classList.toggle('is-done', n < current);
				item.setAttribute('aria-current', n === current ? 'step' : 'false');
			});

			if (statusEl) {
				statusEl.textContent = formatStepStatus(current, steps.length);
			}

			if (prevBtn) {
				prevBtn.hidden = current <= 1;
			}
			if (nextBtn) {
				nextBtn.hidden = current >= steps.length;
			}

			if (steps[current - 1] && steps[current - 1].id === 'contact') {
				enforcePhoneRequired(root);
			}
		};

		prevBtn?.addEventListener('click', () => {
			if (current <= 1 || saving) return;
			current -= 1;
			sync();
			progress.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		});

		nextBtn?.addEventListener('click', async () => {
			if (saving) return;
			const step = steps[current - 1];
			if (!step || !validateStep(step, root)) return;
			if (current >= steps.length) return;

			if (step.id === 'details') {
				saving = true;
				nextBtn.disabled = true;
				const ok = await saveDetails(step.nodes[0]);
				nextBtn.disabled = false;
				saving = false;
				if (!ok) return;
			}

			current += 1;
			sync();
			progress.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		});

		enforcePhoneRequired(root);
		sync();
	};

	const tryInit = () => {
		document.querySelectorAll('.wp-block-woocommerce-checkout').forEach((root) => {
			if (root.dataset.xcivCheckoutBound === '1') return;
			if (!ready(root)) return;
			init(root);
		});
	};

	const observe = () => {
		tryInit();
		const observer = new MutationObserver(() => {
			tryInit();
			document.querySelectorAll('.wp-block-woocommerce-checkout.xciv-checkout--stepped').forEach((root) => {
				if (!root.querySelector('[data-xciv-checkout-progress]')) {
					root.dataset.xcivCheckoutBound = '';
					root.classList.remove('xciv-checkout--stepped', 'xciv-checkout--has-details');
					tryInit();
				}
			});
			document.querySelectorAll('.wp-block-woocommerce-checkout.xciv-checkout--stepped').forEach((root) => {
				enforcePhoneRequired(root);
			});
		});
		observer.observe(document.body, { childList: true, subtree: true });
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', observe);
	} else {
		observe();
	}
})();
