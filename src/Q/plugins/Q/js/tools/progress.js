(function (Q, $) {
	/**
	 * @module Q-tools
	 */

	/**
	 * This tool show round process bar.
	 * @class Q progress
	 * @constructor
	 * @param {Object} [options] Override various options for this tool
	 * @param {Number} [options.fraction] - process percents
	 * @param {String} [options.color] - progress bar color
	 * @param {Boolean} [options.showProgress] - whether to show percents
	 * @return {Q.Tool}
	 */
	Q.Tool.define("Q/progress", function (options) {
		var tool = this;
		var state = tool.state;

		tool.Q.onStateChanged('fraction').set(function () {
			var fraction = parseInt(state.fraction) + '%';
			tool.$(".Q_progress_bar").width(fraction);
			tool.$(".Q_progress_text").html(fraction);
		}, tool);

		tool.Q.onStateChanged('color').set(function () {
			tool.$(".Q_progress_bar").css("background", state.color);
		}, tool);

		tool.Q.onStateChanged('showProgress').set(function () {
			$(tool.element).attr("data-showProgress", state.showProgress);
		}, tool);

		tool.refresh();
	},

	{
		fraction: 0,
		showProgress: false,
		color: 'red',
		clickPos: {
			fraction: 0
		}
	},

	{
		refresh: function () {
			var tool = this;
			var state = tool.state;
			var $toolElement = $(tool.element);

			Q.Template.render('Q/progress', {}, function (err, html) {
				if (err) return;

				$toolElement.html(html);

				tool.stateChanged('fraction');
				tool.stateChanged('showProgress');
				tool.stateChanged('color');

				var lastEvent = {};
				$toolElement.on('touchstart touchmove', function (event) {
					lastEvent = event;
				});

				// set toolElement click event
				$toolElement.on(Q.Pointer.fastclick, function (event) {
					var x = (Q.getObject(["originalEvent", "touches", 0, "pageX"], lastEvent) || event.pageX) - $toolElement.offset().left;
					var y = (Q.getObject(["originalEvent", "touches", 0, "pageY"], lastEvent) || event.pageY) - $toolElement.offset().top;
					var toolWidth = $toolElement.width();
					//TODO: move progress bar to event pointer
				});
			});
		},
		/**
		 * Set to initial position
		 * @method initPos
		 */
		initPos: function () {
			this.state.fraction = 0;
			this.stateChanged('fraction');
		}
	});

	Q.Template.set('Q/progress',
		`<div class="Q_progress_bar"></div><div class="Q_progress_text"></div>`
	);

})(Q, Q.jQuery);
