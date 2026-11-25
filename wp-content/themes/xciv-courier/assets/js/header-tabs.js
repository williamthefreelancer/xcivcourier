(function () {
	'use strict';

	function initFulfillmentTabs() {
		var root = document.querySelector('[data-xciv-header-tabs]');
		if (!root) {
			return;
		}

		var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-xciv-fulfillment]'));
		var thumb = root.querySelector('[data-xciv-header-thumb]');
		var riderRoot = document.querySelector('[data-xciv-rider-modes]');
		var storageKey = 'xciv_fulfillment_mode';
		var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

		function moveThumb(activeTab, animate) {
			if (!thumb || !activeTab) {
				return;
			}

			var rootRect = root.getBoundingClientRect();
			var tabRect = activeTab.getBoundingClientRect();
			var styles = window.getComputedStyle(root);
			var padLeft = parseFloat(styles.paddingLeft) || 0;
			var x = tabRect.left - rootRect.left - padLeft;
			var w = tabRect.width;

			root.style.setProperty('--xciv-tab-x', x + 'px');
			root.style.setProperty('--xciv-tab-w', w + 'px');
			if (!animate || reduceMotion) {
				return;
			}
		}

		function syncRiderVisibility(mode) {
			if (!riderRoot) {
				return;
			}
			var show = mode === 'delivery';
			riderRoot.hidden = !show;
			riderRoot.setAttribute('aria-hidden', show ? 'false' : 'true');
		}

		function setActive(mode, animate) {
			var activeTab = null;

			tabs.forEach(function (tab) {
				var active = tab.getAttribute('data-xciv-fulfillment') === mode;
				tab.classList.toggle('is-active', active);
				tab.setAttribute('aria-selected', active ? 'true' : 'false');
				if (active) {
					activeTab = tab;
				}
			});

			moveThumb(activeTab, animate !== false);
			document.documentElement.setAttribute('data-xciv-fulfillment', mode);
			syncRiderVisibility(mode);

			try {
				window.localStorage.setItem(storageKey, mode);
			} catch (e) {
				/* ignore */
			}

			window.dispatchEvent(
				new CustomEvent('xciv:fulfillment', {
					detail: { mode: mode },
				})
			);
		}

		var saved = null;
		try {
			saved = window.localStorage.getItem(storageKey);
		} catch (e) {
			saved = null;
		}

		var initial = saved === 'delivery' || saved === 'pickup' ? saved : 'delivery';
		setActive(initial, false);

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				setActive(tab.getAttribute('data-xciv-fulfillment') || 'delivery', true);
			});
		});

		window.addEventListener('resize', function () {
			var current = root.querySelector('.xciv-header-tabs__tab.is-active');
			moveThumb(current, false);
		});
	}

	function initRiderModes() {
		var root = document.querySelector('[data-xciv-rider-modes]');
		if (!root) {
			return;
		}

		var buttons = Array.prototype.slice.call(root.querySelectorAll('[data-xciv-rider]'));
		var hint = root.querySelector('[data-xciv-rider-hint]');
		var configEl = root.querySelector('[data-xciv-rider-config]');
		var storageKey = 'xciv_rider_mode';
		var config = { default: 'car', modes: {}, limits: {} };

		try {
			if (configEl && configEl.textContent) {
				config = Object.assign(config, JSON.parse(configEl.textContent));
			}
		} catch (e) {
			/* ignore */
		}

		function applyMode(mode, persist) {
			if (!config.modes[mode]) {
				mode = config.default || Object.keys(config.modes)[0] || 'car';
			}

			buttons.forEach(function (btn) {
				var active = btn.getAttribute('data-xciv-rider') === mode;
				btn.classList.toggle('is-active', active);
				btn.setAttribute('aria-pressed', active ? 'true' : 'false');
			});

			document.documentElement.setAttribute('data-xciv-rider', mode);

			if (hint && config.modes[mode]) {
				hint.textContent = config.modes[mode].hint || '';
			}

			if (persist !== false) {
				try {
					window.localStorage.setItem(storageKey, mode);
				} catch (e) {
					/* ignore */
				}
			}

			window.dispatchEvent(
				new CustomEvent('xciv:rider', {
					detail: {
						mode: mode,
						limits: (config.limits && config.limits[mode]) || {},
					},
				})
			);
		}

		var saved = null;
		try {
			saved = window.localStorage.getItem(storageKey);
		} catch (e) {
			saved = null;
		}

		applyMode(saved || config.default || 'car', false);

		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				applyMode(btn.getAttribute('data-xciv-rider') || 'car', true);
			});
		});
	}

	function initProfileMenu() {
		var roots = Array.prototype.slice.call(document.querySelectorAll('[data-xciv-header-profile]'));
		if (!roots.length) {
			return;
		}

		function closeMenu(root) {
			var trigger = root.querySelector('[data-xciv-header-profile-trigger]');
			var menu = root.querySelector('[data-xciv-header-profile-menu]');
			if (!trigger || !menu) {
				return;
			}
			root.classList.remove('is-open');
			trigger.setAttribute('aria-expanded', 'false');
			menu.hidden = true;
		}

		function openMenu(root) {
			var trigger = root.querySelector('[data-xciv-header-profile-trigger]');
			var menu = root.querySelector('[data-xciv-header-profile-menu]');
			if (!trigger || !menu) {
				return;
			}
			roots.forEach(function (other) {
				if (other !== root) {
					closeMenu(other);
				}
			});
			root.classList.add('is-open');
			trigger.setAttribute('aria-expanded', 'true');
			menu.hidden = false;
		}

		roots.forEach(function (root) {
			var trigger = root.querySelector('[data-xciv-header-profile-trigger]');
			if (!trigger) {
				return;
			}

			trigger.addEventListener('click', function (event) {
				event.preventDefault();
				event.stopPropagation();
				if (root.classList.contains('is-open')) {
					closeMenu(root);
				} else {
					openMenu(root);
				}
			});
		});

		document.addEventListener('click', function (event) {
			roots.forEach(function (root) {
				if (!root.contains(event.target)) {
					closeMenu(root);
				}
			});
		});

		document.addEventListener('keydown', function (event) {
			if (event.key !== 'Escape') {
				return;
			}
			roots.forEach(closeMenu);
		});
	}

	initFulfillmentTabs();
	initRiderModes();
	initProfileMenu();
})();
