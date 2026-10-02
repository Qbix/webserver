/**
 * Metrics.ScrollTracker — Section-aware scroll telemetry
 *
 * Tracks which sections a user reads, how far they scroll,
 * and what they click. Delegates transport, session, visibility,
 * and unload handling to the core Metrics object.
 *
 * Usage (standalone):
 *   Metrics.init({ endpoint: '/Q/metrics', page: 'My Page' });
 *   Metrics.ScrollTracker.init({
 *     sections: 'h2[id], h3[id]',
 *     debounce: 1000
 *   });
 *
 * Usage (with Q framework):
 *   // Auto-initializes from config if endpoint is set
 *
 * @module Metrics
 * @class Metrics.ScrollTracker
 */
"use strict";
(function (root) {

var Metrics = root.Metrics;
if (!Metrics) {
	console.warn('Metrics.ScrollTracker: Metrics core not loaded');
	return;
}

var defaults = {
	// CSS selector for sections to track
	sections: 'h2[id], h3[id], section[id], [data-section]',

	// Minimum pixel height for auto-detected containers
	minSectionHeight: 100,

	// Milliseconds to wait after scroll stops before firing
	debounce: 1000,

	// Milliseconds to wait on page load (ignores scroll restoration)
	initDelay: 800,

	// Milliseconds to suppress tracking after anchor click
	anchorCooldown: 1500,

	// Scroll depth milestones (percentage)
	depthMilestones: [25, 50, 75, 100],

	// Max px above viewport top to consider a section "current"
	sectionLookback: 300,

	// Scroll considered settled if moved less than this (px)
	settleTolerance: 2,

	// Recheck interval when not settled (ms)
	recheckInterval: 500,

	// Track link/anchor clicks
	trackClicks: true,

	// Visual TOC highlighting selector (real-time, not debounced)
	tocSelector: null,
	tocActiveClass: 'active',
	tocSectionSelector: 'h2[id]'
};

// ── State ──
var state = {
	initialized: false,
	options: null,
	sections: [],
	tocSections: [],
	seen: {},
	depthHit: {},
	scrollTimer: null,
	anchorCooling: false,
	_prevY: -1
};

// ── Section Discovery ──

function discoverSections(selector) {
	var elements = document.querySelectorAll(selector);
	var result = [];
	var ordinal = 0;
	var minH = (state.options && state.options.minSectionHeight) || 0;

	for (var i = 0; i < elements.length; i++) {
		var el = elements[i];
		if (el.offsetHeight < minH) continue;

		if (!el.id) {
			var text = (el.textContent || '').trim().slice(0, 60);
			var slug = text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
			el.id = slug || ('section-' + ordinal);
		}

		result.push({
			el: el,
			id: el.id,
			tag: el.tagName.toLowerCase(),
			ordinal: ordinal++,
			snippet: (el.textContent || '').trim().slice(0, 80)
		});
	}
	return result;
}

// ── Find Current Section ──

function findCurrentSection() {
	var scrollY = window.scrollY || window.pageYOffset;
	var opts = state.options;
	var best = null;
	var bestDist = Infinity;

	for (var i = 0; i < state.sections.length; i++) {
		var sec = state.sections[i];
		var top = sec.el.offsetTop;
		if (top <= scrollY + opts.sectionLookback) {
			var dist = Math.abs(top - scrollY - 180);
			if (dist < bestDist) {
				bestDist = dist;
				best = sec;
			}
		}
	}
	return best;
}

// ── Scroll Settle Detection ──

function onScrollSettle() {
	var opts = state.options;
	var curY = window.scrollY || window.pageYOffset;

	if (Math.abs(curY - state._prevY) > opts.settleTolerance) {
		state._prevY = curY;
		state.scrollTimer = setTimeout(onScrollSettle, opts.recheckInterval);
		return;
	}

	// Settled — report section
	var current = findCurrentSection();
	if (current && !state.seen[current.id]) {
		state.seen[current.id] = true;
		Metrics.send('section:' + current.id, {
			tag: current.tag,
			ordinal: current.ordinal,
			snippet: current.snippet
		});
	}

	// Report depth
	var docH = document.documentElement.scrollHeight - window.innerHeight;
	if (docH > 0) {
		var pct = Math.round((curY / docH) * 100);
		for (var j = 0; j < opts.depthMilestones.length; j++) {
			var m = opts.depthMilestones[j];
			if (pct >= m && !state.depthHit[m]) {
				state.depthHit[m] = true;
				Metrics.send('depth:' + m + '%');
			}
		}
	}
}

function onScroll() {
	if (state.anchorCooling) return;
	clearTimeout(state.scrollTimer);
	state._prevY = window.scrollY || window.pageYOffset;
	state.scrollTimer = setTimeout(onScrollSettle, state.options.debounce);
}

// ── TOC Highlighting (real-time) ──

function updateTocHighlight() {
	var opts = state.options;
	if (!opts.tocSelector) return;

	var scrollY = window.scrollY || window.pageYOffset;
	var currentId = '';

	for (var i = 0; i < state.tocSections.length; i++) {
		if (scrollY >= state.tocSections[i].el.offsetTop - 160) {
			currentId = state.tocSections[i].id;
		}
	}

	var links = document.querySelectorAll(opts.tocSelector);
	for (var j = 0; j < links.length; j++) {
		links[j].classList.remove(opts.tocActiveClass);
		if (links[j].getAttribute('href') === '#' + currentId) {
			links[j].classList.add(opts.tocActiveClass);
		}
	}
}

// ── Click Tracking ──

function onDocumentClick(e) {
	var a = e.target.closest('a[href]');
	if (!a) return;

	var href = a.getAttribute('href') || '';

	if (href.charAt(0) === '#') {
		// Anchor click — cooldown to suppress scroll tracking
		state.anchorCooling = true;
		setTimeout(function () { state.anchorCooling = false; },
			state.options.anchorCooldown);
		Metrics.send('anchor:' + href.slice(1));
		return;
	}

	// External link
	var label = a.dataset.track || 'link:' + href;
	Metrics.send(label);
}

// ── Pre-mark Initial State ──

function premarkInitialState() {
	var scrollY = window.scrollY || window.pageYOffset;

	for (var i = 0; i < state.sections.length; i++) {
		var rect = state.sections[i].el.getBoundingClientRect();
		if (rect.top >= -100 && rect.top < window.innerHeight) {
			state.seen[state.sections[i].id] = true;
		}
	}

	var docH = document.documentElement.scrollHeight - window.innerHeight;
	if (docH > 0) {
		var pct = Math.round((scrollY / docH) * 100);
		for (var j = 0; j < state.options.depthMilestones.length; j++) {
			var m = state.options.depthMilestones[j];
			if (pct >= m) state.depthHit[m] = true;
		}
	}
}

// ── Public API ──

Metrics.ScrollTracker = {

	init: function (options) {
		if (state.initialized) {
			console.warn('Metrics.ScrollTracker already initialized');
			return this;
		}

		var opts = {};
		var k;
		for (k in defaults) { if (defaults.hasOwnProperty(k)) opts[k] = defaults[k]; }
		for (k in (options || {})) { if (options.hasOwnProperty(k) && options[k] !== undefined) opts[k] = options[k]; }
		state.options = opts;
		state.initialized = true;

		// If Metrics core hasn't been initialized yet with an endpoint,
		// initialize it now from our options
		if (!Metrics._endpoint && options && options.endpoint) {
			Metrics.init({
				endpoint: options.endpoint,
				page: options.page,
				sessionKey: options.sessionKey,
				sessionId: options.sessionId,
				extra: options.extra,
				trackUnload: options.trackUnload
			});
		}

		// Delayed init — let scroll restoration settle
		setTimeout(function () {
			state.sections = discoverSections(opts.sections);

			if (opts.tocSelector && opts.tocSectionSelector) {
				state.tocSections = discoverSections(opts.tocSectionSelector);
			}

			premarkInitialState();

			window.addEventListener('scroll', onScroll, { passive: true });

			if (opts.tocSelector) {
				window.addEventListener('scroll', updateTocHighlight, { passive: true });
				updateTocHighlight();
			}
		}, opts.initDelay);

		if (opts.trackClicks) {
			document.addEventListener('click', onDocumentClick);
		}

		// Listen for SPA navigations to reset tracking
		if (Metrics.onSpaNav) {
			Metrics.onSpaNav(function () {
				// Delay reset to let new DOM settle
				setTimeout(function () {
					Metrics.ScrollTracker.reset();
				}, opts.initDelay);
			}, 'Metrics.ScrollTracker');
		}

		return this;
	},

	send: function (label, data) { Metrics.send(label, data); },
	markSeen: function (id) { state.seen[id] = true; },
	getSessionId: function () { return Metrics.getSessionId(); },
	getSections: function () {
		return state.sections.map(function (s) {
			return { id: s.id, tag: s.tag, ordinal: s.ordinal, snippet: s.snippet };
		});
	},
	getSeen: function () {
		var copy = {};
		for (var k in state.seen) copy[k] = true;
		return copy;
	},
	reset: function () {
		state.seen = {};
		state.depthHit = {};
		state.anchorCooling = false;
		clearTimeout(state.scrollTimer);
		state.sections = discoverSections(state.options.sections);
		premarkInitialState();
	},
	destroy: function () {
		window.removeEventListener('scroll', onScroll);
		window.removeEventListener('scroll', updateTocHighlight);
		document.removeEventListener('click', onDocumentClick);
		clearTimeout(state.scrollTimer);
		state.initialized = false;
	},

	defaults: defaults,
	state: state
};

})(typeof window !== 'undefined' ? window : this);
