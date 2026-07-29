(function (window, Q, $, undefined) {

/**
 * @module Q
 */

/**
 * @class Q audio
 * @constructor
 * @param {Object} [options] Override various options for this tool
 *	@param {String} [options.action="implement"] Which mode need to use for this tool. Can be:
 *		player - render larger player with source audio from state.url
 *		recorder - render audio recorder
 *		compact - render preview in Q/pie view (for preview play)
 *	@param {Integer} [options.maxRecordTime=60] max record time for recorder in seconds
 *	@param {Integer} [options.clipStart] if clip defined, start time in milliseconds
 *	@param {Integer} [options.clipEnd] if clip defined, end time in milliseconds
 *  @param {object} [options.metrics=null] Params for State.Metrics (publisherId and streamName required)
 *  @param {Integer} [options.positionUpdatePeriod=1] Time period in seconds to check new play position.
 *  @param {String} [options.url] URL of audio source
 *  @param {boolean} [options.autoplay=false] If true - start play on load
 */
Q.Tool.define("Q/audio", function (options) {
	var tool = this;
	var state = tool.state;
	var $toolElement = $(tool.element);

	if (state.url) {
		state.url = state.url.interpolate({ "baseUrl": Q.info.baseUrl });
	}

	if (state.action === "player" && Q.isEmpty(state.url)) {
		throw new Q.Error("URL required");
	}

	if (!Q.isEmpty(state.metrics)) {
		tool.metrics = new Q.Streams.Metrics(state.metrics);
	}

	// convert to milliseconds, as we use milliseconds everywhere for calculations
	state.maxRecordTime *= 1000;

	tool.adapters = {
		"soundcloud": {
			refresh: function (showPlayer) {
				showPlayer = Q.typeOf(showPlayer) === "boolean" ? showPlayer : true;
				var url = 'https://w.soundcloud.com/player/?' + $.param({
					url: state.url,
					auto_start: state.autoplay,
					buying: false,
					sharing: false,
					download: false
				});

				$toolElement.addClass("Q_audio_soundcloud");

				Q.Template.render('Q/audio/soundcloud', {
					url: url
				}, function (err, html) {
					if (showPlayer) {
						Q.replace(tool.element, html);;

						var $iframe = tool.$("iframe");
						$iframe.on("load", function () {
							Q.handle(state.onLoad, tool);
						});
					} else {
						$toolElement.append(html);
					}
				});

				Q.addScript('https://w.soundcloud.com/player/api.js', function () {
					var $iframe = tool.$("iframe[name=soundcloud]");
					state.audio = SC.Widget($iframe[0]);
					var onPlay = Q.throttle(function (position) {
						//console.log("Started at position " + position + " milliseconds");
						Q.handle(state.onPlay, tool, [position]);
					}, 100);
					var onPause = Q.throttle(function (position) {
						//console.log("Paused at position " + position + " milliseconds");
						Q.handle(state.onPause, tool, [position]);
					}, 100);
					var onFinish = Q.throttle(function (position) {
						//console.log("Finished at position " + position + " milliseconds");
						Q.handle(state.onEnded, tool, [position]);
					}, 100);
					var onSeek = Q.throttle(function (position) {
						//console.log("Seeked at position " + position + " milliseconds");
						Q.handle(state.onSeek, tool, [position]);
					}, 100);
					state.audio.bind(SC.Widget.Events.READY, function() {
						state.audio.bind(SC.Widget.Events.PLAY, function() {
							// get current sound position in milliseconds
							state.audio.getPosition(onPlay);
						});
						state.audio.bind(SC.Widget.Events.PAUSE, function() {
							// get current sound position in milliseconds
							state.audio.getPosition(onPause);
						});
						state.audio.bind(SC.Widget.Events.FINISH, function() {
							onPause(state.currentPosition);
							// get current sound position in milliseconds
							state.audio.getPosition(onFinish);
						});
						state.audio.bind(SC.Widget.Events.SEEK, function() {
							onPause(state.currentPosition);
							// get current sound position in milliseconds
							state.audio.getPosition(onSeek);
						});
						state.audio.bind(SC.Widget.Events.PLAY_PROGRESS, function() {
							state.audio.getPosition(function (position) {
								state.currentPosition = Math.trunc(position);
							});
						});
						state.audio.getDuration(function(duration) {
							state.duration = duration;
						});

						if (showPlayer) {
							tool.renderPlayer();
						}
					});
				});
			},
			renderPlayer: function () {
				tool.$("iframe[name=soundcloud]").show();
			},
			play: function () {
				state.audio.play();
			},
			pause: function () {
				state.audio.pause();
			},
			setCurrentPosition: function (position) {
				state.audio.seekTo(Math.trunc(position));
			},
			getCurrentPosition: function () {
				return state.currentPosition;
			},
			getDuration: function () {
				return tool.audio.getDuration();
			}
		},
		"general": {
			refresh: function (showPlayer) {
				showPlayer = Q.typeOf(showPlayer) === "boolean" ? showPlayer : true;
				tool.implementNativeAudio();

				var justOnce = true;
				tool.audioElement.addEventListener("canplay", function(){
					if (!justOnce) {
						return;
					}

					justOnce = false;
					if (showPlayer) {
						tool.renderPlayer();
					}

					if (state.autoplay) {
						tool.play();
					}
				});
			},
			renderPlayer: function () {
				tool.audioElement.setAttribute('controls', true);
				$toolElement.append(tool.audioElement);
			},
			play: function () {
				// if audio element visible, use it to interaction
				if (tool.$audioElement.is(":visible")) {
					tool.audioElement.play();
				} else { // else use Q.Audio class method
					tool.audio.play(tool.audioElement.currentTime);
				}
			},
			pause: function () {
				// if audio element visible, use it to interaction
				if (tool.$audioElement.is(":visible")) {
					tool.audioElement.pause();
				} else { // else use Q.Audio class method
					tool.audio.pause();
				}
			},
			setCurrentPosition: function (position) {
				tool.audioElement.currentTime = position/1000;
			},
			getCurrentPosition: function () {
				return Math.trunc(tool.audioElement.currentTime * 1000);
			},
			getDuration: function () {
				return tool.audioElement.duration * 1000;
			}
		}
	};

	Q.handle(tool[state.action], tool);

	$toolElement.on(Q.Pointer.fastclick, ".Q_audio_close", function () {
		tool.pause();
		$toolElement.hide();
	});
},

{
	action: "player",
	url: null,
	autoplay: false,
	/* <Q/audio jquery plugin states> */
	pie: { // default Q/pie options
		fraction: 0,
		borderSize: 5,
		size: null
	},
	positionUpdatePeriod: 1, // seconds
	maxRecordTime: 60, // seconds
	duration: 0,
	currentPosition: 0,
	clipStart: null,
	clipEnd: null,
	metrics: {},
	floating: {
		evenIfPaused: false
	},
	onSuccess: new Q.Event(),
	onError: new Q.Event(function (message) {
		Q.alert('File upload error' + (message ? ': ' + message : '') + '.');
	}, 'Q/audio'),
	onFinish: new Q.Event(),
	/* </Q/audio jquery plugin states> */
	onLoad: new Q.Event(function () {
		var tool = this;
		var state = this.state;

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
						$("<div>").appendTo("body").tool("Q/audio", {
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
	onPlay: new Q.Event(function () {
		var tool = this;
		var state = this.state;

		// set box attribute to apply valid styles
		this.$pieBox && this.$pieBox.attr("data-state", "pause");

		tool.checkClip();

		tool.clearPlayInterval();
		state.playIntervalID = setInterval(function() {
			state.currentPosition = tool.getCurrentPosition();

			if (state.currentPosition === this.currentPosition) {
				return;
			}

			this.currentPosition = state.currentPosition;

			Q.handle(state.onPlaying, tool, [tool]);
		}, state.positionUpdatePeriod * 1000);
	}),
	onPlaying: new Q.Event(function () {
		var tool = this;
		var state = this.state;

		if (tool.metrics) {
			tool.metrics.add(state.currentPosition/1000);
		}

		if (tool.pieTool) {
			tool.pieTool.state.fraction = 100 * state.currentPosition / state.duration;
			tool.pieTool.stateChanged('fraction');
		}

		tool.checkClip();
	}),
	onPause: new Q.Event(function () {
		// stop timer if exist
		this.clearPlayInterval();

		// set box attribute to apply valid styles
		this.$pieBox && this.$pieBox.attr("data-state", "play");
	}),
	onSeek: new Q.Event(),
	onEnded: new Q.Event()
},

{
	/**
	 * Check clip borders and pause if outside
	 * @method checkClip
	 */
	checkClip: function () {
		var tool = this;
		var state = this.state;

		// clipStart handler
		if (state.clipStart && state.currentPosition < state.clipStart) {
			tool.setCurrentPosition(state.clipStart);
		}
		// clipStart handler
		if (state.clipEnd && state.currentPosition > state.clipEnd) {
			tool.pause();
			tool.setCurrentPosition(state.clipEnd);
		}
	},
	/**
	 * Refreshes the appearance of the tool completely
	 * @method refresh
	 * @param {boolean} [showPlayer=true] If true show player, if false hide.
	 */
	refresh: function (showPlayer) {
		var adapterName = this.adapterNameFromUrl();
		this.adapters[adapterName].refresh(showPlayer);
	},
	/**
	 * Handle recorder states
	 * @method recorderStateChange
	 * @param {string} newState New recorder state
	 */
	recorderStateChange: function(newState){
		var tool = this;
		var state = this.state;
		var $toolElement = $(this.element);

		var $recordTextElement = $(".Q_audio_record_recordText", $toolElement);
		var $recordElapsedElement = $(".Q_audio_record_elapsed", $toolElement);
		var $recordTimeElement = $(".Q_audio_record_recordTime", $toolElement);

		var _resetPie = function () {
			// reset pie tool to start point
			if(tool.pieTool){
				state.currentRecordTime = state.maxRecordTime;

				$recordTextElement.html(tool.text.audio.record);
				$recordElapsedElement.html(tool.text.audio.maximum);
				$recordTimeElement.html(tool.formatRecordTime(state.currentRecordTime));
				tool.pieTool.initPos();
			}
		};

		state.recorderState = newState;

		// set tool element attribute to style player according to newState
		$toolElement.attr("data-state", newState);

		// set pieBox attribute to style player according to newState
		tool.$pieBox.attr("data-state", newState);

		// init recorder
		if(newState === "init"){
			// reset pie tool to start point
			_resetPie();

			// if recorder exists - just change state to "ready"
			if(state.recorder /* && state.recorder.stream */){
				//tool.recorderStateChange("ready");
				state.recorder.open(function() {
					tool.recorderStateChange("ready");
				}, function(msg, isUserNotAllow) {
					console.error("Open recorder failed: " + msg);
				});
				return;
			}

			//if recorder don't exist - create one
			/* tool.audio.recorderInit({
				onStreamReady: function(){
					state.recorder = tool.audio.recorder;

					// when recorder created - change state to "ready"
					tool.recorderStateChange("ready");
				},
				onDataAvailable: function(){
					var e = this;

					state.dataBlob = new Blob( [e.detail], { type: 'audio/mp3', name: "audio.mp3" } );
					tool.audioElement.src = URL.createObjectURL(state.dataBlob);
				}
			}); */

			Q.addScript("{{Q}}/js/audioRecorder/recorder.mp3.min.js", function(){ //https://github.com/xiangyuecn/Recorder
				state.recorder = Recorder({
					type: "mp3",
					sampleRate: 16000,
					bitRate: 16,
					onProcess: function(buffers, powerLevel, bufferDuration, bufferSampleRate, newBufferIdx, asyncEnd) {
						// Optional: handle real-time processing
					}
				});

				state.recorder.open(function() {
					tool.recorderStateChange("ready");

				}, function(msg, isUserNotAllow) {
					console.error("Open recorder failed: " + msg);
				});
			});
		}

		// clear recorder
		if(newState === "clear"){
			// reset pie tool to start point
			_resetPie();

			tool.audioElement.pause();
			state.dataBlob = null;
			if(state.recorder){
				//state.recorder.clearStream();
				state.recorder.close();
			}
			return;
		}

		// play recorded track
		if(newState === "playing"){
			$recordTextElement.html(tool.text.audio.playing);
			tool.audioElement.play();
		}else{
			tool.audioElement.pause();
		}

		// ready to play
		if(newState === "play"){
			$recordTextElement.html(tool.text.audio.recorded);
			$recordElapsedElement.html(tool.text.audio.clip);
			$recordTimeElement.html(tool.formatRecordTime(tool.audioElement.duration));
		}

		// recorder stoped
		if(newState === "recorded"){
			tool.pieTool.initPos();
			state.recorder.stop(function(blob, duration) {
				var url = URL.createObjectURL(blob);
				state.dataBlob = blob;
				tool.audioElement.src = url;
				tool.recorderStateChange("play");

				state.recorder.close();
			}, function(msg) {
				console.error("Recording failed: " + msg);
				state.recorder.close();
			});
		}

		// stop recording
		if(newState !== "recording"){
			// stop recording if recorder exist
			//if(state.recorder){ state.recorder.stop(); }

			// stop recorder timer if exist
			tool.clearPlayInterval();

			return;
		}

		// start record
		state.recorder.start();

		// set some elements text
		$recordTextElement.html(tool.text.audio.recording);
		$recordElapsedElement.html(tool.text.audio.remains);
		$recordTimeElement.html(tool.formatRecordTime(state.maxRecordTime));

		// start recorder timer to handle all recorder interface actions
		state.recordIntervalID = setInterval(function(){
			// set currentRecordTime to maxRecordTime if undefined
			if(typeof state.currentRecordTime === 'undefined') state.currentRecordTime = state.maxRecordTime - 1000;

			// decrease currentRecordTime to 100 milliseconds
			state.currentRecordTime -= 100;

			// set time element text
			$recordTimeElement.html(tool.formatRecordTime(state.currentRecordTime));

			// set Q/pie tool position (percents of currentRecordTime time from max maxRecordTime)
			tool.pieTool.state.fraction = 100*(state.maxRecordTime - state.currentRecordTime)/state.maxRecordTime;
			tool.pieTool.stateChanged('fraction');

			// max record time spent
			if(state.currentRecordTime <= 1){ tool.recorderStateChange("recorded"); }
		}, 100);
		//--------------------//
	},
	/**
	 * @method playAudio
	 * Play audio and detect promise event on mobile devices
	 */
	play: function(){
		var adapterName = this.adapterNameFromUrl();
		var adapter = this.adapters[adapterName];

		adapter.play();
	},
	renderPlayer: function () {
		var adapterName = this.adapterNameFromUrl();
		var adapter = this.adapters[adapterName];

		adapter.renderPlayer();
	},
	/**
	 * @method pauseAudio
	 * Pause audio and detect promise event on mobile devices
	 */
	pause: function(){
		var adapterName = this.adapterNameFromUrl();
		this.adapters[adapterName].pause();
	},
	/**
	 * Get audio duration
	 * @method getDuration
	 * @return {float} Audio track duration in milliseconds
	 */
	getDuration: function(){
		var adapterName = this.adapterNameFromUrl();
		return this.adapters[adapterName].getDuration();
	},
	/**
	 * Set current play position
	 * @method setCurrentPosition
	 * @return {integer} position in milliseconds
	 */
	setCurrentPosition: function (position) {
		var adapterName = this.adapterNameFromUrl();
		this.adapters[adapterName].setCurrentPosition(position);
	},
	/**
	 * Get current play position
	 * @method getCurrentPosition
	 */
	getCurrentPosition: function () {
		var adapterName = this.adapterNameFromUrl();
		return this.adapters[adapterName].getCurrentPosition();
	},
	/**
	 * @method userGesture
	 * User gesture handler for audio actions if promise != undefined (mobile devices)
	 * @param {string} action Player action (play, pause, ...)
	 * @param {object} promise Promise returned
	 */
	userGesture: function(action, promise){
		var tool = this;
		var pieTool = tool.$pieElement.clone().removeAttr("id");

		//throw new Q.Error("User gesture required!");
		return console.warn("User gesture required!");

		// we have promise on mobile device which need user gesture
		promise.then(function() {
			console.log('The play() Promise fulfilled!');
		}).catch(function(error) {
			console.log('The play() Promise rejected!');
			Q.Dialogs.push({
				className: 'Streams_audio_preview_dialog_promisePlay',
				content: pieTool,
				destroyOnClose: true,
				noClose: true,
				onActivate: function (dialog) {
					$(".Q_mask").on(Q.Pointer.fastclick, Q.Dialogs.pop);
					dialog.attr("data-state", tool.$playerBox.attr("data-state"));

					$(".Q_tool.Q_pie_tool", dialog).on(Q.Pointer.fastclick, function(event){
						event.stopPropagation();
						event.preventDefault();

						if(action === "play") {
							tool.audioElement.play();
						}

						if(action === "pause") {
							tool.audioElement.pause();
						}

						Q.Dialogs.pop();
					})
				},
				beforeClose: function(dialog){
					$(".Q_mask").off(Q.Pointer.fastclick, Q.Dialogs.pop);
				}
			});
		});
	},
	/**
	 * Render recorder as Q/pie view
	 * @method recorder
	 */
	recorder: function() {
		var tool = this;
		var state = this.state;
		var $toolElement = $(this.element);

		$toolElement.addClass("Q_audio_recorder");

		Q.Template.render("Q/audio/recorder", {
			maxRecordTime: tool.formatRecordTime(state.maxRecordTime),
			text: tool.text.audio,
			allowMicrophoneAccess: window.isSecureContext
				? Q.text.Q.audio.allowMicrophoneAccess
				: Q.text.Q.browser.insecureContext
		}, function (err, html) {
			$toolElement.html(html);

			tool.implementNativeAudio();

			$(".Q_audio_pie", $toolElement).append(Q.Tool.setUpElement('div', 'Q/pie')).activate(function () {
				var pieTool = tool.pieTool = this;
				tool.$pieBox = $toolElement;
				var $recordTimeElement = $(".Q_audio_record_recordTime", $toolElement);

				// set Q/pie tool handler
				$toolElement.on(Q.Pointer.fastclick, function(event){
					event.stopPropagation();
					event.preventDefault();

					var recorderState = state.recorderState;

					// click on arc border - means change play position
					if(!pieTool.state.clickPos.inside && recorderState === "playing"){
						tool.audioElement.currentTime = tool.audioElement.duration / 100 * pieTool.state.clickPos.anglePercent;
						return;
					}

					// set pie state
					if(!recorderState) recorderState = "init";
					else if(recorderState === "ready") recorderState = "recording";
					else if(recorderState === "recording") recorderState = "recorded";
					else if(recorderState === "playing") recorderState = "play";
					else if(recorderState === "play") recorderState = "playing";

					tool.recorderStateChange(recorderState);
				});

				// discard recorded track
				$("button[name=discard]", $toolElement).on(Q.Pointer.click, function (e) {
					tool.recorderStateChange("init");
				});

				// change tracking time during playing
				state.onPlaying.set(function () {
					$recordTimeElement.html(tool.formatRecordTime(state.duration - state.currentPosition) + '/' + tool.formatRecordTime(state.duration));
				}, tool.id + "_recorder");
			});
		});
	},
	/**
	 * Render player as Q/pie view
	 * @method player
	 */
	player: function() {
		var tool = this;
		var state = this.state;
		tool.$pieBox = $(tool.element);
		tool.$playerBox = $("<div>").appendTo(tool.$pieBox);

		tool.refresh(false);

		// set player state if it didn't set yet
		tool.$pieBox.attr("data-state", "play");

		// set up the pie options
		var pieOptions = state.pie;

		// set up Q/pie tool and activate
		tool.$playerBox.addClass("Q_disabled").tool("Q/pie", pieOptions).activate(function(){
			var pieTool = tool.pieTool = this;
			var $pieElement = tool.$pieElement = $(pieTool.element);

			state.onLoad.add(function () {
				tool.$playerBox.removeClass("Q_disabled")
			}, tool);

			$pieElement.on(Q.Pointer.fastclick, function (event) {
				event.stopPropagation();
				event.preventDefault();

				// click on arc border - means change play position
				if(!pieTool.state.clickPos.inside){
					var newPosition = state.duration/100*pieTool.state.clickPos.anglePercent;
					tool.setCurrentPosition(newPosition);
					tool.play();
					return;
				}

				// swap play/pause
				if (tool.$pieBox.attr("data-state") === "play") {
					tool.play();
				} else {
					tool.pause();
				}
			});
		});
	},
	/**
	 * Get or create new Q.Audio with needed events to use later in tool
	 * @method implementNativeAudio
	 */
	implementNativeAudio: function () {
		var tool = this;
		var $toolElement = $(this.element);
		var state = this.state;

		if (tool.audio) {
			return tool.audio;
		}

		$toolElement.addClass("Q_audio_native");

		var $recordTimeElement = $(".Q_audio_record_recordTime", this.element);

		tool.audio = new Q.Audio(state.url, tool.element);
		tool.audioElement = tool.audio.audio;
		tool.$audioElement = $(tool.audioElement);

		$toolElement.append($('<div class="Q_audio_close"></div>'));

		tool.audioElement.addEventListener('play', function () {
			state.currentPosition = Math.trunc(this.currentTime * 1000);
			//console.log("Started at position " + state.currentPosition + " milliseconds");
			Q.handle(state.onPlay, tool, [state.currentPosition]);
		});

		tool.audioElement.addEventListener('seeked', function () {
			state.currentPosition = Math.trunc(this.currentTime * 1000);
			Q.handle(state.onSeek, tool, [state.currentPosition]);
		});

		/* tool.audioElement.addEventListener("canplay", function(){
			state.duration = Math.trunc(tool.audioElement.duration * 1000);

			Q.handle(state.onLoad, tool);

			if(state.action === "recorder"){
				tool.recorderStateChange("play");
			}
		}); */

		//Safari doesn't fire canplay event unless web-site is in the user-controlled white-list:
		//https://stackoverflow.com/questions/50051639/javascript-html5-video-event-canplay-not-firing-on-safari
		tool.audioElement.addEventListener("loadedmetadata", function(){
			state.duration = Math.trunc(tool.audioElement.duration * 1000);

			Q.handle(state.onLoad, tool);

			if(state.action === "recorder"){
				tool.recorderStateChange("play");
			}
		});
		tool.audioElement.addEventListener("ended", function(){
			var duration = this.duration;

			// stop timer if exist
			tool.clearPlayInterval();

			// Q/pie tool reset position
			tool.pieTool && tool.pieTool.initPos();

			// set audio element to start
			this.currentTime = 0;

			// set box attribute to apply valid styles
			tool.$pieBox && tool.$pieBox.attr("data-state", "play");

			if(state.action === "player") {

			} else if (state.action === "recorder") {
				tool.recorderStateChange("play");
				$recordTimeElement.length && $recordTimeElement.html(tool.formatRecordTime(duration));
			}

			var time = Math.trunc(this.currentTime * 1000);
			//console.log("Ended at position " + time + " milliseconds");
			Q.handle(state.onEnded, tool, [time]);
		});
		tool.audioElement.addEventListener("pause", function(){
			var time = Math.trunc(this.currentTime * 1000);
			//console.log("Paused at position " + time + " milliseconds");
			Q.handle(state.onPause, tool, [time]);
		});
	},
	/**
	 * Draw custom audio player
	 * @method customPlayer
	 */
	customPlayer: function () {
		var tool = this;
		var state = this.state;

		Q.Template.render("Q/audio/general", {
			duration: tool.formatRecordTime(tool.getDuration(), "short")
		}, function (err, html) {
			var $this = $(html);
			$(tool.element).append($this);

			var $track = $(".Q_audio_general_track", $this);
			var $play = $(".Q_audio_general_play", $this);
			var $sound = $(".Q_audio_general_sound", $this);
			var $trackCurrent = $(".Q_audio_general_trackCurrent", $this);

			var _calcTrackWidth = function () {
				var siblingsWidth = 0;
				$track.siblings().each(function() {
					siblingsWidth += $(this).outerWidth(true);
				});

				$track.width($this.innerWidth() - siblingsWidth);
			}();

			$play.on(Q.Pointer.fastclick, function () {
				var state = $play.attr("data-state");

				if (state === "pause") {
					$play.attr("data-state", "play");
					tool.audio.pause();
					//tool.pause();
				} else {
					$play.attr("data-state", "pause");
					tool.audio.play();
					//tool.play();
				}
			});

			$sound.on(Q.Pointer.fastclick, function () {
				var state = $sound.attr("data-state");

				if (state === "on") {
					$sound.attr("data-state", "off");
				} else {
					$sound.attr("data-state", "on");
				}
			});

			var isDragging = false;
			$trackCurrent.on(Q.Pointer.fastclick, function (e) {
				e.stopPropagation();
			});

			$track.mousedown(function() {
				isDragging = true;
			}).mousemove(function(e) {
				if (isDragging) {
					$trackCurrent.css("left", e.offsetX);
					//console.log(e.offsetX);
				}
			}).mouseup(function() {
				isDragging = false;
			});
		});
	},
	/**
	 * Detect adapter from url
	 * @method adapterNameFromUrl
	 */
	adapterNameFromUrl: function () {
		var url = this.state.url || "";

		if (url.includes("soundcloud.com") && !url.includes(".mp3")) {
			return 'soundcloud';
		}
		if (url.split('.').pop() === 'mp3') {
			return 'general';
		}
		if (url.split('.').pop() === 'ogg') {
			return 'general';
		}

		return "general";
	},
	/**
	 * Format record time elapsed
	 * @method formatRecordTime
	 * @param {int} time Time elapsed in miliseconds
	 * @param {string} [format="classic"] "classic": "01h:21m:32s", "short": "01:21:32"
	 * @return {string} formatted string
	 */
	formatRecordTime: function(time, format) {
		time = time/1000;
		format = format || "classic";
		var sec_num = parseInt(time, 10);
		var hours   = Math.floor(sec_num / 3600);
		var minutes = Math.floor((sec_num - (hours * 3600)) / 60);
		var seconds = sec_num - (hours * 3600) - (minutes * 60);

		var res;

		switch (format) {
			case "short":
				res = (hours ? hours + ':' : '') + (minutes ? minutes + ':' : '') + seconds;
				break;
			default:
				res = (hours ? hours + 'h ' : '') + (minutes ? minutes + 'm ' : '') + seconds+'s';
		}

		return res;
	},
	clearPlayInterval: function () {
		if (this.state.playIntervalID) {
			clearInterval(this.state.playIntervalID);
			this.state.playIntervalID = false;
		}

		if(this.state.recordIntervalID) {
			clearInterval(this.state.recordIntervalID);
			this.state.recordIntervalID = false;
		}
	},
	Q: {
		beforeRemove: function () {
			this.clearPlayInterval();
			if (this.metrics) {
				this.metrics.stop();
			}

			this.pause();
		}
	}
});

Q.Template.set('Q/audio/general',
'<div class="Q_audio_general">' +
	'	<div class="Q_audio_general_play" data-state="play"></div>' +
	'	<div class="Q_audio_general_time"><span class="Q_audio_general_timeCurrent">0</span>/<span class="Q_audio_general_timeTotal">{{duration}}</span></div>' +
	'	<div class="Q_audio_general_track">' +
	'		<span class="Q_audio_general_trackTotal"></span>' +
	'		<span class="Q_audio_general_trackPlay"></span>' +
	'		<span class="Q_audio_general_trackCurrent"></span>' +
	'	</div>' +
	'	<div class="Q_audio_general_sound" data-state="on"></div>' +
	'</div>' +
	'<div class="Q_audio_close"></div>'
);

Q.Template.set('Q/audio/soundcloud',
	'<iframe id="sc-widget" name="soundcloud" width="100%" height="166" allow=autoplay scrolling="no" frameborder="no" src="{{url}}"></iframe>' +
	'<div class="Q_audio_close"></div>'
);

Q.Template.set('Q/audio/recorder',
	'  <div class="Q_audio_record">'
	+ '    <div class="Q_audio_pie"></div>'
	+ '    <div class="Q_audio_record_label"><p class="Q_audio_record_recordText">{{text.record}}</p><p><span class="Q_audio_record_recordTime">{{maxRecordTime}}</span> <span class="Q_audio_record_elapsed">{{text.maximum}}</span></p></div>'
	+ '    <div class="Q_audio_allow">{{allowMicrophoneAccess}}</div>'
	+ '</div>'
	//+ '<div class="Q_audio_actions">'
	//+ '    <button name="discard">{{textDiscard}}</button>'
	//+ '</div>'
	+ '<div class="Q_audio_encoding">{{text.encoding}}</div>'
);
})(window, Q, Q.jQuery);