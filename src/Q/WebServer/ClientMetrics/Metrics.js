/**
 * Metrics — Client-side telemetry
 *
 * Works standalone (any website) or with the Q framework.
 * Tracks page loads, visibility changes, unload timing,
 * outbound links, and window.open calls.  Transport uses
 * sendBeacon with a fetch fallback.
 *
 * Standalone:
 *   Metrics.init({ endpoint: '/Q/metrics' });
 *
 * With Q framework:
 *   Loaded automatically when the Metrics plugin is activated.
 *   Q.Metrics is an alias for the global Metrics object.
 *
 * Separate tracker files add scroll, navigation, and media
 * tracking on top of this core:
 *   Metrics.ScrollTracker.js
 *   Metrics.NavigationTracker.js
 *   Metrics.MediaTracker.js
 *
 * @module Metrics
 * @class Metrics
 */
"use strict";

// ── Core Metrics object (works with or without Q) ──
(function (root) {

var Metrics = root.Metrics || {};
root.Metrics = Metrics;

// ── Session Management ──

Metrics._sessionKey = 'metrics_sid';
Metrics._visitorKey = 'metrics_vid';
Metrics._sid = null;
Metrics._vid = null;

/**
 * Get or create a per-tab session ID (sessionStorage).
 * A new ID is generated for each browser tab.
 * @method getSessionId
 * @return {String} The session ID
 */
Metrics.getSessionId = function () {
	if (Metrics._sid) return Metrics._sid;
	try {
		var sid = sessionStorage.getItem(Metrics._sessionKey);
		if (!sid) {
			sid = Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
			sessionStorage.setItem(Metrics._sessionKey, sid);
		}
		Metrics._sid = sid;
	} catch (e) {
		Metrics._sid = Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
	}
	return Metrics._sid;
};

/**
 * Get or create a persistent visitor ID that survives across sessions.
 * Tries localStorage first (persists until cleared).
 * Falls back to sessionStorage (ITP-safe, but per-tab only).
 * When loaded cross-origin (e.g. from a CDN), localStorage is still
 * first-party to the page's domain — ITP won't block it.
 * @method getVisitorId
 * @return {String} The visitor ID
 */
Metrics.getVisitorId = function () {
	if (Metrics._vid) return Metrics._vid;
	var vid = null;
	try {
		vid = localStorage.getItem(Metrics._visitorKey);
		if (!vid) {
			vid = Math.random().toString(36).slice(2) + Date.now().toString(36)
				+ Math.random().toString(36).slice(2);
			localStorage.setItem(Metrics._visitorKey, vid);
		}
		Metrics._vid = vid;
		return vid;
	} catch (e) {}
	try {
		vid = sessionStorage.getItem(Metrics._visitorKey);
		if (!vid) {
			vid = Math.random().toString(36).slice(2) + Date.now().toString(36)
				+ Math.random().toString(36).slice(2);
			sessionStorage.setItem(Metrics._visitorKey, vid);
		}
		Metrics._vid = vid;
		return vid;
	} catch (e) {}
	vid = Math.random().toString(36).slice(2) + Date.now().toString(36)
		+ Math.random().toString(36).slice(2);
	Metrics._vid = vid;
	return vid;
};

// ── Transport (standalone — overridden by Q integration below) ──

Metrics._defaultEndpoint = null;
Metrics._endpoint = null;
Metrics._page = null;
Metrics._extra = null;
Metrics._unloaded = false;
Metrics._startTime = Date.now();

/**
 * Send a telemetry event. Standalone mode uses sendBeacon / fetch.
 * When Q framework is loaded, this is enhanced to also POST via Q.req().
 * @method send
 * @param {String} label — event label
 * @param {Object} [data] — optional extra data
 */
Metrics.send = function (label, data) {
	if (!Metrics._endpoint || Metrics._unloaded) return;

	var payload = {
		session: Metrics.getSessionId(),
		visitor: Metrics.getVisitorId(),
		origin: location.origin,
		url: location.pathname + location.search + location.hash,
		page: Metrics._page || document.title,
		label: label,
		t: Date.now()
	};
	if (Metrics._extra) payload.extra = Metrics._extra;
	if (data) payload.data = data;

	var body = JSON.stringify(payload);
	try {
		if (navigator.sendBeacon) {
			navigator.sendBeacon(Metrics._endpoint, new Blob([body], { type: 'text/plain' }));
		} else {
			fetch(Metrics._endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'text/plain' },
				keepalive: true,
				body: body
			});
		}
	} catch (e) { /* silent */ }
};

