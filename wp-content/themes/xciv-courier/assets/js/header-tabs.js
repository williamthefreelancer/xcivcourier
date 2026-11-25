(function () {
	'use strict';

	function initFulfillmentTabs() {
		var root = document.querySelector('[data-xciv-header-tabs]');
		if (!root) {
			return;
		}

		var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-xciv-fulfillment]'));
		var thumb = root.querySelector('[data-xciv-header-thumb]');
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

			if (!animate || reduceMotion) {
				root.style.setProperty('--xciv-tab-x', x + 'px');
				root.style.setProperty('--xciv-tab-w', w + 'px');
				return;
			}

			root.style.setProperty('--xciv-tab-x', x + 'px');
			root.style.setProperty('--xciv-tab-w', w + 'px');
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

			try {
				window.localStorage.setItem(storageKey, mode);
			} catch (e) {
				/* ignore */
			}
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
	initProfileMenu();
})();
