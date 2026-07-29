(function (Q, $) {
/**
 * @module Q-tools
 */
	
Q.text.Q.rating = {
	titles: {
		'1': 'Amazing',
		'0.9': 'Really good',
		'0.8': 'Good',
		'0.7': 'Pretty good',
		'0.6': 'Decent',
		'0.5': 'Average',
		'0.4': 'Could be better',
		'0.3': 'Pretty bad',
		'0.2': 'Bad',
		'0.1': 'Really bad',
		'0': 'Terrible'
	}
};
	
/**
 * Implements a way to display a rating
 * @class Q rating
 * @constructor
 * @param {Object} [options] Override various options for this tool
 * @param {Number} [options.value] The value to represent as the rating
 * @param {Number} [options.max=5] The maximum of the range of values
 * @param {Number} [options.min=0] The minimum of the range of values
 * @param {Object} [options.stars] Information about stars
 * @param {Number} [options.stars.count=5] The maximum number of stars
 * @param {String} [options.stars.full] Override the img src for the full star
 * @param {String} [options.stars.empty] Override the img src for the empty star
 * @param {Object} [options.stars.titles=Q.text.Q.rating.titles] A hash of {fraction: title} pairs
 * @return {Q.Tool}
 */
Q.Tool.define('Q/rating', function (options) {

	this.refresh();
	this.Q.onStateChanged('value').set(this.refresh.bind(this), this);

},

{
	max: 5,
	min: 0,
	stars: {
		count: 5,
		titles: Q.text.Q.rating.titles,
		full: '{{Q}}/img/rating/star-yellow.png',
		empty: '{{Q}}/img/rating/star-gray.png'
	}
}, 

{
	refresh: function () {
		var tool = this;
		var state = tool.state;
		if (state.value == undefined) {
			throw new Q.Error("Q/rating: value is expected");
		}
		var count = state.stars.count;
		var ratio = (state.value - state.min) / (state.max - state.min);
		for (var i=1; i<=count; ++i) {
			var fraction = i/count;
			var rounded = Math.round(fraction*10)/10;
			var value = state.min + fraction * (state.max - state.min);
			var $star, $full, $empty, $occlusion;
			if (!tool.filled) {
				$star = $('<div class="Q_rating_star" />');
				$full = $('<img class="Q_rating_star Q_rating_star_full" />')
				.attr({
					'alt': value,
					'title': value + ': ' + state.stars.titles[rounded],
					'src': Q.url(state.stars.full)
				}).appendTo($star);
				$occlusion = $('<div class="Q_rating_star_occlusion">').appendTo($star);
				$empty = $('<img class="Q_rating_star Q_rating_star_empty" />')
				.attr({
					'alt': value,
					'title': value + ': ' + state.stars.titles[rounded],
					'src': Q.url(state.stars.empty)
				}).appendTo($occlusion);
				$star.appendTo(tool.element);
			} else {
				$occlusion = $('.Q_rating_star_occlusion', tool.element).eq(i-1);
			}
			var cr = count * ratio;
			if (i < cr) {
				$occlusion.css('width', '0');
			} else if (i < cr + 1) {
				$occlusion.css('width', (i-cr)*100+'%');
			} else {
				$occlusion.css('width', '100%');
			}
		}
		tool.filled = true;
	}
});

})(Q, Q.jQuery);