// ── Visibility Detection ──
// Uses Q.onVisibilityChange when available, otherwise vendor-prefixed
// visibilitychange + mobile lifecycle events (Cordova/Capacitor)

Metrics._visible = true;
Metrics._visibilityCallbacks = [];
Metrics._visibilityBound = false;

/**
 * Whether the page is currently visible
 * @method isVisible
 * @returns {Boolean}
 */
Metrics.isVisible = function () {
	return Metrics._visible;
};

/**
 * Register a callback for visibility changes
 * @method onVisibilityChange
 * @param {Function} fn(isVisible) — called when visibility changes
 * @param {String} [key] — optional key for deduplication
 */
Metrics.onVisibilityChange = function (fn, key) {
	if (key) {
		// Replace existing callback with same key
		for (var i = 0; i < Metrics._visibilityCallbacks.length; i++) {
			if (Metrics._visibilityCallbacks[i].key === key) {
				Metrics._visibilityCallbacks[i].fn = fn;
				return;
			}
		}
	}
	Metrics._visibilityCallbacks.push({ fn: fn, key: key || null });
};

function _fireVisibility(isVisible) {
	if (isVisible === Metrics._visible) return; // deduplicate
	Metrics._visible = isVisible;
	for (var i = 0; i < Metrics._visibilityCallbacks.length; i++) {
		try { Metrics._visibilityCallbacks[i].fn(isVisible); } catch (e) {}
	}
}

function _bindVisibility() {
	if (Metrics._visibilityBound) return;
	Metrics._visibilityBound = true;

	// Detect vendor-prefixed visibility API
	var visibilityChange = null;
	var prefixes = ['', 'moz', 'ms', 'webkit', 'o'];
	for (var i = 0; i < prefixes.length; i++) {
		var k = prefixes[i];
		var hidden = k ? k + 'Hidden' : 'hidden';
		if (hidden in document) {
			visibilityChange = k ? k + 'visibilitychange' : 'visibilitychange';
			break;
		}
	}

	function handleVisEvent(event) {
		var isHidden;
		if (event.type === 'pause' || event.type === 'resign') {
			isHidden = true;
		} else if (event.type === 'resume' || event.type === 'active') {
			isHidden = false;
		} else {
			isHidden = document.visibilityState === 'hidden';
		}
		_fireVisibility(!isHidden);
	}

	if (visibilityChange) {
		document.addEventListener(visibilityChange, handleVisEvent, false);
	}
	// Mobile lifecycle (Cordova / Capacitor)
	document.addEventListener('pause', handleVisEvent, false);
	document.addEventListener('resume', handleVisEvent, false);
	document.addEventListener('resign', handleVisEvent, false);
	document.addEventListener('active', handleVisEvent, false);
}

// ── Unload / bfcache / background-foreground ──

Metrics._unloadBound = false;
Metrics._bgTime = null;        // when backgrounded
Metrics._bgCooldown = false;   // suppress rapid duplicate background events
Metrics._bgCooldownMs = 1000;  // ignore repeated bg events within 1s

function _bindUnload() {
	if (Metrics._unloadBound) return;
	Metrics._unloadBound = true;

	Metrics.onVisibilityChange(function (isVisible) {
		if (!isVisible) {
			// Backgrounded — send immediately (timers don't fire in bg tabs)
			// but suppress if we just sent one within the cooldown
			if (!Metrics._bgCooldown) {
				Metrics._bgCooldown = true;
				var elapsed = Math.round((Date.now() - Metrics._startTime) / 1000);
				Metrics.send('background', { elapsed: elapsed });
			}
			Metrics._bgTime = Date.now();
			_sendUnload();
		} else {
			// Foregrounded — reset cooldown so next background can fire
			Metrics._bgCooldown = false;
			Metrics._unloaded = false;
			if (Metrics._bgTime) {
				var awaySeconds = Math.round((Date.now() - Metrics._bgTime) / 1000);
				Metrics._bgTime = null;
				// Only report return if they were away > 1 second
				// (rapid alt-tab = noise, background event already sent but
				// no point sending a foreground for a sub-second switch)
				if (awaySeconds > 1) {
					Metrics.send('foreground', { away: awaySeconds });
				}
			}
		}
	}, 'Metrics.unload');

	// pagehide fallback
	window.addEventListener('pagehide', function () {
		_sendUnload();
	});

	// bfcache restore
	window.addEventListener('pageshow', function (e) {
		if (e.persisted) {
			Metrics._unloaded = false;
			Metrics._bgCooldown = false;
			Metrics.send('foreground', { bfcache: true });
		}
	});
}

