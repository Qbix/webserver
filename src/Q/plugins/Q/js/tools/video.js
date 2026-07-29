(function (window, Q, $, undefined) {

/**
 * @module Q
 */

/**
 * Video player tool, that can play videos hosted by
 * youtube, vimeo, muse, twitch, odysee and other backends
 * @class Q video
 * @constructor
 * @param {Object} [options] Override various options for this tool
 *  @param {String} [options.url] URL of video source
 *  @param {string} [options.start] start position in milliseconds
 *  @param {string} [options.clipStart] Clip start position in milliseconds
 *  @param {string} [options.clipEnd] Clip end position in milliseconds
 *  @param {string} [options.className] Additional class name to add to tool element
 *  @param {object} [options.metrics] Whether to send metrics about playback
 *  @param {string} [options.image] URL of image which will show as illustration before play.
 *  @param {object} [options.clips] Contains options for "clips" mode.
 *  @param {Integer} [options.positionUpdatePeriod=1]
 *  @param {boolean} [options.autoplay=false]
 *  @param {boolean} [options.loop=false]
 *  @param {array} [options.ads]
 *  @param {array} [options.skipPositionOnLoad=false]
 *  @param {boolean} [options.skipPauseOnload=false]
 */
Q.Tool.define("Q/video", function (options) {
	var tool = this;
	var state = tool.state;
	var $toolElement = $(tool.element);
	state.isIos = Q.info.platform === "ios";

	if (state.url) {
		state.url = state.url.interpolate({ "baseUrl": Q.info.baseUrl });
	}

	if (state.className) {
		$toolElement.addClass(state.className);
	}

	tool.adapters = {};

	var sm = state.metrics;
	if (Q.Streams && Q.Streams.Metrics) {
		if (!Q.isEmpty(sm) && sm.publisherId && sm.streamName) {
			tool.metrics = new Q.Streams.Metrics(state.metrics);
		}
	}

	state.videojsOptions = Q.extend({
		autoplay: state.autoplay,
		loop: state.loop,
		muted: state.muted,
		controls: state.controls,
		playsinline: state.playsinline
	}, state.videojsOptions);

	// helper to hide overlays when playback actually starts
	function hidePlayOverlays() {
		$(".vjs-big-play-button", tool.element).hide();
		$(".Q_video_overlay_play", tool.element).hide();
	}

	tool.adapters.mp4 = {
		init: function () {
			tool.initVideojsPlayer({
				sources: [{ src: state.url, type: 'video/mp4' }]
			});
		}
	};

	tool.adapters.webm = {
		init: function () {
			tool.initVideojsPlayer({
				sources: [{ src: state.url, type: 'video/webm' }]
			});
		}
	};

	tool.adapters.ogg = {
		init: function () {
			tool.initVideojsPlayer({
				sources: [{ src: state.url, type: 'video/ogg' }]
			});
		}
	};

	tool.adapters.youtube = {
		init: function () {
			state.controls = 0;
			state.videojsOptions.ytControls = 1;
			Q.addScript("{{Q}}/js/videojs/plugins/YouTube.js", function () {
				tool.initVideojsPlayer({
					techOrder: ["youtube"],
					sources: [{ src: state.url, type: 'video/youtube' }],
					youtube: state.videojsOptions
				});
			});
		}
	};

	tool.adapters.vimeo = {
		init: function () {
			Q.addScript("{{Q}}/js/videojs/plugins/Vimeo.js", function () {
				tool.initVideojsPlayer({
					techOrder: ["vimeo"],
					sources: [{ src: state.url, type: 'video/vimeo' }],
					vimeo: state.videojsOptions
				});
			});
		}
	};

	tool.adapters.odysee = { //plays livestream from odysee in iframe
		init: function () {
			Q.addScript("https://cdn.embed.ly/player-0.1.0.min.js", function () {
				var iframe = document.createElement('iframe');
				iframe.src = state.url;
				tool.element.appendChild(iframe);
				tool.element.classList.add('Q_video_odysee');

				state.player = new playerjs.Player(iframe);
				state.player.on('play', function () {
					Q.handle(state.onPlay, tool);
				});
				state.player.on('pause', function () {
					Q.handle(state.onPause, tool);
				});
				state.player.on('ended', function () {
					Q.handle(state.onEnded, tool);
				});
				state.player.on('volumechange', function () {
					Q.handle(state.onVolumechange, tool, [state.player.volume()]);
				});
			});
		}
	};

	tool.adapters.muse = {
		init: function () {
			Q.addScript("https://muse.ai/static/js/embed-player.min.js", function () {
				var customPlayButton = Q.getObject("overlay.play.src", state);
				var defaults = {
					container: tool.element,
					start: state.start || 0,
					loop: state.loop,
					muted: state.muted,
					controls: state.controls,
					autoplay: state.autoplay,
					width: "100%", // Desired player width. Can be provided as an integer (in pixels) or a relative value as a string (e.g. '100%').
					height: "100%", // Desired player height. Can be provided as an integer (in pixels) or a relative value as a string (e.g. '100%').
					sizing: "fill", // Set to 'fill' to indicate that the player should fill the entire container's size and introduce
					style: 'no-controls', // Set to minimal for minimal player controls, and to no-controls to hide controls entirely.
					logo: false, // Set to false to hide branding on the player.
					resume: false, // Set to true to seek back to the last viewing position when the video loads.
					links: false, // Set to false to disable links to muse.ai.
					search: false, // Set to false to hide the search functionality.
					title: false, // Set to false to hide the title.
					volume: 100 // Set volume to a value between 0 and 100.
				}
				if (defaults.autoplay || defaults.muted) {
					defaults.volume = 0; // otherwise browsers block it
				}
				var match = state.url.match(/\/v\/([0-9A-Za-z]+).*$/);
				if (!match) {
					return Q.Error("Q/video/muse: need url to contain '/v/:museVideoId'");
				}
				var custom = {};
				custom.video = match[1];
				if (customPlayButton) {
			
					custom.css = '.video-container{min-width:0; min-height:0;} .player-cover-play{background-image: url('
					+ Q.url(customPlayButton) 
					+ ');top: 50%;left: 50%;transform: translate(-50%, -50%);}';
				}
				var options = Q.extend({}, defaults, custom, state.muse);
				state.player = MusePlayer(options);
				state.player.on('play', function () {
					Q.handle(state.onPlay, tool);
				});
				state.player.on('pause', function () {
					Q.handle(state.onPause, tool);
				});
				state.player.on('ended', function () {
					Q.handle(state.onEnded, tool);
				});
				state.player.on('volumechange', function () {
					Q.handle(state.onVolumechange, tool, [state.player.volume()]);
				});
				state.player.on('metadata', function () {

				});
			});
		}
	};

	tool.adapters.twitch = {
		init: function () {
			var element = tool.element;
			var throttle = state.throttle;

			var newA = document.createElement('A');
			newA.href = state.url;
			var host = newA.hostname;

			element.classList.add("Q_video_twitch");
			var options = {
				autoplay: state.autoplay,
				loop: state.loop,
				muted: state.muted,
				controls: state.controls,
				//channel: "<channel ID>",
				//video: "782042263",
				//collection: "<collection ID>"
			};

			// convert start time to pass as option
			var startTime = tool.calculateStartPosition();
			if (startTime) {
				startTime = Q.displayDuration(startTime, {hours:true}).split(':');
				startTime = startTime[0] + 'h' + startTime[1] + 'm' + startTime[2] + 's';
				options.time = startTime;
			}

			// skip setting start position on load because we set this time in options.time
			state.skipPositionOnLoad = true;

			var videoId = state.url.match(/\/videos?\/([0-9]+).*$/);
			var channel = state.url.match(new RegExp(host + "/(\\w+)"));
			if (videoId) {
				options.video = videoId[1];
			} else if (channel) {
				options.channel = channel[1];
			} else {
				throw new Q.Error("Q/video/twitch: none of videoId or channel filled");
			}

			$toolElement.append($("<div class='Q_video_close'>"));

			Q.addScript("{{Q}}/js/twitch/lib.js", function () {
				state.player = new Twitch.Player(element, options);

				state.player.waiting = function (status) {
					// noop for twitch, but preserve interface
				};


				// place play button above the player
				var $overlayPlay = $(".Q_video_overlay_play", tool.element);

				// skip using overlay button for ios, because of weird behavior
				// some times called event onPause but video doens't paused and play further
				if (!$overlayPlay.length) {
					Q.Template.render("Q/video/twitch/overplay", {
						src: Q.url(state.overlay.play.src),
						poster: state.image
					}, function (err, html) {
						if (err) {
							return;
						}

						$overlayPlay = $(html);
						$overlayPlay.appendTo(tool.element);
						$("img", $overlayPlay).on(Q.Pointer.fastclick, function () {
							$overlayPlay.hide();
							state.player.play();
						})
					});
				}

				/**
				 * Implemented global function "currentTime" to set/get position
				 * @method currentTime
				 * @param {integer} [time] If defined, set play position. If not, return current pos.
				 */
				state.player.currentTime = function (time) {
					if (!isNaN(time)) {
						state.player.seek(time);
						return time;
					}

					return state.player.getCurrentTime();
				};

				/**
				 * Implemented global function "muted" to on/off sound
				 * @method muted
				 * @param {boolean} [value=true] If true/false, turn off/on sound.
				 */
				state.player.muted = function (value) {
					if (typeof value === "boolean") {
						state.player.setMuted(value);
					} else {
						return state.player.getMuted();
					}
				};

				/**
				 * Trigger to show/hide loading spinner above player
				 * @method waiting
				 * @param {boolean} [status=true] If true/false - show/hide spinner.
				 */
				state.player.waiting = function (status) {
					if (status || typeof status === "undefined") {
						$('<div class="Q_video_spinner">').appendTo(state.player._target);
					} else {
						$(".Q_video_spinner", state.player._target).remove();
					}
				};

				var onPlay = Q.throttle(function () {
					state.currentPosition = tool.getCurrentPosition();
					//console.log("Started at position " + state.currentPosition + " milliseconds");
					Q.handle(state.onPlay, tool);
					$overlayPlay.hide();
				}, throttle);
				var onPause = Q.throttle(function () {
					var position = tool.getCurrentPosition();
					//console.log("Paused at position " + position + " milliseconds");
					Q.handle(state.onPause, tool);
					//$overlayPlay.show();
				}, throttle);
				var onEnded = Q.throttle(function () {
					var position = tool.getCurrentPosition();
					//console.log("Seeked at position " + position + " milliseconds");
					Q.handle(state.onEnded, tool);
					//$overlayPlay.show();
				}, throttle);

				state.player.addEventListener(Twitch.Player.PLAY, onPlay);
				state.player.addEventListener(Twitch.Player.PAUSE, onPause);
				state.player.addEventListener(Twitch.Player.ENDED, onEnded);
				state.player.addEventListener(Twitch.Player.READY, function () {
					Q.handle(state.onLoad, tool);
				});
			});
		}
	};

	$toolElement.on(Q.Pointer.fastclick, ".Q_video_close", function () {
		tool.pause();
		$toolElement.hide();
	});

	tool.refresh();
},

{
	url: null,
	autoplay: false,
	loop: false,
	muted: false,
	controls: true,
	playsinline: true,
	throttle: 10,
	currentPosition: 0,
	className: null,
	image: null,
	skipPositionOnLoad: false,
	positionUpdatePeriod: 1, // seconds
	start: null,
	clipStart: null,
	clipEnd: null,
	skipPauseOnload: false,
	muse: {
		//start: 0, // Time at which the video should start playing.
		//width: "100%", // Desired player width. Can be provided as an integer (in pixels) or a relative value as a string (e.g. '100%').
		//height: "100%", // Desired player height. Can be provided as an integer (in pixels) or a relative value as a string (e.g. '100%').
		//sizing: "fill", // Set to 'fill' to indicate that the player should fill the entire container's size and introduce
		// black bars as necessary. Set to 'fit' to span the parent container while maintaining the player's aspect ratio.
		// When using this value make sure that the container's parent has explicit width and height that don't depend on the player.
		// If sizing is set, width and height will be ignored.
		//style: 'no-controls', // Set to minimal for minimal player controls, and to no-controls to hide controls entirely.
		//loop: true,
		//logo: false, // Set to false to hide branding on the player.
		//resume: false, // Set to true to seek back to the last viewing position when the video loads.
		//links: false, // Set to false to disable links to muse.ai.
		//search: false, // Set to false to hide the search functionality.
		//title: false, // Set to false to hide the title.
		//autoplay: true,
		//volume: 100 // Set volume to a value between 0 and 100.
	},
	ads: [],
	floating: {
		evenIfPaused: false
	},
	adsTimeOut: 10,
	overlay: {
		play: {
			src: '{{Q}}/img/play.png'
		}
	},
	metrics: {
		useFaces: false
	},
	videojsOptions: {
		inactivityTimeout: 2000,
		userActive: true
	},
	clips: {
		handler: null,
		duration: null,
		oneSecondPercent: 1/6,
		offset: 0,
		pointers: []
	},
	onSuccess: new Q.Event(),
	onError: new Q.Event(function (message) {
		Q.alert('File upload error' + (message ? ': ' + message : '') + '.');
	}, 'Q/video'),
	onFinish: new Q.Event(),
	onLoad: new Q.Event(function () {
		var tool = this;
		var state = this.state;
		var skipPauseOnload = !state.skipPauseOnload && !state.autoplay;
		this.setCurrentPosition(this.calculateStartPosition(), skipPauseOnload, skipPauseOnload);
		this.addAdvertising();

		// preload next clip
		this.preloadNextClip();

		if (state.floating.evenIfPaused) {
			// listen element size changes
			Q.onLayout(tool.element).set(function () {
				var rect = tool.element.getBoundingClientRect();

				if (rect.width === 0 || rect.height === 0) {
					tool.pause();
					if (tool.floated) {
						var $floatedElement = $(tool.floated.element);
						if (!$floatedElement.is(":visible")) {
							tool.floated.setCurrentPosition(state.currentPosition);
							$floatedElement.show();
						}
					} else if (!state.floated) {
						$("<div>").appendTo("body").tool("Q/video", {
							url: state.url,
							start: state.currentPosition,
							floated: true
						}).tool("Q/floating").activate(function () {
							tool.floated = this;
						});
					}
				} else {
					if (tool.floated) {
						tool.setCurrentPosition(tool.floated.state.currentPosition);
						tool.floated.pause();
						$(tool.floated.element).hide();
					}
				}
			}, tool);
		}
	}),
	onCanPlay: new Q.Event(function () {
		// don't call calculateStartPosition here! Because onCanPlay event can be called each seeking.
	}),
	onPlay: new Q.Event(function () {
		var tool = this;
		var state = this.state;

		this.checkClip();

		tool.clearPlayInterval();
		state.playIntervalID = setInterval(function() {
			var currentPosition = tool.getCurrentPosition();

			if (state.currentPosition === currentPosition) {
				return;
			}

			state.currentPosition = (currentPosition || state.clips.start || 0);
			Q.handle(state.onPlaying, tool, [tool]);
		}, state.positionUpdatePeriod * 1000);
	}),
	onPlaying: new Q.Event(function () {
		var state = this.state;

		if (this.metrics) {
			this.metrics.add(state.currentPosition/1000);
		}

		// check clip borders if clip defined
		this.checkClip();

		// update pointers position on timeline for "clips" mode
		this.moveClipsPointers();

		if (!state.clips.useNativeDuration) {
			// check clips duration limit
			var clipsDuration = Q.getObject("clips.duration", state);
			if (clipsDuration && state.currentPosition/1000 >= clipsDuration) {
				Q.handle(state.onEnded, this);
			}
		}
	}),
	onPause: new Q.Event(function () {
		this.clearPlayInterval();
	}),
	onSeek: new Q.Event(function (position) {
		this.state.currentPosition = position;
	}),
	onVolumechange: new Q.Event(),
	onEnded: new Q.Event(function () {
		var tool = this;
		var state = this.state;

		// stop timer if exist
		this.clearPlayInterval();

		// check clips to play next
		var pointers = Q.getObject("clips.pointers", state);
		if (!Q.isEmpty(pointers)) {
			var nextPointer = null;
			var i = 1000;
			Q.each(pointers, function () {
				var start = parseFloat(this.$start.attr("data-time"));

				// only next points
				if (start < 0 || state.url === this.url) {
					return;
				}

				var offsetDeviation = this.offset - state.clips.offset;
				if (offsetDeviation > 0 && offsetDeviation < i) {
					i = offsetDeviation;
					nextPointer = this;
				}
			});
			if (nextPointer) {
				tool.changeSource(nextPointer);
			}
		}
	})
},

{
	/**
	 * Change current player source.
	 * @method changeSource
	 * @param {object} source Object with properties: url, duration, offset, ...
	 */
	changeSource: function (source) {
		var state = this.state;
		var player = state.player;

		// set autoplay to autostart next clip
		state.player.autoplay(true);

		state.url = source.url;
		player.src(source.url);

		state.clips.duration = source.duration;

		// don't pause when new clip loaded
		state.skipPauseOnload = true;

		// set offset to duration of prev clip
		state.clips.offset = source.offset;
	},
	/**
	 * Check clip borders and pause if outside
	 * @method checkClip
	 */
	checkClip: function () {
		var tool = this;
		var state = this.state;
		var currentPosition = tool.getCurrentPosition();

		// clipStart handler
		if (state.clipStart && currentPosition < state.clipStart) {
			tool.setCurrentPosition(state.clipStart);
		}
		// clipStart handler
		if (state.clipEnd && currentPosition > state.clipEnd) {
			tool.pause();
			tool.setCurrentPosition(state.clipEnd);
		}
	},
	/**
	 * Refreshes the appearance of the tool completely
	 * @method refresh
	 */
	refresh: function () {
		var tool = this;

		var adapterName = tool.adapterNameFromUrl();
		adapterName && tool.adapters[adapterName].init();
	},
	/**
	 *
	 * @method initVideojsPlayer
	 */
	initVideojsPlayer: function (options) {
		var tool = this;
		var state = this.state;
		var throttle = state.throttle;
		var fields = {
			autoplay: state.autoplay ? 'autoplay' : '',
			loop: state.loop ? 'loop' : '',
			controls: state.controls ? 'controls' : '',
			poster: state.image ? 'poster="' + Q.url(state.image) + '"' : '',
			muted: state.muted ? 'muted' : '',
			playsinline: state.playsinline ? 'playsinline webkit-playsinline' : '',
			timeOut: state.adsTimeOut
		};
		Q.Template.render('Q/video/videojs', fields, function (err, html) {
			Q.replace(tool.element, html);

			var onPlay = Q.throttle(function () {
				var position = state.currentPosition || tool.getCurrentPosition();
				//console.log("Started at position " + position + " milliseconds");

				Q.handle(state.onPlay, tool, [position]);
			}, throttle);
			var onPause = Q.throttle(function () {
				$(".Q_video_overlay_play", tool.element).show();
				var position = state.currentPosition || tool.getCurrentPosition();
				//console.log("Paused at position " + position + " milliseconds");
				Q.handle(state.onPause, tool, [position]);
			}, throttle);
			var onSeek = Q.throttle(function () {
				var position = tool.getCurrentPosition();
				//console.log("Seeked at position " + position + " milliseconds");
				Q.handle(state.onSeek, tool, [position]);
			}, throttle);
			var onVolumechange = Q.throttle(function () {
				Q.handle(state.onVolumechange, tool, [state.player.volume()]);
			}, throttle);
			var onEnded = Q.throttle(function () {
				$(".Q_video_overlay_play", tool.element).show();
				var position = state.currentPosition || tool.getCurrentPosition();
				//console.log("Seeked at position " + position + " milliseconds");
				Q.handle(state.onEnded, tool, [position]);
			}, throttle);

			state.player = videojs($("video", tool.element)[0], options, function onPlayerReady() {
				var player = this;
				tool.player = this;

				videojs.log('Your player is ready!');

				this.on('play', onPlay);

				this.on('pause', onPause);

				this.on('volumechange', onVolumechange);

				//this.on('waiting', function() {
				//	onPause();
				//});

				this.on('seeked', onSeek);

				this.on('ended', onEnded);

				// apply play button image
				$(".vjs-big-play-button", this.el_).css("background-image", "url(" +Q.url(state.overlay.play.src) + ")");

				var playerElement = player.el_;

				/**
				 * Trigger to show/hide loading spinner above player
				 * @method waiting
				 * @param {boolean} [state=true] If true - show spinner, false - hide.
				 */
				this.waiting = function (state) {
					if (state || typeof state === "undefined") {
						player.addClass("vjs-waiting");

						// need to check eventBusEl_ to avoid error from videojs lib
						if (playerElement) {
							// this event show preloader spinner
							player.trigger("waiting");
						}
					} else {
						player.removeClass("vjs-waiting");

						// need to check eventBusEl_ to avoid error from videojs lib
						if (playerElement) {
							player.trigger("canplay");
						}
					}
				};

				// call onLoad when loadedmetadata event occured
				this.on("loadedmetadata", function() {
					Q.handle(state.onLoad, tool);
				});

				this.on("canplay", function() {
					if (this.canplay) {
						return;
					}

					this.canplay = true;
					Q.handle(state.onCanPlay, tool);
				});

				// apply clips mode if clips.handler defined
				if (Q.getObject("clips.handler", state)) {
					// set initial clips.offset when first clip loaded
					state.clips.offset = 0;

					// render custom player controls
					Q.Template.render("Q/video/clips/control", {}, function (err, html) {
						var $newControls = $(html);

						// wait till all control elements rendered
						var waitControls = setInterval(function () {
							var nativeControl = {
								$controls: $(".vjs-control-bar", playerElement),
								$playButton: $(".vjs-control-bar .vjs-play-control", playerElement),
								$volumeControl: $(".vjs-control-bar .vjs-volume-panel", playerElement),
								$fullScreen: $(".vjs-control-bar .vjs-fullscreen-control", playerElement),
								$currentTime: $(".vjs-control-bar .vjs-current-time", playerElement)
							};

							var passed = true;

							if (!player.duration()) {
								passed = false;
							}

							Q.each(nativeControl, function () {
								if (!this.length) {
									passed = false;
								}
							});

							if (!passed) {
								return;
							}

							clearInterval(waitControls);

							videojs.log('duration: ' + player.duration());
							state.clips.duration = Q.getObject("clips.duration", state) || Q.getObject("duration", state.clips.handler(0));
							state.duration = state.clips.duration || player.duration();

							nativeControl.$controls.after($newControls).hide();
							$(".vjs-play-control", $newControls).replaceWith(nativeControl.$playButton);
							$(".vjs-volume-panel", $newControls).replaceWith(nativeControl.$volumeControl);
							$(".vjs-fullscreen-control", $newControls).replaceWith(nativeControl.$fullScreen);
							$(".vjs-current-time", $newControls).replaceWith(nativeControl.$currentTime);

							// fill timeline with clips pointers
							var nextClip;
							var fullTimelineSeconds = 100/state.clips.oneSecondPercent;
							for (var nextClipIndex = -10000; nextClipIndex <= 10000; nextClipIndex++) {
								nextClip = state.clips.handler(-fullTimelineSeconds/2 + nextClipIndex);
								if (!nextClip) {
									continue;
								}

								tool.addClipsPointer(nextClip);
							}

							tool.preloadNextClip();

							// progress bar seek handler
							$(".vjs-progress-control", $newControls).on("click", function (e) {
								var $holder = $(".vjs-progress-holder", $newControls);
								var rect = $holder[0].getBoundingClientRect();
								var x = e.clientX - rect.left; //x position within the element.

								// click outside holder
								if (x < 0 || rect.width < x) {
									return;
								}

								var offset = state.clips.offset + tool.getCurrentPosition()/1000 + ((x / rect.width * 100) - 50)/state.clips.oneSecondPercent ;

								// search pointer to move to
								var pointer = Q.getObject("pointer", tool.getClipsPointer(offset));
								if (!pointer) {
									return;
								}

								// change offset to move timeline
								state.clips.offset = pointer.offset;

								var seekedPosition = (offset - pointer.start) * 1000; // in milliseconds

								// move timeline
								tool.moveClipsPointers(null, seekedPosition);

								//return;
								if (state.url === pointer.url) {
									tool.setCurrentPosition(seekedPosition);
								} else {
									// set next clip start position
									state.clips.start = seekedPosition;

									// change source
									tool.changeSource(pointer);
								}

							});
						}, 500);
					});
				}
			});
		});
	},
	/**
	 * @method play
	 */
	play: function () {
        console.log('play start')
		var tool = this;
		var state = this.state;
		state.player && state.player.play();
		Q.handle(state.onPlay, tool, [state.currentPosition || tool.getCurrentPosition()]);
	},
	/**
	 * @method pause
	 */
	pause: function () {
		var tool = this;
		var state = this.state;
		state.player && state.player.pause();
		Q.handle(state.onPause, tool, [state.currentPosition || tool.getCurrentPosition()]);
	},
	/**
	 * Add clip pointer to timeline
	 * @method addClipsPointer
	 * @param {object} clip
	 */
	addClipsPointer: function (clip) {
		var tool = this;
		var state = this.state;

		// check if already exists
		var exists = false;
		Q.each(state.clips.pointers, function () {
			if (this.url === clip.url) {
				exists = true;
			}
		});
		if (exists) {
			return;
		}

		console.log(clip.url);

		var start = clip.offset;
		var end = start + clip.duration;

		clip.$start = $("<div class='Q_video_clips_pointer' data-time='" + start + "'>").appendTo($(".Q_video_clips_control .vjs-progress-control", tool.element));
		clip.$end = $("<div class='Q_video_clips_pointer' data-time='" + end + "'>").appendTo($(".Q_video_clips_control .vjs-progress-control", tool.element));
		clip.start = start;
		clip.end = end;

		state.clips.pointers.push(clip);

		// place on timeline
		tool.moveClipsPointers(clip);
	},
	/**
	 * Change clips pointers position on timeline.
	 * @method moveClipsPointers
	 * @param {object} [pointer] Pointer to move. If empty move all pointers.
	 * @param {number} [offset] Offset in milliseconds to move pointer inside clip
	 */
	moveClipsPointers: function (pointer, offset) {
		var state = this.state;
		var pointers = state.clips.pointers;

		if (Q.isEmpty(pointer) && Q.isEmpty(pointers)) {
			return;
		}

		var oneSecondPercent = state.clips.oneSecondPercent;
		var _handler = function () {
			var $start = this.$start;
			var $end = this.$end;

			var startLeft = 50 + this.start * oneSecondPercent - (offset || state.currentPosition)/1000*oneSecondPercent - state.clips.offset*oneSecondPercent;
			var endLeft = startLeft + this.duration * oneSecondPercent;

			$start[0].style.left = startLeft + '%';
			startLeft > 98 || startLeft < 2 ? $start.hide() : $start.show();

			$end[0].style.left = endLeft + '%';
			endLeft > 98 || endLeft < 2 ? $end.hide() : $end.show();
		};

		if (pointer) {
			return Q.handle(_handler, pointer);
		}

		// move all pointers
		Q.each(pointers, _handler);
	},
	/**
	 * Get clips pointer by offset. Or current pointer if offset null.
	 * @method getClipsPointer
	 * @param {number} [offset] Offset in milliseconds. If undefined, current pointer will return;
	 * @return {object} pointer founded or null
	 */
	getClipsPointer: function (offset) {
		var state = this.state;

		// if offset not defined, use current offset
		offset = offset === undefined ? state.clips.offset : offset;

		var pointer = null;
		var index = null;
		Q.each(state.clips.pointers, function (i) {
			if (this.start > offset || this.end < offset) {
				return;
			}

			pointer = this;
			index = i;
		});

		return {
			index: index,
			pointer: pointer
		};
	},
	/**
	 *
	 * @method preloadNextCLip
	 */
	preloadNextClip: function () {
		var index = Q.getObject("index", this.getClipsPointer());
		if (!index) {
			return;
		}

		var nextClip = this.state.clips.pointers[index + 1];
		if (nextClip) {
			this.preloadClip(nextClip.url);
		}
	},
	/**
	 * @method addAdvertising
	 */
	addAdvertising: function () {
		var tool = this;
		var state = this.state;
		var player = state.player;
		var ads = state.ads;
		var timeOut = state.adsTimeOut;

		if (Q.isEmpty(ads)) {
			return;
		}

		Q.addStylesheet("{{Q}}/css/videojs.markers.min.css");
		Q.addScript("{{Q}}/js/videojs/plugins/videojs-markers.js", function () {
			var markers = [];

			// wait till duration
			var durationTimeId = setInterval(function () {
				var duration = player.duration();
				if (!duration) {
					return;
				}

				clearInterval(durationTimeId);

				Q.each(ads, function (i) {
					if (typeof this.position === "string" && this.position.indexOf("%") >= 0) {
						this.position = duration / 100 * parseInt(this.position);
					}

					markers.push({
						time: this.position,
						text: "Advertising " + (i+1),
						index: i,
						url: this.url
					});
				});

				if (typeof player.markers !== "function") {
					return;
				}

				player.markers({
					breakOverlay: {
						display: false
					},
					onMarkerReached: function(marker) {
						if (Q.isEmpty(marker)) {
							return;
						}

						player.pause();
						player.addClass("Q_video_hidden");

						$("<div>").appendTo(tool.element).tool("Q/video", {
							url: marker.url,
							autoplay: true,
							metrics: null,
							className: "Q_video_advertising Q_current",
							positionUpdatePeriod: 1,
							onLoad: function () {
								var advTool = this;
								advTool.play();

								Q.Template.render('Q/video/skip', {
									text: tool.text.video,
									timeOut: state.adsTimeOut
								}, function (err, html) {
									$(html).appendTo(advTool.element);
								});

								player.pause();
							},
							onPlaying: function () {
								var advTool = this;
								var $skipElement = $(".Q_video_skip", this.element);
								if (!$skipElement.length) {
									return;
								}

								var $timeToSkip = $(".Q_video_skip_time", $skipElement);
								var timeToSkip = parseInt($timeToSkip.text());
								if (!timeToSkip || timeToSkip <= 0) {
									$(".Q_video_skip_after", $skipElement).html(tool.text.video.Skip);
									$(".Q_video_skip_time", $skipElement).html(">>");
									$skipElement.on(Q.Pointer.fastclick, function () {
										Q.handle(advTool.state.onEnded, advTool);
									});
								} else {
									$timeToSkip.html(timeToSkip-1);
								}

								player.pause();
							},
							onEnded: function () {
								Q.Tool.remove(this.element, true, true);
								player.markers.remove([0]);
								player.removeClass("Q_video_hidden");
								player.play();
							}
						}).activate();
					},
					markers: markers
				});
				player.trigger("loadedmetadata");
			}, 1000);
		});
	},
	/**
	 * @method setCurrentPosition
	 * @param {integer} position in milliseonds
	 * @param {boolean} [silent=false] whether to mute sound while setting position
	 * @param {boolean} [pause=false] whether to pause video after position changed
	 */
	setCurrentPosition: Q.debounce(function (position, silent, pause) {
        console.log('setCurrentPosition start', position)
		var tool = this;
		var state = this.state;
		var player = state.player;
		var currentPosition = tool.getCurrentPosition();

		if (currentPosition === position) {
			return;
		}

		if (silent) {
			player.muted(true);
			player.waiting(true);
		}

            console.log('setCurrentPosition 1')
		// convert to seconds
		player.currentTime(position > 0 ? position/1000 : 0);

            console.log('setCurrentPosition 3')
		// this event need to show videojs control bar
		player.hasStarted && player.hasStarted(true);

		if (silent || pause) {
            console.log('setCurrentPosition 4')

			// wait for start position
			var counter = 0;
			var intervalId = setInterval(function() {
				var currentPosition = tool.getCurrentPosition();

            console.log('setCurrentPosition 5')
				if (currentPosition === position || counter > 20) {
					if (silent) {
						player.muted(!!state.videojsOptions.muted);
						player.waiting(false);
					}

            console.log('setCurrentPosition 6')
					if (pause) {
						player.pause();

            console.log('setCurrentPosition 7')
						// this event need to set status paused, because for some reason it stay in status vjs-playing
						if (player.trigger) {
            console.log('setCurrentPosition 8')
							player.trigger('pause');
							player.removeClass('vjs-playing');
						}
					}

					intervalId && clearInterval(intervalId);
				}

				counter++;
			}, 500);
		}
	}, 200),
	/**
	 * @method getCurrentPosition
	 */
	getCurrentPosition: function () {
		var currentTime = (Q.getObject("state.player.currentTime", this) && this.state.player.currentTime()) || 0;
		return Math.floor(currentTime * 1000);
	},
	/**
	 * Detect adapter from url
	 * @method adapterNameFromUrl
	 * @param {string} url
	 */
	adapterNameFromUrl: function (url) {
		var state = this.state;
		
		url = url || state.url;
		if (!url) {
			//throw new Q.Exception(this.id + ": url is required");
			console.warn(this.id + ": url is required");
			return null;
		}
		var newA = document.createElement('A');
		newA.href = url;
		var host = newA.hostname;

		var ext = url.split('.').pop();
		switch(ext) {
			case 'webm': return 'webm';
			case 'ogg': return 'ogg';
			case 'mp4': return 'mp4';
		}

		if (host.indexOf("youtube.com") >= 0 || host.indexOf("youtu.be") >= 0) {
			return 'youtube';
		} else if (host.indexOf("vimeo.com") >= 0) {
			return 'vimeo';
		} else if (host.indexOf("twitch") >= 0) {
			return 'twitch';
		} else if (host.indexOf("odysee") >= 0) {
			return 'odysee';
		} else if (host.indexOf("muse.ai") >= 0) {
			return 'muse';
		}

		return 'mp4';
		//throw new Q.Exception(this.id + ': No adapter for this URL');
	},
	/**
	 * Preload movie and create URL object with source.
	 * @method preloadClip
	 */
	preloadClip: function (url) {
		var req = new XMLHttpRequest();
		req.open('GET', url, true);
		req.responseType = 'blob';
		req.onload = function() {
			// Onload is triggered even on 404
			// so we need to check the status code
			if (this.status === 200) {
				URL.createObjectURL(this.response);
			}
		}
		req.send();
	},
	/**
	 *
	 * @method calculateStartPosition
	 */
	calculateStartPosition: function () {
		var state = this.state;
		var start = 0;

		if (state.clipStart) {
			start = parseInt(state.clipStart) || 0;
		}
		if (state.start) {
			start = parseInt(state.start) || 0;
		}
		if (state.clips.start) {
			start = parseInt(state.clips.start) || 0;

			// avoid from seeking next clip
			state.clips.start = null;
		}

		return start;
	},
	/**
	 *
	 * @method clearPlayInterval
	 */
	clearPlayInterval: function () {
		if (this.state.playIntervalID) {
			clearInterval(this.state.playIntervalID);
			this.state.playIntervalID = false;
		}
	},
	Q: {
		beforeRemove: function () {
			this.clearPlayInterval();

			this.pause();

			// if videojs, call dispose to kill this player with events, triggers, dom etc
			if (Q.getObject("player.dispose", this.state)) {
				this.state.player.dispose();
			}

			if (this.metrics) {
				this.metrics.stop();
			}
		}
	}
});

Q.Template.set("Q/video/videojs",
	'<video preload="auto" class="video-js vjs-default-skin vjs-4-3" width="100%" height="auto" {{{muted}}} {{{controls}}} {{{autoplay}}} {{{loop}}} {{{poster}}} {{{playsinline}}} /></video>' +
	'<div class="Q_video_close"></div>'
);

Q.Template.set("Q/video/skip",
	'<span class="Q_video_skip">' +
	'	<span class="Q_video_skip_after">{{text.SkipAfter}}</span> <span class="Q_video_skip_time">{{timeOut}}</span>' +
	'</div>'
);

Q.Template.set("Q/video/clips/control",
	'<div class="vjs-control-bar Q_video_clips_control" dir="ltr">' +
	'	<div class="vjs-play-control"></div>' +
	'	<div class="vjs-volume-panel"></div>' +
	'	<div class="vjs-current-time"></div>' +
	'	<div class="vjs-progress-control vjs-control">' +
	'		<div class="vjs-progress-holder vjs-slider vjs-slider-horizontal" role="slider" aria-label="Progress Bar">' +
	'			<div class="vjs-play-progress vjs-slider-bar" aria-hidden="true" style="width: 50%;"></div>' +
	'		</div>' +
	'	</div>' +
	'	<div class="vjs-fullscreen-control"></div>' +
	'</div>'
);

Q.Template.set("Q/video/twitch/overplay", 
	'<div class="Q_video_overlay_play" style="background-image: url({{poster}})"><img src="{{src}}" /></div>'
);

})(window, Q, Q.jQuery);
