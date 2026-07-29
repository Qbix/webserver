(function (Q, $) {
	/**
	 * @module Q-tools
	 */

	/**
	 * When you scroll and reach the end of a list, more has already been loaded.
	 * @class Q infinitescroll
	 * @constructor
	 * @param {Object} [options] Override various options for this tool
	 *  @param {Float} [options.future.threshold=0.8] Required. How much to scroll to load future elements.
	 *  @param {Boolean} [options.flipped=false] If true, seek for scrolling from bottom to top.
	 *  @param {Object} [options.onInvoke] Event fired when need load new elements.
	 * @return {Q.Tool}
	 */
	Q.Tool.define('Q/infinitescroll', function (options) {
		var tool = this;
		var state = tool.state;
		var $te = $(tool.element);

		Q.addStylesheet('{{Q}}/css/tools/infinitescroll.css', { slotName: 'Q' });

		state.lastScrollHeight = $te[0].scrollHeight;

		$te.on('scroll', tool, function () {
			var scrolled;

			if (state.flipped) {
				scrolled = 1 - $te[0].scrollTop/$te[0].scrollHeight;
			} else {
				scrolled = ($te[0].scrollTop + $te[0].clientHeight)/$te[0].scrollHeight;
			}

			if (scrolled >= state.future.threshold && !state.waiting) {
				state.waiting = true;
				Q.handle(state.onInvoke, tool, [scrolled]);
			}
		});

		// watch for tool.element scrollHeight change
		tool.watchingScrollHeight = setInterval(function () {
			if (state.lastScrollHeight < tool.element.scrollHeight) {
				// if flipped, scroll down to the value of scrollHeight diff
				if (state.flipped) {
					tool.element.scrollTop += tool.element.scrollHeight - state.lastScrollHeight;
				}
				state.waiting = false;
			}
			state.lastScrollHeight = tool.element.scrollHeight;
		}, 1000);

		// listen for included tools rendered
		var includedTools = [];
		var pipeTimer = null;
		tool.element.forEachTool(function () {
			if (pipeTimer === true) {
				return;
			}

			if (this.state.onRefresh) {
				includedTools.push(this);
				clearTimeout(pipeTimer);
				pipeTimer = setTimeout(function () {
					pipeTimer = true; // mark that pipe called
					var pipeArray = includedTools.map(function (currTool) {
						return currTool.id;
					});
					var pipe = new Q.Pipe(pipeArray, 1, function () {
						var waitToolVisible = setInterval(function () {
							// it means that tool element invisible
							if (tool.element.clientHeight === 0) {
								return;
							}

							// check if tool element scrollable
							if (tool.element.clientHeight >= tool.element.scrollHeight) {
								Q.handle(tool.state.onInvoke, tool, [0]);
								pipeTimer = null;
							}

							clearInterval(waitToolVisible);
						}, 1000);
					});
					Q.each(includedTools, function () {
						this.state.onRefresh.add(pipe.fill(this.id), tool);
					});
				}, 1000);
			}
		}, tool);
	}, {
		future: {
			threshold: 0.8
		},
		past: {
			threshold: 0.2
		},
		flipped: false,
		onInvoke: new Q.Event()
	}, {
		/**
		 * Show or hide preloader indicated that new results loading
		 * @method setLoading
		 * @param {boolean} state If true - show preloader, false - hide.
		 */
		setLoading: function (state) {
			if (state) {
				this.preloader = $("<div class='Q_infinitescroll_preloader'>").appendTo(this.element);
			} else {
				this.preloader && this.preloader.remove();
			}
		},
		Q: {
			beforeRemove: function () {
				if (this.watchingScrollHeight) {
					clearInterval(this.watchingScrollHeight);
				}
			}
		}
	});

})(Q, Q.jQuery);