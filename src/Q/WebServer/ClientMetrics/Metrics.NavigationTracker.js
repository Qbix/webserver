/**
 * Metrics.NavigationTracker — Track how users navigate and explore content
 *
 * Tracks scroll-based sections, dynamic panels (tabs, columns, expandables,
 * contextual menus), and links that scroll into view in navigation containers.
 * Delegates transport, session, visibility, and unload to Metrics core.
 *
 * This is a superset of ScrollTracker. Loading NavigationTracker sets
 * Metrics.ScrollTracker as an alias for backward compatibility, so you
 * only need one or the other.
 *
 * Event prefixes:
 *   section:id       — scroll-based section reached viewport
 *   depth:N%         — scroll depth milestone
 *   anchor:id        — clicked an anchor link
 *   link:url         — clicked an external link
 *   tab:name         — tab switched to
 *   column:name      — column opened/closed
 *   expandable:id    — expandable opened/closed
 *   contextual:id    — contextual menu shown/hidden
 *   contextual-item:action — item selected from contextual menu
 *   nav-link:id      — link scrolled into view in a nav container
 *   opened:prefix:id — first time viewing a dynamic section
 *   switched:prefix:id — returned to previously viewed section
 *   dwell:prefix:id  — time spent in a dynamic section
 *
 * @module Metrics
 * @class Metrics.NavigationTracker
 */
