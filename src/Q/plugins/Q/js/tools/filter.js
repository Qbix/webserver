(function (Q, $) {
/**
 * @module Q-tools
 */
	
/**
 * Implements an input that filters an associated list (like an autocomplete)
 * @class Q filter
 * @constructor
 * @param {Object} [options] Override various options for this tool
 *  @param {String} [options.name='filter'] The name of the text input
 *  @param {String} [options.value=''] The initial value of the text input
 *  @param {String} [options.placeholder] Any placeholder text
 *  @param {Object} [options.placeholders={}] Options for Q/placeholders, or null to omit it
 *  @param {String} [options.results=''] HTML to display in the results initially. If setting them later, remember to call stateChanged('results')
 *  @param {Q.Event} [options.onFilter] You are meant to attach an event handler to fetch and update results by editing the contents of the element pointed to by the second argument. The first argument is the content of the text input.
 *  @param {Q.Event} [options.onChoose] This event occurs when one of the elements with class "Q_filter_result" is chosen. It is passed (element, details) where you can modify details.text to set the text which will be displayed in the text input to represent the chosen item.
 *  @param {Q.Event} [options.onClear] This event occurs when the filter input is cleared
 *  @param {Q.Event} [options.onFocus] This event occurs when input element focused
 * @return {Q.Tool}
 */
Q.Tool.define('Q/filter', function (options) {
	var tool = this;
	var state = tool.state;
	var $te = $(tool.element);
	
	Q.addStylesheet('{{Q}}/css/tools/filter.css');
	
	if (!$te.children().length) {
		// set it up with javascript
		tool.$input = $('<input />')
		.attr({
			name: state.name,
			value: state.value,
			'class': 'Q_filter_input',
			placeholder: state.placeholder,
			autocomplete: "off"
		}).appendTo(this.element);
		if (state.placeholders) {
			tool.$input.plugin('Q/placeholders', state.placeholders);
		}
		tool.$results = $('<div class="Q_filter_results" />')
			.appendTo(this.element);
	} else {
		tool.$input = tool.$('.Q_filter_input');
		tool.$results = tool.$('.Q_filter_results');
	}
	if ($te.css('position') === 'static') {
		$te.css('position', 'relative');
	}
	
	var events = 'focus ' + Q.Pointer.start.eventName;
	var wasAlreadyFocused = false;
	tool.$input.on(events, function (event) {
		// check if clicked clear icon
		if (event.type === Q.Pointer.start.eventName && tool.checkIfClose(event)) {
			return;
		}

		if (wasAlreadyFocused) {
			return;
		}

		wasAlreadyFocused = true;
		Q.handle(state.onFocus, tool);
		_changed.call(this, event);
	}).on('blur', function () {
		wasAlreadyFocused = false;
		setTimeout(function () {
			if (tool.canceledBlur) {
				tool.canceledBlur = false;
				return false;
			}
			tool.end();
		}, 100);
		tool.cancelBegin = true;
		setTimeout(function () {
			tool.cancelBegin = false;
		}, 500);
	}).on('keydown keyup change input focus paste blur Q_refresh Q_refresh_filter', _changed);
	$te.addClass(state.fullscreen ? 'Q_filter_fullscreen' : 'Q_filter_notFullscreen');
	
	tool.$results.on(Q.Pointer.start.eventName + ' ' + Q.Pointer.end.eventName, function () {
		tool.canceledBlur = true;
	});
	
	var lastVal = null;
	function _changed(event) {
		var $this = $(this);
		if (event.keyCode === 27) {
			$this.val('');
			tool.end();
		}

		if (!tool.cancelRemoveClass) {
			tool.$input.removeClass('Q_filter_chose');
		}
		if (event.type !== 'blur' && event.type !== 'Q_refresh') {
			tool.begin();
		}
		var val = $this.val();
		if (val !== lastVal) {
			state.onFilter.handle.call(tool, val, tool.$results[0]);
		}
		if (val) {
			tool.$input.addClass('Q_nonempty');
		} else {
			tool.$input.removeClass('Q_nonempty');
		}
		lastVal = val;
	}
	
	this.Q.onStateChanged('results').set(function () {
		this.$results.empty().append(state.results);
	});
	this.$results.on(Q.Pointer.fastclick, ".Q_filter_result", function (event) {
		var $cur = $(event.target);
		$cur = $cur.is('.Q_filter_result') ? $cur : $cur.closest('.Q_filter_result');
		tool.choose($cur[0]);
	});
	this.$input.on('keydown', _selection);
	function _selection(event) {
		var $cur = $('.Q_selected', tool.$results);
		var $results = tool.$results.find('.Q_filter_result');
		switch (event.keyCode) {
			case 38: // up arrow
				var $prev = $cur.prev();
				if (!$prev.length) {
					$prev = $results.last();
				}
				$results.removeClass('Q_selected');
				$prev.addClass('Q_selected');
				$se = $($prev[0].scrollingParent());
				$se.scrollTop($prev.offset().top - $results.first().offset().top);
				return false;
			case 40: // down arrow
				var $next = $cur.next();
				if (!$next.length) {
					$next = $results.first();
				}
				$results.removeClass('Q_selected');
				$next.addClass('Q_selected');
				$se = $($next[0].scrollingParent());
				$se.scrollTop($next.offset().top - $results.first().offset().top);
				return false;
			case 13: // enter
				if ($cur) {
					tool.choose($cur[0]);
				}
				return false;
			default:
				break;
		}
	}

}, {
	name: 'filter',
	value: '',
	placeholder: Q.text.Q.input.Placeholder,
	placeholders: {},
	results: null,
	begun: false,
	delayTouchscreen: 500,
	fullscreen: Q.info.isMobile,
	onFilter: new Q.Event(),
	onChoose: new Q.Event(),
	onClear: new Q.Event(),
	onFocus: new Q.Event()
}, {
	/**
	 * Show the filtered results
	 * @method begin
	 * @return {Boolean} May return false if the tool is suspended, etc.
	 */
	begin: function () {
		var tool = this;
		var state = tool.state;
		if (tool.suspended || tool.cancelBegin) {
			return false;
		}
		if (state.begun) {
			return true;
		}
		state.begun = true;
		
		tool.canceledBlur = true;
		setTimeout(function () {
			tool.canceledBlur = false;
		}, 500);

		var $te = $(tool.element);
		$te.addClass('Q_filter_begun');

		if (state.fullscreen) {
			tool.canceledBlur = true;

			// on slower mobile browsers, the following might synchronously lag a bit
			var $body = $('body');
			state.oldBodyOverflow = $body.css('overflow');
			$body.css('overflow', 'auto')
				.addClass('Q_overflow');
			tool.suspended = true;
			Q.Pointer.cancelClick();
			tool.$placeholder = $('<div class="Q_filter_placeholder" />')
				.insertAfter($te);
			$te.addClass('Q_filter_begun')
				.prependTo('body');
			$te.nextAll().not('.Q_dont_hide').addClass('Q_filter_hide');
			var top = 0;
			$te.nextAll('.Q_dont_hide').each(function () {
				top += $(this).outerHeight(true);
			});
			$te.css('top', top + 'px');
			Q.Masks.show(tool);
			tool.$input.trigger('Q_refresh').plugin('Q/clickfocus');
			setTimeout(function () {
				tool.suspended = false;
			}, 10);
			setTimeout(function () {
				Q.Masks.hide(tool);
			}, state.delayTouchscreen); // to prevent touchend events from wreaking havoc
		}
		
		var $container = tool.$input.parent('.Q_placeholders_container');
		var topH = tool.$input.outerHeight();
		if (!$container.length) {
			$container = tool.$input;
		} else {
			topH += parseInt(tool.$input.css('margin-top')) ;
		}
		tool.$results.insertAfter($container).css({
			left: parseInt($container.css('marginLeft'), 10) || 0,
			width: $container.outerWidth(),
			"box-sizing": 'border-box',
			top: state.fullscreen 
				? 0
				: $container.offset().top - $te.offset().top + topH
		});
		return true;
	},
	/**
	 * Hide the filtered results
	 * @param [chosenText] the text of the chosen option, if any, to display in the input
	 * @method end
	 */
	end: function (chosenText) {
		var tool = this;
		var state = tool.state;
		if (chosenText !== undefined) {
			tool.cancelBegin = true;
			setTimeout(function () {
				tool.cancelBegin = false;
			}, 300);
			tool.setText(chosenText);
		}
		if (!state.begun || tool.suspended) return;
		setTimeout(function () {
			state.begun = false;
			var $te = $(tool.element);
			$te.removeClass('Q_filter_begun');
			if (state.fullscreen) {
				$te.nextAll().removeClass('Q_filter_hide');
				$te.insertAfter(tool.$placeholder);
				tool.$placeholder.remove();
				tool.$input.blur();
				$('body').css('overflow', state.oldBodyOverflow)
				.removeClass('Q_overflow');
			}
		}, 0);
		Q.Pointer.cancelClick();
		return false;
	},
	/**
	 * Set text in the input
	 * @param {String} [chosenText] the text of the chosen option, if any, to display in the input
	 *   Pass the empty string here to clear the filter and trigger the onClear method
	 * @param {Boolean} [dontRefresh] pass true here to not refresh the filter
	 * @method setText
	 */
	setText: function (chosenText, dontRefresh) {
		this.$input.val(chosenText);
		if (!dontRefresh) {
			this.$input.trigger('Q_refresh');
		}
		if (chosenText === '') {
			this.state.onClear.handle.call(this);
		}
	},
	/**
	 * Choose an item in the results.
	 * @method choose
	 * @param {HTMLElement} [element] the element to choose
	 */
	choose: function (element) {
		var streamName = $(element).data('streamName');
		var details = {
			text: $(element).text()
		};
		var tool = this;
		tool.setText(details.text, true);
		Q.handle(tool.state.onChoose, tool, [element, details]);
		tool.end(details.text);
		tool.$input.blur();
		tool.$input.addClass('Q_filter_chose');
		tool.cancelRemoveClass = true;
		setTimeout(function () {
			tool.cancelRemoveClass = false;
		}, 300);
	},
	/**
	 * Close results and clear input
	 * @method checkIfClose
	 */
	checkIfClose: function (event) {
		var tool = this;
		var state = this.state;
		var $input = tool.$input;

		var xMax = $input.offset().left + $input.outerWidth(true) -
			parseInt($input.css('margin-right'));
		var xMin = xMax - parseInt($input.css('padding-right'));
		var x = Q.Pointer.getX(event);
		if (xMin < x && x < xMax) {
			$input.val('');
			state.onClear.handle.call(tool);

			$input.trigger("blur");
		}

		return false;
	}
});

})(Q, Q.jQuery);