function _sendUnload() {
	if (Metrics._unloaded) return;
	var elapsed = Math.round((Date.now() - Metrics._startTime) / 1000);
	Metrics.send('unload:' + elapsed + 's');
	Metrics._unloaded = true; // set AFTER send, not before
}

// ── Outbound link + window.open tracking ──

Metrics._linksBound = false;

function _bindLinks() {
	if (Metrics._linksBound) return;
	Metrics._linksBound = true;

	// Intercept clicks on <a> elements that navigate away
	document.addEventListener('click', function (e) {
		var a = e.target.closest ? e.target.closest('a[href]') : null;
		if (!a) return;
		var href = a.getAttribute('href') || '';
		if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;

		try {
			var url = new URL(href, location.href);
			var isExternal = url.origin !== location.origin;
			var label = isExternal ? 'outbound' : 'navigate';

			if (isExternal || a.target === '_blank') {
				Metrics.send(label, {
					href: url.href,
					text: (a.textContent || '').trim().slice(0, 100)
				});
			} else {
				Metrics.send(label, {
					href: url.pathname + url.search + url.hash,
					text: (a.textContent || '').trim().slice(0, 100)
				});
			}
		} catch (ex) {
			Metrics.send('click', { href: href });
		}
	}, true); // capture phase — fires before preventDefault

	// Intercept window.open
	var _origOpen = window.open;
	window.open = function () {
		var url = arguments[0];
		if (url) {
			try {
				var parsed = new URL(url, location.href);
				Metrics.send('window.open', {
					href: parsed.href
				});
			} catch (ex) {
				Metrics.send('window.open', { href: String(url) });
			}
		}
		return _origOpen.apply(this, arguments);
	};
}

/**
 * Initialize standalone page tracking (no Q framework needed).
 * @method init
 * @param {Object} options
 * @param {String} options.endpoint — POST URL for beacons
 * @param {String} [options.page] — page identifier
 * @param {String} [options.sessionKey] — sessionStorage key
 * @param {String} [options.sessionId] — override session ID
 * @param {Object} [options.extra] — extra data with every event
 * @param {Boolean} [options.trackUnload=true] — send unload beacon
 * @param {Boolean} [options.skipLinkTracking] — skip outbound link + window.open tracking
 */
Metrics.init = function (options) {
	options = options || {};
	Metrics._endpoint = options.endpoint || Metrics._defaultEndpoint;
	if (options.page) Metrics._page = options.page;
	if (options.sessionKey) Metrics._sessionKey = options.sessionKey;
	if (options.sessionId) Metrics._sid = options.sessionId;
	if (options.extra) Metrics._extra = options.extra;

	_bindVisibility();
	if (options.trackUnload !== false) {
		_bindUnload();
	}
	if (options.skipLinkTracking !== true) {
		_bindLinks();
	}

	// Context snapshot — sent once with the "loaded" event
	var ctx = {
		referrer: document.referrer || '',
		screen: screen.width + 'x' + screen.height,
		viewport: window.innerWidth + 'x' + window.innerHeight,
		dpr: window.devicePixelRatio || 1,
		lang: navigator.language || '',
		touch: ('ontouchstart' in window) || (navigator.maxTouchPoints > 0),
		pwa: window.matchMedia('(display-mode: standalone)').matches
			|| window.navigator.standalone === true
	};
	try { ctx.tz = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) {}
	try {
		if (navigator.connection && navigator.connection.effectiveType) {
			ctx.conn = navigator.connection.effectiveType;
		}
	} catch (e) {}
	try {
		var nav = performance.getEntriesByType('navigation');
		if (nav && nav[0]) ctx.navType = nav[0].type;
	} catch (e) {}

	Metrics.send('loaded', ctx);

	// SPA navigation — detect pushState/replaceState + popstate
	if (!options.skipSpaTracking) {
		_bindSpaNav();
	}

	return Metrics;
};

