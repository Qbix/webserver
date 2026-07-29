(function (Q, $) {
/**
 * @module Q-tools
 */
	
/**
 * Implements a way to display a paging indicator, especially on a mobile phone
 * @class Q paging
 * @constructor
 * @param {Object} [options] Override various options for this tool
 * @param {Number} [options.index] The index of the current page, null means nothing selected
 * @param {Number} [options.total] The total number of pages
 * @param {Object} [options.pages] Information about the pages and their indicators
 * @param {Number} [options.pages.total=5] The number of dots corresponding to the max
 * @param {String} [options.pages.current] Override the img src representing the current page
 * @param {String} [options.pages.other] Override the img src representing the other pages
 * @return {Q.Tool}
 */
Q.Tool.define('Q/paging', function (options) {

	var tool = this;
	var state = this.state;
	if (!('total' in tool.state)) {
		throw new Q.Error("options.total is required");
	}

	tool.refresh();
	tool.Q.onStateChanged('index').set(tool.refresh.bind(tool), tool);
	tool.Q.onStateChanged('total').set(tool.refresh.bind(tool), tool);

},

{
	index: null,
	total: null,
	pages: {
		current: '{{Q}}/img/paging/dot-white.png',
		other: '{{Q}}/img/paging/dot-gray.png'
	}
}, 

{
	refresh: function () {
		var tool = this;
		var state = tool.state;
		var index = state.index;
		var total = state.total;
		if (!Q.isInteger(state.total) || state.total < 0) {
			throw new Q.Error("Q/paging: total is not valid: " + state.total);
		}
		$(tool.element).empty();
		for (var i=0; i<=state.total-1; ++i) {
			var $dot = $('<img />').attr({
				'src': Q.url((i === index) ? state.pages.current : state.pages.other),
				'class': 'Q_paging_dot '
					+ ((i === index) ? 'Q_paging_dot_current' : 'Q_paging_dot_other')
			}).appendTo(tool.element);
		}
	}
});

})(Q, Q.jQuery);