"use strict";
(function (root) {

var Metrics = root.Metrics;
if (!Metrics) {
	console.warn('Metrics.NavigationTracker: Metrics core not loaded');
	return;
}

var defaults = {
	// ── Scroll-based section tracking ──
	sections: 'h2[id], h3[id], section[id], [data-section]',
	minSectionHeight: 100,
	debounce: 1000,
	initDelay: 800,
	anchorCooldown: 1500,
	depthMilestones: [25, 50, 75, 100],
	sectionLookback: 300,
	settleTolerance: 2,
	recheckInterval: 500,

	// ── Dynamic section tracking ──
	observeDom: false,
	observeRoot: null,
	observeSelector: '[data-section], [data-track-section]',
	trackDwell: true,

	// ── Navigation container link tracking ──
	// CSS selector for scrollable nav containers whose links should be
	// tracked as they scroll into view
	navContainers: null, // e.g. '.Q_listing_wrapper, .sidebar-nav'

	// ── Contextual menu tracking ──
	trackContextuals: true,

	// ── Click & TOC ──
	trackClicks: true,
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
	_prevY: -1,
	// Dynamic sections
	dynamicSections: {},
	activeSection: null,
	activeSince: null,
	// DOM observer
	_mutationObserver: null,
	// Nav container observers
	_navObservers: [],
	_navLinksSeen: {}
};

// ── Utilities ──

function slugify(text) {
	return text.toLowerCase()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-|-$/g, '')
		.slice(0, 60);
}

function snippet(el) {
	return (el.textContent || '').trim().slice(0, 80);
}

function now() { return Date.now(); }

// ── Section Discovery ──

/**
 * Extract a meaningful title from an element.
 * For headings: use the heading text.
 * For containers: look for a heading child, aria-label, title attr,
 * data-section-name, or first meaningful text.
 */
function extractTitle(el) {
	var tag = el.tagName.toLowerCase();

	// Headings — just use their text
	if (/^h[1-6]$/.test(tag)) {
		return (el.textContent || '').trim();
	}

	// Explicit name attributes
	var explicit = el.getAttribute('data-section-name')
		|| el.getAttribute('data-section')
		|| el.getAttribute('aria-label')
		|| el.getAttribute('title');
	if (explicit) return explicit.trim();

	// <details> — use <summary> text
	if (tag === 'details') {
		var summary = el.querySelector('summary');
		if (summary) return (summary.textContent || '').trim();
	}

	// Containers — look for first heading child
	var heading = el.querySelector('h1, h2, h3, h4, h5, h6');
	if (heading) return (heading.textContent || '').trim();

	// Look for a title-like element
	var titleEl = el.querySelector('[class*="title"], [class*="header"], legend, caption, label');
	if (titleEl) return (titleEl.textContent || '').trim();

	// Fallback: first 80 chars of text content
	return (el.textContent || '').trim().slice(0, 80);
}

function discoverSections(selector) {
	var elements = document.querySelectorAll(selector);
	var result = [];
	var ordinal = 0;
	var minH = (state.options && state.options.minSectionHeight) || 0;
	var seenIds = {};

	for (var i = 0; i < elements.length; i++) {
		var el = elements[i];
		var tag = el.tagName.toLowerCase();

		// Skip tiny elements (but not headings, which are naturally small)
		if (!/^h[1-6]$/.test(tag) && el.offsetHeight < minH) continue;

		// Skip nested: if this container has a heading that's also matched,
		// skip the container to avoid duplicates
		// UNLESS the container has explicit data-section attr
		if (!/^h[1-6]$/.test(tag)) {
			var childHeading = el.querySelector('h1, h2, h3, h4');
			if (childHeading && elements.length > 0) {
				if (!el.hasAttribute('data-section')
					&& !el.hasAttribute('data-track-section')
					&& !el.hasAttribute('aria-label')) {
					continue;
				}
			}
		}

		// Generate or use ID
		var title = extractTitle(el);
		if (!el.id) {
			var slug = slugify(title) || ('section-' + ordinal);
			if (seenIds[slug]) {
				slug = slug + '-' + ordinal;
			}
			el.id = slug;
		}

		// Avoid duplicate IDs in results
		if (seenIds[el.id]) continue;
		seenIds[el.id] = true;

		result.push({
			el: el, id: el.id,
			tag: tag,
			ordinal: ordinal++,
			snippet: title.slice(0, 80)
		});
	}
	return result;
}

// ── Find Current Section ──

function findCurrentSection() {
	var scrollY = window.scrollY || window.pageYOffset;
	var opts = state.options;
	var best = null, bestDist = Infinity;
	for (var i = 0; i < state.sections.length; i++) {
		var sec = state.sections[i];
		var top = sec.el.offsetTop;
		if (top <= scrollY + opts.sectionLookback) {
			var dist = Math.abs(top - scrollY - 180);
			if (dist < bestDist) { bestDist = dist; best = sec; }
		}
	}
	return best;
}

// ── Scroll Settle ──

function onScrollSettle() {
	var opts = state.options;
	var curY = window.scrollY || window.pageYOffset;
	if (Math.abs(curY - state._prevY) > opts.settleTolerance) {
		state._prevY = curY;
		state.scrollTimer = setTimeout(onScrollSettle, opts.recheckInterval);
		return;
	}
	var current = findCurrentSection();
	if (current && !state.seen[current.id]) {
		state.seen[current.id] = true;
		Metrics.send('section:' + current.id, {
			tag: current.tag, ordinal: current.ordinal, snippet: current.snippet
		});
	}
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

// ── TOC Highlighting ──

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
		state.anchorCooling = true;
		setTimeout(function () { state.anchorCooling = false; },
			state.options.anchorCooldown);
		Metrics.send('anchor:' + href.slice(1));
		return;
	}
	var label = a.dataset.track || 'link:' + href;
	Metrics.send(label);
}

// ── Dynamic Section Tracking ──

function _closeCurrent() {
	if (!state.activeSection || !state.options.trackDwell) return;
	var elapsed = Math.round((now() - state.activeSince) / 1000);
	if (elapsed > 0) {
		var sec = state.dynamicSections[state.activeSection];
		Metrics.send('dwell:' + state.activeSection, {
			seconds: elapsed,
			name: sec ? sec.name : state.activeSection
		});
	}
	state.activeSection = null;
	state.activeSince = null;
}

