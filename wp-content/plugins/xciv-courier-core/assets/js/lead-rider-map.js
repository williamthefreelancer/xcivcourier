(function () {
	'use strict';

	var cfg = window.xcivLeadRiderMap || {};
	var milesToMeters = 1609.344;

	function qs(root, sel) {
		return root.querySelector(sel);
	}

	function activeRiderMode() {
		return (
			document.documentElement.getAttribute('data-xciv-rider') ||
			cfg.riderMode ||
			'car'
		);
	}

	function radiusForMode(mode) {
		var radii = cfg.radii || {};
		return Number(radii[mode] || radii.car || 18);
	}

	function freeRadius() {
		return Number((cfg.radii && cfg.radii.free) || 10);
	}

	function venuePopup(v) {
		var miles = v.miles ? ' · ' + v.miles + ' mi' : '';
		return '<strong>' + escapeHtml(v.name || '') + '</strong><br>' + escapeHtml(v.address || v.area || '') + miles;
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function circleGeoJSON(lng, lat, radiusMeters, steps) {
		steps = steps || 64;
		var coords = [];
		var earth = 6378137;
		var i;
		for (i = 0; i <= steps; i += 1) {
			var bearing = (i / steps) * 2 * Math.PI;
			var lat1 = (lat * Math.PI) / 180;
			var lng1 = (lng * Math.PI) / 180;
			var angDist = radiusMeters / earth;
			var lat2 = Math.asin(
				Math.sin(lat1) * Math.cos(angDist) +
					Math.cos(lat1) * Math.sin(angDist) * Math.cos(bearing)
			);
			var lng2 =
				lng1 +
				Math.atan2(
					Math.sin(bearing) * Math.sin(angDist) * Math.cos(lat1),
					Math.cos(angDist) - Math.sin(lat1) * Math.sin(lat2)
				);
			coords.push([(lng2 * 180) / Math.PI, (lat2 * 180) / Math.PI]);
		}
		return {
			type: 'Feature',
			geometry: { type: 'Polygon', coordinates: [coords] },
		};
	}

	function geocodeAddress(address) {
		if (!address) {
			return Promise.resolve(null);
		}
		if (cfg.provider === 'mapbox' && cfg.token) {
			var url =
				'https://api.mapbox.com/geocoding/v5/mapbox.places/' +
				encodeURIComponent(address) +
				'.json?access_token=' +
				encodeURIComponent(cfg.token) +
				'&limit=1&country=US';
			return fetch(url)
				.then(function (r) {
					return r.json();
				})
				.then(function (data) {
					var f = data && data.features && data.features[0];
					if (!f || !f.center) {
						return null;
					}
					return { lng: f.center[0], lat: f.center[1] };
				})
				.catch(function () {
					return null;
				});
		}

		var nominatim =
			'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' +
			encodeURIComponent(address);
		return fetch(nominatim, {
			headers: { Accept: 'application/json' },
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (data) {
				if (!data || !data[0]) {
					return null;
				}
				return { lng: parseFloat(data[0].lon), lat: parseFloat(data[0].lat) };
			})
			.catch(function () {
				return null;
			});
	}

	function resolveVenues(venues) {
		venues = venues || [];
		return Promise.all(
			venues.map(function (v) {
				if (v.lat != null && v.lng != null) {
					return Promise.resolve(v);
				}
				return geocodeAddress(v.address || v.name).then(function (pt) {
					if (!pt) {
						return v;
					}
					v.lat = pt.lat;
					v.lng = pt.lng;
					return v;
				});
			})
		);
	}

	function initMapbox(root, canvas, venues) {
		if (!window.mapboxgl) {
			return null;
		}
		mapboxgl.accessToken = cfg.token;
		var map = new mapboxgl.Map({
			container: canvas,
			style: cfg.style || 'mapbox://styles/mapbox/dark-v11',
			center: cfg.center || [-80.744, 35.312],
			zoom: cfg.zoom || 11,
		});
		map.addControl(new mapboxgl.NavigationControl({ visualizePitch: false }), 'top-right');

		var markers = [];
		var riderMarker = null;
		var trackRiderMarker = null;
		var trackTargetMarker = null;
		var trackLine = null;

		function clearTrackOverlays() {
			if (trackRiderMarker) {
				trackRiderMarker.remove();
				trackRiderMarker = null;
			}
			if (trackTargetMarker) {
				trackTargetMarker.remove();
				trackTargetMarker = null;
			}
			if (map.getLayer && map.getLayer('xciv-track-line')) {
				map.removeLayer('xciv-track-line');
			}
			if (map.getSource && map.getSource('xciv-track-line')) {
				map.removeSource('xciv-track-line');
			}
			trackLine = null;
		}

		function onTrackListing(ev) {
			var detail = (ev && ev.detail) || {};
			var venue = detail.venue;
			var pos = detail.position;
			if (!venue || !pos) {
				clearTrackOverlays();
				return;
			}

			var destLng = venue.lng != null ? Number(venue.lng) : pos.lng;
			var destLat = venue.lat != null ? Number(venue.lat) : pos.lat;
			var origin = detail.origin || { lng: cfg.center[0], lat: cfg.center[1] };

			if (!trackRiderMarker) {
				var el = document.createElement('div');
				el.className = 'xciv-map-pin xciv-map-pin--rider';
				el.title = detail.riderName || 'Lead rider';
				trackRiderMarker = new mapboxgl.Marker({ element: el })
					.setLngLat([pos.lng, pos.lat])
					.setPopup(
						new mapboxgl.Popup({ offset: 12 }).setHTML(
							'<strong>' +
								escapeHtml(detail.riderName || 'Lead rider') +
								'</strong><br>' +
								escapeHtml(venue.name || '')
						)
					)
					.addTo(map);
			} else {
				trackRiderMarker.setLngLat([pos.lng, pos.lat]);
			}

			if (!trackTargetMarker) {
				var tel = document.createElement('div');
				tel.className = 'xciv-map-pin xciv-map-pin--target';
				tel.title = venue.name || 'Listing';
				trackTargetMarker = new mapboxgl.Marker({ element: tel })
					.setLngLat([destLng, destLat])
					.addTo(map);
			} else {
				trackTargetMarker.setLngLat([destLng, destLat]);
			}

			var lineData = {
				type: 'Feature',
				geometry: {
					type: 'LineString',
					coordinates: [
						[origin.lng, origin.lat],
						[pos.lng, pos.lat],
						[destLng, destLat],
					],
				},
			};
			if (map.getSource('xciv-track-line')) {
				map.getSource('xciv-track-line').setData(lineData);
			} else if (map.isStyleLoaded && map.isStyleLoaded()) {
				map.addSource('xciv-track-line', { type: 'geojson', data: lineData });
				map.addLayer({
					id: 'xciv-track-line',
					type: 'line',
					source: 'xciv-track-line',
					paint: {
						'line-color': '#c4a35a',
						'line-width': 2.5,
						'line-opacity': 0.85,
						'line-dasharray': [1.5, 1.5],
					},
				});
			}
			trackLine = lineData;

			map.fitBounds(
				[
					[Math.min(origin.lng, pos.lng, destLng), Math.min(origin.lat, pos.lat, destLat)],
					[Math.max(origin.lng, pos.lng, destLng), Math.max(origin.lat, pos.lat, destLat)],
				],
				{ padding: 56, maxZoom: 13, duration: 600 }
			);
		}

		window.addEventListener('xciv:track-listing', onTrackListing);
		root._xcivClearTrack = clearTrackOverlays;

		function clearRadiusLayers() {
			['xciv-range', 'xciv-free'].forEach(function (id) {
				if (map.getLayer(id)) {
					map.removeLayer(id);
				}
				if (map.getSource(id)) {
					map.removeSource(id);
				}
			});
		}

		function drawRadii(center) {
			if (!cfg.showRadius || !center) {
				return;
			}
			clearRadiusLayers();
			var mode = activeRiderMode();
			var rangeM = radiusForMode(mode) * milesToMeters;
			var freeM = freeRadius() * milesToMeters;
			map.addSource('xciv-range', {
				type: 'geojson',
				data: circleGeoJSON(center[0], center[1], rangeM),
			});
			map.addLayer({
				id: 'xciv-range',
				type: 'fill',
				source: 'xciv-range',
				paint: { 'fill-color': '#c4a35a', 'fill-opacity': 0.12 },
			});
			map.addSource('xciv-free', {
				type: 'geojson',
				data: circleGeoJSON(center[0], center[1], freeM),
			});
			map.addLayer({
				id: 'xciv-free',
				type: 'line',
				source: 'xciv-free',
				paint: { 'line-color': '#7dcea0', 'line-width': 2, 'line-opacity': 0.7 },
			});
		}

		map.on('load', function () {
			var loading = qs(canvas, '.xciv-lead-map__loading');
			if (loading) {
				loading.remove();
			}

			var hubs = cfg.hubs || [];
			var hubCenter = cfg.center;

			if (cfg.showHubs) {
				hubs.forEach(function (hub) {
					var el = document.createElement('div');
					el.className = 'xciv-map-pin xciv-map-pin--hub';
					el.title = hub.label || hub.zip || 'Hub';
					new mapboxgl.Marker({ element: el })
						.setLngLat([hub.lng, hub.lat])
						.setPopup(
							new mapboxgl.Popup({ offset: 12 }).setHTML(
								'<strong>' +
									escapeHtml(hub.label || '') +
									'</strong><br>ZIP ' +
									escapeHtml(hub.zip || '')
							)
						)
						.addTo(map);
				});
				if (hubs[0]) {
					hubCenter = [hubs[0].lng, hubs[0].lat];
				}
			}

			if (cfg.showVenues) {
				venues.forEach(function (v) {
					if (v.lat == null || v.lng == null) {
						return;
					}
					var el = document.createElement('div');
					el.className = 'xciv-map-pin xciv-map-pin--venue';
					var marker = new mapboxgl.Marker({ element: el })
						.setLngLat([v.lng, v.lat])
						.setPopup(new mapboxgl.Popup({ offset: 12 }).setHTML(venuePopup(v)))
						.addTo(map);
					markers.push(marker);
				});
			}

			drawRadii(hubCenter);

			window.addEventListener('xciv:rider', function () {
				drawRadii(hubCenter);
			});

			window.addEventListener('xciv:hub', function (event) {
				var hub = event.detail && event.detail.hub;
				if (!hub || hub.lat == null || hub.lng == null) {
					return;
				}
				hubCenter = [hub.lng, hub.lat];
				map.flyTo({ center: hubCenter, zoom: Math.max(map.getZoom(), 11) });
				drawRadii(hubCenter);
			});
		});

		var locateBtn = qs(root, '[data-xciv-map-locate]');
		if (locateBtn && navigator.geolocation) {
			locateBtn.addEventListener('click', function () {
				navigator.geolocation.getCurrentPosition(function (pos) {
					var lng = pos.coords.longitude;
					var lat = pos.coords.latitude;
					if (riderMarker) {
						riderMarker.remove();
					}
					var el = document.createElement('div');
					el.className = 'xciv-map-pin xciv-map-pin--me';
					riderMarker = new mapboxgl.Marker({ element: el })
						.setLngLat([lng, lat])
						.addTo(map);
					map.flyTo({ center: [lng, lat], zoom: 12 });
					drawRadii([lng, lat]);
				});
			});
		}

		return map;
	}

	function initLeaflet(root, canvas, venues) {
		if (!window.L) {
			return null;
		}

		if (cfg.leafletImagePath && L.Icon && L.Icon.Default) {
			L.Icon.Default.mergeOptions({
				iconUrl: cfg.leafletImagePath + 'marker-icon.png',
				iconRetinaUrl: cfg.leafletImagePath + 'marker-icon-2x.png',
				shadowUrl: cfg.leafletImagePath + 'marker-shadow.png',
			});
		}

		var loading = qs(canvas, '.xciv-lead-map__loading');
		if (loading) {
			loading.remove();
		}

		var center = cfg.center || [-80.744, 35.312];
		var map = L.map(canvas, {
			scrollWheelZoom: false,
		}).setView([center[1], center[0]], cfg.zoom || 11);

		L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
			attribution:
				'&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; <a href="https://carto.com/">CARTO</a>',
			subdomains: 'abcd',
			maxZoom: 19,
		}).addTo(map);

		var rangeLayer = null;
		var freeLayer = null;
		var meMarker = null;
		var trackRiderMarker = null;
		var trackTargetMarker = null;
		var trackLine = null;
		var radiusCenter = center;

		function clearTrackOverlays() {
			if (trackRiderMarker) {
				map.removeLayer(trackRiderMarker);
				trackRiderMarker = null;
			}
			if (trackTargetMarker) {
				map.removeLayer(trackTargetMarker);
				trackTargetMarker = null;
			}
			if (trackLine) {
				map.removeLayer(trackLine);
				trackLine = null;
			}
		}

		function onTrackListing(ev) {
			var detail = (ev && ev.detail) || {};
			var venue = detail.venue;
			var pos = detail.position;
			if (!venue || !pos) {
				clearTrackOverlays();
				return;
			}

			var destLat = venue.lat != null ? Number(venue.lat) : pos.lat;
			var destLng = venue.lng != null ? Number(venue.lng) : pos.lng;
			var origin = detail.origin || { lat: center[1], lng: center[0] };

			if (!trackRiderMarker) {
				trackRiderMarker = L.circleMarker([pos.lat, pos.lng], {
					radius: 8,
					color: '#f2efe8',
					fillColor: '#c4a35a',
					fillOpacity: 1,
					weight: 2,
					className: 'xciv-map-pin--rider',
				})
					.bindPopup(
						'<strong>' +
							escapeHtml(detail.riderName || 'Lead rider') +
							'</strong><br>' +
							escapeHtml(venue.name || '')
					)
					.addTo(map);
			} else {
				trackRiderMarker.setLatLng([pos.lat, pos.lng]);
			}

			if (!trackTargetMarker) {
				trackTargetMarker = L.circleMarker([destLat, destLng], {
					radius: 7,
					color: '#f2efe8',
					fillColor: '#7dcea0',
					fillOpacity: 1,
					weight: 2,
				}).addTo(map);
			} else {
				trackTargetMarker.setLatLng([destLat, destLng]);
			}

			var latlngs = [
				[origin.lat, origin.lng],
				[pos.lat, pos.lng],
				[destLat, destLng],
			];
			if (!trackLine) {
				trackLine = L.polyline(latlngs, {
					color: '#c4a35a',
					weight: 2.5,
					opacity: 0.85,
					dashArray: '6 6',
				}).addTo(map);
			} else {
				trackLine.setLatLngs(latlngs);
			}

			map.fitBounds(L.latLngBounds(latlngs).pad(0.35), { maxZoom: 13, animate: true });
		}

		window.addEventListener('xciv:track-listing', onTrackListing);
		root._xcivClearTrack = clearTrackOverlays;

		function drawRadii(lngLat) {
			if (!cfg.showRadius) {
				return;
			}
			radiusCenter = lngLat;
			if (rangeLayer) {
				map.removeLayer(rangeLayer);
			}
			if (freeLayer) {
				map.removeLayer(freeLayer);
			}
			var mode = activeRiderMode();
			rangeLayer = L.circle([lngLat[1], lngLat[0]], {
				radius: radiusForMode(mode) * milesToMeters,
				color: '#c4a35a',
				weight: 1,
				fillColor: '#c4a35a',
				fillOpacity: 0.12,
			}).addTo(map);
			freeLayer = L.circle([lngLat[1], lngLat[0]], {
				radius: freeRadius() * milesToMeters,
				color: '#7dcea0',
				weight: 2,
				fillOpacity: 0,
			}).addTo(map);
		}

		if (cfg.showHubs) {
			(cfg.hubs || []).forEach(function (hub) {
				L.circleMarker([hub.lat, hub.lng], {
					radius: 8,
					color: '#c4a35a',
					fillColor: '#c4a35a',
					fillOpacity: 0.95,
					weight: 2,
				})
					.bindPopup(
						'<strong>' +
							escapeHtml(hub.label || '') +
							'</strong><br>ZIP ' +
							escapeHtml(hub.zip || '')
					)
					.addTo(map);
			});
			if (cfg.hubs && cfg.hubs[0]) {
				radiusCenter = [cfg.hubs[0].lng, cfg.hubs[0].lat];
			}
		}

		if (cfg.showVenues) {
			venues.forEach(function (v) {
				if (v.lat == null || v.lng == null) {
					return;
				}
				L.circleMarker([v.lat, v.lng], {
					radius: 6,
					color: '#f2efe8',
					fillColor: '#9aa3ad',
					fillOpacity: 0.95,
					weight: 1,
				})
					.bindPopup(venuePopup(v))
					.addTo(map);
			});
		}

		drawRadii(radiusCenter);

		window.addEventListener('xciv:rider', function () {
			drawRadii(radiusCenter);
		});

		window.addEventListener('xciv:hub', function (event) {
			var hub = event.detail && event.detail.hub;
			if (!hub || hub.lat == null || hub.lng == null) {
				return;
			}
			radiusCenter = [hub.lng, hub.lat];
			map.setView([hub.lat, hub.lng], Math.max(map.getZoom(), 11));
			drawRadii(radiusCenter);
		});

		var locateBtn = qs(root, '[data-xciv-map-locate]');
		if (locateBtn && navigator.geolocation) {
			locateBtn.addEventListener('click', function () {
				navigator.geolocation.getCurrentPosition(function (pos) {
					var lat = pos.coords.latitude;
					var lng = pos.coords.longitude;
					if (meMarker) {
						map.removeLayer(meMarker);
					}
					meMarker = L.circleMarker([lat, lng], {
						radius: 7,
						color: '#fff',
						fillColor: '#3b82f6',
						fillOpacity: 1,
						weight: 2,
					}).addTo(map);
					map.setView([lat, lng], 12);
					drawRadii([lng, lat]);
				});
			});
		}

		setTimeout(function () {
			map.invalidateSize();
		}, 80);

		return map;
	}

	function boot(root) {
		var canvas = qs(root, '[data-xciv-map-canvas]');
		if (!canvas) {
			return;
		}

		resolveVenues(cfg.venues || []).then(function (venues) {
			if (cfg.provider === 'mapbox' && cfg.token && window.mapboxgl) {
				initMapbox(root, canvas, venues);
			} else {
				initLeaflet(root, canvas, venues);
			}
		});
	}

	function initAll() {
		var roots = document.querySelectorAll('[data-xciv-lead-map]');
		Array.prototype.forEach.call(roots, boot);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
})();
