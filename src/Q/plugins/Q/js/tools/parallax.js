(function (Q, $) {
	/**
	 * @module Q-tools
	 */

	/**
	 * This tool creates parallax effect on tool element.
	 * @class Q parallax
	 * @constructor
	 * @param {Object}             [options] Override various options for this tool
	 *  @param {bool|Number}       [options.speed=false] A negative value will make it move slower than regular scrolling, and faster for positive. Recommended keeping the speed between -10 and 10.
	 *  @param {Float}             [options.center=0.5] Can move start position of parallax element. Top if > 0.5 and bottom if less.
	 *  @param {Number}            [options.zIndex] Can set particular z-index
	 *  @param {String}            [style] Any styles you want to to apply to element.
	 *  @param {String}            [className] Any css classes you want to to apply to element.
	 *  @param {boolean|number}    [leftToRight=false] Horizontal left to right. Use number to define movement limit. If true, 1 value using (means move from border to border).
	 *  @param {boolean|number}    [rightToLeft=false] Horizontal right to left. Use number to define movement limit. If true, 1 value using (means move from border to border).
	 *  @param {boolean|Number}    [spin=false] will spin element to angle defined by number. If just true, angle=360.
	 *  @param {boolean|Number}    [spinRev=false] same as above but spin counterclock-wise.
	 *  @param {boolean|Number}    [spinIn=false] will spin element to angle defined by number. If just true, angle=360.
	 *  @param {boolean|Number}    [spinOut=false] will spin element to angle defined by number. If just true, angle=360.
	 *  @param {boolean|Number}    [fadeInOut=false] fade in than out.
	 *  @param {boolean|Number}    [fadeIn=false] just fade in
	 *  @param {boolean|Number}    [fadeOut=false] just fade out
	 *  @param {boolean|number}    [zoomInOut=false] zoom in than out. Use number as measure of zoom. If true, 0.2 value using.
	 *  @param {boolean|Number}    [zoomIn=false] just zoom in. Use number as measure of zoom. If true, 0.2 value using.
	 *  @param {boolean|Number}    [zoomOut=false] just zoom out. Use number as measure of zoom. If true, 0.2 value using.
	 *  @param {boolean|Number}    [blurInOut=false] blur in than out. Use number as measure of blur. If true, 40 value using.
	 *  @param {boolean|Number}    [blurIn=false] just blur in. Use number as measure of blur. If true, 40 value using.
	 *  @param {boolean|Number}    [blurOut=false] just blur out. Use number as measure of blur. If true, 40 value using.
	 *  @param {boolean|number}    [spin=false] Set angle to rotate element (for example 360). If positive - clockwise, negative - back clockwise.
	 *  @param {boolean|number}    [swing=false] Skew element to and fro. Use number as measure of Skew. If true, 30 value using.
	 *  @param {boolean|number}    [slalom=false] Moving element to and fro. Use number as measure of swing. If true, 50 value using.
	 *  @param {number}            [offset=0] scrollY position so the animation will begin at this point
	 *  @param {String|HTMLElement}	[wrapper] By default, the position of parallax elements is determined via the scroll position of the body.
	 *    Passing in the wrapper property will force to watch that element instead.
	 *  @param {Q.Event}           [onMove] Event that happens when parallax position changed.
	 * @return {Q.Tool}
	 */
	Q.Tool.define("Q/parallax", function () {
		var tool = this;
		var state = this.state;
		
		var s = Q.Pointer.preventRubberBand.suspend;
		if (!s['Q/parallax']) {
			s['Q/parallax'] = 0;
		}
		++s['Q/parallax'];

		Q.setObject("Q.Pointer.preventRubberBand.suspend", true);

		if (state.style) {
			tool.element.setAttribute('style', state.style);
		}
		if (state.className) {
			tool.element.className += ' ' + state.className;
		}

		if (state.speed) {
			tool.rellax();
		} else {
			tool.lax();
		}
	},

	{
		speed: false,
		center: 0.5,
		zIndex: null,
		optimize: false,
		wrapper: null,
		leftToRight: false,
		rightToLeft: false,
		spin: false,
		fadeInOut: false,
		fadeIn: false,
		fadeOut: false,
		zoomInOut: false,
		zoomIn: false,
		zoomOut: false,
		blurInOut: false,
		blurIn: false,
		blurOut: false,
		swing: false,
		slalom: false,
		offset: 0,
		onMove: new Q.Event()
	},

	{
		lax: function () {
			var tool = this;
			var $te = $(this.element);
			var state = this.state;
			
			function update () {
				lax.update(window.scrollY);
				if (!tool.removed) {
					window.requestAnimationFrame(update);
				}
			};

			Q.addScript("{{Q}}/js/parallax/lax.js", function () {
				var preset = [];
				var options = [];

				if (state.leftToRight) {
					preset.push(state.leftToRight === true ? 'leftToRight' : 'leftToRight-' + state.leftToRight);
				} else if (state.rightToLeft) {
					preset.push(state.rightToLeft === true ? 'rightToLeft' : 'rightToLeft-' + state.rightToLeft);
				}

				if (state.spin) {
					if (state.spin > 0) {
						preset.push('spin-' + state.spin);
					} else {
						preset.push('spinRev-' + (-1 * state.spin));
					}
				}

				Q.each(['fadeInOut', 'fadeIn', 'fadeOut', 'spinIn', 'spinOut'], function (i, k) {
					if (state[k]) {
						preset.push(state[k] === true ? k : (k + '-' + state[k]));
					}
				});
				
				Q.each([
					'zoomInOut', 'zoomIn', 'zoomOut', 'blurInOut', 'blurIn', 'blurOut',
					'swing', 'slalom'
				], function (i, k) {
					if (state[k]) {
						preset.push(state[k] === true ? k : (k + '-' + state[k]));
					}
				});

				if (state.optimize) {
					$te.attr('data-lax-optimize', true);
				}

				if (state.offset) {
					options.push('offset=' + state.offset);
				}

				$te.addClass('lax');
				if (preset.length) {
					$te.attr('data-lax-preset', preset.join(' '));
				}
				if (options.length) {
					$te.attr('data-lax-options', options.join(' '));
				}

				lax.addElement($te[0]);

				if (!_parallaxApplied) {
					lax.setup();

					var w = window.innerWidth;
					window.addEventListener("resize", function() {
						if (w !== window.innerWidth) {
							lax.updateElements();
						}
					});
					_parallaxApplied = true;
				}
				
				if (!_startedAnimationFrames) {
					window.requestAnimationFrame(update);
					_startedAnimationFrames = true;
				}
			});
		},
		rellax: function () {
			var tool = this;
			var state = this.state;

			Q.addScript("{{Q}}/js/parallax/rellax.js", function () {
				var options = {
					speed: state.speed,
					center: true,
					round: true,
					optimize: state.optimize,
					vertical: true,
					horizontal: false,
					callback: function(positions) {
						Q.handle(state.onMove, tool, [positions]);
					}
				};

				if (state.wrapper) {
					options.wrapper = state.wrapper;
				}
				if (state.center) {
					tool.element.setAttribute('data-rellax-percentage', state.center);
				}
				if (state.zIndex) {
					tool.element.setAttribute('data-rellax-zindex', state.zIndex);
				}

				tool.parallax = new Rellax(tool.element, options);
			});
		},
		refresh: function () {
			this.parallax && this.parallax.refresh();
		},
		Q: {
			beforeRemove: function () {
				this.parallax && this.parallax.destroy();
				var s = Q.Pointer.preventRubberBand.suspend;
				if (--s['Q/parallax'] === 0) {
					_startedAnimationFrames = false;
					delete Q.Pointer.preventRubberBand.suspend['Q/parallax'];
				}
			}
		}
	});
	
	var _parallaxApplied = false;
	var _startedAnimationFrames = false;

})(Q, Q.jQuery);