function _observeElement(el, opts) {
	var id = opts.id || el.id || el.getAttribute('data-section')
		|| el.getAttribute('data-track-section')
		|| slugify(el.textContent || '') || ('dyn-' + Object.keys(state.dynamicSections).length);
	if (!el.id) el.id = id;
	state.dynamicSections[id] = {
		el: el, id: id,
		name: opts.name || el.getAttribute('data-section-name') || el.getAttribute('title') || id,
		snippet: snippet(el), observedAt: now()
	};
	if (window.IntersectionObserver && opts.autoTrack !== false) {
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting && entry.intersectionRatio > 0.3) {
					NT.opened(id);
				} else if (!entry.isIntersecting && state.activeSection === id) {
					NT.closed(id);
				}
			});
		}, { threshold: [0, 0.3] });
		io.observe(el);
		state.dynamicSections[id]._io = io;
	}
	return id;
}

// ── Nav Container Link Tracking ──
// Watches scrollable navigation containers and fires 'nav-link:' events
// when links scroll into view (like contextual menu items, sidebar nav, etc.)

function _setupNavContainerTracking() {
	var selector = state.options.navContainers;
	if (!selector || !window.IntersectionObserver) return;

	var containers = document.querySelectorAll(selector);
	for (var c = 0; c < containers.length; c++) {
		_observeNavContainer(containers[c]);
	}
}

function _observeNavContainer(container) {
	var links = container.querySelectorAll('a[href], li[data-action], li[data-name]');
	if (!links.length) return;

	var io = new IntersectionObserver(function (entries) {
		entries.forEach(function (entry) {
			if (!entry.isIntersecting) return;
			var el = entry.target;
			var linkId = el.getAttribute('data-name')
				|| el.getAttribute('data-action')
				|| el.getAttribute('href')
				|| el.textContent.trim().slice(0, 40);
			var key = 'nav-link:' + linkId;
			if (!state._navLinksSeen[key]) {
				state._navLinksSeen[key] = true;
				Metrics.send(key, { text: el.textContent.trim().slice(0, 60) });
			}
		});
	}, {
		root: container,
		threshold: 0.5
	});

	for (var i = 0; i < links.length; i++) {
		io.observe(links[i]);
	}
	state._navObservers.push(io);
}

// ── DOM Mutation Observer ──

