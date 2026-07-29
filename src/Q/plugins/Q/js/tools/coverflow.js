(function (Q, $) {
/**
 * @module Q-tools
 */

/**
 * Implements an Apple-style "cover flow" effect based on:
 * https://scroll-driven-animations.style/demos/cover-flow/css/
 *
 * RTL-aware (mirrors geometry + interaction correctly).
 *
 * Cooperates with Q/sortable when co-activated on the coverflow's ul
 * (wired by Streams/related._updateCoverflow).
 *
 * All coverflow 3D transforms stay active during a sort drag.
 * getBoundingClientRect() returns projected 2D screen coordinates for
 * transformed elements, so sortable's hit-testing works correctly.
 *
 * Two elements need special handling:
 *
 *   $placeholder — sortable clones the li before lift fires. The clone
 *     inherits the CSS scroll-driven animation which applies rotateY,
 *     making its projected width near-zero and breaking sortable's
 *     before/after threshold. sortableOptions() onLift adds
 *     Q_coverflow_sorting_placeholder so CSS resets it to a flat rect.
 *
 *   $dragged — the absolute-positioned ghost also inherits the CSS
 *     animation. onLift freezes the ghost's img at the lift-moment
 *     transform value and suppresses the animation. It also corrects the
 *     ghost's initial position and gx/gy to match the visual cover bounds
 *     rather than the li's CSS layout box.
 *
 * indicate threshold: a custom onIndicate handler uses the raw pointer x
 *   vs the target's visual centre — more natural than the projected-edge
 *   default for rotated covers.
 *
 * Pointer cooperation:
 *   coverflow holds pointer capture for drag-scroll. Once sortable lifts
 *   (300 ms hold) it sets covers._sortableLifted = true. coverflow's
 *   pointermove releases capture so sortable can drive.
 *   Edge auto-scroll is handled by sortable's scrolling() which walks
 *   $item.parents() and finds the covers ul naturally.
 *
 * @class Q coverflow
 * @constructor
 * @param {Object}   [options] Override various options for this tool
 *  @param {Array}   [options.elements=null] HTML elements to display. Each may have a "title" attribute.
 *  @param {Array}   [options.titles=null] Titles corresponding to the elements.
 *  @param {Boolean} [options.dontSnapScroll=false] Disable scroll snapping
 *  @param {Integer} [options.index] Index of item to bring to front initially
 *  @param {Number}  [options.scrollOnMouseMove=0] (unused) scroll factor
 *  @param {Boolean} [options.dragScrollOnlyOnTouchscreens=false] Restrict drag scrolling to touchscreens
 *  @param {Q.Event} [options.onInvoke] Triggered when center item is clicked/tapped
 * @return {Q.Tool}
 */
Q.Tool.define("Q/coverflow", function _Q_coverflow(options) {
	var tool = this;
	var state = tool.state;

	/**
	 * Whether layout direction is RTL
	 * @property isRTL
	 * @type Boolean
	 */
	var isRTL = getComputedStyle(tool.element).direction === "rtl";

	if (!state.dontSnapScroll) {
		tool.element.addClass("Q_coverflow_snapping");
	}

	var covers = tool.element.querySelector(".Q_coverflow_covers");
	if (!covers) {
		covers = Q.element("ul", { class: "Q_coverflow_covers" });
		tool.element.appendChild(covers);
	}
	
	var updateBounds = function () {
		covers._boundingClientRect = covers.getBoundingClientRect();
	};
	Q.onLayout(covers).set(updateBounds, tool);
	updateBounds();

	tool._covers = covers;	

	tool._buildCovers();

	// ---- snap control ----

	var snapTimer = null;

	function enableSnapSoon() {
		if (state.dontSnapScroll) return;
		clearTimeout(snapTimer);
		snapTimer = setTimeout(function () {
			tool.element.addClass("Q_coverflow_snapping");
		}, 120);
	}

	function disableSnapNow() {
		tool.element.removeClass("Q_coverflow_snapping");
		clearTimeout(snapTimer);
	}

	tool._enableSnapSoon = enableSnapSoon;
	tool._disableSnapNow = disableSnapNow;

	// ---- caption ----

	var caption = tool.element.querySelector(".Q_coverflow_caption");
	if (!caption) {
		caption = Q.element("div", { class: "Q_coverflow_caption" });
		tool.element.appendChild(caption);
		$(caption).plugin("Q/textfill");
	}
	tool._caption = caption;

		// Capturing phase (true) ensures this runs before any child's listener
	tool.element.addEventListener("click", (event) => {
		let el = document.elementFromPoint(event.clientX, event.clientY);

		if (el?.tagName.toLowerCase() === "li") {
			el = el.querySelector(":scope > img") || el;
		}

		if (el?.tagName.toLowerCase() === "img") {
			Q.handle(state.onInvoke, tool, [el]);
		}
	}, true); // <-- capturing phase

	/**
	 * Updates caption from the li at the visual centre.
	 * Skips Q_coverflow_sorting_placeholder items.
	 * @method _updateCaption
	 * @private
	 */
	var updateCaption = Q.throttle(function () {
		var rect = covers._boundingClientRect;
		var element = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
		if (!element) return;
		var li = element.closest("li");
		if (!li) return;
		if (li.classList.contains("Q_coverflow_sorting_placeholder")) return;

		var title = li.getAttribute("title");
		if (title) {
			caption.innerText = title;
			caption.style.display = "block";
		} else {
			caption.style.display = "none";
		}
		$(caption).plugin("Q/textfill", "refresh");
	}, 50);

	tool._updateCaption = updateCaption;

	updateCaption();
	// Poll briefly at startup until covers is visible and caption resolves.
	// updateCaption is throttled and returns undefined, so we cap at 20 tries.
	var _ivalCount = 0;
	var ival = setInterval(function () {
		updateCaption();
		if (++_ivalCount >= 20) clearInterval(ival);
	}, 100);

	// ---- JS transform ----
	// Inline style wins over CSS @keyframes — this is authoritative.
	// Skips placeholder items (flat by CSS).

	var updateCovers = Q.throttle(function () {
		var rect = covers._boundingClientRect;
		var cx = rect.left + rect.width / 2;

		var items = covers.querySelectorAll("li");
		for (var i = 0; i < items.length; i++) {
			var li = items[i];
			if (li.classList.contains("Q_coverflow_sorting_placeholder")) continue;

			var r = li.getBoundingClientRect();
			var itemCx = r.left + r.width / 2;
			var norm = (itemCx - cx) / (rect.width / 2);

			if (isRTL) norm = -norm;
			if (norm < -1) norm = -1;
			if (norm > 1) norm = 1;

			var rotateY = norm * 60;
			var depth = (1 - Math.abs(norm)) * 120;
			var scale = 1 + (1 - Math.abs(norm)) * 0.25;

			var img = li.querySelector("img, video");
			if (!img) continue;

			img.style.transform =
				"perspective(900px) translateZ(" + depth + "px) " + "rotateY(" + rotateY + "deg) scale(" + scale + ")";

			li.style.zIndex = Math.round(1000 * (1 - Math.abs(norm)));
		}
	}, 16);

	tool._updateCovers = updateCovers;

	covers.addEventListener(
		"scroll",
		function () {
			updateCaption();
			updateCovers();
			enableSnapSoon();
		},
		{ passive: true }
	);

	// ---- pointer drag-to-scroll ----
	// Yields to sortable once covers._sortableLifted is set.

	if (!state.dragScrollOnlyOnTouchscreens) {
		var slider = covers;
		var isDown = false;
		var startX;
		var startScrollLeft;

		slider.addEventListener("pointerdown", function (e) {
			if (slider._sortableLifted) return;
			isDown = true;
			slider.classList.add("active");
			startX = e.pageX;
			startScrollLeft = slider.scrollLeft;
			disableSnapNow();
			if (slider.setPointerCapture) {
				slider.setPointerCapture(e.pointerId);
			}
		});

		slider.addEventListener("pointerup", function (e) {
			isDown = false;
			slider.classList.remove("active");
			enableSnapSoon();
			if (slider.releasePointerCapture) {
				slider.releasePointerCapture(e.pointerId);
			}
		});

		slider.addEventListener("pointerleave", function () {
			isDown = false;
			slider.classList.remove("active");
			enableSnapSoon();
		});

		slider.addEventListener(
			"pointermove",
			function (e) {
				if (slider._sortableLifted) {
					if (isDown) {
						isDown = false;
						slider.classList.remove("active");
						if (slider.releasePointerCapture) {
							slider.releasePointerCapture(e.pointerId);
						}
					}
					return;
				}
				if (!isDown) return;
				e.preventDefault();

				var dx = e.pageX - startX;
				slider.scrollLeft = isRTL ? startScrollLeft + dx : startScrollLeft - dx;
			},
			{ passive: false }
		);
	}
},
{
	elements: [],
	dontSnapScroll: false,
	index: null,
	dragScrollOnlyOnTouchscreens: false,
	scrollOnMouseMove: 0,
	scrollerOnMouseMove: false,
	onInvoke: new Q.Event()
},
{
	/**
	 * Rebuilds ul.Q_coverflow_covers from state.elements / state.titles.
	 * @method _buildCovers
	 */
	_buildCovers: function () {
		var tool = this;
		var state = tool.state;
		var covers = tool._covers || tool.element.querySelector('.Q_coverflow_covers');
		if (!covers) {
			return;
		}

		covers.innerHTML = '';
		var titles = state.titles || [];
		Q.each(state.elements, function (i) {
			var title = titles[i]
				|| (this.title)
				|| (this.getAttribute && this.getAttribute('title'))
				|| '';
			covers.appendChild(Q.element('li', { title: title }, [this]));
		});
	},

	/**
	 * Rebuilds covers from state.elements and re-applies transforms.
	 * @method refresh
	 */
	refresh: function () {
		var tool = this;
		tool._buildCovers();
		if (tool._updateCovers) tool._updateCovers();
		if (tool._updateCaption) tool._updateCaption();
	},

	/**
	 * Returns sortable options for use when applying Q/sortable to the
	 * covers ul (called by Streams/related._updateCoverflow).
	 *
	 * Encapsulates all coverflow-specific cooperation:
	 *   onLift  — marks placeholder flat, freezes ghost transform at
	 *             lift-moment, corrects ghost position and gx/gy for 3D
	 *             visual offset, signals coverflow to release pointer capture
	 *   onDrop  — clears _sortableLifted and gx/gy override
	 *   onIndicate — centre-based swap threshold using raw pointer x vs
	 *                target visual centre; returns false to suppress default
	 *
	 * @method sortableOptions
	 * @return {Object}
	 */
	sortableOptions: function () {
		var tool = this;
		var covers = tool._covers;

		// Returns draggable/droppable plus _onLift/_onDrop/_onIndicate as plain
		// functions. Streams/related wires these via .set() on the sortable state
		// after plugin() runs, so they coexist with any user-supplied handlers.
		return {
			draggable: 'li',
			droppable: 'li',

			_onLift: function ($item, info) {
				// Release coverflow's pointer capture so sortable can drive
				covers._sortableLifted = true;

				var $placeholder = info.$placeholder;
				var $dragged     = info.$dragged;

				// ---- placeholder: flat rect, no animation ----
				// CSS suppresses animation/transform via this class.
				// Also clear any inline transform from updateCovers on the clone.
				$placeholder.addClass('Q_coverflow_sorting_placeholder');
				var pImg = $placeholder[0].querySelector('img, video');
				if (pImg) {
					pImg.style.transform = '';
					pImg.style.animationName = 'none';
				}

				// ---- dragged ghost: freeze at lift-moment appearance ----
				// Ghost is appended to body outside the ul so the scroll-driven
				// animation-timeline fires from default (start-of-scroll) position,
				// immediately warping it. Freeze the transform at the moment of lift.
				var origImg = $item[0].querySelector('img, video');
				var frozenTransform = '';
				if (origImg) {
					// Prefer the inline style set by updateCovers; fall back to
					// computed (which captures the CSS animation's current value).
					frozenTransform = origImg.style.transform
						|| getComputedStyle(origImg).transform
						|| '';
				}

				var dImg = $dragged[0].querySelector('img, video');
				if (dImg) {
					dImg.style.transform = frozenTransform;
					dImg.style.animationName = 'none';
				}
				// Suppress the li-level scroll-driven animation on the ghost too
				$dragged[0].style.animationName = 'none';

				// ---- correct ghost position to visual cover bounds ----
				// sortable positions $dragged at the li's CSS layout origin, but
				// the cover visually appears at origImg's projected screen rect
				// (offset by translateZ + scale). Reposition to match.
				// Convert viewport rect to page coordinates via scroll offsets —
				// more reliable than document.body.getBoundingClientRect().
				if (origImg) {
					var origRect  = origImg.getBoundingClientRect();
					var visualLeft = origRect.left + window.pageXOffset;
					var visualTop  = origRect.top  + window.pageYOffset;

					$dragged.css({
						left:   visualLeft,
						top:    visualTop,
						width:  origRect.width,
						height: origRect.height
					});

					// Update gx/gy via state override so move() tracks from the
					// visual grab point. info.event carries the original pointer coords.
					var sortableState = $(covers).state('Q/sortable');
					if (sortableState) {
						var ex = info.event ? Q.Pointer.getX(info.event) : 0;
						var ey = info.event ? Q.Pointer.getY(info.event) : 0;
						sortableState._coverflowGxOverride = ex - visualLeft;
						sortableState._coverflowGyOverride = ey - visualTop;
					}
				}
			},

			_onDrop: function () {
				covers._sortableLifted = false;
				var sortableState = $(covers).state('Q/sortable');
				if (sortableState) {
					delete sortableState._coverflowGxOverride;
					delete sortableState._coverflowGyOverride;
				}
			},

			// Centre-based swap threshold for horizontal coverflow.
			// The default threshold (projected edge ± mw) is too conservative
			// for rotated covers whose projected width is cos(angle) × natural.
			// Instead compare raw pointer x with the target's visual centre.
			// Returning false suppresses the default indicate placement logic;
			// this handler inserts $placeholder directly.
			_onIndicate: function ($item, info) {
				var $target = info.$target;
				if (!$target || !$target.length) return;

				var tr = $target[0].getBoundingClientRect();
				var targetCx = (tr.left + tr.right) / 2;

				// Use raw pointer x from indicate() — avoids any per-frame lag
				// from reading the ghost's getBoundingClientRect after css update.
				var x = info.x;
				var isBefore = info.isBefore;

				var shouldSwap = isBefore ? (x < targetCx) : (x > targetCx);

				if (shouldSwap) {
					if (isBefore) {
						info.$placeholder.insertBefore($target);
					} else {
						info.$placeholder.insertAfter($target);
					}
				}

				return false; // suppress default indicate logic
			}
		};
	}
}

);

})(Q, Q.jQuery);