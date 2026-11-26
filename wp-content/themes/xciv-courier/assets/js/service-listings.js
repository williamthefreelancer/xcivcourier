(() => {
	'use strict';

	const cfg = window.xcivServiceListings || {};
	const root = document.querySelector('[data-xciv-listings]');
	if (!root) return;

	const state = {
		mode: 'all', // all | queue | delivery
		cat: 'all',
		filter: 'all',
		q: '',
		activeId: cfg.activeId || '',
		remainMins: null,
		timer: null,
		hubId: '',
	};

	const i18n = cfg.i18n || {};
	const venuesById = {};
	(cfg.venues || []).forEach((v) => {
		if (v && v.id) venuesById[v.id] = v;
	});

	const cards = () => [...root.querySelectorAll('[data-xciv-listing]')];
	const countEl = root.querySelector('[data-xciv-listings-count]');
	const emptyEl = root.querySelector('[data-xciv-listings-empty]');
	const statusEl = root.querySelector('[data-xciv-live-status]');
	const etaEl = root.querySelector('[data-xciv-live-eta]');
	const liveBox = root.querySelector('[data-xciv-listing-live]');
	const mapLabel = root.querySelector('[data-xciv-map-live-label]');

	function hubOrigin() {
		const hubs = cfg.hubs || [];
		if (state.hubId) {
			const match = hubs.find((h) => h && h.id === state.hubId);
			if (match && match.lat != null && match.lng != null) {
				return { lat: Number(match.lat), lng: Number(match.lng) };
			}
		}
		if (hubs[0] && hubs[0].lat != null && hubs[0].lng != null) {
			return { lat: Number(hubs[0].lat), lng: Number(hubs[0].lng) };
		}
		return { lat: 35.312, lng: -80.744 };
	}

	function setListingsMode(mode) {
		state.mode = mode || 'all';
		root.querySelectorAll('[data-xciv-listings-mode]').forEach((b) =>
			b.classList.toggle('is-active', (b.getAttribute('data-xciv-listings-mode') || '') === state.mode)
		);
		applyFilters();
	}

	function syncFromHeaderFulfillment(mode) {
		// Header pickup → queue/pickup listings; delivery → delivery listings.
		if (mode === 'pickup') {
			setListingsMode('queue');
			if (state.filter === 'all') {
				state.filter = 'near';
				root.querySelectorAll('[data-xciv-listings-filter]').forEach((b) =>
					b.classList.toggle(
						'is-active',
						(b.getAttribute('data-xciv-listings-filter') || '') === 'near'
					)
				);
			}
			applyFilters();
			return;
		}
		if (mode === 'delivery') {
			setListingsMode('delivery');
		}
	}

	function formatEta(mins) {
		if (mins == null || Number.isNaN(mins)) return '—';
		mins = Math.max(0, Math.round(mins));
		if (mins <= 0) return i18n.onSite || 'On site';
		if (mins < 60) return `~${mins} min out`;
		const h = Math.floor(mins / 60);
		const m = mins % 60;
		return m === 0 ? `~${h} hr out` : `~${h}h ${m}m out`;
	}

	function matches(card) {
		const service = card.getAttribute('data-service') || '';
		const mode = card.getAttribute('data-mode') || '';
		const kind = card.getAttribute('data-kind') || '';
		const near = card.getAttribute('data-near') === '1';
		const search = (card.getAttribute('data-search') || '').toLowerCase();

		if (state.mode !== 'all' && mode !== state.mode) return false;
		if (state.cat !== 'all' && service !== state.cat) return false;
		if (state.q && !search.includes(state.q)) return false;

		if (state.filter === 'near' && !near) return false;
		if (state.filter === 'fee' && near) return false;
		if (state.filter === 'grocery' && kind !== 'grocery') return false;
		if (state.filter === 'sneakers' && kind !== 'sneakers') return false;
		if (state.filter === 'line' && (kind === 'grocery' || kind === 'sneakers')) return false;

		return true;
	}

	function applyFilters() {
		let visible = 0;
		cards().forEach((card) => {
			const ok = matches(card);
			card.hidden = !ok;
			if (ok) visible += 1;
		});

		if (countEl) {
			const tpl = i18n.results || '%d listings';
			countEl.textContent = tpl.replace('%d', String(visible));
		}
		if (emptyEl) {
			emptyEl.hidden = visible > 0;
		}
	}

	function venueFor(id) {
		return venuesById[id] || null;
	}

	function updateLiveCopy(venue, remain) {
		if (!venue) {
			if (statusEl) statusEl.textContent = i18n.select || 'Select a listing to track live ETA';
			if (etaEl) etaEl.textContent = '—';
			if (liveBox) {
				liveBox.classList.add('xciv-listing-live--idle');
				const pulse = liveBox.querySelector('.xciv-listing-live__pulse');
				if (pulse) pulse.classList.add('is-idle');
			}
			if (mapLabel) {
				mapLabel.textContent = `Tracking ${cfg.riderName || 'William A. Johnson'}`;
			}
			return;
		}

		const modeStatus =
			venue.mode === 'delivery'
				? i18n.delivery || 'Lead delivery · goods en route'
				: i18n.queueSwap || 'Queue swap · holding your place';

		const onSite = remain != null && remain <= 0;
		if (statusEl) {
			statusEl.textContent = onSite
				? i18n.onSite || 'William A. Johnson on site'
				: `${i18n.arriving || 'William A. Johnson en route'} · ${modeStatus}`;
		}
		if (etaEl) etaEl.textContent = formatEta(remain);
		if (liveBox) {
			liveBox.classList.remove('xciv-listing-live--idle');
			const pulse = liveBox.querySelector('.xciv-listing-live__pulse');
			if (pulse) pulse.classList.remove('is-idle');
		}
		if (mapLabel) {
			mapLabel.textContent = onSite
				? `${cfg.riderName || 'William A. Johnson'} · on site`
				: `${cfg.riderName || 'William A. Johnson'} · ${formatEta(remain)}`;
		}

		const cardEta = root.querySelector(
			`[data-xciv-listing="${CSS.escape(venue.id)}"] [data-xciv-card-eta]`
		);
		if (cardEta) cardEta.textContent = formatEta(remain);
	}

	function lerp(a, b, t) {
		return a + (b - a) * t;
	}

	function riderPosition(venue, progress) {
		const hub = hubOrigin();
		const lat = venue.lat != null ? Number(venue.lat) : hub.lat;
		const lng = venue.lng != null ? Number(venue.lng) : hub.lng;
		const t = Math.min(1, Math.max(0, progress));
		return {
			lat: lerp(hub.lat, lat, t),
			lng: lerp(hub.lng, lng, t),
		};
	}

	function dispatchTrack(venue, progress, remain) {
		const pos = venue ? riderPosition(venue, progress) : null;
		window.dispatchEvent(
			new CustomEvent('xciv:track-listing', {
				detail: {
					venue: venue || null,
					riderName: cfg.riderName || 'William A. Johnson',
					position: pos,
					progress,
					remainMins: remain,
					origin: hubOrigin(),
				},
			})
		);
	}

	function stopTracking() {
		if (state.timer) {
			clearInterval(state.timer);
			state.timer = null;
		}
	}

	function startTracking(id) {
		stopTracking();
		state.activeId = id || '';
		const venue = venueFor(state.activeId);

		cards().forEach((card) => {
			card.classList.toggle(
				'is-active',
				card.getAttribute('data-xciv-listing') === state.activeId
			);
		});

		if (!venue) {
			state.remainMins = null;
			updateLiveCopy(null, null);
			dispatchTrack(null, 0, null);
			return;
		}

		const total = Math.max(1, Number(venue.travelMins) || 12);
		// Resume mid-route so the map always feels "live" when opening a listing.
		state.remainMins = Math.max(1, Math.round(total * 0.72));

		const tick = () => {
			const remain = state.remainMins;
			const progress = 1 - remain / total;
			updateLiveCopy(venue, remain);
			dispatchTrack(venue, progress, remain);
			if (remain <= 0) {
				stopTracking();
				return;
			}
			// Advance ~1 simulated minute every poll window.
			state.remainMins = Math.max(0, remain - 1);
		};

		tick();
		state.timer = setInterval(tick, Number(cfg.pollMs) || 4000);
	}

	/* Filters */
	root.querySelectorAll('[data-xciv-listings-mode]').forEach((btn) => {
		btn.addEventListener('click', () => {
			setListingsMode(btn.getAttribute('data-xciv-listings-mode') || 'all');
		});
	});

	window.addEventListener('xciv:fulfillment', (event) => {
		const mode = event.detail && event.detail.mode;
		if (mode) syncFromHeaderFulfillment(mode);
	});

	window.addEventListener('xciv:hub', (event) => {
		const hubId = event.detail && event.detail.hubId;
		if (!hubId) return;
		state.hubId = hubId;
		if (state.activeId) {
			startTracking(state.activeId);
		} else {
			dispatchTrack(null, 0, null);
		}
	});

	// Apply current header state if already set.
	try {
		const ff = document.documentElement.getAttribute('data-xciv-fulfillment');
		if (ff === 'pickup' || ff === 'delivery') {
			syncFromHeaderFulfillment(ff);
		}
		const hubAttr = document.documentElement.getAttribute('data-xciv-hub');
		if (hubAttr) state.hubId = hubAttr;
	} catch (e) {
		/* ignore */
	}

	root.querySelectorAll('[data-xciv-cat]').forEach((btn) => {
		btn.addEventListener('click', () => {
			state.cat = btn.getAttribute('data-xciv-cat') || 'all';
			root.querySelectorAll('[data-xciv-cat]').forEach((b) =>
				b.classList.toggle('is-active', b === btn)
			);
			applyFilters();
		});
	});

	root.querySelectorAll('[data-xciv-listings-filter]').forEach((btn) => {
		btn.addEventListener('click', () => {
			state.filter = btn.getAttribute('data-xciv-listings-filter') || 'all';
			root.querySelectorAll('[data-xciv-listings-filter]').forEach((b) =>
				b.classList.toggle('is-active', b === btn)
			);
			applyFilters();
		});
	});

	const search = root.querySelector('[data-xciv-listings-search], [data-xciv-search]');
	if (search) {
		search.addEventListener('input', () => {
			state.q = (search.value || '').trim().toLowerCase();
			applyFilters();
		});
	}

	/* Listing open — soft-track while map updates; full navigation loads detail template */
	root.querySelectorAll('[data-xciv-listing-open]').forEach((link) => {
		link.addEventListener('click', (e) => {
			const id = link.getAttribute('data-xciv-listing-open') || '';
			if (!id) return;

			// Kick tracking immediately so the map animates before navigation paint.
			startTracking(id);

			const detail = root.querySelector('[data-xciv-listing-detail]');
			const sameListing =
				detail && detail.getAttribute('data-listing-id') === id;

			if (sameListing) {
				e.preventDefault();
				detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			}
			// Otherwise allow normal navigation to ?listing=id for the detail template.
		});
	});

	window.addEventListener('popstate', () => {
		const params = new URLSearchParams(window.location.search);
		const id = params.get('listing') || '';
		startTracking(id);
	});

	applyFilters();
	if (state.activeId) {
		startTracking(state.activeId);
	} else {
		updateLiveCopy(null, null);
	}
})();