// ── SPA Navigation Detection ──
// Monkey-patches pushState/replaceState and listens to popstate.
// When Q.js is loaded, also hooks into Q.Page.onPush for reliable
// SPA page tracking.

Metrics._spaBound = false;
Metrics._spaCallbacks = [];
Metrics._lastSpaUrl = null;

/**
 * Register a callback for SPA navigation events.
 * Called with (newUrl, oldUrl) after each pushState/popstate.
 * Trackers use this to reset scroll depth, re-discover sections, etc.
 * @method onSpaNav
 * @param {Function} fn(newUrl, oldUrl)
 * @param {String} [key] — optional key for deduplication
 */
Metrics.onSpaNav = function (fn, key) {
	if (key) {
		for (var i = 0; i < Metrics._spaCallbacks.length; i++) {
			if (Metrics._spaCallbacks[i].key === key) {
				Metrics._spaCallbacks[i].fn = fn;
				return;
			}
		}
	}
	Metrics._spaCallbacks.push({ fn: fn, key: key || null });
};

function _fireSpaNav(newUrl, oldUrl) {
	if (newUrl === oldUrl) return;
	Metrics._lastSpaUrl = newUrl;
	Metrics._page = null; // reset so send() uses current title
	Metrics._startTime = Date.now(); // reset elapsed for unload tracking

	Metrics.send('navigate', {
		from: oldUrl,
		to: newUrl
	});

	for (var i = 0; i < Metrics._spaCallbacks.length; i++) {
		try { Metrics._spaCallbacks[i].fn(newUrl, oldUrl); } catch (e) {}
	}
}

function _bindSpaNav() {
	if (Metrics._spaBound) return;
	Metrics._spaBound = true;
	Metrics._lastSpaUrl = location.pathname + location.search + location.hash;

	// Monkey-patch pushState and replaceState
	var origPush = history.pushState;
	var origReplace = history.replaceState;

	history.pushState = function () {
		var oldUrl = location.pathname + location.search + location.hash;
		var result = origPush.apply(this, arguments);
		var newUrl = location.pathname + location.search + location.hash;
		_fireSpaNav(newUrl, oldUrl);
		return result;
	};

	history.replaceState = function () {
		var oldUrl = location.pathname + location.search + location.hash;
		var result = origReplace.apply(this, arguments);
		var newUrl = location.pathname + location.search + location.hash;
		// Only fire for path/query changes, not hash-only or state-only updates
		if (newUrl.split('#')[0] !== oldUrl.split('#')[0]) {
			_fireSpaNav(newUrl, oldUrl);
		}
		return result;
	};

	// popstate fires on back/forward navigation
	window.addEventListener('popstate', function () {
		var newUrl = location.pathname + location.search + location.hash;
		var oldUrl = Metrics._lastSpaUrl || '';
		_fireSpaNav(newUrl, oldUrl);
	});
}

})(typeof window !== 'undefined' ? window : this);


