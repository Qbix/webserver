/**
 * Metrics.MediaTracker — Track video/audio engagement
 *
 * Auto-discovers native <video>/<audio> elements and embedded players
 * from YouTube, Vimeo, SoundCloud, Wistia, JW Player, Dailymotion,
 * Spotify, Twitch, and Muse.ai. Hooks into play/pause/seek/ended
 * events and sends periodic checkpoints during playback.
 *
 * Events:
 *   media-play:id       — playback started
 *   media-pause:id      — playback paused
 *   media-checkpoint:id — periodic position update during playback
 *   media-ended:id      — reached the end
 *   media-seeked:id     — user jumped to a position
 *
 * Usage (standalone):
 *   Metrics.init({ endpoint: '/Q/metrics', page: 'My Page' });
 *   Metrics.MediaTracker.init({
 *     checkpointInterval: 10,  // seconds
 *     reloadIframes: false     // don't reload to inject API params
 *   });
 *
 * Usage (with Q framework):
 *   // Auto-initializes from config if endpoint is set
 *
 * @module Metrics
 * @class Metrics.MediaTracker
 */
"use strict";
(function (root) {

var Metrics = root.Metrics;
if (!Metrics) {
	console.warn('Metrics.MediaTracker: Metrics core not loaded');
	return;
}

var defaults = {
	// Seconds between checkpoint events during playback
	checkpointInterval: 10,

	// Auto-discover media elements on init
	autoDiscover: true,

	// CSS selector for native media elements
	mediaSelector: 'video, audio',

	// Whether to reload YouTube/Vimeo iframes to inject API params
	// Default false: logs a warning instead of disrupting playback
	reloadIframes: false,

	// Observe DOM for dynamically added media
	observeDom: true,

	// Root element to watch for mutations
	observeRoot: null,

	// Debounce checkpoint sends (ms) — prevents burst on rapid seeks
	checkpointDebounce: 1000
};

// ── State ──
var state = {
	initialized: false,
	options: null,
	tracked: {},       // id → tracker object
	_counter: 0,       // for generating IDs
	_mutationObserver: null,
	_ytApiLoaded: false,
	_ytApiLoading: false,
	_ytPendingPlayers: [], // iframes waiting for API
	_vimeoApiLoaded: false,
	_vimeoApiLoading: false
};

// ── Utilities ──

function genId(el) {
	if (el.id) return el.id;
	var src = el.src || el.currentSrc || '';
	if (src) {
		// Extract meaningful part of URL
		var match = src.match(/(?:youtu\.be\/|youtube\.com\/embed\/|vimeo\.com\/video\/|vimeo\.com\/)([^?&#]+)/);
		if (match) return match[1];
		// Use filename
		var parts = src.split('/').pop().split('?')[0];
		if (parts && parts.length < 60) return parts;
	}
	return 'media-' + (state._counter++);
}

// ── Watched Seconds Tracker ──
// Tracks unique seconds viewed (handles seeking/rewatching)

function WatchedTracker() {
	this.ranges = []; // [{start, end}] sorted, non-overlapping
}

WatchedTracker.prototype.add = function (from, to) {
	if (to <= from) return;
	var newRange = { start: Math.floor(from), end: Math.ceil(to) };
	var merged = [];
	var inserted = false;
	for (var i = 0; i < this.ranges.length; i++) {
		var r = this.ranges[i];
		if (r.end < newRange.start) {
			merged.push(r);
		} else if (r.start > newRange.end) {
			if (!inserted) { merged.push(newRange); inserted = true; }
			merged.push(r);
		} else {
			newRange.start = Math.min(newRange.start, r.start);
			newRange.end = Math.max(newRange.end, r.end);
		}
	}
	if (!inserted) merged.push(newRange);
	this.ranges = merged;
};

WatchedTracker.prototype.total = function () {
	var t = 0;
	for (var i = 0; i < this.ranges.length; i++) {
		t += this.ranges[i].end - this.ranges[i].start;
	}
	return t;
};

// ── Core Tracker Object (per media element) ──

function createTracker(id, type, el, duration) {
	return {
		id: id,
		type: type,
		el: el,
		duration: duration || 0,
		playing: false,
		lastPosition: 0,
		lastCheckpointAt: 0,
		watched: new WatchedTracker(),
		checkpointTimer: null,
		_lastTimeUpdate: 0
	};
}

function sendEvent(tracker, event, extra) {
	var data = {
		type: tracker.type,
		position: Math.round(tracker.lastPosition),
		duration: Math.round(tracker.duration),
		watched: tracker.watched.total()
	};
	if (extra) {
		for (var k in extra) data[k] = extra[k];
	}
	Metrics.send(event + ':' + tracker.id, data);
}

// ── Checkpoint Timer ──

function startCheckpoints(tracker) {
	stopCheckpoints(tracker);
	var interval = state.options.checkpointInterval * 1000;
	tracker.checkpointTimer = setInterval(function () {
		if (tracker.playing) {
			sendEvent(tracker, 'media-checkpoint');
		}
	}, interval);
}

function stopCheckpoints(tracker) {
	if (tracker.checkpointTimer) {
		clearInterval(tracker.checkpointTimer);
		tracker.checkpointTimer = null;
	}
}

// ── Native <video> / <audio> ──

function trackNative(el) {
	var id = genId(el);
	if (state.tracked[id]) return;

	var tracker = createTracker(id, 'native', el, el.duration || 0);
	state.tracked[id] = tracker;

	el.addEventListener('loadedmetadata', function () {
		tracker.duration = el.duration || 0;
	});

	el.addEventListener('play', function () {
		tracker.playing = true;
		tracker.lastPosition = el.currentTime;
		tracker._lastTimeUpdate = el.currentTime;
		sendEvent(tracker, 'media-play');
		startCheckpoints(tracker);
	});

	el.addEventListener('pause', function () {
		if (!tracker.playing) return;
		tracker.playing = false;
		tracker.watched.add(tracker._lastTimeUpdate, el.currentTime);
		tracker.lastPosition = el.currentTime;
		stopCheckpoints(tracker);
		sendEvent(tracker, 'media-pause');
	});

	el.addEventListener('ended', function () {
		tracker.playing = false;
		tracker.watched.add(tracker._lastTimeUpdate, el.currentTime);
		tracker.lastPosition = el.currentTime;
		stopCheckpoints(tracker);
		sendEvent(tracker, 'media-ended');
	});

	el.addEventListener('seeked', function () {
		var from = tracker.lastPosition;
		tracker.lastPosition = el.currentTime;
		tracker._lastTimeUpdate = el.currentTime;
		sendEvent(tracker, 'media-seeked', {
			from: Math.round(from),
			to: Math.round(el.currentTime)
		});
	});

	el.addEventListener('timeupdate', function () {
		if (tracker.playing && el.currentTime > tracker._lastTimeUpdate) {
			tracker.watched.add(tracker._lastTimeUpdate, el.currentTime);
		}
		tracker._lastTimeUpdate = el.currentTime;
		tracker.lastPosition = el.currentTime;
	});
}

// ── YouTube Iframe ──

function loadYouTubeAPI(callback) {
	if (state._ytApiLoaded) { callback(); return; }
	if (state._ytApiLoading) {
		state._ytPendingPlayers.push(callback);
		return;
	}
	state._ytApiLoading = true;

	var prev = root.onYouTubeIframeAPIReady;
	root.onYouTubeIframeAPIReady = function () {
		state._ytApiLoaded = true;
		state._ytApiLoading = false;
		if (prev) prev();
		callback();
		for (var i = 0; i < state._ytPendingPlayers.length; i++) {
			state._ytPendingPlayers[i]();
		}
		state._ytPendingPlayers = [];
	};

	var script = document.createElement('script');
	script.src = 'https://www.youtube.com/iframe_api';
	document.head.appendChild(script);
}

function trackYouTube(iframe) {
	var src = iframe.src || '';
	var id = genId(iframe);
	if (state.tracked[id]) return;

	// Check for enablejsapi=1
	if (src.indexOf('enablejsapi') === -1) {
		if (state.options.reloadIframes) {
			var separator = src.indexOf('?') === -1 ? '?' : '&';
			iframe.src = src + separator + 'enablejsapi=1&origin=' + encodeURIComponent(location.origin);
		} else {
			console.warn('Metrics.MediaTracker: YouTube iframe missing enablejsapi=1, '
				+ 'set reloadIframes:true to auto-fix. iframe:', iframe);
			return;
		}
	}

	// Ensure iframe has an id for the YT API
	if (!iframe.id) iframe.id = 'yt-' + id;

	loadYouTubeAPI(function () {
		if (state.tracked[id]) return;
		var tracker = createTracker(id, 'youtube', iframe, 0);
		state.tracked[id] = tracker;

		var player = new YT.Player(iframe.id, {
			events: {
				onReady: function (e) {
					tracker.duration = player.getDuration() || 0;
				},
				onStateChange: function (e) {
					var pos = player.getCurrentTime() || 0;
					tracker.lastPosition = pos;

					switch (e.data) {
						case YT.PlayerState.PLAYING:
							tracker.playing = true;
							tracker._lastTimeUpdate = pos;
							tracker.duration = player.getDuration() || tracker.duration;
							sendEvent(tracker, 'media-play');
							startCheckpoints(tracker);
							// Poll position since YT has no timeupdate
							tracker._pollTimer = setInterval(function () {
								var p = player.getCurrentTime() || 0;
								if (tracker.playing && p > tracker._lastTimeUpdate) {
									tracker.watched.add(tracker._lastTimeUpdate, p);
								}
								tracker._lastTimeUpdate = p;
								tracker.lastPosition = p;
							}, 1000);
							break;

						case YT.PlayerState.PAUSED:
							if (!tracker.playing) break;
							tracker.playing = false;
							tracker.watched.add(tracker._lastTimeUpdate, pos);
							stopCheckpoints(tracker);
							clearInterval(tracker._pollTimer);
							sendEvent(tracker, 'media-pause');
							break;

						case YT.PlayerState.ENDED:
							tracker.playing = false;
							tracker.watched.add(tracker._lastTimeUpdate, pos);
							stopCheckpoints(tracker);
							clearInterval(tracker._pollTimer);
							sendEvent(tracker, 'media-ended');
							break;
					}
				}
			}
		});
		tracker._player = player;
	});
}

// ── Vimeo Iframe ──

function loadVimeoAPI(callback) {
	if (state._vimeoApiLoaded) { callback(); return; }
	if (state._vimeoApiLoading) {
		setTimeout(function () { loadVimeoAPI(callback); }, 200);
		return;
	}
	state._vimeoApiLoading = true;

	var script = document.createElement('script');
	script.src = 'https://player.vimeo.com/api/player.js';
	script.onload = function () {
		state._vimeoApiLoaded = true;
		state._vimeoApiLoading = false;
		callback();
	};
	document.head.appendChild(script);
}

function trackVimeo(iframe) {
	var src = iframe.src || '';
	var id = genId(iframe);
	if (state.tracked[id]) return;

	loadVimeoAPI(function () {
		if (state.tracked[id]) return;
		var tracker = createTracker(id, 'vimeo', iframe, 0);
		state.tracked[id] = tracker;

		var player = new Vimeo.Player(iframe);
		tracker._player = player;

		player.getDuration().then(function (d) { tracker.duration = d || 0; });

		player.on('play', function (data) {
			tracker.playing = true;
			tracker.lastPosition = data.seconds || 0;
			tracker._lastTimeUpdate = tracker.lastPosition;
			tracker.duration = data.duration || tracker.duration;
			sendEvent(tracker, 'media-play');
			startCheckpoints(tracker);
		});

		player.on('pause', function (data) {
			if (!tracker.playing) return;
			tracker.playing = false;
			var pos = data.seconds || 0;
			tracker.watched.add(tracker._lastTimeUpdate, pos);
			tracker.lastPosition = pos;
			stopCheckpoints(tracker);
			sendEvent(tracker, 'media-pause');
		});

		player.on('ended', function (data) {
			tracker.playing = false;
			var pos = data.seconds || tracker.duration;
			tracker.watched.add(tracker._lastTimeUpdate, pos);
			tracker.lastPosition = pos;
			stopCheckpoints(tracker);
			sendEvent(tracker, 'media-ended');
		});

		player.on('seeked', function (data) {
			var from = tracker.lastPosition;
			tracker.lastPosition = data.seconds || 0;
			tracker._lastTimeUpdate = tracker.lastPosition;
			sendEvent(tracker, 'media-seeked', {
				from: Math.round(from),
				to: Math.round(tracker.lastPosition)
			});
		});

		player.on('timeupdate', function (data) {
			var pos = data.seconds || 0;
			if (tracker.playing && pos > tracker._lastTimeUpdate) {
				tracker.watched.add(tracker._lastTimeUpdate, pos);
			}
			tracker._lastTimeUpdate = pos;
			tracker.lastPosition = pos;
		});
	});
}

// ── SoundCloud Widget ──

function loadSoundCloudAPI(callback) {
	if (root.SC && root.SC.Widget) { callback(); return; }
	var script = document.createElement('script');
	script.src = 'https://w.soundcloud.com/player/api.js';
	script.onload = callback;
	document.head.appendChild(script);
}

function trackSoundCloud(iframe) {
	var id = genId(iframe);
	if (state.tracked[id]) return;

	loadSoundCloudAPI(function () {
		if (state.tracked[id]) return;
		var widget = SC.Widget(iframe);
		var tracker = createTracker(id, 'soundcloud', iframe, 0);
		state.tracked[id] = tracker;

		widget.bind(SC.Widget.Events.READY, function () {
			widget.getDuration(function (d) { tracker.duration = (d || 0) / 1000; });
		});
		widget.bind(SC.Widget.Events.PLAY, function () {
			tracker.playing = true;
			widget.getPosition(function (p) {
				tracker.lastPosition = (p || 0) / 1000;
				tracker._lastTimeUpdate = tracker.lastPosition;
				sendEvent(tracker, 'media-play');
				startCheckpoints(tracker);
			});
		});
		widget.bind(SC.Widget.Events.PAUSE, function () {
			if (!tracker.playing) return;
			tracker.playing = false;
			widget.getPosition(function (p) {
				var pos = (p || 0) / 1000;
				tracker.watched.add(tracker._lastTimeUpdate, pos);
				tracker.lastPosition = pos;
				stopCheckpoints(tracker);
				sendEvent(tracker, 'media-pause');
			});
		});
		widget.bind(SC.Widget.Events.FINISH, function () {
			tracker.playing = false;
			tracker.watched.add(tracker._lastTimeUpdate, tracker.duration);
			tracker.lastPosition = tracker.duration;
			stopCheckpoints(tracker);
			sendEvent(tracker, 'media-ended');
		});
		widget.bind(SC.Widget.Events.SEEK, function (e) {
			var from = tracker.lastPosition;
			tracker.lastPosition = (e.currentPosition || 0) / 1000;
			tracker._lastTimeUpdate = tracker.lastPosition;
			sendEvent(tracker, 'media-seeked', {
				from: Math.round(from), to: Math.round(tracker.lastPosition)
			});
		});
		widget.bind(SC.Widget.Events.PLAY_PROGRESS, function (e) {
			var pos = (e.currentPosition || 0) / 1000;
			if (tracker.playing && pos > tracker._lastTimeUpdate) {
				tracker.watched.add(tracker._lastTimeUpdate, pos);
			}
			tracker._lastTimeUpdate = pos;
			tracker.lastPosition = pos;
		});
	});
}

// ── Wistia ──

function trackWistia(container) {
	var id = genId(container);
	if (state.tracked[id]) return;

	var handleId = container.getAttribute('data-wistia-id')
		|| (container.className.match(/wistia_async_(\w+)/) || [])[1]
		|| id;

	root._wq = root._wq || [];
	root._wq.push({
		id: handleId,
		onReady: function (video) {
			if (state.tracked[id]) return;
			var tracker = createTracker(id, 'wistia', container, video.duration() || 0);
			state.tracked[id] = tracker;
			tracker._player = video;

			video.bind('play', function () {
				tracker.playing = true;
				tracker.lastPosition = video.time();
				tracker._lastTimeUpdate = tracker.lastPosition;
				tracker.duration = video.duration() || tracker.duration;
				sendEvent(tracker, 'media-play');
				startCheckpoints(tracker);
			});
			video.bind('pause', function () {
				if (!tracker.playing) return;
				tracker.playing = false;
				var pos = video.time();
				tracker.watched.add(tracker._lastTimeUpdate, pos);
				tracker.lastPosition = pos;
				stopCheckpoints(tracker);
				sendEvent(tracker, 'media-pause');
			});
			video.bind('end', function () {
				tracker.playing = false;
				tracker.watched.add(tracker._lastTimeUpdate, tracker.duration);
				tracker.lastPosition = tracker.duration;
				stopCheckpoints(tracker);
				sendEvent(tracker, 'media-ended');
			});
			video.bind('seek', function (currentTime, lastTime) {
				tracker.lastPosition = currentTime;
				tracker._lastTimeUpdate = currentTime;
				sendEvent(tracker, 'media-seeked', {
					from: Math.round(lastTime), to: Math.round(currentTime)
				});
			});
			video.bind('secondchange', function (s) {
				var pos = s;
				if (tracker.playing && pos > tracker._lastTimeUpdate) {
					tracker.watched.add(tracker._lastTimeUpdate, pos);
				}
				tracker._lastTimeUpdate = pos;
				tracker.lastPosition = pos;
			});
		}
	});

	// Load Wistia E-v1 if not present
	if (!root.Wistia) {
		var script = document.createElement('script');
		script.src = 'https://fast.wistia.com/assets/external/E-v1.js';
		script.async = true;
		document.head.appendChild(script);
	}
}

// ── JW Player ──

function trackJWPlayer(container) {
	var id = genId(container);
	if (state.tracked[id]) return;

	var playerId = container.id || id;
	function _bind() {
		if (!root.jwplayer || typeof root.jwplayer !== 'function') return false;
		var player;
		try { player = jwplayer(playerId); } catch (e) { return false; }
		if (!player || !player.getState) return false;

		var tracker = createTracker(id, 'jwplayer', container, player.getDuration() || 0);
		state.tracked[id] = tracker;
		tracker._player = player;

		player.on('play', function () {
			tracker.playing = true;
			tracker.lastPosition = player.getPosition();
			tracker._lastTimeUpdate = tracker.lastPosition;
			tracker.duration = player.getDuration() || tracker.duration;
			sendEvent(tracker, 'media-play');
			startCheckpoints(tracker);
		});
		player.on('pause', function () {
			if (!tracker.playing) return;
			tracker.playing = false;
			var pos = player.getPosition();
			tracker.watched.add(tracker._lastTimeUpdate, pos);
			tracker.lastPosition = pos;
			stopCheckpoints(tracker);
			sendEvent(tracker, 'media-pause');
		});
		player.on('complete', function () {
			tracker.playing = false;
			tracker.watched.add(tracker._lastTimeUpdate, tracker.duration);
			tracker.lastPosition = tracker.duration;
			stopCheckpoints(tracker);
			sendEvent(tracker, 'media-ended');
		});
		player.on('seek', function (e) {
			tracker.lastPosition = e.offset;
			tracker._lastTimeUpdate = e.offset;
			sendEvent(tracker, 'media-seeked', {
				from: Math.round(e.position), to: Math.round(e.offset)
			});
		});
		player.on('time', function (e) {
			var pos = e.position;
			if (tracker.playing && pos > tracker._lastTimeUpdate) {
				tracker.watched.add(tracker._lastTimeUpdate, pos);
			}
			tracker._lastTimeUpdate = pos;
			tracker.lastPosition = pos;
			tracker.duration = e.duration || tracker.duration;
		});
		return true;
	}

	// Try immediately, retry after a delay if JW hasn't initialized yet
	if (!_bind()) {
		setTimeout(function () { _bind(); }, 2000);
	}
}

// ── Dailymotion ──

function trackDailymotion(iframe) {
	var id = genId(iframe);
	if (state.tracked[id]) return;

	function _loadAndBind() {
		if (!root.DM || !root.DM.player) {
			var script = document.createElement('script');
			script.src = 'https://api.dmcdn.net/all.js';
			script.onload = function () { _createPlayer(); };
			document.head.appendChild(script);
		} else {
			_createPlayer();
		}
	}

	function _createPlayer() {
		if (state.tracked[id]) return;
		var tracker = createTracker(id, 'dailymotion', iframe, 0);
		state.tracked[id] = tracker;

		var player = DM.player(iframe, { events: {
			playing: function () {
				tracker.playing = true;
				tracker.lastPosition = player.currentTime || 0;
				tracker._lastTimeUpdate = tracker.lastPosition;
				tracker.duration = player.duration || tracker.duration;
				sendEvent(tracker, 'media-play');
				startCheckpoints(tracker);
			},
			pause: function () {
				if (!tracker.playing) return;
				tracker.playing = false;
				var pos = player.currentTime || 0;
				tracker.watched.add(tracker._lastTimeUpdate, pos);
				tracker.lastPosition = pos;
				stopCheckpoints(tracker);
				sendEvent(tracker, 'media-pause');
			},
			end: function () {
				tracker.playing = false;
				tracker.watched.add(tracker._lastTimeUpdate, tracker.duration);
				tracker.lastPosition = tracker.duration;
				stopCheckpoints(tracker);
				sendEvent(tracker, 'media-ended');
			},
			seeking: function () {
				var from = tracker.lastPosition;
				tracker.lastPosition = player.currentTime || 0;
				tracker._lastTimeUpdate = tracker.lastPosition;
				sendEvent(tracker, 'media-seeked', {
					from: Math.round(from), to: Math.round(tracker.lastPosition)
				});
			},
			timeupdate: function () {
				var pos = player.currentTime || 0;
				if (tracker.playing && pos > tracker._lastTimeUpdate) {
					tracker.watched.add(tracker._lastTimeUpdate, pos);
				}
				tracker._lastTimeUpdate = pos;
				tracker.lastPosition = pos;
				tracker.duration = player.duration || tracker.duration;
			}
		}});
		tracker._player = player;
	}

	_loadAndBind();
}

// ── Spotify Embed (limited — position only via playbackUpdate) ──

function trackSpotify(iframe) {
	var id = genId(iframe);
	if (state.tracked[id]) return;

	var tracker = createTracker(id, 'spotify', iframe, 0);
	state.tracked[id] = tracker;

	// Spotify Embed API uses window.onSpotifyIframeApiReady + postMessage
	root.addEventListener('message', function (e) {
		if (!e.data || e.source !== iframe.contentWindow) return;
		var data;
		try { data = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; }
		catch (err) { return; }
		if (!data.type) return;

		if (data.type === 'playback_update') {
			var pos = (data.payload && data.payload.position) || 0;
			pos = pos / 1000; // ms to seconds
			var dur = (data.payload && data.payload.duration) || 0;
			dur = dur / 1000;
			var isPaused = data.payload && data.payload.isPaused;

			tracker.duration = dur || tracker.duration;

			if (!isPaused && !tracker.playing) {
				tracker.playing = true;
				tracker.lastPosition = pos;
				tracker._lastTimeUpdate = pos;
				sendEvent(tracker, 'media-play');
				startCheckpoints(tracker);
			} else if (isPaused && tracker.playing) {
				tracker.playing = false;
				tracker.watched.add(tracker._lastTimeUpdate, pos);
				tracker.lastPosition = pos;
				stopCheckpoints(tracker);
				sendEvent(tracker, 'media-pause');
			} else if (!isPaused && tracker.playing && pos > tracker._lastTimeUpdate) {
				tracker.watched.add(tracker._lastTimeUpdate, pos);
				tracker._lastTimeUpdate = pos;
				tracker.lastPosition = pos;
			}
		}
	});
}

// ── Twitch Player ──

function trackTwitch(iframe) {
	var id = genId(iframe);
	if (state.tracked[id]) return;

	function _loadAndBind() {
		if (!root.Twitch || !root.Twitch.Player) {
			var script = document.createElement('script');
			script.src = 'https://player.twitch.tv/js/embed/v1.js';
			script.onload = function () { _createPlayer(); };
			document.head.appendChild(script);
		} else {
			_createPlayer();
		}
	}

	function _createPlayer() {
		if (state.tracked[id]) return;
		if (!iframe.id) iframe.id = 'twitch-' + id;
		var tracker = createTracker(id, 'twitch', iframe, 0);
		state.tracked[id] = tracker;

		var player = new Twitch.Player(iframe.id, {});
		tracker._player = player;

		player.addEventListener(Twitch.Player.PLAY, function () {
			tracker.playing = true;
			tracker.lastPosition = player.getCurrentTime() || 0;
			tracker._lastTimeUpdate = tracker.lastPosition;
			tracker.duration = player.getDuration() || tracker.duration;
			sendEvent(tracker, 'media-play');
			startCheckpoints(tracker);
			tracker._pollTimer = setInterval(function () {
				var p = player.getCurrentTime() || 0;
				if (tracker.playing && p > tracker._lastTimeUpdate) {
					tracker.watched.add(tracker._lastTimeUpdate, p);
				}
				tracker._lastTimeUpdate = p;
				tracker.lastPosition = p;
			}, 1000);
		});
		player.addEventListener(Twitch.Player.PAUSE, function () {
			if (!tracker.playing) return;
			tracker.playing = false;
			var pos = player.getCurrentTime() || 0;
			tracker.watched.add(tracker._lastTimeUpdate, pos);
			tracker.lastPosition = pos;
			stopCheckpoints(tracker);
			clearInterval(tracker._pollTimer);
			sendEvent(tracker, 'media-pause');
		});
		player.addEventListener(Twitch.Player.ENDED, function () {
			tracker.playing = false;
			var pos = player.getCurrentTime() || tracker.duration;
			tracker.watched.add(tracker._lastTimeUpdate, pos);
			tracker.lastPosition = pos;
			stopCheckpoints(tracker);
			clearInterval(tracker._pollTimer);
			sendEvent(tracker, 'media-ended');
		});
	}

	_loadAndBind();
}

// ── Muse.ai (postMessage API) ──

function trackMuseAi(iframe) {
	var id = genId(iframe);
	if (state.tracked[id]) return;

	var tracker = createTracker(id, 'museai', iframe, 0);
	state.tracked[id] = tracker;

	root.addEventListener('message', function (e) {
		if (!e.data || e.source !== iframe.contentWindow) return;
		var data;
		try { data = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; }
		catch (err) { return; }

		if (data.event === 'play') {
			tracker.playing = true;
			tracker.lastPosition = data.currentTime || 0;
			tracker._lastTimeUpdate = tracker.lastPosition;
			tracker.duration = data.duration || tracker.duration;
			sendEvent(tracker, 'media-play');
			startCheckpoints(tracker);
		} else if (data.event === 'pause') {
			if (!tracker.playing) return;
			tracker.playing = false;
			var pos = data.currentTime || 0;
			tracker.watched.add(tracker._lastTimeUpdate, pos);
			tracker.lastPosition = pos;
			stopCheckpoints(tracker);
			sendEvent(tracker, 'media-pause');
		} else if (data.event === 'ended') {
			tracker.playing = false;
			tracker.watched.add(tracker._lastTimeUpdate, tracker.duration);
			tracker.lastPosition = tracker.duration;
			stopCheckpoints(tracker);
			sendEvent(tracker, 'media-ended');
		} else if (data.event === 'timeupdate') {
			var pos = data.currentTime || 0;
			tracker.duration = data.duration || tracker.duration;
			if (tracker.playing && pos > tracker._lastTimeUpdate) {
				tracker.watched.add(tracker._lastTimeUpdate, pos);
			}
			tracker._lastTimeUpdate = pos;
			tracker.lastPosition = pos;
		}
	});

	// Request the iframe to emit events
	try { iframe.contentWindow.postMessage({ method: 'addEventListener', value: 'play' }, '*'); } catch(e){}
	try { iframe.contentWindow.postMessage({ method: 'addEventListener', value: 'pause' }, '*'); } catch(e){}
	try { iframe.contentWindow.postMessage({ method: 'addEventListener', value: 'ended' }, '*'); } catch(e){}
	try { iframe.contentWindow.postMessage({ method: 'addEventListener', value: 'timeupdate' }, '*'); } catch(e){}
}

// ── Auto-Discovery ──

function discoverMedia() {
	// Native elements
	var natives = document.querySelectorAll(state.options.mediaSelector);
	for (var i = 0; i < natives.length; i++) {
		trackNative(natives[i]);
	}

	// Iframes — detect by src URL
	var iframes = document.querySelectorAll('iframe[src]');
	for (var j = 0; j < iframes.length; j++) {
		var src = iframes[j].src || '';
		if (/youtube\.com\/embed|youtube-nocookie\.com\/embed/.test(src)) {
			trackYouTube(iframes[j]);
		} else if (/player\.vimeo\.com/.test(src)) {
			trackVimeo(iframes[j]);
		} else if (/w\.soundcloud\.com\/player/.test(src)) {
			trackSoundCloud(iframes[j]);
		} else if (/dailymotion\.com\/embed/.test(src)) {
			trackDailymotion(iframes[j]);
		} else if (/open\.spotify\.com\/embed/.test(src)) {
			trackSpotify(iframes[j]);
		} else if (/player\.twitch\.tv/.test(src)) {
			trackTwitch(iframes[j]);
		} else if (/muse\.ai\/embed/.test(src)) {
			trackMuseAi(iframes[j]);
		}
	}

	// Wistia — detected by class name, not iframe
	var wistias = document.querySelectorAll('[class*="wistia_embed"], [class*="wistia_async_"]');
	for (var k = 0; k < wistias.length; k++) {
		trackWistia(wistias[k]);
	}

	// JW Player — detected by container class or data attribute
	var jwContainers = document.querySelectorAll('.jwplayer, [data-jw-id]');
	for (var l = 0; l < jwContainers.length; l++) {
		trackJWPlayer(jwContainers[l]);
	}
}

// ── DOM Mutation Observer ──

function _startMutationObserver() {
	if (!root.MutationObserver || !state.options.observeDom) return;
	var observeRoot = state.options.observeRoot || document.body;

	state._mutationObserver = new MutationObserver(function (mutations) {
		mutations.forEach(function (mutation) {
			mutation.addedNodes.forEach(function (node) {
				if (node.nodeType !== 1) return;
				// Check the node itself
				if (node.matches && node.matches('video, audio')) {
					trackNative(node);
				}
				if (node.tagName === 'IFRAME' && node.src) {
					var s = node.src;
					if (/youtube\.com\/embed/.test(s)) trackYouTube(node);
					else if (/player\.vimeo\.com/.test(s)) trackVimeo(node);
					else if (/w\.soundcloud\.com\/player/.test(s)) trackSoundCloud(node);
					else if (/dailymotion\.com\/embed/.test(s)) trackDailymotion(node);
					else if (/open\.spotify\.com\/embed/.test(s)) trackSpotify(node);
					else if (/player\.twitch\.tv/.test(s)) trackTwitch(node);
					else if (/muse\.ai\/embed/.test(s)) trackMuseAi(node);
				}
				// Wistia containers
				if (node.className && /wistia_embed|wistia_async_/.test(node.className)) {
					trackWistia(node);
				}
				// JW Player containers
				if (node.className && /jwplayer/.test(node.className)) {
					trackJWPlayer(node);
				}
				// Check descendants
				if (node.querySelectorAll) {
					var childNatives = node.querySelectorAll('video, audio');
					for (var i = 0; i < childNatives.length; i++) trackNative(childNatives[i]);
					var childIframes = node.querySelectorAll('iframe[src]');
					for (var j = 0; j < childIframes.length; j++) {
						var cs = childIframes[j].src || '';
						if (/youtube\.com\/embed/.test(cs)) trackYouTube(childIframes[j]);
						else if (/player\.vimeo\.com/.test(cs)) trackVimeo(childIframes[j]);
						else if (/w\.soundcloud\.com\/player/.test(cs)) trackSoundCloud(childIframes[j]);
						else if (/dailymotion\.com\/embed/.test(cs)) trackDailymotion(childIframes[j]);
						else if (/open\.spotify\.com\/embed/.test(cs)) trackSpotify(childIframes[j]);
						else if (/player\.twitch\.tv/.test(cs)) trackTwitch(childIframes[j]);
						else if (/muse\.ai\/embed/.test(cs)) trackMuseAi(childIframes[j]);
					}
					var childWistias = node.querySelectorAll('[class*="wistia_embed"], [class*="wistia_async_"]');
					for (var w = 0; w < childWistias.length; w++) trackWistia(childWistias[w]);
					var childJws = node.querySelectorAll('.jwplayer, [data-jw-id]');
					for (var l = 0; l < childJws.length; l++) trackJWPlayer(childJws[l]);
				}
			});
		});
	});

	state._mutationObserver.observe(observeRoot, { childList: true, subtree: true });
}

// ── Flush All Playing Media ──

function flushAll() {
	for (var id in state.tracked) {
		var tracker = state.tracked[id];
		if (tracker.playing) {
			// Update watched range one last time
			if (tracker.type === 'native' && tracker.el) {
				tracker.watched.add(tracker._lastTimeUpdate, tracker.el.currentTime || tracker.lastPosition);
				tracker.lastPosition = tracker.el.currentTime || tracker.lastPosition;
			}
			sendEvent(tracker, 'media-checkpoint');
		}
	}
}

// ── Public API ──

var MT = {

	/**
	 * Initialize media tracking
	 */
	init: function (options) {
		if (state.initialized) {
			console.warn('Metrics.MediaTracker already initialized');
			return this;
		}

		var opts = {};
		var k;
		for (k in defaults) { if (defaults.hasOwnProperty(k)) opts[k] = defaults[k]; }
		for (k in (options || {})) { if (options.hasOwnProperty(k) && options[k] !== undefined) opts[k] = options[k]; }
		state.options = opts;
		state.initialized = true;

		// Auto-init Metrics core if needed
		if (!Metrics._endpoint && options && options.endpoint) {
			Metrics.init({
				endpoint: options.endpoint, page: options.page,
				trackUnload: options.trackUnload
			});
		}

		// Discover existing media
		if (opts.autoDiscover) {
			setTimeout(function () {
				discoverMedia();
			}, 500);
		}

		// Watch for dynamically added media
		if (opts.observeDom) {
			_startMutationObserver();
		}

		// Flush on page exit
		Metrics.onVisibilityChange(function (visible) {
			if (!visible) flushAll();
		}, 'MediaTracker.flush');

		return this;
	},

	/**
	 * Manually track a native video/audio element
	 * @param {Element} el — the <video> or <audio> element
	 * @param {String} [id] — optional custom ID
	 */
	trackNative: function (el, id) {
		if (id) el.id = id;
		trackNative(el);
	},

	/**
	 * Manually track a YouTube iframe
	 * @param {Element} iframe
	 * @param {String} [id]
	 */
	trackYouTube: function (iframe, id) {
		if (id) iframe.id = id;
		trackYouTube(iframe);
	},

	/**
	 * Manually track a Vimeo iframe
	 * @param {Element} iframe
	 * @param {String} [id]
	 */
	trackVimeo: function (iframe, id) {
		if (id) iframe.id = id;
		trackVimeo(iframe);
	},

	trackSoundCloud: function (iframe, id) {
		if (id) iframe.id = id;
		trackSoundCloud(iframe);
	},

	trackWistia: function (container, id) {
		if (id) container.id = id;
		trackWistia(container);
	},

	trackJWPlayer: function (container, id) {
		if (id) container.id = id;
		trackJWPlayer(container);
	},

	trackDailymotion: function (iframe, id) {
		if (id) iframe.id = id;
		trackDailymotion(iframe);
	},

	trackSpotify: function (iframe, id) {
		if (id) iframe.id = id;
		trackSpotify(iframe);
	},

	trackTwitch: function (iframe, id) {
		if (id) iframe.id = id;
		trackTwitch(iframe);
	},

	trackMuseAi: function (iframe, id) {
		if (id) iframe.id = id;
		trackMuseAi(iframe);
	},

	/**
	 * Get all tracked media and their current state
	 */
	getTracked: function () {
		var result = {};
		for (var id in state.tracked) {
			var t = state.tracked[id];
			result[id] = {
				id: t.id, type: t.type,
				playing: t.playing,
				position: Math.round(t.lastPosition),
				duration: Math.round(t.duration),
				watched: t.watched.total()
			};
		}
		return result;
	},

	/**
	 * Flush checkpoint for all currently playing media
	 */
	flush: flushAll,

	/**
	 * Re-scan the page for new media elements
	 */
	rescan: discoverMedia,

	/**
	 * Destroy — stop all tracking and remove observers
	 */
	destroy: function () {
		for (var id in state.tracked) {
			var t = state.tracked[id];
			stopCheckpoints(t);
			if (t._pollTimer) clearInterval(t._pollTimer);
		}
		state.tracked = {};
		if (state._mutationObserver) {
			state._mutationObserver.disconnect();
			state._mutationObserver = null;
		}
		state.initialized = false;
	},

	defaults: defaults,
	state: state
};

Metrics.MediaTracker = MT;

})(typeof window !== 'undefined' ? window : this);
