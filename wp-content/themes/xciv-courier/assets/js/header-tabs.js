(function () {
	'use strict';

	function haversineMiles(lat1, lng1, lat2, lng2) {
		var toRad = Math.PI / 180;
		var dLat = (lat2 - lat1) * toRad;
		var dLng = (lng2 - lng1) * toRad;
		var a =
			Math.sin(dLat / 2) * Math.sin(dLat / 2) +
			Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
		return 3958.8 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
	}

	function nearestHubId(hubs, lat, lng) {
		var bestId = null;
		var bestDist = Infinity;
		Object.keys(hubs || {}).forEach(function (id) {
			var hub = hubs[id];
			if (!hub || hub.lat == null || hub.lng == null) {
				return;
			}
			var d = haversineMiles(lat, lng, Number(hub.lat), Number(hub.lng));
			if (d < bestDist) {
				bestDist = d;
				bestId = id;
			}
		});
		return bestId;
	}

	function initFulfillmentTabs() {
		var root = document.querySelector('[data-xciv-header-tabs]');
		if (!root) {
			return;
		}

		var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-xciv-fulfillment]'));
		var thumb = root.querySelector('[data-xciv-header-thumb]');
		var riderRoot = document.querySelector('[data-xciv-rider-modes]');
		var hubRoot = document.querySelector('[data-xciv-hub-modes]');
		var storageKey = 'xciv_fulfillment_mode';
		var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		var hubConfig = { defaultMode: 'delivery' };

		if (hubRoot) {
			var hubConfigEl = hubRoot.querySelector('[data-xciv-hub-config]');
			try {
				if (hubConfigEl && hubConfigEl.textContent) {
					hubConfig = Object.assign(hubConfig, JSON.parse(hubConfigEl.textContent));
				}
			} catch (e) {
				/* ignore */
			}
		}

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

		function syncModePanels(mode) {
			var isDelivery = mode === 'delivery';
			if (riderRoot) {
				riderRoot.hidden = !isDelivery;
				riderRoot.setAttribute('aria-hidden', isDelivery ? 'false' : 'true');
			}
			if (hubRoot) {
				hubRoot.hidden = isDelivery;
				hubRoot.setAttribute('aria-hidden', isDelivery ? 'true' : 'false');
			}
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
			syncModePanels(mode);

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

		var initial =
			saved === 'delivery' || saved === 'pickup'
				? saved
				: hubConfig.defaultMode === 'pickup'
					? 'pickup'
					: 'delivery';
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

	function initHubModes() {
		var root = document.querySelector('[data-xciv-hub-modes]');
		if (!root) {
			return;
		}

		var buttons = Array.prototype.slice.call(root.querySelectorAll('[data-xciv-hub]'));
		var locateBtn = root.querySelector('[data-xciv-hub-locate]');
		var hint = root.querySelector('[data-xciv-hub-hint]');
		var configEl = root.querySelector('[data-xciv-hub-config]');
		var storageKey = 'xciv_pickup_hub';
		var config = { defaultHub: 'ucity', hubs: {}, i18n: {} };

		try {
			if (configEl && configEl.textContent) {
				config = Object.assign(config, JSON.parse(configEl.textContent));
			}
		} catch (e) {
			/* ignore */
		}

		function applyHub(hubId, persist, extra) {
			if (!config.hubs[hubId]) {
				hubId = config.defaultHub || Object.keys(config.hubs)[0] || '';
			}
			if (!hubId || !config.hubs[hubId]) {
				return;
			}

			var hub = config.hubs[hubId];

			buttons.forEach(function (btn) {
				var active = btn.getAttribute('data-xciv-hub') === hubId;
				btn.classList.toggle('is-active', active);
				btn.setAttribute('aria-pressed', active ? 'true' : 'false');
			});

			document.documentElement.setAttribute('data-xciv-hub', hubId);

			if (hint) {
				hint.textContent = hub.label || hubId;
			}

			if (persist !== false) {
				try {
					window.localStorage.setItem(storageKey, hubId);
				} catch (e) {
					/* ignore */
				}
			}

			window.dispatchEvent(
				new CustomEvent('xciv:hub', {
					detail: Object.assign(
						{
							hubId: hubId,
							hub: hub,
						},
						extra || {}
					),
				})
			);
		}

		var saved = null;
		try {
			saved = window.localStorage.getItem(storageKey);
		} catch (e) {
			saved = null;
		}

		applyHub(saved || config.defaultHub, false);

		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				applyHub(btn.getAttribute('data-xciv-hub') || config.defaultHub, true);
			});
		});

		if (locateBtn) {
			if (!navigator.geolocation) {
				locateBtn.hidden = true;
			} else {
				locateBtn.addEventListener('click', function () {
					locateBtn.classList.add('is-busy');
					locateBtn.disabled = true;
					navigator.geolocation.getCurrentPosition(
						function (pos) {
							locateBtn.classList.remove('is-busy');
							locateBtn.disabled = false;
							var id = nearestHubId(config.hubs, pos.coords.latitude, pos.coords.longitude);
							if (id) {
								applyHub(id, true, {
									fromGeolocation: true,
									lat: pos.coords.latitude,
									lng: pos.coords.longitude,
								});
							}
						},
						function () {
							locateBtn.classList.remove('is-busy');
							locateBtn.disabled = false;
							if (hint && config.i18n && config.i18n.locateFail) {
								hint.textContent = config.i18n.locateFail;
							}
						},
						{ enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 }
					);
				});
			}
		}
	}

	function initHeaderMenus() {
		var roots = Array.prototype.slice.call(
			document.querySelectorAll('[data-xciv-header-profile], [data-xciv-product-switcher]')
		);
		if (!roots.length) {
			return;
		}

		function parts(root) {
			return {
				trigger: root.querySelector(
					'[data-xciv-header-profile-trigger], [data-xciv-product-switcher-trigger]'
				),
				menu: root.querySelector(
					'[data-xciv-header-profile-menu], [data-xciv-product-switcher-menu]'
				),
			};
		}

		function closeMenu(root) {
			var els = parts(root);
			if (!els.trigger || !els.menu) {
				return;
			}
			root.classList.remove('is-open');
			els.trigger.setAttribute('aria-expanded', 'false');
			els.menu.hidden = true;
		}

		function openMenu(root) {
			var els = parts(root);
			if (!els.trigger || !els.menu) {
				return;
			}
			roots.forEach(function (other) {
				if (other !== root) {
					closeMenu(other);
				}
			});
			root.classList.add('is-open');
			els.trigger.setAttribute('aria-expanded', 'true');
			els.menu.hidden = false;
		}

		roots.forEach(function (root) {
			var els = parts(root);
			if (!els.trigger) {
				return;
			}

			els.trigger.addEventListener('click', function (event) {
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
	initHubModes();
	initHeaderMenus();
})();
