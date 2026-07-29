(function (Q, $) {

/**
 * Q Tools
 * @module Q-tools
 */

/**
 * This renders a ticker that scrolls its items
 * @class Q ticker
 * @constructor
 * @param {Object} [this.state] This is an object of parameters for this function
 *  @param {Boolean} [this.state.vertical=false]
 *  @param {Number} [this.state.speed=1]
 *  @param {Number} [this.state.pause=2000]
 *  @param {Boolean} [this.state.scrollbars=true]
 *  @param {Number} [this.state.scrollbarsPause=500]
 *  @param {Number} [this.state.frameRate = 50]
 */

Q.Tool.define("Q/ticker", function() {
	if (!('vertical' in this.state))
		this.state.vertical = false;

	if (!('pauseMin' in this.state))
		this.state['pauseMin'] = this.state['pause'];

	// constructor
	var me = this;
	var $te = $(this.element);
	this.$children = $te.children();
	if (this.$children.length && this.$children.get(0).tagName.toUpperCase() == 'TABLE') {
		this.$children = this.$children.children();
	}
	if (this.$children.length && this.$children.get(0).tagName.toUpperCase() == 'TBODY') {
		this.$children = this.$children.children();
	}
	
	Q.addStylesheet('{{Q}}/css/tools/ticker.css', { slotName: 'Q' })

	this.ease = ('ease' in this.state)
			? Q.Animation.ease[this.state['ease']]
			: Q.Animation.ease.smooth;

	this.ready();
},

{
	vertical: false,
	speed: 1,
	pause: 2000,
	scrollbars: true,
	scrollbarsPause: 500,
	frameRate: 50,
	threshold: 5,
	onHitStart: new Q.Event(),
	onHitEnd: new Q.Event()
},

{
	getFirstScrollPos: function(curScrollPos) {
		var ticker = document.getElementById(this.state['elementId']);
		var speed = this.state['speed'];
		var i;

		this.calculateDimensions();

		if (speed >= 0) {
			return 0;
		}

		if (this.state.vertical) {
			var bottom = 0;
			var top = 0;
			for (i = 0; i < this.heights.length; ++i) {
				bottom += this.heights[i];
			}
			for (i = 0; i < this.heights.length; ++i) {
				top += this.heights[i];
				if (top + this.heights[i] > bottom - $(this.element).innerHeight()) {
					return top;
				}
			}
			return top;
		} else {
			var right = 0;
			var left = 0;
			for (i = 0; i < this.widths.length; ++i) {
				right += this.widths[i];
			}
			for (i = 0; i < this.widths.length; ++i) {
				left += this.widths[i];
				if (left + this.widths[i] > right - $(this.element).innerWidth()) {
					return left;
				}
			}
			return left;
		}
		return -2;
	},

	getNextScrollPos: function(curScrollPos) {
		var speed = this.state['speed'];
		var i;

		this.calculateDimensions();

		if (speed === 0) {
			return -1;
		}

		if (this.state.vertical) {
			var top = 0;
			for (i = 0; i < this.heights.length; ++i) {
				if (speed > 0) {
					if (top + this.heights[i] > curScrollPos + 1)
						return top + this.heights[i];
				} else {
					if (top + this.heights[i] >= curScrollPos - 1)
						return top;
				}
				top += this.heights[i];
			}
		} else {
			var left = 0;
			for (i = 0; i < this.widths.length; ++i) {
				if (speed > 0) {
					if (left + this.widths[i] > curScrollPos + 1) {
						return left + this.widths[i];
					}
				} else {
					if (left + this.widths[i] >= curScrollPos - 1) {
						return left;
					}
				}
				left += this.widths[i];
			}
		}
		return -2;
	},

	calculateDimensions: function () {
		this.widths = [];
		this.heights = [];
		var tool = this;

		this.$children.each(function(index){
			var $el = $(this);
			tool.widths.push($el.outerWidth(true));
			tool.heights.push($el.outerHeight() + parseInt($el.css('margin-top')));
		});
	},

	ready: function() {
		var $container = $(this.element);
		if ($container.data('isTicker'))
			return;

		$container.data('isTicker', true);

		if (!$container.hasClass('Q_ticker'))
			$container.addClass('Q_ticker');

		if (!$container.hasClass('Q_vertical') && !$container.hasClass('Q_horizontal'))
			$container.addClass(this.state['Q_vertical'] ? 'vertical' : 'horizontal');

		this.setScrollbars(this.state['scrollbars']);

		$container.css('visibility','hidden');

		var tool = this;
		setTimeout(function(){
			tool.calculateDimensions();

			if (tool.state['speed'] < 0) {
				if (tool.state.vertical)
					$container.scrollTop($container.height());
				else
					$container.scrollLeft($container.width());
			}

			$container.css('visibility','visible');

			tool.frameRate = tool.state['frameRate'] || 100;

			setInterval(tool.autoScroll.bind(tool), tool.frameRate);
		}, 1000);
	},

	setScrollbars: function(shouldShow) {
		$(this.element).toggleClass('Q_scrollbars', shouldShow);
	},

	pause: function() {
		this.scrollMode = 'paused';
		this.msSincePaused = 0;
		this.pauseDuration = this.state['pauseMin'] + Math.random() * (this.state['pause'] - this.state['pauseMin']);
	},

	resume: function(curScrollPos) {
		this.scrollMode = 'auto';
		this.frameIndex = 0;
		this.startScrollPos = curScrollPos;
		this.newScrollPos = this.getNextScrollPos(curScrollPos);
	},

	autoScroll: function () {
		var $container = $(this.element);
		var curScrollPos = (this.state.vertical) ? $(this.element).scrollTop() : $(this.element).scrollLeft();

		// Raise scrolling events, if any
		if (! ('lastScrollPos' in this) || this.lastScrollPos != curScrollPos)
		{
			this.raisedOnHitStart = false;
			this.raisedOnHitEnd = false;
		}

		if (curScrollPos === 0)
		{
			if (!this.raisedOnHitStart)
				Q.handle(this.state.onHitStart);

			this.raisedOnHitStart = true;
		}
		else
		{
			if (this.state.vertical)
			{
				if (curScrollPos == $container[0].scrollHeight - $container[0].clientHeight) //FIXME: check scrollHeight && clientHeight
				{
					if (!this.raisedOnHitEnd)
						Q.handle(this.state.onHitEnd);
					this.raisedOnHitEnd = true;
				}
			}
			else
			{
				if (curScrollPos == $container[0].scrollWidth - $container[0].clientWidth) //FIXME: check scrollHeight && clientHeight
				{
					if (!this.raisedOnHitEnd)
						Q.handle(this.state.onHitEnd);

					this.raisedOnHitEnd = true;
				}
			}
		}

		// Handle the auto scrolling

		if (! ('scrollMode' in this)) {
			if ('initialScrollMode' in this.state) {
				this.scrollMode = this.state.initialScrollMode;
			} else {
				this.scrollMode = 'auto';
			}
			if (this.scrollMode == 'auto') {
				this.resume(curScrollPos);
				this.newScrollPos = this.getFirstScrollPos(curScrollPos);
			} else {
				this.msSincePaused = 0;
				this.pauseDuration = this.state['pauseMin'] + Math.random() *
						(this.state['pause'] - this.state['pauseMin']);
			}
		}

		if (speed === 0) {
			return -1;
		}

		if (! ('frameIndex' in this)) {
			this.frameIndex = 0;
		}

		if (! ('startScrollPos' in this)) {
			this.startScrollPos = 0;
		}

		if (this.scrollMode != 'manual'
		&& 'lastAutoScrollPos' in this
		&& !isNaN(this.lastAutoScrollPos)
		&& Math.abs(this.lastAutoScrollPos - curScrollPos) > this.state.threshold) {

			// the scrollbar has started moving by some other means
			// stop this function from executing, start waiting
			// until it stops and this.state['scrollbarsPause']
			// milliseconds have elapsed since then.
			this.scrollMode = 'manual';

			// reset # of msec passed since manual scrolling
			this.msSinceManual = 0;

		} else if (this.scrollMode == 'manual') {

			if ('lastScrollPos' in this
			&& this.lastScrollPos != curScrollPos) {
				// the scrollbar continues to move,
				// reset # of msec to wait since manual scrolling
				this.msSinceManual = 0;

				// keep the scroll mode as 'manual'
			} else {
				// increment the # of msec passed since manual scrolling
				this.msSinceManual += this.frameRate;

				if (this.state['scrollbarsPause'] >= 0
				&& this.msSinceManual > this.state['scrollbarsPause']) {
					this.resume(curScrollPos);
				}
			}

		} else if (this.scrollMode == 'paused') {

			// increment the # of msec passed since manual scrolling
			this.msSincePaused += this.frameRate;

			if (this.pauseDuration >= 0 && this.msSincePaused > this.pauseDuration) {
				this.resume(curScrollPos);
			}
		}

		if (this.scrollMode == 'auto') {
			var speed = this.state['speed'];
			var fraction = this.frameIndex * (this.frameRate / 1000 * Math.abs(speed));

			var nextScrollPos;
			if (fraction >= 1) {
				this.pause();
			}

			nextScrollPos = this.startScrollPos +
					(this.newScrollPos - this.startScrollPos) * this.ease(fraction);

			if ($container.hasClass('Q_noScrollbar')) {
				setTimeout(function () {
					$container.removeClass('Q_noScrollbar')
				}, this.frameRate * 10);
			}
			$container.addClass('Q_noScrollbar');
			if (this.state.vertical) {
				$container.scrollTop(nextScrollPos);
				this.lastAutoScrollPos = $container.scrollTop();
			} else {
				$container.scrollLeft(nextScrollPos);
				this.lastAutoScrollPos = $container.scrollLeft();
			}

			++this.frameIndex;
		} else {
			this.lastScrollPos = curScrollPos;
		}

		// This makes sure the scrolling events don't happen twice
		if (! ('lastScrollPos' in this) || this.lastScrollPos != curScrollPos) {
			this.lastScrollPos = curScrollPos;
		}

		return 0;
	}
}

);

})(Q, Q.jQuery);