function _startMutationObserver() {
	if (!window.MutationObserver || !state.options.observeDom) return;
	var root = state.options.observeRoot || document.body;
	var selector = state.options.observeSelector;
	state._mutationObserver = new MutationObserver(function (mutations) {
		mutations.forEach(function (mutation) {
			mutation.addedNodes.forEach(function (node) {
				if (node.nodeType !== 1) return;
				if (node.matches && node.matches(selector)) {
					_observeElement(node, {});
				}
				if (node.querySelectorAll) {
					var matches = node.querySelectorAll(selector);
					for (var i = 0; i < matches.length; i++) {
						_observeElement(matches[i], {});
					}
				}
			});
		});
	});
	state._mutationObserver.observe(root, { childList: true, subtree: true });
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

var NT = {

	init: function (options) {
		if (state.initialized) {
			console.warn('Metrics.NavigationTracker already initialized');
			return this;
		}
		var opts = {};
		var k;
		for (k in defaults) { if (defaults.hasOwnProperty(k)) opts[k] = defaults[k]; }
		for (k in (options || {})) { if (options.hasOwnProperty(k) && options[k] !== undefined) opts[k] = options[k]; }
		state.options = opts;
		state.initialized = true;

		if (!Metrics._endpoint && options && options.endpoint) {
			Metrics.init({
				endpoint: options.endpoint, page: options.page,
				sessionKey: options.sessionKey, sessionId: options.sessionId,
				extra: options.extra, trackUnload: options.trackUnload
			});
		}

		// Delayed init
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
			if (opts.observeDom) _startMutationObserver();
			if (opts.navContainers) _setupNavContainerTracking();
		}, opts.initDelay);

		if (opts.trackClicks) {
			document.addEventListener('click', onDocumentClick);
		}

		if (opts.trackDwell) {
			Metrics.onVisibilityChange(function (visible) {
				if (!visible) _closeCurrent();
			}, 'NavigationTracker.dwell');
		}

		// Listen for SPA navigations to reset tracking
		if (Metrics.onSpaNav) {
			Metrics.onSpaNav(function () {
				setTimeout(function () {
					Metrics.NavigationTracker.reset();
				}, opts.initDelay);
			}, 'Metrics.NavigationTracker');
		}

		return this;
	},

	/**
	 * Observe a dynamic element as a trackable section
	 */
	observe: function (el, opts) {
		return _observeElement(el, opts || {});
	},

	/**
	 * Signal a section/panel/tab/contextual was opened or switched to
	 */
	opened: function (id) {
		if (state.activeSection === id) return;
		_closeCurrent();
		state.activeSection = id;
		state.activeSince = now();
		if (!state.seen[id]) {
			state.seen[id] = true;
			var sec = state.dynamicSections[id];
			Metrics.send('opened:' + id, {
				name: sec ? sec.name : id,
				snippet: sec ? sec.snippet : ''
			});
		} else {
			Metrics.send('switched:' + id, {
				name: (state.dynamicSections[id] || {}).name || id
			});
		}
	},

	/**
	 * Signal a section/panel/tab/contextual was closed
	 */
	closed: function (id) {
		if (state.activeSection !== id) return;
		_closeCurrent();
	},

	/**
	 * Track a navigation container — observe its links scrolling into view
	 */
	observeNavContainer: function (container) {
		_observeNavContainer(container);
	},

	send: function (label, data) { Metrics.send(label, data); },
	markSeen: function (id) { state.seen[id] = true; },
	getSessionId: function () { return Metrics.getSessionId(); },
	getSections: function () {
		return state.sections.map(function (s) {
			return { id: s.id, tag: s.tag, ordinal: s.ordinal, snippet: s.snippet };
		});
	},
	getDynamicSections: function () {
		var result = {};
		for (var id in state.dynamicSections) {
			var s = state.dynamicSections[id];
			result[id] = { id: s.id, name: s.name, snippet: s.snippet };
		}
		return result;
	},
	getSeen: function () {
		var copy = {};
		for (var k in state.seen) copy[k] = true;
		return copy;
	},
	getActive: function () {
		if (!state.activeSection) return null;
		return {
			id: state.activeSection,
			since: state.activeSince,
			elapsed: Math.round((now() - state.activeSince) / 1000)
		};
	},
	reset: function () {
		_closeCurrent();
		state.seen = {};
		state.depthHit = {};
		state.anchorCooling = false;
		state._navLinksSeen = {};
		clearTimeout(state.scrollTimer);
		for (var id in state.dynamicSections) {
			if (state.dynamicSections[id]._io) state.dynamicSections[id]._io.disconnect();
		}
		state.dynamicSections = {};
		state.activeSection = null;
		state.activeSince = null;
		state.sections = discoverSections(state.options.sections);
		premarkInitialState();
	},
	destroy: function () {
		_closeCurrent();
		window.removeEventListener('scroll', onScroll);
		window.removeEventListener('scroll', updateTocHighlight);
		document.removeEventListener('click', onDocumentClick);
		clearTimeout(state.scrollTimer);
		if (state._mutationObserver) {
			state._mutationObserver.disconnect();
			state._mutationObserver = null;
		}
		for (var id in state.dynamicSections) {
			if (state.dynamicSections[id]._io) state.dynamicSections[id]._io.disconnect();
		}
		for (var n = 0; n < state._navObservers.length; n++) {
			state._navObservers[n].disconnect();
		}
		state._navObservers = [];
		state.initialized = false;
	},

	defaults: defaults,
	state: state
};

Metrics.NavigationTracker = NT;
// Backward compat aliases
Metrics.SectionTracker = NT;
Metrics.ScrollTracker = NT;

})(typeof window !== 'undefined' ? window : this);
