(function (Q, $) {
/**
 * @module Q-tools
 */
	
/**
 * Implements vertical drawers that work on most modern browsers,
 * including ones on touchscreens.
 * @class Q drawers
 * @constructor
 * @param {Object}   [options] Override various options for this tool
 *  @param {Element} [options.container=null] Optional container element for handling scrolling
 *  @param {Object}   [options.initial] Information for the initial animation
 *  @param {Number}   [options.initial.index=1] The index of the drawer to show, either 0 or 1
 *  @param {Number}   [options.initial.delay=0] Delay before starting initial animation
 *  @param {Number}   [options.initial.duration=300] The duration of the initial animation
 *  @param {Function} [options.initial.ease=Q.Animation.linear] The easing function of the initial animation
 *  @param {Object}   [options.transition] Information for the transition animation
 *  @param {Number}   [options.transition.duration=300] The duration of the transition animation
 *  @param {Function}   [options.transition.ease=Q.Animation.linear] The easing function of the transition animation
 *  @param {Function}   [options.width] Override the function that computes the width of the drawers
 *  @param {Function}   [options.height] Override the function that computes the height drawers tool
 *  @param {Array}   [options.heights=[100,100]] Array of [height0, height1] for drawers that are pinned. Can contain numbers or functions returning numbers, or names of functions.
 *  @param {Array}   [options.placeholders=['','']] Array of [html0, html1] for drawers that are pinned.
 *  @param {Array}   [options.behind=[true,false]] Array of [boolean0, boolean1] to indicate which drawer is behind the others
 *  @param {Array}   [options.bottom=[false,false]] Array of [boolean0, boolean1] to indicate whether to scroll to the bottom of a drawer after switching to it
 *  @param {Array}   [options.triggers=['{{Q}}/img/drawers/up.png', '{{Q}}/img/drawers/down.png']] Array of [src0, src1] for img elements that act as triggers to swap drawers. Set array elements to false to avoid rendering a trigger.
 *  @param {Object}   [options.trigger]] Options for the trigger elements
 *  @param {Number}   [options.trigger.rightMargin=10]] How many pixels from the right side of the drawers
 *  @param {Number}   [options.transition=300]] Number of milliseconds for fading in the trigger images
 *  @param {Boolean}   [options.fullscreen=Q.info.isMobile && Q.info.isAndroid(1000)]] Whether the drawers should take up the whole screen
 *  @param {Number}   [options.foregroundZIndex=50] The z-index of the drawer in the foreground
 *  @param {Number}   [options.beforeSwap=new Q.Event()] Occurs right before drawer swap
 *  @param {Number}   [options.onSwap=new Q.Event()] Occurs right after drawer swap
 * @return {Q.Tool}
 */
Q.Tool.define("Q/drawers", function _Q_drawers(options) {
	var tool = this;
	var state = tool.state;
	var $te = $(tool.element);
	state.containerOriginal = state.container;
	state.swapCount = 0;
	
	Q.addStylesheet('{{Q}}/css/drawers.css', { slotName: 'Q' });
	
	if (state.fullscreen || !state.container) {
		state.container = $(tool.element).parents().eq(-3)[0];
	}
	
	var $scrolling = state.$scrolling = 
		$(state.container && !state.fullscreen ? state.container : window);
	
	if ($te.css('position') == 'static') {
		$te.css('position', 'relative');
	}

	if (!state.behind[0]) {
		state.bottom[0] = true;
	}
	if (!state.behind[1]) {
		state.bottom[1] = false;
	}

	state.$drawers = $(this.element).children('.Q_drawers_drawer');
	state.currentIndex = 1 - state.initial.index;
	state.canceledSwap = null;
	var lastScrollingHeight;
	setTimeout(function () {
		_initialize();
		var $column = $(tool.element).closest('.Q_columns_column');
		if (!$column.length || $column.hasClass('Q_columns_opened')) {
			return;
		}
		var columns = $column.closest('.Q_tool')[0].Q("Q/columns");
		var key = columns.state.onActivate.set(function () {
			if (state.fullscreen) {
				_initialize();
			} else {
				_layout();
			}
			columns.state.onActivate.remove(key);
		}, tool);
	}, state.initial.delay || 0);
	
	function _initialize() {
		state.lastScrollingHeight = _h($scrolling[0].clientHeight || $scrolling.height(), tool);
		tool.swap(_layout);
		Q.onLayout(tool).set(_layout, tool);
	}
	
	$te.parents().each(function () {
		var $this = $(this);
		$this.data('Q/drawers originalBackground', this.style.background);
		this.style.background = 'transparent';
		if ($this.is(state.container)) return false;
	});
	
	if (Q.info.isMobile) {
		this.managePinned();
	}
	
	// Accomodate mobile keyboard
	if (Q.info.isMobile) {
		var scrollTop = null;
		state.$drawers.eq(0).on(Q.Pointer.focusin, tool, function () {
			scrollTop = $scrolling.scrollTop();
			setTimeout(function () {
				state.$drawers.eq(1).hide();
				state.$placeholder.hide();
				state.$trigger.hide();
			}, 100);
		});
		state.$drawers.eq(0).on(Q.Pointer.focusout, tool, function () {
			state.$drawers.eq(1).show();
			state.$placeholder.show();
			state.$trigger.show();
			setTimeout(function () {
				$scrolling.scrollTop(scrollTop);
			}, 0);
		});
	}

	function _layout() {
		// to do: fix for cases where element doesn't take up whole screen
		if (Q.info.isMobile) {
			var w = state.drawerWidth = $(window).width();
			$(tool.element).width(w);
			state.$drawers.each(function () {
				var $this = $(this);
				$this.width(w - $this.outerWidth(true) + $this.width());
			});
			state.$drawers.height();
		}
		var sh = _h($scrolling[0].clientHeight || $scrolling.height(), tool);
		var sHeights = (state.heights instanceof Array)
			? state.heights : Q.getObject(state.heights).apply(tool);
		var $d0 = state.$drawers.eq(0);
		var $d1 = state.$drawers.eq(1);
		$d0.css('min-height', sh-sHeights[1]+'px');
		$d1.css('min-height', sh-sHeights[0]+'px');
		if (state.currentIndex == 0) {
			var heightDiff = sh - lastScrollingHeight;
			var offset = $d1.offset();
			$d1.offset({
				left: offset.left,
				top: offset.top + heightDiff
			});
		}
		state.lastScrollingHeight = _h($scrolling[0].clientHeight || $scrolling.height(), tool);
	}
},

{
	initial: {
		delay: 0,
		duration: 300,
		ease: Q.Animation.linear,
		index: 1
	},
	transition: {
		duration: 300,
		easing: Q.Animation.linear
	},
	container: null,
	width: function () { return $(this.element).width() },
	height: function () {
		var sp = this.element.scrollingParent();
		if (sp === document.documentElement) {
			return _h(Q.Pointer.windowHeight(), this);
		}
		return sp.clientHeight;
	},
	currentIndex: null,
	placeholders: ['', ''],
	heights: [100, 100],
	behind: [true, false],
	bottom: [false, false],
	triggers: ['{{Q}}/img/drawers/up.png', '{{Q}}/img/drawers/down.png'],
	trigger: { rightMargin: 10, transition: 300 },
	fullscreen: Q.info.useFullscreen,
	foregroundZIndex: 50,
	beforeSwap: new Q.Event(),
	onSwap: new Q.Event()
},

{	
	swap: function (callback, animationStartCallback) {
		var tool = this;
		var state = tool.state;
		
		if (state.locked) return false;
		state.locked = true;
		
		var otherIndex = state.currentIndex;
		var index = state.currentIndex = (state.currentIndex + 1) % 2;
		var $drawer = state.$drawers.eq(index);
		var $otherDrawer = state.$drawers.eq(otherIndex);
		var sWidth = (typeof state.width === 'number')
			? state.width : Q.getObject(state.width).apply(tool);
		var sHeight = (typeof state.height === 'number')
			? state.height : Q.getObject(state.height).apply(tool);
		var sHeights = (state.heights instanceof Array)
			? state.heights : Q.getObject(state.heights).apply(tool);
		var $scrolling = $(state.container && !state.fullscreen ? state.container : window);
		var behind = state.behind[index];
		var fromHeight = behind 
			? sHeights[index] 
			: sHeight - sHeights[index];
		var toHeight = behind 
			? sHeight - sHeights[otherIndex] 
			: sHeights[otherIndex];
		var eventName = Q.info.isTouchscreen
			? 'touchend.Q_drawers'
			: 'mouseup.Q_drawers';
		var scrollEventName = 'scroll.Q_drawers';
		var scrollingHeight;
		
		// give things a chance to settle down
		setTimeout(_setup1, 0);
		
		function _setup1() {
			var scrollTop;
			var sHeights = (state.heights instanceof Array)
				? state.heights : Q.getObject(state.heights).apply(tool);
			state.lastScrollingHeight = scrollingHeight = _h(
				$scrolling[0].clientHeight || $scrolling.height(),
				tool
			);
			if (state.$pinnedElement) {
				scrollTop = state.bottom[otherIndex]
					? scrollingHeight - sHeights[otherIndex] - $otherDrawer.height()
					: 0;
				$scrolling.scrollTop(scrollTop);
			}
		
			$scrolling.off(scrollEventName);
		
			$drawer.addClass('Q_drawers_current')
				.removeClass('Q_drawers_notCurrent');
			$otherDrawer.removeClass('Q_drawers_current')
				.addClass('Q_drawers_notCurrent');
			
			if ($(tool.element).css('position') == 'static') {
				$(tool.element).css('position', 'relative');
			}
			
			// give that scrollTop a chance to take effect
			setTimeout(_setup2, 0);
		}
		
		function _setup2() {
			$drawer.add($otherDrawer).add(state.$placeholder).off(eventName);

			function _onSwap() {
				state.onSwap.handle.call(tool, state.currentIndex);
				Q.handle(callback, tool);
			};

			state.beforeSwap.handle.call(tool, index);
			
			if (state.$trigger) {
				state.$trigger.remove();
			}
			if (behind) {
				_animate([_pin, _addEvents, _onSwap]);
			} else {
				_pin([_animate, _addEvents, _onSwap]);
			}
		}
		
		function _pin(callbacks) {
			var ae = document.activeElement;
			$otherDrawer.css('position', 'relative');
			var p = state.drawerPosition;
			var w = state.drawerWidth;
			var h = state.drawerHeight;
		
			state.drawerPosition = $otherDrawer.css('position');
			state.drawerWidth = $otherDrawer.width();
			state.drawerHeight = $otherDrawer.height();
			state.drawerOffset = $otherDrawer.offset();
			
			var $pe;
			var sHeights = (state.heights instanceof Array)
				? state.heights : Q.getObject(state.heights).apply(tool);
			if ($pe = state.$pinnedElement) {
				state.$placeholder.before($pe).remove();
				$pe.css({
					position: p,
					left: 0,
					top: 0
				});
			} else if (!index) {
				state.drawerOffset = $scrolling.offset()
					|| {left: 0, top: 0};
				state.drawerOffset.top += state.bottom[1]
					? 0
					: scrollingHeight - sHeights[1];
			}
			
			var scrollHeight = ($scrolling[0] === window)
				? document.documentElement.scrollHeight
				: $scrolling[0].scrollHeight;
			$scrolling.scrollTop(
				state.bottom[index] ? _h(scrollHeight, tool) : 0
			);
			if ($pe && index) {
				state.drawerOffset = $otherDrawer.offset();
			}
			
			state.$placeholder = $('<div class="Q_drawers_placeholder" />')
				.html(state.placeholders[otherIndex])
				.css({
					background: 'transparent',
					height: (index ? fromHeight : sHeights[1]) + 'px',
					cursor: 'pointer'
				}).insertAfter($otherDrawer);
			state.$placeholder.find('*').css('pointer-events', 'none');
			
			var jqAction = 'insert'+(state.behind[otherIndex]?'Before':'After');
			$otherDrawer[jqAction](state.container).css({
				position: state.fullscreen ? 'fixed' : 'absolute',
				width: sWidth,
				zIndex: $(state.container).css('zIndex')
			}).offset(state.drawerOffset)
			.activate(); // Q.find missed it outside the tool's element
			if (state.behind[index]) {
				$otherDrawer.css({cursor: 'pointer'});
			}
			if (state.fullscreen && state.behind[index]) {
				$otherDrawer.css({zIndex: state.foregroundZIndex});
			}
			state.$pinnedElement = $otherDrawer;
			if (Q.info.isMobile) {
				tool.managePinned();
			}
			
			// TODO: adjust height, do not rely on parent of container having
			// overflow: hidden
			
			if (!$(ae).closest(state.$otherDrawer).length) {
				ae.focus();
			}
			callbacks[0](callbacks.slice(1));
		}
		
		function _animate(callbacks) {
			Q.handle(animationStartCallback);
			var o = state[state.swapCount ? 'transition' : 'initial'];
			if (!state.$placeholder) {
				return _continue();
			}
			if (!o.duration) {
				state.$placeholder.height(toHeight);
				return _continue();
			}
			Q.Animation.play(function (x, y) {
				state.$placeholder.height(fromHeight + (toHeight-fromHeight)*y);
			}, o.duration, o.ease)
			.onComplete.set(function () {
				this.onComplete.remove("Q/drawers");
				_continue();
			}, "Q/drawers");
			function _continue() {
				setTimeout(function () {
					callbacks[0](callbacks.slice(1));
				}, 0);
			}
		}
		
		function _addEvents(callbacks) {
			var o = state[state.swapCount ? 'transition' : 'initial'];
			var $jq = $(behind ? state.$pinnedElement : state.$placeholder);
			$jq.off(eventName).on(eventName, function (evt) {
				var product = Q.Pointer.movement && Q.Pointer.movement.movingAverageVelocity
					? Q.Pointer.movement.movingAverageVelocity.y * (state.currentIndex-0.5)
					: 0;
				if (!$(evt.target).closest('.Q_discourageDrawerSwap').length
				&& product >= 0) {
					if (Q.Pointer.which(evt) < 2) {
						// don't do it right away, so that other event handlers
						// can still access the old state.currentIndex
						setTimeout(function () {
							if (!state.canceledSwap) {
								tool.swap();
							}
							state.canceledSwap = null;
						}, 0);
					}
				}
			});
			if (!behind) {
				if (Q.info.isTouchscreen) {
					$scrolling.off('touchstart.Q_columns')
						.off('touchend.Q_columns')
						.on('touchstart.Q_columns', function (event) {
							state.touchCount = Q.Pointer.touchCount(event);
						}).on('touchend.Q_columns', function (event) {
							state.touchCount = 0;
						});
				}
			}
			state.locked = false;
			++state.swapCount;
			
			if (Q.info.isTouchscreen && !(Q.info.isAndroidStock)) {
				_addTouchEvents();
			}
			
			var src = state.triggers[state.currentIndex];
			if (state.$trigger) {
				state.$trigger.remove();
			}
			if (src) {
				state.$trigger = $('<img />').attr({
					'src': Q.url(src),
					'class': 'Q_drawers_trigger ' + 'Q_drawers_trigger_' + state.currentIndex,
					'alt': state.currentIndex ? 'reveal bottom drawer' : 'reveal top drawer'
				}).insertAfter(state.$drawers[1])
				.css({'opacity': 0})
				.animate({'opacity': 1}, state.trigger.transition)
				.on(Q.Pointer.start, function (evt) {
					if (Q.Pointer.which(evt) < 2) {
						state.$trigger.hide();
						tool.swap();
					}
				});
				var $drawer = tool.state.$drawers.eq(1);
				if ($drawer.is(':visible')) {
					var left = $drawer.offset().left
						- $drawer.offsetParent().offset().left
						+ $drawer.outerWidth()
						- state.$trigger.outerWidth()
						- state.trigger.rightMargin;
					var top = $drawer.offset().top
						- $drawer.offsetParent().offset().top
						- state.$trigger.height() / 2;
					state.$trigger.css({
						left: left + 'px',
						top: top + 'px',
						position: state.fullscreen && state.currentIndex == 0
							? 'fixed'
							: 'absolute'
					});
				} else {
					state.$trigger.hide();
				}
			}
			
			Q.handle(callbacks[0], tool);
		}
		
		function _addTouchEvents() {
			var y1, y2;
			var anim = null;
			var notThisOne = false;
			var canShowTrigger = true;
			state.$placeholder.on('touchmove', function (e) {
				e.preventDefault();
			});
			state.$drawers.eq(state.currentIndex)
			.on('touchstart', true, function (e) {
				if (anim) anim.pause();
				notThisOne = false;
				if (state.currentIndex == 0
				|| state.$scrolling.scrollTop() > 0
				|| $(e.target).closest('.Q_discourageDrawerSwap').length) {
					notThisOne = true;
					return;
				}
				state.$trigger.hide();
				canShowTrigger = false;
				y1 = Q.Pointer.getY(e);
				e.preventDefault();
			}).on('touchmove', true, function (e) {
				if (notThisOne) return;
				y2 = Q.Pointer.getY(e);
				if (y1 - y2 > 0) {
					state.$scrolling.scrollTop(y1-y2);	
					state.$drawers.eq(1).css('margin-top', 0);
				} else {
					state.$drawers.eq(1).css('margin-top', y2-y1);
				}
				canShowTrigger = false;
			}).on('touchend', true, function (e) {
				if (notThisOne) return;
				if (y2 - y1 > 0) {
					tool.swap(null, function () {
						var $d = state.$drawers.eq(1);
						var mt = parseInt($d.css('margin-top'));
						Q.Animation.play(function (x, y) {
							$d.css('margin-top', mt*(1-y)+'px');
						}, state.transition.duration);
					});
				} else {
					anim = Q.Animation.play(function (x, y) {
						if (!Q.Pointer.movement
						|| !Q.Pointer.movement.movingAverageVelocity) {
							return;
						}
						var v = Q.Pointer.movement.movingAverageVelocity.y;
						var t = state.$scrolling.scrollTop();
						var dampening = 1-y;
						state.$scrolling.scrollTop(
							t-v*this.sinceLastFrame*dampening
						);
					}, 3000, Q.Animation.ease.power(3.5));
				}
				y1 = y2 = undefined;
				canShowTrigger = true;
			});
			state.$interval = setInterval(function () {
				if (!state.$drawers.eq(1).is(':visible')) {
					state.$trigger.hide();
				} else if (canShowTrigger && state.$scrolling.scrollTop() === 0) {
					var $drawer = tool.state.$drawers.eq(1);
					var left = $drawer.offset().left
						- $drawer.offsetParent().offset().left
						+ $drawer.outerWidth()
						- state.$trigger.outerWidth()
						- state.trigger.rightMargin;
					var top = $drawer.offset().top
						- $drawer.offsetParent().offset().top
						- state.$trigger.height() / 2;
					state.$trigger.show().css({
						left: left + 'px',
						top: top + 'px'
					});
				}
			}, 300);
		}

	},
	
	Q: {
		beforeRemove: {"Q/drawers": function () {
			var state = this.state;
			$(this.element).parents().each(function () {
				var $this = $(this);
				var b = $this.data('Q/drawers originalBackground');
				if (b != null) {
					this.style.background = b;
					$this.removeData('Q/drawers originalBackground');
				}
				if ($this.is(state.container)) return false;
			});
			var $pinnedElement = state.$pinnedElement;
			if (!$pinnedElement) return;
			Q.removeElement($pinnedElement[0], true);
			var $scrolling = state.fullscreen ? $(window) : $(state.container);
			$scrolling.off(state.scrollEventName)
				.off('touchstart.Q_drawers')
				.off('touchend.Q_drawers');
			if (state.$trigger) {
				state.$trigger.remove();
			}
			clearInterval(state.$interval);
		}}
	},
	managePinned: function () {
		var columnIndex;
		var tool = this;
		var state = tool.state;
		$(this.element).parents().each(function () {
			var $this = $(this);
			if ($this.hasClass('Q_columns_column')) {
				columnIndex = $this.attr('data-index');
			}
			var columns = this.Q("Q/columns");
			if (columns) {
				if (columns.state.currentIndex != columnIndex
				&& state.$pinnedElement
				&& state.behind[state.currentIndex]) {
					state.$hiddenElements = state.$pinnedElement
					.add(state.$trigger).hide();
				}
				columns.state.beforeOpen.set(function (options, index) {
					if (index !== columnIndex
					&& state.$pinnedElement
					&& state.behind[state.currentIndex]) {
						state.$hiddenElements = state.$pinnedElement
						.add(state.$trigger).hide();
					}
				}, tool);
				columns.state.beforeClose.set(function (index, indexAfterClose) {
					if (indexAfterClose === columnIndex && state.$hiddenElements) {
						state.$hiddenElements.show();
						state.$hiddenElements = null;
					}
				}, tool);
				return false;
			}
		});
	}
}

);

function _h(scrollingHeight, tool) {
	if (!tool.state.fullscreen) {
		return scrollingHeight;
	}
	return scrollingHeight - $(tool.element).position().top;
}

})(Q, Q.jQuery);