(() => {
	const feed = document.querySelector('[data-xciv-feed]');
	if (!feed) return;

	const state = {
		mode: 'live',
		cat: 'all',
		filter: 'all',
		q: '',
	};

	const cards = () => [...feed.querySelectorAll('[data-xciv-card]')];

	const matches = (card) => {
		const isVenue = card.hasAttribute('data-xciv-venue');
		const live = card.getAttribute('data-live') === '1';
		const status = card.getAttribute('data-status') || '';
		const service = card.getAttribute('data-service') || '';
		const sms = card.getAttribute('data-sms') === '1';
		const search = (card.getAttribute('data-search') || '').toLowerCase();

		if (state.cat !== 'all' && service !== state.cat) return false;
		if (state.q && !search.includes(state.q)) return false;

		// Venue store cards stay visible across Live/Past; only cat + search apply.
		if (isVenue) {
			if (state.filter === 'ready' || state.filter === 'sms' || state.filter === 'completed') {
				return false;
			}
			return true;
		}

		if (state.mode === 'live' && !live) return false;
		if (state.mode === 'past' && live) return false;

		if (state.filter === 'live' && !live) return false;
		if (state.filter === 'ready' && status !== 'ready') return false;
		if (state.filter === 'sms' && !sms) return false;
		if (state.filter === 'completed' && status !== 'completed') return false;

		return true;
	};

	const apply = () => {
		cards().forEach((card) => {
			card.hidden = !matches(card);
		});

		feed.querySelectorAll('[data-xciv-section]').forEach((section) => {
			const sectionCards = [...section.querySelectorAll('[data-xciv-card]')];
			// Panel-only sections (e.g. shop booking/services) have no cards — keep them visible.
			if (!sectionCards.length) {
				section.hidden = false;
				return;
			}
			section.hidden = !sectionCards.some((c) => !c.hidden);
		});
	};

	feed.querySelectorAll('[data-xciv-mode]').forEach((btn) => {
		btn.addEventListener('click', () => {
			state.mode = btn.getAttribute('data-xciv-mode') || 'live';
			feed.querySelectorAll('[data-xciv-mode]').forEach((b) => b.classList.toggle('is-active', b === btn));
			apply();
		});
	});

	feed.querySelectorAll('[data-xciv-cat]').forEach((btn) => {
		btn.addEventListener('click', () => {
			state.cat = btn.getAttribute('data-xciv-cat') || 'all';
			feed.querySelectorAll('[data-xciv-cat]').forEach((b) => b.classList.toggle('is-active', b === btn));
			apply();
		});
	});

	feed.querySelectorAll('[data-xciv-filter]').forEach((btn) => {
		btn.addEventListener('click', () => {
			state.filter = btn.getAttribute('data-xciv-filter') || 'all';
			feed.querySelectorAll('[data-xciv-filter]').forEach((b) => b.classList.toggle('is-active', b === btn));
			apply();
		});
	});

	const search = feed.querySelector('[data-xciv-search]');
	if (search) {
		search.addEventListener('input', () => {
			state.q = (search.value || '').trim().toLowerCase();
			apply();
		});
	}

	feed.querySelectorAll('.xciv-feed__section').forEach((section) => {
		const carousel = section.querySelector('[data-xciv-carousel]');
		if (!carousel) return;
		section.querySelectorAll('[data-xciv-scroll]').forEach((btn) => {
			btn.addEventListener('click', () => {
				const dir = btn.getAttribute('data-xciv-scroll') === 'next' ? 1 : -1;
				carousel.scrollBy({ left: dir * Math.max(260, carousel.clientWidth * 0.8), behavior: 'smooth' });
			});
		});
	});

	apply();
})();
