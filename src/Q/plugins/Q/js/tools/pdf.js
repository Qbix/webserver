(function (window, Q, $, undefined) {
	
/**
 * @module Q
 */
	
/**
 * YUIDoc description goes here
 * @class Q pdf
 * @constructor
 * @param {Object} [options] Override various options for this tool
 *  @param {string} [options.url] Source to get pdf from. Can be remote url or "blob:" for local files
 *  @param {float} [options.currentTopPosition] Current top scroll position in percents
 *  @param {float} [options.currentPosition] Current left scroll position in percents
 *  @param {string} [options.clipStart] Clip start position in percents
 *  @param {string} [options.clipEnd] Clip end position in percents
 *  @param {float} [options.scale="fit"] Page scale. Can also be a fraction between 0 and 1
 *  @param {Q.Event} [options.onSuccess] Call when save or upload action successfully ended.
 *  @param {Q.Event} [options.onFinish] Call when save or upload action ended.
 *  @param {Q.Event} [options.onError] Call when error occur.
 *  @param {Q.Event} [options.onScroll] Call when pdf scrolled.
 *  @param {Q.Event} [options.onSlide] Call on slide click.
 *  @param {Q.Event} [options.onRefresh] Call when pdf rendered.
 *  @param {Q.Event} [options.onEnded] Call when pdf scrolled to the end.
 *
 */
Q.Tool.define("Q/pdf", function (options) {
	var tool = this;
	var state = tool.state;

	if (state.url) {
		state.url = state.url.interpolate({ "baseUrl": Q.info.baseUrl });
	}

	tool.cache = Q.Cache.document('Q/pdf');
	tool.cacheKey = Q.normalize(state.url);

	tool.refresh();

	tool.Q.onStateChanged('clipStart').set(function () {
		tool.setClip("start")
	});
	tool.Q.onStateChanged('clipEnd').set(function () {
		tool.setClip("end")
	});
},

{
	url: null,
	currentTopPosition: 0,
	currentLeftPosition: 0,
	clipStart: null,
	clipEnd: null,
	scale: 0.5,
	onSuccess: new Q.Event(),
	onError: new Q.Event(function (message) {
		Q.alert('File upload error' + (message ? ': ' + message : '') + '.');
	}, 'Q/audio'),
	onFinish: new Q.Event(),
	onScroll: new Q.Event(function (currentTopPosition, currentLeftPosition) {
		this.cacheData.scrollTop = currentTopPosition;
		this.cacheData.scrollLeft = currentLeftPosition;
	}),
	onSlide: new Q.Event(function (index) {
		this.cacheData.slideIndex = this.cacheData.slideIndex === index ? null : index;
	}),
	/* </Q/audio jquery plugin states> */
	onRefresh: new Q.Event(function (numPages, element) {
		// remove preloader
		this.$preloader && this.$preloader.remove();

		this.state.stuffHeight = this.element.scrollHeight;
		this.state.stuffWidth = this.element.scrollWidth;

		this.setClip("start");
		this.setClip("end");
	}),
	onEnded: new Q.Event()
},

{
	/**
	 * Check clip borders and pause if outside
	 * @method checkClip
	 */
	checkClip: function () {
		var tool = this;
		var elementHeight = $(tool.element).height();
		var elementScrollTop = tool.element.scrollTop;
		var topClipLimit = tool.getClip("start");
		var bottomClipLimit = tool.getClip("end");

		// check if selected clip gap less than element height
		if (topClipLimit && bottomClipLimit && bottomClipLimit - topClipLimit < elementHeight) {
			return tool.setCurrentPosition(topClipLimit - (elementHeight - (bottomClipLimit - topClipLimit))/2);
		}

		// check clipStart border
		if (topClipLimit && topClipLimit && elementScrollTop < topClipLimit) {
			tool.setCurrentPosition(topClipLimit);
		}

		// check clipEnd border
		if (bottomClipLimit && bottomClipLimit && (elementScrollTop + elementHeight) > bottomClipLimit) {
			tool.setCurrentPosition(bottomClipLimit - elementHeight);
		}
	},
	/**
	 * Refreshes the appearance of the tool completely
	 * @method refresh
	 */
	refresh: function () {
		var tool = this;
		var state = this.state;
		var $toolElement = $(tool.element);

		// add preloader to cover up tool.element till pdf rendered (state.onRefresh)
		if (!tool.$preloader) {
			tool.$preloader = $("<div class='Q_pdf_preloader'><img src='" + Q.url("{{Q}}/img/throbbers/loading.gif") + "'></div>").appendTo(tool.element);
		}

		// wait till lib loaded
		if (Q.typeOf(window.pdfjsLib) === "undefined" || Q.typeOf(window.canvasSize) === "undefined") {
			return setTimeout(tool.implement.bind(tool), 500);
		}

		// listen scroll event of preview element
		$toolElement.on("scroll", function () {
			state.currentTopPosition = ($toolElement.scrollTop()/state.stuffHeight * 100).toPrecision(3);
			state.currentLeftPosition = ($toolElement.scrollLeft()/state.stuffWidth * 100).toPrecision(3);
			Q.handle(state.onScroll, tool, [state.currentTopPosition, state.currentLeftPosition]);
			tool.checkClip();
		});
		// listen slide click event of preview element
		$toolElement.on(Q.Pointer.click, "canvas", function () {
			var slideIndex = Array.prototype.indexOf.call(this.parentNode.children, this);
			Q.handle(state.onSlide, tool, [slideIndex]);
		});

		tool.cacheData = Q.getObject(['params', 0], tool.cache && tool.cache.get(tool.cacheKey));
		if (tool.cacheData && !Q.isEmpty(tool.cacheData.pages)) {
			Q.each(tool.cacheData.pages, function (i, canvas) {
				tool.element.appendChild(canvas);
			});

			var scrollTop = tool.cacheData.scrollTop && tool.element.scrollHeight/100*tool.cacheData.scrollTop;
			var scrollLeft = tool.cacheData.scrollLeft && tool.element.scrollWidth/100*tool.cacheData.scrollLeft;
			return setTimeout(function () {
				tool.setCurrentPosition(scrollTop, scrollLeft);
				Q.handle(state.onRefresh, tool, [tool.cacheData.pages.length, tool.element]);
			}, 100);
		}

		tool.cacheData = {
			pdfInfo: {},
			pages: [],
			slideIndex: null,
			scrollTop: 0,
			scrollLeft: 0
		};

		window.canvasSize.maxArea({onSuccess({ width, height, testTime, totalTime }) {
			state.maxCanvas = width * height;
			pdfjsLib.getDocument(state.url).promise.then(function(pdf) {
				state.pdf = pdf;

				tool.cache

				pdf.getMetadata().then(function(stuff) {
					tool.pdfInfo = tool.cacheData.pdfInfo = {
						title: Q.getObject("info.Title", stuff) || Q.getObject("contentDispositionFilename", stuff),
						description: Q.getObject("info.Subject", stuff),
						author: Q.getObject("info.Author", stuff)
					};
				}).catch(function(err) {
					console.log('Q/pdf: Error getting meta data');
					console.log(err);
				});

				tool.renderPage();
			});
		}});
	},
	/**
	 * Render pdf page
	 * @method renderPage
	 * @param {object} page
	 */
	renderPage: function (page) {
		var tool = this;
		var state = this.state;
		var pdf = state.pdf;
		var $toolElement = $(tool.element);
	
		if (!page) {
			return pdf.getPage(1).then(tool.renderPage.bind(tool));
		}
	
		// Get native page dimensions at scale=1.0
		var nativeViewport = page.getViewport({ scale: 1.0 });
		var pageWidth = nativeViewport.width;
	
		// Calculate actual scale
		var scale = state.scale;
		if (scale === "fit") {
			scale = $toolElement.width() / pageWidth;
		}
		scale = parseFloat(scale) || 1.0;
	
		// Build viewport for rendering
		var viewport = page.getViewport({ scale: scale });
		var i = 1;
		while (viewport.width * viewport.height > state.maxCanvas) {
			scale -= 0.05;
			viewport = page.getViewport({ scale: scale });
			i++;
		}
	
		// Create canvas
		var canvas = document.createElement("canvas");
		canvas.height = viewport.height;
		canvas.width = viewport.width;
		var context = canvas.getContext("2d");
	
		// Render PDF page to canvas
		page.render({
			canvasContext: context,
			viewport: viewport
		});
	
		tool.element.appendChild(canvas);
		tool.cacheData.pages.push(canvas);
	
		var currPage = page.pageNumber + 1;
		if (pdf !== null && currPage <= pdf.numPages) {
			pdf.getPage(currPage).then(tool.renderPage.bind(tool));
		} else {
			Q.handle(state.onRefresh, tool, [pdf.numPages, tool.element]);
		}
	},	
	/**
	 * @method setCurrentPosition
	 * @param {number} top
	 * @param {number} left
	 */
	setCurrentPosition: function (top, left) {
		var element = this.element;
		top = parseInt(top);
		left = parseInt(left);
		element.scrollTo(isNaN(left) ? element.scrollLeft : left, isNaN(top) ? element.scrollTop : top);
	},
	/**
	 * @method setClip
	 * @param {string} which Which side to setup, "start" or "end"
	 */
	setClip: function (which) {
		var tool = this;
		var state = this.state;
		var className = "Q_pdf_clip_" + which;
		var clipValue = tool.getClip(which);

		var $element = $("." + className, tool.element);

		if (clipValue === null) {
			return $element.remove();
		}

		if (!$element.length) {
			$element = $("<div>").addClass(className).appendTo(tool.element);
		}

		var height = 0;
		var top = 0;
		switch (which) {
			case "start":
				height = clipValue;
				break;
			case "end":
				height = state.stuffHeight - clipValue;
				top = clipValue;
				break;
		}

		$element.css({
			height: height,
			top: top,
			width: tool.element.scrollWidth
		});

		// scroll doc to clipStart
		if (which === "start") {
			$(tool.element).scrollTop(clipValue);
		}
	},
	/**
	 * Get clip border in pixels related to top
	 * @method getClip
	 * @param {string} which Which side to setup, "start" or "end"
	 */
	getClip: function (which) {
		var clipValue = this.state["clip" + which.toCapitalized()] || null;

		if (clipValue === null) {
			return null;
		}

		return this.state.stuffHeight * clipValue / 100;
	},
	Q: {
		beforeRemove: function () {
			!Q.isEmpty(this.cacheData) && this.cache.set(this.cacheKey, 0, null, [this.cacheData]);
		}
	}
});
})(window, Q, Q.jQuery);