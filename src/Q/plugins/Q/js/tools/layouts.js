(function (Q, $) {
    /**
     * @module Q-tools
     */

    /**
     * Implements a common way to manage layouts for elements on desktop, mobile, etc.
     * @class Q layouts
     * @constructor
     * @param {Object} [options] Override various options for this tool
     * @param {Function} [options.filter] Gets passed an element, returns true if it should be processed
     * @return {Q.Tool}
     */
    Q.Tool.define('Q/layouts', function (options) {

            var tool = this;
            var state = this.state;
        },

        {
            elementToWrap: null,
            alternativeContainer: null,
            customGenerators: [],
            currentGenerator: [],
            currentMappedRects: [],
            filter: null,
            key: null,
            onLayout: new Q.Event()
        },

        {
            Q:{
                beforeRemove: function () {
                }
            },
            /**
             * Sets a function to generate a layout
             * @method setLayoutGenerator
             * @param {String} key
             * @param {Function} generator takes a number and returns array of DOMRect
             */
            setLayoutGenerator: function (key, generator) {
                _generators[key] = generator;
                this.state.customGenerators.push(key);
            },
            /**
             * Gets a layout generator that was added
             * @method getLayoutGenerator
             */
            getLayoutGenerator: function (key) {
                return _generators[key];
            },
            /**
             * Clears custom generators of current tool instance when it is being removed from page
             * @method clearCustomGenerators
             */
            clearCustomGenerators: function(){
                for(var i in this.state.customGenerators) {
                    var name = this.state.customGenerators[i];
                    delete _generators[name];
                }
                this.state.customGenerators = [];
            },
            /**
             * Applies a layout
             * @method layout
             * @param {String} key the key of the alyout
             * @param {Number} count How many elements
             */
            layout: function (key, count) {
                var layout = _generators[key];;
                return layout != null
                    ? layout(this.element, count)
                    : false;
            },
            /**
             * Animates given elements to a layout
             * @method layout
             * @param {String|Function} generator The name of the layout generator, or a function
             * @param {Array|jQuery} elements An array of elements, in order to fit the layout rectangles
             * @param {Number} [duration=0] Number of milliseconds
             * @param {String|Function} ease
             *  The key of the ease function in Q.Animation.ease object, or another ease function
             * @return {Boolean} Whether the layout generator existed
             */
            animate: function (generator, elements, duration, ease) {
                var tool = this;
                var g = (typeof generator === 'function')
                    ? generator
                    : _generators[generator];
                if (!g) {
                    return false;
                }
                var tool = this;
                var container = tool.element;
                var wrappingContainer = tool.state.alternativeContainer != null ? tool.state.alternativeContainer : tool.element;
                var layout = g(wrappingContainer, elements.length, tool);
                if(!layout) return;
                tool.state.currentMappedRects = [];

                if (container.computedStyle('position') === 'static') {
                    container.style.position = 'relative';
                }
                var rects = [];
                var i, element;

                for(i = 0; element = elements[i]; i++){
                    var layoutRect = layout[i];

                    if(layoutRect.width == 0 &&  layoutRect.height == 0) {
                        element.style.height = 'auto';
                        element.style.width = 'auto';
                    }

                    let notRenderedYet = false;
                    if (!container.contains(element)) {
                        notRenderedYet = true;
                        container.appendChild(element);
                    }

                    if (element.style.position != 'absolute') {
                        element.style.position = 'absolute';
                    }
                    var elementRect = element.getBoundingClientRect();
                    var elementTop = element.offsetTop;
                    var elementLeft = element.offsetLeft;
                    /*if(elementRect.width != 0 && elementRect.height != 0 && layoutRect.width != 0 && layoutRect.height != 0 && element.style.height == 'auto') {
                        element.style.height = '';
                        element.style.width = '';
                    }*/
                    if(notRenderedYet == false) {
                        elementRect = new DOMRect(elementLeft, elementTop, elementRect.width, elementRect.height);
                        rects.push(elementRect);
                    } else {
                        elementRect = new DOMRect(0, 0, elementRect.width, elementRect.height);
                        rects.push(elementRect);
                    }

                    tool.state.currentMappedRects.push({rect: layoutRect, el: element});

                }
                if (this.animation) {
                    this.animation.pause();
                }


                this.animation = Q.Animation.play(function (x, y) {
                    Q.each(elements, function (i) {

                        var rect1 = rects[i];
                        var rect2 = layout[i];
                        var ts = elements[i].style;

                        if(ts.left == '') ts.left = rect2.left + 'px';
                        if(ts.top == '')  ts.top = rect2.top + 'px';

                        if(ts.width == '') ts.width = rect2.width + 'px';
                        if(ts.height == '') ts.height = rect2.height + 'px';

                        var currentLeft = parseFloat(ts.left);
                        var currentTop = parseFloat(ts.top);
                        var currentWidth = parseFloat(ts.width);
                        var currentHeight = parseFloat(ts.height);

                        if(currentLeft !== rect2.left) ts.left = (rect1.left + (rect2.left - rect1.left) * x) + 'px';
                        if(currentTop !== rect2.top) ts.top = (rect1.top + (rect2.top - rect1.top) * y) + 'px';


                        if((rect2.width != 0 && currentWidth != rect2.width) && currentWidth !== rect2.width) ts.width = rect1.width + (rect2.width - rect1.width) * y + 'px';
                        if((rect2.height != 0 && currentHeight != rect2.height) && currentHeight !== rect2.height) ts.height = rect1.height + (rect2.height - rect1.height) * y + 'px';
                    });

                }, duration, ease);
                tool.state.currentGenerator = generator;
            }
        });

    var _generators = {
        tiledVertical: function (container, count) {
            var containerRect = container == document.body ? new DOMRect(0, 0, window.innerWidth, window.innerHeight) : container.getBoundingClientRect();

            return tiledDesktopGrid(count, containerRect);
        },
        tiledHorizontal: function (container, count) {
            var containerRect = container == document.body ? new DOMRect(0, 0, window.innerWidth, window.innerHeight) : container.getBoundingClientRect();

            return tiledDesktopGrid(count, containerRect);
        },
        fullScreen: function (container, count) {
            var containerRect = container == document.body ? new DOMRect(0, 0, window.innerWidth, window.innerHeight) : container.getBoundingClientRect();
            var size = {parentWidth:containerRect.width, parentHeight:containerRect.height};

            return fullScreenLayout(count, size, true);
        },
        maximizedVertical: function (container, count) {

        },
        maximizedHorizontal: function (container, count, elementToWrap) {

        },

        tiledVerticalMobile: function (container, count) {
            var containerRect = container == document.body ? new DOMRect(0, 0, window.innerWidth, window.innerHeight) : container.getBoundingClientRect();
            var size = {parentWidth:containerRect.width, parentHeight:containerRect.height};

            switch (count) {
                case 1:
                    return simpleGrid(count, size, 1);
                case 2:
                    return simpleGrid(count, size, 1);
                case 3:
                    return simpleGrid(count, size, 2);
                case 4:
                    return simpleGrid(count, size, 2);
                case 5:
                    return simpleGridBasedOnRowsNum(count, 3, size)
                case 6:
                    return simpleGrid(count, size, 2);
                default:
                    return simpleGrid(count, size, 2);

            }
        },

        tiledStreamingLayout:  function (container, count, _layoutTool) {

            var defaultSide = 'top-full';

            var containerRect = container.constructor.name != 'DOMRect' ? container.getBoundingClientRect() : container;
            var parentWidth = containerRect.width;
            var parentHeight = containerRect.height;
            var startFromX = container.constructor.name == 'DOMRect' ? container.x : 0;
            var startFromY = container.constructor.name == 'DOMRect' ? container.y : 0;

            _layoutTool.state.currentGenerator = 'tiledStreamingLayout';

            if(_layoutTool.currentRects.length == 0) {
                _layoutTool.currentRects = build(container, count);
            } else {

                if(count > _layoutTool.currentRects.length) {
                    _layoutTool.basicGridRects = build(container, count);
                    //var availableRects = addAndUpdate(container, count);
                    //_layoutTool.currentRects = _layoutTool.basicGridRects = _layoutTool.currentRects.concat(availableRects);
                    let numOfEls = _layoutTool.basicGridRects.length - _layoutTool.currentRects.length;
                    let last = _layoutTool.basicGridRects.slice(Math.max(_layoutTool.basicGridRects.length - numOfEls, 0))

                    let updatedRects = updateRealToBasicGrid();
                    _layoutTool.currentRects = updatedRects.concat(last);

                } else if(count < _layoutTool.currentRects.length) {
                    _layoutTool.basicGridRects = build(container, count);
                    _layoutTool.currentRects = updateRealToBasicGrid();
                    //_layoutTool.currentRects = removeAndUpdate();
                }
            }

            return  _layoutTool.currentRects;

            function build() {
                var size;
                if(container.width != null && container.height != null) {
                    size = {parentWidth:container.width, parentHeight:container.height};
                } else {
                    var containerRect = container == document.body ? new DOMRect(0, 0, window.innerWidth, window.innerHeight) : container.getBoundingClientRect();
                    size = {parentWidth:containerRect.width, parentHeight:containerRect.height};
                }

                if(count == 1) {
                    return simpleGrid(count, size, 1);
                } else if(count == 2) {
                    return simpleGrid(count, size, 2);
                } else if(count == 3) {
                    return simpleGrid(count, size, 3);
                } else if(count == 4) {
                    return simpleGrid(count, size, 2);
                } else if(count == 5) {
                    return simpleGrid(count, size, 3);
                } else if(count >= 6 && count < 13) {
                    return simpleGrid(count, size, 3);
                } else {
                    return simpleGrid(count, size, 4);
                }
            }

            function updateRealToBasicGrid() {

                var actualLayoutRects = []
                for(var i = 0; i < _layoutTool.state.currentMappedRects.length; i++) {
                    if(_layoutTool.element.contains(_layoutTool.state.currentMappedRects[i].el) ) {
                        actualLayoutRects.push({
                            key: actualLayoutRects.length,
                            rect: _layoutTool.state.currentMappedRects[i].rect
                        });
                    }
                }

                let actualLayoutRectsClone = [...actualLayoutRects];

                // for(let r = _layoutTool.basicGridRects.length - 1; r >= 0 ; r--){
                for(let r in _layoutTool.basicGridRects) {
                    let rect = _layoutTool.basicGridRects[r];

                    let closestIndex = closest(rect, actualLayoutRectsClone);

                    console.log('updateRealToBasicGrid closestIndex', r, closestIndex);
                    console.log('updateRealToBasicGrid closestIndex', rect.x, rect.y, rect.width, rect.height);
                    if(actualLayoutRects[closestIndex]) {
                        console.log('updateRealToBasicGrid closestIndex2', actualLayoutRects[closestIndex].rect.x, actualLayoutRects[closestIndex].rect.y, actualLayoutRects[closestIndex].rect.width, actualLayoutRects[closestIndex].rect.height);
                    }

                    if(closestIndex == null) continue;

                    actualLayoutRects[closestIndex].rect.x = rect.x;
                    actualLayoutRects[closestIndex].rect.y = rect.y;
                    actualLayoutRects[closestIndex].rect.width = rect.width;
                    actualLayoutRects[closestIndex].rect.height = rect.height;
                    //rectsToSkip.push(closestIndex);

                    for(let c in actualLayoutRectsClone) {
                        if(actualLayoutRectsClone[c].key == closestIndex) {
                            actualLayoutRectsClone.splice(c, 1);
                        }

                    }
                }

                return actualLayoutRects.map(function (o) {
                    return o.rect;
                })

                function closest(rect, rects) {
                    var distance = function (x1,y1,x2,y2) {
                        return Math.sqrt(Math.pow(x2-x1,2)+Math.pow(y2-y1,2));
                    }

                    if(rects.length != 0) {

                        let closestRect = rects.reduce(function (prev, current, index) {
                            return (distance(current.rect.left + (current.rect.width / 2), current.rect.top + (current.rect.height / 2), rect.left + (rect.width / 2), rect.top + (rect.height / 2)) < distance(prev.rect.left + (prev.rect.width / 2), prev.rect.top + (prev.rect.height / 2), rect.left + (rect.width / 2), rect.top + (rect.height / 2))) ? current : prev;
                        })

                        return closestRect.key;

                    } else {
                        return null;
                    }
                }
            }

            function simpleGrid(count, size, perRow, rowsNum) {
                console.log('simpleGrid');
                var rects = [];
                var spaceBetween = 10;
                var centerX = containerRect.width / 2;
                var centerY = containerRect.height / 2;
                var rectHeight;
                var rectWidth = (size.parentWidth / perRow) - (spaceBetween*(perRow - 1));
                if(rowsNum == null) {
                    rectHeight = size.parentHeight / Math.ceil(count / perRow) - (spaceBetween*(perRow - 1));
                } else {
                    rectHeight = size.parentHeight / rowsNum - (spaceBetween*(perRow - 1));
                }
                var newRectSize = getElementSizeKeepingRatio({
                    width: 1280,
                    height: 720
                }, {width: rectWidth, height: rectHeight})

                rectWidth = newRectSize.width;
                rectHeight = newRectSize.height;
                rowsNum = Math.floor(size.parentHeight / (rectHeight + spaceBetween));
                console.log('simpleGrid 1', size.parentHeight, rectHeight, rectHeight + spaceBetween);

                var isNextNewLast = false;
                var rowItemCounter = 1;
                var i;
                for (i = 1; i <= count; i++) {
                    console.log('simpleGrid for', currentRow, rowsNum);

                    var prevRect = rects[rects.length - 1] ? rects[rects.length - 1] : new DOMRect(0, 0, 0, 0) ;
                    var currentRow = isNextNewLast  ? rowsNum : Math.ceil(i/perRow);
                    var isNextNewRow  = rowItemCounter == perRow;
                    isNextNewLast = isNextNewLast == true ? true : isNextNewRow && currentRow + 1 == rowsNum;

                    if(rowItemCounter == 1) {
                        var y = (prevRect.y + prevRect.height) + spaceBetween;
                        var x = spaceBetween;
                    } else {
                        var y = prevRect.y;
                        var x = prevRect.x + prevRect.width + spaceBetween;
                    }

                    var rect = new DOMRect(x, y, rectWidth, rectHeight);

                    rects.push(rect);

                    if (rowItemCounter == perRow) {
                        rowItemCounter = 1;
                    } else rowItemCounter++;
                }

                return centralizeRects(rects);
            }

            function getRectsRows(rects) {
                var rows = {};
                var i, count = rects.length;
                for(i = 0; i < count; i++) {
                    var rect = rects[i];

                    if(rows[rect.top] == null) rows[rect.top] = [];

                    rows[rect.top].push({indx: i, top: rect.top, rect:rect, side:'none'});

                }

                var rowsArray = [];
                for (var property in rows) {
                    if (rows.hasOwnProperty(property)) {
                        rowsArray.push(rows[property]);
                    }
                }

                return rowsArray;
            }

            function centralizeRects(rects) {

                var centerX = containerRect.width / 2;
                var centerY = containerRect.height / 2;

                var minY = Math.min.apply(Math, rects.map(function(r) { return r.y; }));
                var maxY = Math.max.apply(Math, rects.map(function(r) { return r.y + r.height;}));

                var sortedRows = getRectsRows(rects);
                console.log('centralizeRects sortedRows', sortedRows)

                var alignedRects = []
                for(let r in sortedRows) {
                    let row = sortedRows[r].map(function(r) { return r.rect; });
                    var rowMinX = Math.min.apply(Math, row.map(function(r) { return r.x; }));
                    var rowMaxX = Math.max.apply(Math, row.map(function(r) { return r.x + r.width;}));
                    var rowTotalWidth = rowMaxX - rowMinX;
                    console.log('centralizeRects rowTotalWidth', rowMinX, rowMaxX, rowTotalWidth)
                    console.log('centralizeRects centerX', centerX)
                    var newXPosition = centerX - (rowTotalWidth / 2);
                    console.log('centralizeRects newXPosition', newXPosition)

                    var moveAllRectsOn = newXPosition - rowMinX;

                    for(let s = 0; s < row.length; s++) {
                        alignedRects.push(new DOMRect(row[s].left + moveAllRectsOn, row[s].top, row[s].width, row[s].height));
                    }
                }

                var totalHeight = maxY - minY;

                var newTopPosition = centerY - (totalHeight / 2);
                var moveAllRectsOn = newTopPosition - minY;
                for(let s = 0; s < alignedRects.length; s++) {
                    alignedRects[s] = new DOMRect(alignedRects[s].left, alignedRects[s].top + moveAllRectsOn, alignedRects[s].width, alignedRects[s].height);
                }

                return alignedRects;
            }
        },
        sideBySideMobile: function (container, count) {
            var containerRect = container == document.body ? new DOMRect(0, 0, window.innerWidth, window.innerHeight) : container.getBoundingClientRect();
            var height = containerRect.width / 16 * 8.96;
            var y = (containerRect.height / 2) - (height / 2)
            var x = 0;
            var size = {x: x, y: y, parentWidth:containerRect.width, parentHeight:containerRect.width / 16 * 8.96};

            if(count == 2) return simpleGridBasedOnRowsNum(count, 1, size);
        },
        tiledHorizontalMobile: function (container, count, _layoutTool) {
            var containerRect = container.constructor.name != 'DOMRect' ? container.getBoundingClientRect() : container;
            var parentWidth = containerRect.width;
            var parentHeight = containerRect.height;
            var startFromX = container.constructor.name == 'DOMRect' ? container.x : 0;
            var startFromY = container.constructor.name == 'DOMRect' ? container.y : 0;

            _layoutTool.state.currentGenerator = 'squaresGrid';

            if(_layoutTool.currentRects.length == 0) {
                _layoutTool.currentRects = build(container, count);
            } else {

                if(count > _layoutTool.currentRects.length) {
                    _layoutTool.basicGridRects = build(container, count);
                    //var availableRects = addAndUpdate(container, count);
                    //_layoutTool.currentRects = _layoutTool.basicGridRects = _layoutTool.currentRects.concat(availableRects);
                    let numOfEls = _layoutTool.basicGridRects.length - _layoutTool.currentRects.length;
                    let last = _layoutTool.basicGridRects.slice(Math.max(_layoutTool.basicGridRects.length - numOfEls, 0))

                    let updatedRects = updateRealToBasicGrid();
                    _layoutTool.currentRects = updatedRects.concat(last);

                } else if(count < _layoutTool.currentRects.length) {
                    _layoutTool.basicGridRects = build(container, count);
                    _layoutTool.currentRects = updateRealToBasicGrid();
                    //_layoutTool.currentRects = removeAndUpdate();
                }
            }

            return  _layoutTool.currentRects;

            function build() {
                var size;
                if(container.width != null && container.height != null) {
                    size = {parentWidth:container.width, parentHeight:container.height};
                } else {
                    var containerRect = container == document.body ? new DOMRect(0, 0, window.innerWidth, window.innerHeight) : container.getBoundingClientRect();
                    size = {parentWidth:containerRect.width, parentHeight:containerRect.height};
                }

                switch (count) {
                    case 1:
                        return simpleGrid(count, size, 1);
                    case 2:
                        return simpleGridBasedOnRowsNum(count, 1, size)
                    case 3:
                        return simpleGrid(count, size, 3);
                    case 4:
                        return simpleGrid(count, size, 2);
                    case 5:
                        return simpleGridBasedOnRowsNum(count, 3, size)
                    case 6:
                        return simpleGrid(count, size, 2);
                    default:
                        return simpleGrid(count, size, 2);

                }
            }

            function updateRealToBasicGrid() {

                var actualLayoutRects = []
                for(var i = 0; i < _layoutTool.state.currentMappedRects.length; i++) {
                    if(_layoutTool.element.contains(_layoutTool.state.currentMappedRects[i].el) ) {
                        actualLayoutRects.push({
                            key: actualLayoutRects.length,
                            rect: _layoutTool.state.currentMappedRects[i].rect
                        });
                    }
                }

                let actualLayoutRectsClone = [...actualLayoutRects];

                // for(let r = _layoutTool.basicGridRects.length - 1; r >= 0 ; r--){
                for(let r in _layoutTool.basicGridRects) {
                    let rect = _layoutTool.basicGridRects[r];

                    let closestIndex = closest(rect, actualLayoutRectsClone);

                    console.log('updateRealToBasicGrid closestIndex', r, closestIndex);
                    console.log('updateRealToBasicGrid closestIndex', rect.x, rect.y, rect.width, rect.height);
                    if(actualLayoutRects[closestIndex]) {
                        console.log('updateRealToBasicGrid closestIndex2', actualLayoutRects[closestIndex].rect.x, actualLayoutRects[closestIndex].rect.y, actualLayoutRects[closestIndex].rect.width, actualLayoutRects[closestIndex].rect.height);
                    }

                    if(closestIndex == null) continue;

                    actualLayoutRects[closestIndex].rect.x = rect.x;
                    actualLayoutRects[closestIndex].rect.y = rect.y;
                    actualLayoutRects[closestIndex].rect.width = rect.width;
                    actualLayoutRects[closestIndex].rect.height = rect.height;
                    //rectsToSkip.push(closestIndex);

                    for(let c in actualLayoutRectsClone) {
                        if(actualLayoutRectsClone[c].key == closestIndex) {
                            actualLayoutRectsClone.splice(c, 1);
                        }

                    }
                }

                return actualLayoutRects.map(function (o) {
                    return o.rect;
                })

                function closest(rect, rects) {
                    var distance = function (x1,y1,x2,y2) {
                        return Math.sqrt(Math.pow(x2-x1,2)+Math.pow(y2-y1,2));
                    }

                    if(rects.length != 0) {

                        let closestRect = rects.reduce(function (prev, current, index) {
                            return (distance(current.rect.left + (current.rect.width / 2), current.rect.top + (current.rect.height / 2), rect.left + (rect.width / 2), rect.top + (rect.height / 2)) < distance(prev.rect.left + (prev.rect.width / 2), prev.rect.top + (prev.rect.height / 2), rect.left + (rect.width / 2), rect.top + (rect.height / 2))) ? current : prev;
                        })

                        return closestRect.key;

                    } else {
                        return null;
                    }
                }
            }
        },
        maximizedVerticalMobile: function (container, count) {
            var containerRect = container == document.body ? new DOMRect(0, 0, window.innerWidth, window.innerHeight) : container.getBoundingClientRect();
            var size = {parentWidth:containerRect.width, parentHeight:containerRect.height};

            return minimizedOrMaximizedMobile(count, size, true);
        },

        maximizedHorizontalMobile: function (container, count) {
            var containerRect = container == document.body ? new DOMRect(0, 0, window.innerWidth, window.innerHeight) : container.getBoundingClientRect();
            var size = {parentWidth:containerRect.width, parentHeight:containerRect.height};

            return minimizedOrMaximizedHorizontalMobile(count, size, true);
        },
        minimizedVerticalMobile: function (container, count) {
            var containerRect = container == document.body ? new DOMRect(0, 0, window.innerWidth, window.innerHeight) : container.getBoundingClientRect();
            var size = {parentWidth:containerRect.width, parentHeight:containerRect.height};

            return minimizedOrMaximizedMobile(count, size);
        },

        minimizedHorizontalMobile: function (container, count) {
            var containerRect = container == document.body ? new DOMRect(0, 0, window.innerWidth, window.innerHeight) : container.getBoundingClientRect();
            var size = {parentWidth:containerRect.width, parentHeight:containerRect.height};

            return minimizedOrMaximizedHorizontalMobile(count, size);
        }
    };

    function minimizedOrMaximizedMobile(count, size, maximized) {

        var rects = [];

        if(maximized) {
            var mainScreenRect = new DOMRect(0, 0, size.parentWidth, size.parentHeight);
            rects.push(mainScreenRect);
        }


        var rectWidth = 100;
        var rectHeight = 139;
        var spaceBetween = 10;
        var totalRects = (size.parentWidth * (size.parentHeight - 66)) / ((rectWidth + spaceBetween) * (rectHeight + spaceBetween));
        var perCol = Math.floor((size.parentHeight - 66) / (rectHeight + spaceBetween));
        var perRow =  Math.floor(size.parentWidth / (rectWidth + spaceBetween));


        var side = 'right'
        var isNextNewLast = false;
        var createNewColOnRight = null;
        var createNewColOnLeft = null;
        var latestRightRect = null;
        var latestLeftRect = null;
        var colItemCounter = 1;
        var leftSideCounter = 0;
        var rightSideCounter = 0;
        var i;
        for (i = 1; i <= count; i++) {
            var firstRect = new DOMRect(size.parentWidth, size.parentHeight - 66, rectWidth, rectHeight)
            var prevRect = rects.length > 1 ? rects[rects.length - 2] : firstRect;
            var currentCol = isNextNewLast  ? perRow : Math.ceil(i/perCol);
            var isNextNewCol = colItemCounter  == perCol;
            isNextNewLast = isNextNewLast == true ? true : isNextNewCol && currentCol + 1 == perRow;

            var x, y, rect, prevRect;
            if(side == "right") {
                prevRect = latestRightRect;
                if (rightSideCounter > 0 && !createNewColOnRight) {
                    y = prevRect.y - (rectHeight + spaceBetween);
                    x = prevRect.x;
                } else if(createNewColOnRight) {
                    y = (size.parentHeight - 66) - (rectHeight + spaceBetween);
                    x = prevRect.x - (rectWidth + spaceBetween);
                    createNewColOnRight = false;
                } else {
                    y = (size.parentHeight - 66) - (rectHeight + spaceBetween);
                    x = size.parentWidth - (rectWidth + spaceBetween);
                }
                rightSideCounter++;

                rect = new DOMRect(x, y, rectWidth, rectHeight);
                latestRightRect = rect;

                side = 'left';

                if(rightSideCounter % perCol == 0) {
                    createNewColOnRight = true;
                }
            } else {
                prevRect = latestLeftRect;
                if (leftSideCounter > 0 && !createNewColOnLeft) {
                    y = prevRect.y - (rectHeight + spaceBetween);
                    x = prevRect.x;
                } else if(createNewColOnLeft) {
                    y = (size.parentHeight - 66) - (rectHeight + spaceBetween);
                    x = prevRect.x + prevRect.width + spaceBetween;
                    createNewColOnLeft = false;
                } else {
                    y = (size.parentHeight - 66) - (rectHeight + spaceBetween);
                    x = spaceBetween;
                }
                leftSideCounter++;

                rect = new DOMRect(x, y, rectWidth, rectHeight);
                latestLeftRect = rect;

                side = 'right';

                if(leftSideCounter % perCol == 0) {
                    createNewColOnLeft = true;
                }
            }

            rects.push(rect);

            if(isNextNewCol) {
                colItemCounter = 1;
            } else colItemCounter++;
        }


        return rects;
    }


    function minimizedOrMaximizedHorizontalMobile(count, size, maximized) {
        var rects = [];

        if(maximized) {
            var mainScreenRect = new DOMRect(0, 0, size.parentWidth, size.parentHeight);
            rects.push(mainScreenRect);
        }

        var rectWidth = 100;
        var rectHeight = 100;
        var spaceBetween = 10;
        var totalRects = (size.parentWidth * (size.parentHeight - 66)) / ((rectWidth + spaceBetween) * (rectHeight + spaceBetween));
        var perCol = Math.floor((size.parentHeight - 66) / (rectHeight + spaceBetween));
        var perRow =  Math.floor(size.parentWidth / (rectWidth + spaceBetween));

        var side = 'right'
        var isNextNewLast = false;
        var createNewColOnRight = null;
        var createNewColOnLeft = null;
        var latestRightRect = null;
        var latestLeftRect = null;
        var colItemCounter = 1;
        var leftSideCounter = 0;
        var rightSideCounter = 0;
        var i;
        for (i = 1; i <= count; i++) {
            var firstRect = new DOMRect(size.parentWidth, size.parentHeight - 66, rectWidth, rectHeight)
            var prevRect = rects.length > 1 ? rects[rects.length - 2] : firstRect;
            var currentCol = isNextNewLast  ? perRow : Math.ceil(i/perCol);
            var isNextNewCol = colItemCounter  == perCol;
            isNextNewLast = isNextNewLast == true ? true : isNextNewCol && currentCol + 1 == perRow;

            var x, y, rect, prevRect;
            if(side == "right") {
                prevRect = latestRightRect;
                if (rightSideCounter > 0 && !createNewColOnRight) {
                    y = prevRect.y - (rectHeight + spaceBetween);
                    x = prevRect.x;
                } else if(createNewColOnRight) {
                    y = (size.parentHeight - 66) - (rectHeight + spaceBetween);
                    x = prevRect.x - (rectWidth + spaceBetween);
                    createNewColOnRight = false;
                } else {
                    y = (size.parentHeight - 66) - (rectHeight + spaceBetween);
                    x = size.parentWidth - (rectWidth + spaceBetween);
                }
                rightSideCounter++;

                rect = new DOMRect(x, y, rectWidth, rectHeight);
                latestRightRect = rect;

                side = 'left';

                if(rightSideCounter % perCol == 0) {
                    createNewColOnRight = true;
                }
            } else {
                prevRect = latestLeftRect;
                if (leftSideCounter > 0 && !createNewColOnLeft) {
                    y = prevRect.y - (rectHeight + spaceBetween);
                    x = prevRect.x;
                } else if(createNewColOnLeft) {
                    y = (size.parentHeight - 66) - (rectHeight + spaceBetween);
                    x = prevRect.x + prevRect.width + spaceBetween;
                    createNewColOnLeft = false;
                } else {
                    y = (size.parentHeight - 66) - (rectHeight + spaceBetween);
                    x = spaceBetween;
                }
                leftSideCounter++;

                rect = new DOMRect(x, y, rectWidth, rectHeight);
                latestLeftRect = rect;

                side = 'right';

                if(leftSideCounter % perCol == 0) {
                    createNewColOnLeft = true;
                }
            }

            rects.push(rect);

            if(isNextNewCol) {
                colItemCounter = 1;
            } else colItemCounter++;
        }

        return rects;
    }

    function fullScreenLayout(count, size, maximized) {
        var rects = [];

        if(maximized) {
            var mainScreenRect = new DOMRect(0, 0, size.parentWidth, size.parentHeight);
            rects.push(mainScreenRect);
        }

        var rectWidth = 100;
        var rectHeight = 100;
        var spaceBetween = 10;
        var totalRects = (size.parentWidth * (size.parentHeight - 66)) / ((rectWidth + spaceBetween) * (rectHeight + spaceBetween));
        var perCol = Math.floor((size.parentHeight - 66) / (rectHeight + spaceBetween));
        var perRow =  Math.floor(size.parentWidth / (rectWidth + spaceBetween));

        var side = 'right'
        var isNextNewLast = false;
        var createNewColOnRight = null;
        var createNewColOnLeft = null;
        var latestRightRect = null;
        var latestLeftRect = null;
        var colItemCounter = 1;
        var leftSideCounter = 0;
        var rightSideCounter = 0;
        var i;
        for (i = 1; i <= count; i++) {
            var firstRect = new DOMRect(size.parentWidth, size.parentHeight - 66, rectWidth, rectHeight)
            var prevRect = rects.length > 1 ? rects[rects.length - 2] : firstRect;
            var currentCol = isNextNewLast  ? perRow : Math.ceil(i/perCol);
            var isNextNewCol = colItemCounter  == perCol;
            isNextNewLast = isNextNewLast == true ? true : isNextNewCol && currentCol + 1 == perRow;

            var x, y, rect, prevRect;
            if(side == "right") {
                prevRect = latestRightRect;
                if (rightSideCounter > 0 && !createNewColOnRight) {
                    y = prevRect.y - (rectHeight + spaceBetween);
                    x = prevRect.x;
                } else if(createNewColOnRight) {
                    y = (size.parentHeight - 66) - (rectHeight + spaceBetween);
                    x = prevRect.x - (rectWidth + spaceBetween);
                    createNewColOnRight = false;
                } else {
                    y = (size.parentHeight - 66) - (rectHeight + spaceBetween);
                    x = size.parentWidth - (rectWidth + spaceBetween);
                }
                rightSideCounter++;

                rect = new DOMRect(x, y, rectWidth, rectHeight);
                latestRightRect = rect;

                side = 'left';

                if(rightSideCounter % perCol == 0) {
                    createNewColOnRight = true;
                }
            } else {
                prevRect = latestLeftRect;
                if (leftSideCounter > 0 && !createNewColOnLeft) {
                    y = prevRect.y - (rectHeight + spaceBetween);
                    x = prevRect.x;
                } else if(createNewColOnLeft) {
                    y = (size.parentHeight - 66) - (rectHeight + spaceBetween);
                    x = prevRect.x + prevRect.width + spaceBetween;
                    createNewColOnLeft = false;
                } else {
                    y = (size.parentHeight - 66) - (rectHeight + spaceBetween);
                    x = spaceBetween;
                }
                leftSideCounter++;

                rect = new DOMRect(x, y, rectWidth, rectHeight);
                latestLeftRect = rect;

                side = 'right';

                if(leftSideCounter % perCol == 0) {
                    createNewColOnLeft = true;
                }
            }

            rects.push(rect);

            if(isNextNewCol) {
                colItemCounter = 1;
            } else colItemCounter++;
        }

        return rects;
    }

    function getElementSizeKeepingRatio(initSize, baseSize) {
        var ratio = Math.min(baseSize.width / initSize.width, baseSize.height / initSize.height);

        var width = Math.floor(initSize.width*ratio);
        var height = Math.floor(initSize.height*ratio);

        return { width: width, height: height};
    }

    function tiledDesktopGrid(count, parentRect) {
        var rects = [];

        var aspectRatio, perRow;
        if (count == 1) {
            aspectRatio = {width:4, height:3};
            perRow = 1;
        } else if (count == 2){
            aspectRatio = {width:3, height:4};
            perRow = 2;
        } else if (count == 3){
            aspectRatio = {width:3, height:4};
            perRow = 3;
        } else if (count == 4){
            aspectRatio = {width:3, height:3};
            perRow = 2;
        } else if (count == 5){
            aspectRatio = {width:4, height:3};
            perRow = 3;
        } else {
            aspectRatio = {width:4, height:3};
            perRow = 4;
        }

        var centerX = parentRect.width / 2;
        var centerY = parentRect.height / 2;

        var spaceBetween = 10;
        var rectHeight;
        var rowsNum = null;
        if(rowsNum == null) {
            rowsNum = Math.ceil(count / perRow);
            rectHeight = (parentRect.height - spaceBetween * (rowsNum + 1)) / rowsNum;
        } else {
            rectHeight = (parentRect.height - spaceBetween * (rowsNum + 1)) / rowsNum;
        }

        var rectWidth = rectHeight / aspectRatio.height * aspectRatio.width;

        var widthLimit = (parentRect.width - spaceBetween * (perRow + 1)) / perRow;
        var heightLimit = (parentRect.height - spaceBetween * (rowsNum + 1)) / rowsNum;
        if(rectWidth > widthLimit || rectHeight > heightLimit) {
            var fittingSize = getElementSizeKeepingRatio({width: rectWidth, height:rectHeight}, {width: widthLimit, height:heightLimit})
            rectWidth = fittingSize.width;
            rectHeight = fittingSize.height;
        }

        var isNextNewLast = false;
        var rowItemCounter = 1;
        var i;
        for (i = 0; i < count; i++) {
            var prevRect = rects[rects.length - 1] ? rects[rects.length - 1] : null;
            var currentRow = isNextNewLast  ? rowsNum : Math.ceil((i + 1)/perRow);
            var isNextNewRow  = rowItemCounter == perRow;
            isNextNewLast = isNextNewLast == true ? true : isNextNewRow && currentRow + 1 == rowsNum;

            var x, y;
            if(rowItemCounter == 1) {
                if (i == 0) {
                    y = centerY - ((rectHeight * rowsNum + spaceBetween * (rowsNum - 1)) / 2);
                    x = centerX - (rectWidth * perRow + spaceBetween * (perRow - 1)) / 2;
                } else if(currentRow == rowsNum) {
                    y = prevRect.y + prevRect.height + spaceBetween;
                    x = centerX - (rectWidth * (count - i) + spaceBetween * ((count - i) - 1)) / 2;
                } else {
                    y = prevRect.y + prevRect.height + spaceBetween;
                    x = centerX - (rectWidth * perRow + spaceBetween * (perRow - 1)) / 2;
                }
            } else {
                y = prevRect.y;
                x = (prevRect.x + prevRect.width) + spaceBetween;
            }

            var rect = new DOMRect(x, y, rectWidth, rectHeight);

            rects.push(rect);

            if (isNextNewRow) {
                rowItemCounter = 1;
            } else rowItemCounter++;
        }

        return rects;
    }

    function simpleGrid(count, size, perRow, rowsNum) {
        var rects = [];
        var rectHeight;
        var rectWidth = size.parentWidth / perRow;
        if(rowsNum == null) {
            rectHeight = size.parentHeight / Math.ceil(count / perRow);
            rowsNum = Math.floor(size.parentHeight / rectHeight);
        } else {
            rectHeight = size.parentHeight / rowsNum;
        }


        var isNextNewLast = false;
        var rowItemCounter = 1;
        var i;
        for (i = 1; i <= count; i++) {
            var prevRect = rects[rects.length - 1] ? rects[rects.length - 1] : new DOMRect(0, 0, 0, 0) ;
            var currentRow = isNextNewLast  ? rowsNum : Math.ceil(i/perRow);
            var isNextNewRow  = rowItemCounter == perRow;
            isNextNewLast = isNextNewLast == true ? true : isNextNewRow && currentRow + 1 == rowsNum;

            if(rowItemCounter == 1) {
                var y = prevRect.height * (currentRow - 1);
                var x = 0;
            } else {
                var y = prevRect.y;
                var x = prevRect.x + prevRect.width;
            }

            var rect = new DOMRect(x, y, rectWidth, rectHeight);

            if (isNextNewRow && isNextNewLast) {
                perRow = count - i;
                rectWidth = size.parentWidth / perRow;

            }
            rects.push(rect);

            if (isNextNewRow) {
                rowItemCounter = 1;
            } else rowItemCounter++;
        }

        return rects;
    }

    function simpleGridBasedOnRowsNum(count, rowsNum, size) {
        var rects = []
        var perRow = Math.floor(count / rowsNum);

        var rectWidth = size.parentWidth / perRow;
        var rectHeight = size.parentHeight / rowsNum;
        var isNextNewLast   = false;
        var rowItemCounter = 1;
        var i;
        for (i = 1; i <= count; i++) {
            var prevRect = rects[rects.length - 1] ? rects[rects.length - 1] : new DOMRect(size.x, size.y, 0, 0) ;
            var currentRow = isNextNewLast  ? rowsNum : Math.ceil(i/perRow);
            var isNextNewRow  = rowItemCounter == perRow;
            isNextNewLast = isNextNewLast == true ? true : isNextNewRow && currentRow + 1 == rowsNum;

            if(rowItemCounter == 1) {
                var y = (prevRect.height) * (currentRow - 1);
                var x = 0;
            } else {
                var y = prevRect.y;
                var x = prevRect.x + prevRect.width;
            }
            var rect = new DOMRect(x, y, rectWidth, rectHeight);

            if(isNextNewRow && isNextNewLast) {
                perRow = count - i;
                rectWidth = size.parentWidth / perRow;
            }


            rects.push(rect);

            if(isNextNewRow) {
                rowItemCounter = 1;
            } else rowItemCounter++
        }

        return rects;
    }

})(Q, Q.jQuery);