// ── Q Framework Integration (only runs if Q exists) ──
if (typeof Q !== 'undefined') {
(function (Q) {

	// Bridge: make Q.Metrics point to the global Metrics
	Q.Metrics = Q.plugins.Metrics = window.Metrics;
	var Metrics = window.Metrics;

	Metrics.setState = function (state, extra) {
		var url = Q.info.url;
		Metrics.setState.pending[url] = Q.setTimeout(function () {
			if (Metrics.setState.pending[url]) {
				clearTimeout(Metrics.setState.pending[url]);
				delete Metrics.setState.pending[url];
			}
			Q.req('Metrics/update', [], null, {
				method: 'POST',
				fields: {
					navigatorUrl: location.href,
					url: Q.info.url,
					state: state,
					extra: JSON.stringify(extra)
				},
				keepalive: true
			});
		}, 5000);
	};
	Metrics.setState.pending = {};

	var dc = Q.extend.dontCopy;
	dc["Q.Users.User"] = true;

	Q.text.Metrics = {};

	function ensureVisitInHash(visitId) {
		var current = location.hash || '#';
		var updated = current.queryField('v', visitId);
		if (updated !== current) {
			history.replaceState(
				history.state,
				document.title,
				updated
			);
		}
	}

	// Hook into Q.Page.onPush for SPA navigation tracking.
	// This fires when Q navigates to a new page via pushState,
	// which is more reliable than the monkey-patch for Q apps.
	if (Q.Page && Q.Page.onPush && Q.Page.onPush.add) {
		Q.Page.onPush.add(function (url, title, prevUrl) {
			var newPath = url;
			try {
				// Q.Page.push passes full URLs — extract the path portion
				var base = Q.baseUrl ? Q.baseUrl() : location.origin;
				if (url.indexOf(base) === 0) {
					newPath = url.substring(base.length) || '/';
				}
			} catch (e) {}
			var oldPath = prevUrl || '';
			try {
				var base2 = Q.baseUrl ? Q.baseUrl() : location.origin;
				if (prevUrl && prevUrl.indexOf(base2) === 0) {
					oldPath = prevUrl.substring(base2.length) || '/';
				}
			} catch (e) {}

			// Update page title if provided
			if (title) Metrics._page = title;

			// Fire the SPA nav callbacks (ScrollTracker/NavigationTracker
			// listen here to reset depth tracking and re-discover sections)
			Metrics._lastSpaUrl = newPath;
			Metrics._startTime = Date.now();
			Metrics.send('navigate', { from: oldPath, to: newPath });
			for (var i = 0; i < Metrics._spaCallbacks.length; i++) {
				try { Metrics._spaCallbacks[i].fn(newPath, oldPath); } catch (e) {}
			}
		}, 'Metrics.js');
	}

	Q.onReady.add(function () {
		// If Q.onVisibilityChange exists, bridge it to Metrics visibility
		if (Q.onVisibilityChange && Q.onVisibilityChange.set) {
			Q.onVisibilityChange.set(function (shown) {
				// Sync Q's visibility detection into Metrics
				if (Metrics._visible !== shown) {
					Metrics._visible = shown;
					for (var i = 0; i < Metrics._visibilityCallbacks.length; i++) {
						try { Metrics._visibilityCallbacks[i].fn(shown); } catch (e) {}
					}
				}
			}, 'Metrics');
		}

		// Initialize NavigationTracker if configured
		var stConfig = Q.getObject('Metrics.navigationTracker', Q.plugins)
			|| Q.getObject('Metrics.navigationTracker', Q);
		if (stConfig && Metrics.NavigationTracker) {
			stConfig.page = stConfig.page || Q.info.url || document.title;
			Metrics.NavigationTracker.init(stConfig);
		}

		// Initialize MediaTracker if configured
		var mtConfig = Q.getObject('Metrics.mediaTracker', Q.plugins)
			|| Q.getObject('Metrics.mediaTracker', Q);
		if (mtConfig && Metrics.MediaTracker) {
			Metrics.MediaTracker.init(mtConfig);
		}

		// ── Auto-wire into Q tools for navigation tracking ──
		var NT = Metrics.NavigationTracker;
		if (NT) {
			// Q/tabs — track tab switches (debounced to avoid rapid fire)
			var _tabDebounce = null;
			Q.Tool.onActivate('Q/tabs').set(function () {
				var tabsTool = this;
				tabsTool.state.onCurrent.set(function (tab, tabName) {
					clearTimeout(_tabDebounce);
					var _name = tabName;
					_tabDebounce = setTimeout(function () {
						if (_name && NT.state.initialized) {
							NT.opened('tab:' + _name);
						}
					}, 300);
				}, 'Metrics.NavigationTracker');
			}, 'Metrics.NavigationTracker');

			// Q/columns — track column open/close
			Q.Tool.onActivate('Q/columns').set(function () {
				var columnsTool = this;
				columnsTool.state.onActivate.set(function (div, options, index) {
					if (!NT.state.initialized) return;
					var name = (div && div.getAttribute('data-name')) || ('column-' + index);
					NT.opened('column:' + name);
				}, 'Metrics.NavigationTracker');
				columnsTool.state.onClose.set(function (index, div) {
					if (!NT.state.initialized) return;
					var name = (div && div.getAttribute('data-name')) || ('column-' + index);
					NT.closed('column:' + name);
				}, 'Metrics.NavigationTracker');
			}, 'Metrics.NavigationTracker');

			// Q/expandable — track expand/collapse
			Q.Tool.onActivate('Q/expandable').set(function () {
				var expTool = this;
				var expId = expTool.element.id
					|| expTool.element.getAttribute('data-name')
					|| expTool.id;
				expTool.state.onExpand.set(function () {
					if (!NT.state.initialized) return;
					NT.opened('expandable:' + expId);
				}, 'Metrics.NavigationTracker');
				expTool.state.onCollapse.set(function () {
					if (!NT.state.initialized) return;
					NT.closed('expandable:' + expId);
				}, 'Metrics.NavigationTracker');
			}, 'Metrics.NavigationTracker');

			// Q.Contextual — track contextual menu show/hide/item selection
			if (Q.Contextual) {
				Q.Contextual.onShow.set(function (contextual) {
					if (!NT.state.initialized) return;
					var $ctx = $(contextual);
					var $trigger = $ctx.data('Q/contextual trigger');
					var ctxId = ($trigger && $trigger.attr('data-name'))
						|| ($trigger && $trigger.attr('id'))
						|| 'contextual-' + Q.Contextual.current;
					NT.opened('contextual:' + ctxId);

					// Track links/items scrolling into view inside the contextual
					var listing = contextual.querySelector
						? contextual.querySelector('.Q_listing_wrapper, .Q_listing')
						: null;
					if (listing) {
						NT.observeNavContainer(listing);
					}
				}, 'Metrics.NavigationTracker');

				Q.Contextual.onHide.set(function (contextual) {
					if (!NT.state.initialized) return;
					if (NT.state.activeSection
					&& NT.state.activeSection.indexOf('contextual:') === 0) {
						NT.closed(NT.state.activeSection);
					}
				}, 'Metrics.NavigationTracker');

				// Intercept contextual item selection
				var _origItemHandler = Q.Contextual.itemSelectHandler;
				if (_origItemHandler) {
					Q.Contextual.itemSelectHandler = function (element, event) {
						if (NT.state.initialized) {
							var action = element.getAttribute('data-action')
								|| element.getAttribute('data-name')
								|| (element.textContent || '').trim().slice(0, 40);
							Metrics.send('contextual-item:' + action);
						}
						return _origItemHandler.apply(this, arguments);
					};
				}
			}
		}

		// Visit chaining — look for a parent visitId in the hash
		var parentVisitId = location.hash.queryField('v');
		if (!parentVisitId) {
			return;
		}
		ensureVisitInHash(parentVisitId);

		Q.req('Metrics/landed', {
			method: 'POST',
			fields: { trackerId: 'visitId:' + parentVisitId }
		}, function (err, res) {
			if (err) {
				if (window.console) {
					console.error('Metrics landed request failed', err);
				}
				return;
			}
			if (res && res.slots && res.slots.visitId) {
				ensureVisitInHash(res.slots.visitId);
			}
		});
	}, 'Metrics');

	// Error telemetry
	(function () {
		function sendErrorTelemetry(errorInfo) {
			var payload = JSON.stringify({ error: errorInfo });
			Q.req('Metrics/update', [], null, {
				method: 'POST',
				fields: {
					navigatorUrl: location.href,
					url: Q.info && Q.info.url,
					state: 'error',
					extra: payload
				},
				keepalive: true
			});
		}

		function formatErrorPayload(message, stack, details) {
			var payload = {
				message: message || '',
				stack: stack || '',
				url: location.href,
				userAgent: navigator.userAgent,
				timestamp: Date.now(),
				performanceNow: performance.now()
			};
			if (details) {
				payload.details = details;
			}
			return payload;
		}

		function handleError(reason, isRejection) {
			var message = '';
			var stack = '';
			var details;

			if (reason instanceof Error) {
				message = reason.message;
				stack = reason.stack;
			} else if (typeof reason === 'string') {
				message = reason;
			} else if (reason && typeof reason === 'object') {
				try {
					details = JSON.stringify(reason);
				} catch (e) {
					details = '[unserializable reason]';
				}
			}

			var errorInfo = formatErrorPayload(message, stack, details);
			sendErrorTelemetry(errorInfo);
			console.warn(isRejection ? 'Unhandled rejection:' : 'Unhandled error:', reason);

			if (message && /indexedDB/i.test(message)) {
				console.warn('[Recovery] Error suggests IndexedDB corruption. Triggering recovery...');
			}
		}

		window.addEventListener('unhandledrejection', function (event) {
			handleError(event.reason, true);
		});

		window.addEventListener('error', function (event) {
			handleError(event.error || event.message, false);
		});
	})();

})(Q);
}
