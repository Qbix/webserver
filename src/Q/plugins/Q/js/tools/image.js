(function (window, Q, $, undefined) {
	
/**
 * @module Q
 */
	
/**
 * YUIDoc description goes here
 * @class Q image
 * @constructor
 * @param {Object} [options] Override various options for this tool
 *  @param {string} options.url Source to get image from. Can be remote url or "blob:" for local files
 *  @param {boolean} [options.useViewport=true] If true apply Q/viewport on image
 *
 */
Q.Tool.define("Q/image", function (options) {
	var tool = this;
	var state = tool.state;

	if (state.url) {
		state.url = state.url.interpolate({ "baseUrl": Q.info.baseUrl });
	}

	tool.cache = Q.Cache.document('Q/image');
	tool.cacheKey = Q.normalize(state.url);

	tool.refresh();
},

{
	url: null,
	useViewport: true
},

{
	/**
	 * Refreshes the appearance of the tool completely
	 * @method implement
	 */
	refresh: function () {
		var tool = this;
		var state = this.state;
		var $toolElement = $(this.element);
		var $img;

		tool.cacheData = Q.getObject(['params', 0], tool.cache && tool.cache.get(tool.cacheKey));
		if (tool.cacheData && !Q.isEmpty(tool.cacheData.img)) {
			$img = $(tool.cacheData.img).appendTo(tool.element);
			if (state.useViewport) {
				// apply Q/viewport once image loaded
				$img.plugin('Q/viewport', {
					width: $toolElement.width(),
				});
			}
		} else {
			$img = $("<img />").prop({
				"src": state.url,
				class: "Q_no_lazyload"
			}).appendTo(tool.element);
			tool.cacheData = {
				img: $img[0]
			};
			$img.on("load", function () {
				tool.cache.set(tool.cacheKey, 0, null, [tool.cacheData]);

				if (state.useViewport) {
					// apply Q/viewport once image loaded
					$img.plugin('Q/viewport', {
						width: $toolElement.width(),
					});
				}
			});
		}
	}
});

})(window, Q, Q.jQuery);