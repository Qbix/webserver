(function ($, window, undefined) {
    /**
     * Q/resize tool.
     * Tool makes possible to resize any element on the page
     * @module Q
     * @class Q resize
     * @constructor
     * @param {Object} options
     *  Hash of possible options
     */
    Q.Tool.define("Q/resize", function(options) {

            var tool = this;

            tool.pointerInfo = {
                prevY: 0,
                prevX: 0,
            };

            this.initTool();

            Q.addStylesheet('{{Q}}/css/tools/resize.css');
        },

        {
            handles: {},
            active: false,
            resize: true,
            move: true,
            elementPosition: null,
            snapToSidesOnly: false,
            moveWithinArea: 'parent',
            negativeMoving: false,
            minimalSize: 100,
            allowOverresizing: false,
            resizeByWheel: true,
            ignoreOnElements: [],
            snapToRects: [],
            activateOnElement: null,
            keepRatio: true,
            keepRatioBasedOnElement: null,
            keepInitialSize: false,
            initialSize: {width:null, height:null},
            showResizeHandles: false,
            appliedRecently: false,
            onMoved: new Q.Event(),
            onResized: new Q.Event(),
            onResizing: new Q.Event(),
            onMovingStart: new Q.Event(),
            onMoving: new Q.Event(),
            onMovingStop: new Q.Event(),
            onUpdate: new Q.Event(),
            onRefresh: new Q.Event(),
            isMoving: false,
        },


        {
            unbindEvents: function () {

            },
            deactivate:function () {
                var tool = this;
                tool.state.active = false;

                var elementToResize = tool.element;
                var elementToMove = tool.state.elementToMove != null ? tool.state.elementToMove : tool.element;

                elementToMove.style.position = '';
                elementToMove.style.top = '';
                elementToMove.style.left = '';

                elementToResize.style.width = '';
                elementToResize.style.height = '';
            },
            getElementToMove: function() {
                var tool = this;
                if(tool.state.elementToMove != null) {
                    var el = tool.element;
                    while ((el = el.parentElement) && !el.classList.contains(tool.state.elementToMove)) ;

                    return el;
                } else return tool.element;

            },
            bindDraggingEvenets: function(){

            },
            snapTo: function(position) {
                var tool = this;

                var elementToMove = tool.state.elementToMove != null ? tool.state.elementToMove : tool.element;

                var moveWithinRect;

                if(typeof tool.state.moveWithinArea == 'object' && tool.state.moveWithinArea.constructor.name == 'DOMRect') {
                    moveWithinRect = tool.state.moveWithinArea;
                } else if(tool.state.moveWithinArea == 'parent') {
                    moveWithinRect = elementToMove.parentElement.getBoundingClientRect();
                } else if(tool.state.moveWithinArea == 'window' || tool.state.moveWithinArea == window) {
                    moveWithinRect = new DOMRect(0, 0, window.innerWidth, window.innerHeight);
                }

                var cHe = moveWithinRect.height;

                if(position == 'top') {
                    if(!elementToMove.classList.contains('Q_resize_snapped_top')) {
                        elementToMove.classList.add('Q_resize_snapped_top');
                        elementToMove.style.position = tool.state.elementPosition;
                        elementToMove.style.top = moveWithinRect.y + 'px';
                    }
                } else if(position == 'bottom') {
                    if(!elementToMove.classList.contains('Q_resize_snapped_bottom')) {
                        elementToMove.classList.add('Q_resize_snapped_bottom');
                        elementToMove.style.position = tool.state.elementPosition;
                        var elHeight = elementToMove.offsetHeight;
                        elementToMove.style.top = ((moveWithinRect.y + moveWithinRect.height) - elHeight) + 'px';
                    }
                } else if(position == 'left') {
                    if(!elementToMove.classList.contains('Q_resize_snapped_left')) {
                        elementToMove.classList.add('Q_resize_snapped_left');
                        elementToMove.style.position = tool.state.elementPosition;
                        elementToMove.style.left = moveWithinRect.x + 'px';
                        var elHeight = elementToMove.offsetHeight;
                        elementToMove.style.top = (cHe / 2) - (elHeight / 2) + 'px';
                    }
                } else if(position == 'right') {
                    if(!elementToMove.classList.contains('Q_resize_snapped_right')) {
                        elementToMove.classList.add('Q_resize_snapped_right');
                        elementToMove.style.position = tool.state.elementPosition;

                        var elHeight = elementToMove.offsetHeight;
                        var elWidth = elementToMove.offsetWidth;
                        elementToMove.style.left = ((moveWithinRect.x + moveWithinRect.width) - elWidth) + 'px';
                        elementToMove.style.top = (cHe / 2) - (elHeight / 2) + 'px';
                    }
                }

                tool.snappedTo = position;

            },
            getContainerRect: function () {
                var tool = this;
                var elementToMove = tool.state.elementToMove != null ? tool.state.elementToMove : tool.element;

                var moveWithinRect;
                if(typeof tool.state.moveWithinArea == 'object' && tool.state.moveWithinArea.constructor.name == 'DOMRect') {
                    moveWithinRect = tool.state.moveWithinArea;
                } else if(tool.state.moveWithinArea == 'parent') {
                    moveWithinRect = elementToMove.parentElement.getBoundingClientRect();
                } else if(tool.state.moveWithinArea == 'window' || tool.state.moveWithinArea == window) {
                    moveWithinRect = new DOMRect(0, 0, window.innerWidth, window.innerHeight);
                }

                return moveWithinRect;
            },
            setContainerRect: function (domRect) {
                var tool = this;
                var elementToMove = tool.state.elementToMove != null ? tool.state.elementToMove : tool.element;
                var elementWidth = parseInt(elementToMove.offsetWidth);
                var elementHeight = parseInt(elementToMove.offsetHeight);

                var moveWithinRect;
                tool.state.moveWithinArea = moveWithinRect = domRect;
                if(tool.state.snapToSidesOnly &&  tool.snappedTo != null){

                    if(tool.snappedTo == 'right') {
                        elementToMove.style.left = ((moveWithinRect.x + moveWithinRect.width) - elementWidth) + 'px';
                    } else if(tool.snappedTo == 'bottom') {
                        elementToMove.style.top = ((moveWithinRect.y + moveWithinRect.height) - elementHeight) + 'px';
                    } else if(tool.snappedTo == 'left') {
                        elementToMove.style.left = moveWithinRect.x + 'px';
                    } else if(tool.snappedTo == 'top') {
                        elementToMove.style.top = moveWithinRect.y + 'px';
                    }
                }
            },
            blockTextSelection: function () {
                var tool = this;
                if (!tool.selectionIsBlockDebouncer) {
                    document.body.classList.add('Q_resize_selection_is_blocked');
                    tool.selectionIsBlockDebouncer = Q.debounce(function () {
                        document.body.classList.remove('Q_resize_selection_is_blocked');
                        tool.selectionIsBlockDebouncer = null;
                    }, 500);

                }

                tool.selectionIsBlockDebouncer();
            },
            initTool: function () {
                var tool = this;
                tool.events = new EventSystem();

                tool.events.on('moving', function(e) {
                    tool.state.onMoving.handle.call(tool, e.x, e.y);
                })
                tool.events.on('movingstart', function(e) {
                    tool.state.onMovingStart.handle.call(tool);
                })
                tool.events.on('movingstop', function(e) {
                    tool.state.onMovingStop.handle.call(tool);
                })
                tool.events.on('moved', function(e) {
                    tool.state.onMoved.handle.call(tool);
                })
                tool.events.on('resizing', function(e) {
                    tool.state.onResizing.handle.call(tool, e.width, e.height, e.x, e.y, e.originalWidth);
                })
                tool.events.on('resized', function(e) {
                    tool.state.onResized.handle.call(tool);
                })

                var _elementToMove = tool.state.elementToMove != null ? tool.state.elementToMove : tool.element;;
                var _elementToResize = tool.element;
                var elementComputedStyle = window.getComputedStyle(_elementToResize);

                var ua = navigator.userAgent;
                var _isMobile = false;
                var _isiOS = false;
                var _isAndroid = false;
                var _isiOSCordova = false;
                var _isAndroidCordova = false;
                var _isTouchScreen = isTouchDevice();
                if (ua.indexOf('iPad') != -1 || ua.indexOf('iPhone') != -1 || ua.indexOf('iPod') != -1) _isiOS = true;
                if (ua.indexOf('Android') != -1) _isAndroid = true;
                if (ua.indexOf('Android') != -1 || ua.indexOf('iPhone') != -1) _isMobile = true;
                if (typeof cordova != 'undefined' && _isiOS) _isiOSCordova = true;
                if (typeof cordova != 'undefined' && _isAndroid) _isAndroidCordova = true;

                function capturePointer(e) {
                    if (e.type == 'touchstart' || e.type == 'mousedown') {
                        tool.pointerInfo.pointerIsPressed = true;
                        tool.pointerInfo.startX = tool.pointerInfo.prevX = _isTouchScreen && e.touches ? e.touches[0].clientX : e.clientX;
                        tool.pointerInfo.startY = tool.pointerInfo.prevY = _isTouchScreen && e.touches ? e.touches[0].clientY : e.clientY;
                        return;
                    }
            
                    if (e.type == 'touchmove' || e.type == 'mousemove') {
                        tool.pointerInfo.prevX = _isTouchScreen && e.touches ? e.changedTouches[0].clientX : e.clientX;
                        tool.pointerInfo.prevY = _isTouchScreen && e.touches ? e.changedTouches[0].clientY : e.clientY;
                        return;
                    }
            
                    if (e.type == 'touchend' || e.type == 'mouseup') {
                        tool.pointerInfo.pointerIsPressed = false;
                        tool.pointerInfo.endX = _isTouchScreen && e.touches ? e.changedTouches[0].clientX : e.clientX;
                        tool.pointerInfo.endY = _isTouchScreen && e.touches ? e.changedTouches[0].clientY : e.clientY;
                        return;
                    }
            
                }
            
                window.addEventListener(_isTouchScreen ? 'touchstart' : 'mousedown', function (e) {
                    capturePointer(e);
                    window.addEventListener(_isTouchScreen ? 'touchmove' : 'mousemove', capturePointer);
                    window.addEventListener(_isTouchScreen ? 'touchend' : 'mouseup', function (e) {
                        capturePointer(e);
                        window.removeEventListener(_isTouchScreen ? 'touchmove' : 'mousemove', capturePointer);
                    });
                }, { capture: true });

                var _dragElementTool = (function () {
                    var posX, posY, divTop, divLeft, eWi, eHe, cWi, cHe, maxX, maxY, diffX, diffY, snappedTo;
                    var moveWithinRect;
            
                    function move(xpos, ypos) {
                        var currentTop = parseInt(_elementToMove.style.top, 10)
                        var currentLeft = parseInt(_elementToMove.style.left, 10)

                        if(tool.state.snapToSidesOnly){
                            if(snappedTo == 'right') {
                                _elementToMove.style.top = ypos + 'px';
                                _elementToMove.style.left = ((moveWithinRect.x + moveWithinRect.width) - eWi) + 'px';
                                _elementToMove.style.left = ((moveWithinRect.x + moveWithinRect.width) - eWi) + 'px';
                            } else if(snappedTo == 'bottom') {
                                _elementToMove.style.top = ((moveWithinRect.y + moveWithinRect.height) - eHe) + 'px';
                                _elementToMove.style.left = xpos + 'px';
                            } else if(snappedTo == 'left') {
                                _elementToMove.style.top = ypos + 'px';
                                _elementToMove.style.left = moveWithinRect.x + 'px';
                            } else if(snappedTo == 'top') {
                                _elementToMove.style.left = xpos + 'px';
                                _elementToMove.style.top = moveWithinRect.y + 'px';
                            }
                        } else {
                            _elementToMove.style.left = xpos + 'px';
                            _elementToMove.style.top = ypos + 'px';
                        }
            
                        if (currentTop != parseInt(ypos, 10) || currentLeft != parseInt(xpos, 10)) tool.state.appliedRecently = true;
                        tool.events.dispatch('moving', { x: xpos, y: ypos });
            
                    }
            
                    function drag(evt) {
                        tool.blockTextSelection();
                        if (tool.state.isResizing || (_isTouchScreen && (tool.state.isResizing || evt.touches.length != 1 || evt.changedTouches.length != 1 || evt.targetTouches.length != 1))) return;
                        evt = evt || window.event;
                        evt.preventDefault();
                        //evt.stopPropagation();
            
                        posX = _isTouchScreen ? evt.changedTouches[0].clientX : evt.clientX;
                        posY = _isTouchScreen ? evt.changedTouches[0].clientY : evt.clientY;

                        var aX, aY;
            
                        if(tool.state.snapToSidesOnly){
                            snapToSides();
                        }

                        aX = posX - diffX;
                        aY = posY - diffY;
            
                        if (tool.state.negativeMoving == false) {
                            if (aX < 0) aX = 0;
                            if (aY < 0) aY = 0;
                            if (aX + eWi > cWi) aX = cWi - eWi;
                            if (aY + eHe > cHe) aY = cHe - eHe;
                        }
            
                        //snap to sides
                        if (tool.state.snapping !== false) {
                            if (aX < 10 && aX > -10) {
                                aX = 0;
                            }
                            let rightSidePos = aX + eWi;
                            if (rightSidePos > (cWi - 10) && rightSidePos < cWi + 10) {
                                aX = cWi - eWi;
                            }
                            if (aY < 10 && aY > -10) {
                                aY = 0;
                            }
                            let bottomSidePos = aY + eHe;
                            if (bottomSidePos > (cHe - 10) && bottomSidePos < cHe + 10) {
                                aY = cHe - eHe;
                            }

                            //snap to other elements
                            if (tool.state.snapToRects.length != 0) {
                                var closestToLeftBorder = tool.state.snapToRects.reduce(function (prev, curr) {
                                    return (Math.abs(curr.right - aX) < Math.abs(prev.right - aX) ? curr : prev);
                                });
                                var closestToRightBorder = tool.state.snapToRects.reduce(function (prev, curr) {
                                    return (Math.abs(curr.left - rightSidePos) < Math.abs(prev.left - rightSidePos) ? curr : prev);
                                });
                                var closestToTopBorder = tool.state.snapToRects.reduce(function (prev, curr) {
                                    return (Math.abs(curr.bottom - aY) < Math.abs(prev.bottom - aY) ? curr : prev);
                                });
                                var closestToBottomBorder = tool.state.snapToRects.reduce(function (prev, curr) {
                                    return (Math.abs(curr.top - bottomSidePos) < Math.abs(prev.top - bottomSidePos) ? curr : prev);
                                });

                                if (closestToLeftBorder && aX <= closestToLeftBorder.right + 10 && aX >= closestToLeftBorder.right - 10) {
                                    aX = closestToLeftBorder.right
                                }
                                if (closestToRightBorder && rightSidePos <= closestToRightBorder.left + 10 && rightSidePos >= closestToRightBorder.left - 10) {
                                    aX = closestToRightBorder.left - eWi;
                                }
                                if (closestToTopBorder && aY <= closestToTopBorder.bottom + 10 && aY >= closestToTopBorder.bottom - 10) {
                                    aY = closestToTopBorder.bottom;
                                }
                                if (closestToBottomBorder && bottomSidePos <= closestToBottomBorder.top + 10 && bottomSidePos >= closestToBottomBorder.top - 10) {
                                    aY = closestToBottomBorder.top - eHe;
                                }
                            }
                        }
            
                        move(aX, aY);
                    }

                    var snapToSides = function () {
                        var toggleClass = function (className) {
                            var classesArr = ['Q_resize_snapped_left', 'Q_resize_snapped_top', 'Q_resize_snapped_right', 'Q_resize_snapped_bottom'];
                            for (var c in classesArr) {
                                if(classesArr[c] != className && _elementToMove.classList.contains(classesArr[c])) _elementToMove.classList.remove(classesArr[c]);
                            }

                            if(className == 'Q_resize_snapped_bottom') {
                                _elementToMove.style.top = '';
                                _elementToMove.style.bottom = '';
                            } else if(className == 'Q_resize_snapped_right') {
                                _elementToMove.style.left = '';
                                _elementToMove.style.right = '';
                            } else if(className == 'Q_resize_snapped_top') {
                                _elementToMove.style.bottom = '';
                                _elementToMove.style.top = '';
                            } else if(className == 'Q_resize_snapped_left') {
                                _elementToMove.style.right = '';
                                _elementToMove.style.left = '';
                            }

                            _elementToMove.style.height = '';
                            _elementToMove.style.width = '';

                            _elementToMove.classList.add(className);
                            diffY = 0;

                            eWi = parseInt(_elementToMove.offsetWidth);
                            eHe = parseInt(_elementToMove.offsetHeight);

                        }

                        if(((maxX - posX) < (maxY - posY)) && ((maxX - posX) < posY) && ((maxX - posX) < posX)) {

                            if(snappedTo != 'right') {
                                toggleClass('Q_resize_snapped_right');
                            }
                            snappedTo = 'right';

                        } else if(((maxY - posY) < (maxX - posX)) && ((maxY - posY) < posY) && ((maxY - posY) < posX)) {
                            if(snappedTo != 'bottom') {
                                toggleClass('Q_resize_snapped_bottom');
                            }
                            snappedTo = 'bottom';

                        } else if((posX < (maxX - posX)) && (posX < posY) && (posX < (maxY - posY))) {

                            if(snappedTo != 'left') {
                                toggleClass('Q_resize_snapped_left');
                            }
                            snappedTo = 'left';

                        } else if((posY < (maxX - posX)) && (posY < posX) && (posY < (maxY - posY))) {

                            if(snappedTo != 'top') {
                                toggleClass('Q_resize_snapped_top');
                            }
                            snappedTo = 'top';

                        }

                        tool.snappedTo = snappedTo;
                    }
            
                    function initMoving(evt) {
                        console.log('initMoving START', _elementToMove);
                        if (tool.state.ignoreOnElements.length != 0) {
                            var ignoreEls = tool.state.ignoreOnElements;
                            for (var e in ignoreEls) {
                                if ((/*(evt.type != "mousemove" && evt.type != "touchmove") && */(ignoreEls[e] && (evt.target == ignoreEls[e] || ignoreEls[e].contains(evt.target))))
                                    || (evt.target.nodeName == 'INPUT' && evt.target.type == 'text')) {
                                    return;
                                }
                            }
                        }

                        if (!tool.state.active || evt.button == 1 || evt.button == 2) return;

                        if (tool.state.isResizing || (_isTouchScreen && (tool.state.isResizing || evt.targetTouches.length != 1))) return;

                        tool.state.isMoving = true;
            
                        var elRect = _elementToMove.getBoundingClientRect();
                        if (!tool.state.keepInitialSize) {
                            _elementToMove.style.width = elRect.width + 'px';
                            _elementToMove.style.height = elRect.height + 'px';
                        }
                        if (tool.state.keepInitialSize) {
                            tool.state.initialSize = {
                                width: _elementToMove.style.width,
                                height: _elementToMove.style.height
                            }
                        }
                        var elementPosition = tool.state.elementPosition ? tool.state.elementPosition : 'absolute';
            
                        elementPosition = elementPosition != '' && elementPosition != null ? elementPosition : elementComputedStyle.position;
                        if (elementPosition == 'fixed') {
                            _elementToMove.style.top = elRect.top + 'px';
                            _elementToMove.style.left = elRect.left + 'px';
                        } else if (elementPosition == 'absolute' || elementPosition == 'relative' || elementPosition == 'static') {
                            _elementToMove.style.top = _elementToMove.offsetTop + 'px';
                            _elementToMove.style.left = _elementToMove.offsetLeft + 'px';
                        }
            
                        _elementToMove.style.transform = '';
                        _elementToMove.style.position = tool.state.elementPosition ? tool.state.elementPosition : 'absolute';
                        _elementToMove.style.cursor = 'grabbing';
                        if (tool.state.showShadow) tool.state.element.style.boxShadow = '10px -10px 60px 0 rgba(0,0,0,0.5)';
            
                        evt = evt || window.event;
            
                        moveWithinRect = getContainerRect();
            
                        posX = _isTouchScreen ? evt.touches[0].clientX : evt.clientX,
                            posY = _isTouchScreen ? evt.touches[0].clientY : evt.clientY,
                            divTop = _elementToMove.offsetTop,
                            divLeft = _elementToMove.offsetLeft,
                            eWi = parseInt(_elementToMove.offsetWidth),
                            eHe = parseInt(_elementToMove.offsetHeight),
                            cWi = moveWithinRect.width,
                            cHe = moveWithinRect.height;
                        maxX = moveWithinRect.x + moveWithinRect.width;
                        maxY = moveWithinRect.y + moveWithinRect.height;
                        diffX = posX - divLeft, diffY = posY - divTop;
            
                        tool.events.dispatch('movingstart');
            
                        if (_isTouchScreen) {
                            window.addEventListener('touchmove', drag, { passive: false });
                        } else {
                            window.addEventListener('mousemove', drag, { passive: false });
                        }
                    }
            
                    function stopMoving(e) {
            
                        if (_isTouchScreen) {
                            window.removeEventListener('touchmove', drag, { passive: false });
                        } else window.removeEventListener('mousemove', drag, { passive: false });
            
                        if (_elementToMove != null) _elementToMove.style.cursor = '';
            
                        if (tool.state.showShadow) _elementToMove.style.boxShadow = '';
            
            
                        tool.events.dispatch('movingstop');
                        tool.state.isMoving = false;
            
                        if (tool.state.keepInitialSize) {
                            if (tool.state.initialSize.width != null) _elementToMove.style.width = tool.state.initialSize.width;
                            if (tool.state.initialSize.height != null) _elementToMove.style.height = tool.state.initialSize.height;
                        }
            
                        if (tool.state.appliedRecently) {
                            tool.events.dispatch('moved');
            
                            setTimeout(function () {
                                tool.state.appliedRecently = false;
                            }, 200)
                        }
                    }
            
                    function getContainerRect() {
                        var moveWithinRect;
                        if (typeof tool.state.moveWithinArea == 'object' && tool.state.moveWithinArea.constructor.name == 'DOMRect') {
                            moveWithinRect = tool.state.moveWithinArea;
                        } else if (tool.state.moveWithinArea == 'parent') {
                            moveWithinRect = _elementToMove.parentElement.getBoundingClientRect();
                        } else if (tool.state.moveWithinArea == 'window' || tool.state.moveWithinArea == window) {
                            moveWithinRect = new DOMRect(0, 0, window.innerWidth, window.innerHeight);
                        }
            
                        return moveWithinRect;
                    }
            
                    function setContainerRect(domRect) {
                        tool.state.moveWithinArea = moveWithinRect = domRect;
                    }
            
                    function initDragTool() {
                        var activateOnElement = tool.state.activateOnElement != null ? tool.state.activateOnElement : _elementToMove;
                        
                        function prepareMoving(event) {
                            event.stopImmediatePropagation();
                            event.stopPropagation();
                            var checkIfShouldInitMoving = function (e) {
                                 console.log('initMoving 1', !tool.state.isMoving, tool.pointerInfo.pointerIsPressed, distance(tool.pointerInfo.startX, tool.pointerInfo.startY, tool.pointerInfo.prevX, tool.pointerInfo.prevY))
                                if (!tool.state.isMoving && tool.pointerInfo.pointerIsPressed && distance(tool.pointerInfo.startX, tool.pointerInfo.startY, tool.pointerInfo.prevX, tool.pointerInfo.prevY) >= (tool.state.dragThreeshold != null ? tool.state.dragThreeshold : 10)) {
                                    console.log('initMoving 2')
                                    initMoving(e);
                                }
                            }
                            var removeCheckIfShouldInitMovingListener = function () {
                                activateOnElement.removeEventListener(_isTouchScreen ? 'touchmove' : 'mousemove', checkIfShouldInitMoving, false);
                                window.removeEventListener(_isTouchScreen ? 'touchend' : 'mouseup', removeCheckIfShouldInitMovingListener, true);
                            }

                            activateOnElement.addEventListener(_isTouchScreen ? 'touchmove' : 'mousemove', checkIfShouldInitMoving, false);
                            window.addEventListener(_isTouchScreen ? 'touchend' : 'mouseup', removeCheckIfShouldInitMovingListener, true);
                            
                        }
                        
                        activateOnElement.addEventListener(_isTouchScreen ? 'touchstart' : 'mousedown', prepareMoving)

                        window.addEventListener(_isTouchScreen ? 'touchend' : 'mouseup', stopMoving, true);
                    }
            
                    return {
                        initMoving: initMoving,
                        stopMoving: stopMoving,
                        initDragTool: initDragTool
                    }
                }())
            
            
                var _resizeElementTool = (function (e) {
                    var docRect = document.body.getBoundingClientRect();
                    var docStyles = window.getComputedStyle(document.body);
            
                    var _handle;
                    var _handlePosition;
                    var _centerPosition;
                    var _centerPositionFromTop;
                    var _elementPosition;
                    var _latestWidthValue;
                    var _latestHeightValue;
                    var _latestScaleValue;
                    var _ratio;
                    var _resetInitPosTimeout
                    var _currentActiveTouches = 0;
            
                    var resizeBasedOn = 'width' // width || height;
                    var originalWidth = 0;
                    var originalHeight = 0;
                    var originalX = 0;
                    var originalY = 0;
                    var originalRect = 0;
                    var originalMouseX = 0;
                    var originalMouseY = 0;
                    var containerRect;
                    function startResizingByHendle(e) {
                        if (!tool.state.active || e.button == 1 || e.button == 2) return;
                        e.propertyIsEnumerable();
                        //e.stopPropagation();
                        _handlePosition = e.target.dataset.position;
                        if (_handlePosition == null) _handlePosition = 'bottomright';
                        _elementToResize = e.target.parentNode;
                        _elementPosition = _elementToResize.style.position;
                        originalRect = _elementToResize.getBoundingClientRect();
            
                        if (_elementPosition == 'fixed') {
                            _centerPosition = originalRect.left + originalRect.width / 2;
                        } else if (_elementPosition == 'absolute') {
                            _centerPosition = _elementToResize.offsetLeft + originalRect.width / 2;
                        }
            
                        if (_elementPosition == 'fixed') {
                            _centerPositionFromTop = originalRect.top + originalRect.height / 2;
                        } else if (_elementPosition == 'absolute') {
                            _centerPositionFromTop = _elementToResize.offsetTop + originalRect.height / 2;
                        }
            
                        if (tool.state.initialSize.width == null || tool.state.initialSize.height == null) {
                            tool.state.initialSize = {
                                width: originalRect.width,
                                height: originalRect.height
                            };
                        }
                        
                        _handle = e.target;
            
                        originalWidth = parseFloat(getComputedStyle(_elementToResize, null).getPropertyValue('width').replace('px', ''));
                        originalHeight = parseFloat(getComputedStyle(_elementToResize, null).getPropertyValue('height').replace('px', ''));
                        originalX = tool.state.moveWithinArea == 'parent' ? _elementToResize.offsetLeft : _elementToResize.getBoundingClientRect().left;
                        originalY = tool.state.moveWithinArea == 'parent' ? _elementToResize.offsetTop : _elementToResize.getBoundingClientRect().top;
                        containerRect = tool.state.moveWithinArea == 'parent' ? _elementToResize.parentElement.getBoundingClientRect() : new DOMRect(0, 0, window.innerWidth, window.innerHeight);
                        originalMouseX = e.clientX;
                        originalMouseY = e.clientY;
            
                        tool.state.isResizing = true;
                        window.addEventListener('mousemove', startResizing);
                        window.addEventListener('mouseup', stopResizing);
                    }
            
                    function keepRatio(width, height, priorityParam) {
                        //var width, height;
                        if (tool.state.keepRatioBasedOnElement != null) {
                            var baseEl = tool.state.keepRatioBasedOnElement;
                            var srcWidth, srcHeight, ratio
                            if (typeof baseEl == 'object' && !(baseEl instanceof HTMLVideoElement)) {
                                srcWidth = baseEl.width;
                                srcHeight = baseEl.height;
                                ratio = srcWidth / srcHeight;
                            } else if (baseEl instanceof HTMLVideoElement) {
                                srcWidth = baseEl.videoWidth;
                                srcHeight = baseEl.videoHeight;
                                ratio = srcWidth / srcHeight;
                            }
            
                            if ((ratio < 1 || priorityParam == 'height') && priorityParam != 'width') {
            
                                width = (height * ratio);
                            } else {
                                height = (width / ratio);
                            }
                        } else {
                            if ((ratio < 1 || priorityParam == 'height') && priorityParam != 'width') {
                                width = (height * _ratio);
                            } else {
                                height = (width / _ratio);
                            }
                        }
            
                        return { width: width, height: height };
                    }

                    function sizeIsCorrect(width, height) {
                        if((width != null && width < tool.state.minimalSize) || (height != null && height < tool.state.minimalSize)){
                            return false;
                        }
                        return true;
                    }
            
                    function startResizing(e) {
                        //if (e.clientX >= docRect.right - (docStyles.paddingRight ? docStyles.paddingRight : '0').replace('px', '')) return;
                        tool.blockTextSelection();
                        e.preventDefault();
                        e.stopPropagation();
                        var elementRect = _elementToResize.getBoundingClientRect();
                        if (_ratio == null) _ratio = elementRect.width / elementRect.height;
            
                        let width, height;
                        if (!e.altKey && !e.shiftKey && tool.state.keepRatio) {
                            if (_handlePosition == 'topleft') {
                                let distToRightBorder = parseFloat(distance(elementRect.x, elementRect.y + (elementRect.height / 2), e.clientX, e.clientY).toFixed(0));
                                let distToTopBorder = parseFloat(distance(elementRect.x + (elementRect.width / 2), elementRect.y, e.clientX, e.clientY).toFixed(0));
                                
                                if(distToRightBorder - (elementRect.height / 2) < distToTopBorder - (elementRect.width / 2)) {
                                    resizeBasedOn = 'width';
                                } else if (distToRightBorder - (elementRect.height / 2) > distToTopBorder - (elementRect.width / 2)) {
                                    resizeBasedOn = 'height';
                                } else {
                                    resizeBasedOn = resizeBasedOn;
                                }
                                width = originalWidth - (e.clientX - originalMouseX)
                                height = originalHeight - (e.clientY - originalMouseY)
            
                                let snapToLeft = elementRect.left - containerRect.left < 10 && e.clientX - containerRect.left < 10 && e.clientX - containerRect.left > -10;
                                if (snapToLeft) {
                                    width = elementRect.width + (elementRect.x - containerRect.x);
                                    height = elementRect.height + (elementRect.x - containerRect.x);
                                }
            
                                let snapToTop = elementRect.top - containerRect.top < 10 && e.clientY - containerRect.top < 10 && e.clientY - containerRect.top > -10;
                                if (snapToTop) {
                                    width = elementRect.width + (elementRect.top - containerRect.top);
                                    height = elementRect.height + (elementRect.top - containerRect.top);
                                }
            
                                let newSize = keepRatio(width, height, resizeBasedOn);
                                width = newSize.width;
                                height = newSize.height;
            
                                let preLeftPos = originalX - (width - originalWidth);
                                let leftPos = preLeftPos < originalX + originalWidth ? preLeftPos : originalX + originalWidth;
            
                                if(!sizeIsCorrect(width, height)){
                                    return;
                                }

                                _elementToResize.style.width = width + 'px'
                                _elementToResize.style.left = leftPos + 'px'
            
            
                                let preTopPos = originalY - (height - originalHeight);
                                let topPos = preTopPos < originalY + originalHeight ? preTopPos : originalY + originalHeight;
            
                                _elementToResize.style.height = height + 'px'
                                _elementToResize.style.top = topPos + 'px'
            
                                tool.events.dispatch('resizing', { width: width, height: height, x: leftPos, y: topPos, originalWidth: originalWidth });
                            } else if (_handlePosition == 'topright') {
                                let distToLeftBorder = parseFloat(distance(elementRect.x + elementRect.width, elementRect.y + (elementRect.height / 2), e.clientX, e.clientY).toFixed(0));
                                let distToTopBorder = parseFloat(distance(elementRect.x + (elementRect.width / 2), elementRect.y, e.clientX, e.clientY).toFixed(0));
                                
                                if(distToLeftBorder - (elementRect.height / 2) < distToTopBorder - (elementRect.width / 2)) {
                                    resizeBasedOn = 'width';
                                } else if (distToLeftBorder - (elementRect.height / 2) > distToTopBorder - (elementRect.width / 2)) {
                                    resizeBasedOn = 'height';
                                } else {
                                    resizeBasedOn = resizeBasedOn;
                                }
                                width = originalWidth + (e.clientX - originalMouseX)
                                height = originalHeight - (e.clientY - originalMouseY)
            
                                let snapToTop = elementRect.top - containerRect.top < 10 && e.clientY - containerRect.top < 10 && e.clientY - containerRect.top > -10;
                                if (snapToTop) {
                                    width = elementRect.width + (elementRect.top - containerRect.top);
                                    height = elementRect.height + (elementRect.top - containerRect.top);
                                }
            
                                if (containerRect.right - elementRect.right < 10 && containerRect.right - e.clientX < 10 && containerRect.right - e.clientX > -10) {
                                    width = elementRect.width + (containerRect.right - elementRect.right);
                                    height = elementRect.height + (containerRect.right - elementRect.right)
                                }
            
                                let newSize = keepRatio(width, height, resizeBasedOn);
                                width = newSize.width;
                                height = newSize.height;

                                if(!sizeIsCorrect(width, height)){
                                    return;
                                }
            
                                _elementToResize.style.width = width + 'px'
            
                                let topPos = originalY + originalHeight - height;
            
                                _elementToResize.style.height = height + 'px'
                                _elementToResize.style.top = topPos + 'px'
            
                                tool.events.dispatch('resizing', { width: width, height: height, x: null, y: topPos, originalWidth: originalWidth });
                            } else if (_handlePosition == 'bottomright') {
                                let distToLeftBorder = parseFloat(distance(elementRect.x + elementRect.width, elementRect.y + (elementRect.height / 2), e.clientX, e.clientY).toFixed(0));
                                let distToBottomBorder = parseFloat(distance(elementRect.x + (elementRect.width / 2), elementRect.y + elementRect.height, e.clientX, e.clientY).toFixed(0));
                                
                                if(distToLeftBorder - (elementRect.height / 2) < distToBottomBorder - (elementRect.width / 2)) {
                                    resizeBasedOn = 'width';
                                } else if (distToLeftBorder - (elementRect.height / 2) > distToBottomBorder - (elementRect.width / 2)) {
                                    resizeBasedOn = 'height';
                                } else {
                                    resizeBasedOn = resizeBasedOn;
                                }
                                width = originalWidth + (e.clientX - originalMouseX);
                                height = originalHeight + (e.clientY - originalMouseY);
            
                                if (containerRect.bottom - elementRect.bottom < 10 && containerRect.bottom - e.clientY < 10 && containerRect.bottom - e.clientY > -10) {
                                    width = elementRect.width + (containerRect.bottom - elementRect.bottom);
                                    height = elementRect.height + (containerRect.bottom - elementRect.bottom)
                                }
            
                                if (containerRect.right - elementRect.right < 10 && containerRect.right - e.clientX < 10 && containerRect.right - e.clientX > -10) {
                                    width = elementRect.width + (containerRect.right - elementRect.right);
                                    height = elementRect.height + (containerRect.right - elementRect.right)
                                }
            
                                let newSize = keepRatio(width, height, resizeBasedOn);
                                width = newSize.width;
                                height = newSize.height;
                                if(!sizeIsCorrect(width, height)){
                                    return;
                                }
            
                                _elementToResize.style.width = width + 'px';
                                _elementToResize.style.height = height + 'px';
            
                                tool.events.dispatch('resizing', { width: width, height: height, originalWidth: originalWidth });
            
                            } else if (_handlePosition == 'middleright' || _handlePosition == 'middlebottom') {
                                width = originalWidth + (e.clientX - originalMouseX);
                                height = originalHeight + (e.clientY - originalMouseY);
            
                                if (containerRect.bottom - elementRect.bottom < 10 && containerRect.bottom - e.clientY < 10 && containerRect.bottom - e.clientY > -10) {
                                    width = elementRect.width + (containerRect.bottom - elementRect.bottom);
                                    height = elementRect.height + (containerRect.bottom - elementRect.bottom)
                                }
            
                                if (containerRect.right - elementRect.right < 10 && containerRect.right - e.clientX < 10 && containerRect.right - e.clientX > -10) {
                                    width = elementRect.width + (containerRect.right - elementRect.right);
                                    height = elementRect.height + (containerRect.right - elementRect.right)
                                }
            
                                let newSize = _handlePosition == 'middleright' ? keepRatio(width, height, 'width') : keepRatio(width, height, 'height');
                                width = newSize.width;
                                height = newSize.height;

                                if(!sizeIsCorrect(width, height)){
                                    return;
                                }
            
                                _elementToResize.style.width = width + 'px';
                                _elementToResize.style.height = height + 'px';
            
                                tool.events.dispatch('resizing', { width: width, height: height, originalWidth: originalWidth });
            
                            } else if (_handlePosition == 'middletop') {
                                width = originalWidth - (e.clientX - originalMouseX)
                                height = originalHeight - (e.clientY - originalMouseY)
            
                                let snapToLeft = elementRect.left - containerRect.left < 10 && e.clientX - containerRect.left < 10 && e.clientX - containerRect.left > -10;
                                if (snapToLeft) {
                                    width = elementRect.width + (elementRect.x - containerRect.x);
                                    height = elementRect.height + (elementRect.x - containerRect.x);
                                }
            
                                let snapToTop = elementRect.top - containerRect.top < 10 && e.clientY - containerRect.top < 10 && e.clientY - containerRect.top > -10;
                                if (snapToTop) {
                                    width = elementRect.width + (elementRect.top - containerRect.top);
                                    height = elementRect.height + (elementRect.top - containerRect.top);
                                }
            
                                let newSize = keepRatio(height, height, 'height');
                                width = newSize.width;
                                height = newSize.height;
            
                                let preLeftPos = originalX - (width - originalWidth);
                                let leftPos = preLeftPos < originalX + originalWidth ? preLeftPos : originalX + originalWidth;

                                if(!sizeIsCorrect(width, height)){
                                    return;
                                }
            
                                _elementToResize.style.width = width + 'px'
                                _elementToResize.style.left = leftPos + 'px'
            
            
                                let preTopPos = originalY - (height - originalHeight);
                                let topPos = preTopPos < originalY + originalHeight ? preTopPos : originalY + originalHeight;
            
                                _elementToResize.style.height = height + 'px'
                                _elementToResize.style.top = topPos + 'px'
            
                                tool.events.dispatch('resizing', { width: width, height: height, x: leftPos, y: topPos, originalWidth: originalWidth });
                            } else if (_handlePosition == 'bottomleft') {
                                let distToRightBorder = parseFloat(distance(elementRect.x, elementRect.y + (elementRect.height / 2), e.clientX, e.clientY).toFixed(0));
                                let distToBottomBorder = parseFloat(distance(elementRect.x + (elementRect.width / 2), elementRect.y + elementRect.height, e.clientX, e.clientY).toFixed(0));
                                
                                if(distToRightBorder - (elementRect.height / 2) < distToBottomBorder - (elementRect.width / 2)) {
                                    resizeBasedOn = 'width';
                                } else if (distToRightBorder - (elementRect.height / 2) > distToBottomBorder - (elementRect.width / 2)) {
                                    resizeBasedOn = 'height';
                                } else {
                                    resizeBasedOn = resizeBasedOn;
                                }
            
                                height = originalHeight + (e.clientY - originalMouseY)
                                width = originalWidth - (e.clientX - originalMouseX)
            
                                let snapToBottom = containerRect.bottom - elementRect.bottom < 10 && containerRect.bottom - e.clientY < 10 && containerRect.bottom - e.clientY > -10;
                                if (snapToBottom) {
                                    width = elementRect.width + (containerRect.bottom - elementRect.bottom);
                                    height = elementRect.height + (containerRect.bottom - elementRect.bottom)
                                }
            
                                let snapToLeft = elementRect.left - containerRect.left < 10 && e.clientX - containerRect.left < 10 && e.clientX - containerRect.left > -10;
                                if (snapToLeft) {
                                    width = elementRect.width + (elementRect.x - containerRect.x);
                                    height = elementRect.height + (elementRect.x - containerRect.x);
                                }
            
                                let newSize = keepRatio(width, height, resizeBasedOn);
                                width = newSize.width;
                                height = newSize.height;

                                if(!sizeIsCorrect(width, height)){
                                    return;
                                }
            
                                _elementToResize.style.height = height + 'px';
                                let preLeftPos = originalX - (width - originalWidth);
                                let leftPos = preLeftPos < originalX + originalWidth ? preLeftPos : originalX + originalWidth;
            
                                _elementToResize.style.width = width + 'px'
                                _elementToResize.style.left = leftPos + 'px'
            
                                tool.events.dispatch('resizing', { width: width, height: height, x: leftPos, originalWidth: originalWidth });
            
                            } else if (_handlePosition == 'middleleft') {
                                height = originalHeight + (e.clientY - originalMouseY)
                                width = originalWidth - (e.clientX - originalMouseX)
            
                                let snapToBottom = containerRect.bottom - elementRect.bottom < 10 && containerRect.bottom - e.clientY < 10 && containerRect.bottom - e.clientY > -10;
                                if (snapToBottom) {
                                    width = elementRect.width + (containerRect.bottom - elementRect.bottom);
                                    height = elementRect.height + (containerRect.bottom - elementRect.bottom)
                                }
            
                                let snapToLeft = elementRect.left - containerRect.left < 10 && e.clientX - containerRect.left < 10 && e.clientX - containerRect.left > -10;
                                if (snapToLeft) {
                                    width = elementRect.width + (elementRect.x - containerRect.x);
                                    height = elementRect.height + (elementRect.x - containerRect.x);
                                }
            
                                let newSize = keepRatio(width, height);
                                width = newSize.width;
                                height = newSize.height;

                                if(!sizeIsCorrect(width, height)){
                                    return;
                                }
            
                                _elementToResize.style.height = height + 'px';
                                let preLeftPos = originalX - (width - originalWidth);
                                let leftPos = preLeftPos < originalX + originalWidth ? preLeftPos : originalX + originalWidth;
            
                                _elementToResize.style.width = width + 'px'
                                _elementToResize.style.left = leftPos + 'px'
            
                                tool.events.dispatch('resizing', { width: width, height: height, x: leftPos, originalWidth: originalWidth });
                            }
            
                        } else {
                            if (_handlePosition == 'middleright') {
                                width = originalWidth + (e.clientX - originalMouseX);
            
                                let snappedToRight = false;
                                /*if (containerRect.bottom - elementRect.bottom < 10 && containerRect.bottom - e.clientY < 10 && containerRect.bottom - e.clientY > -10) {
                                    width = elementRect.width + (containerRect.bottom - elementRect.bottom);
                                    snappedToBottom = true;
                                }*/
            
                                if (containerRect.right - elementRect.right < 10 && containerRect.right - e.clientX < 10 && containerRect.right - e.clientX > -10) {
                                    width = elementRect.width + (containerRect.right - elementRect.right);
                                    snappedToRight = true;
                                }

                                let valid = sizeIsCorrect(width, null);

                                if(!valid){
                                    width = tool.state.minimalSize;
                                }
                                let rightBoundX = originalRect.left + width;
                                if(rightBoundX > containerRect.right) {
                                    console.log('containerRect rightBoundX', originalX, containerRect, rightBoundX)
                                    width -= rightBoundX - containerRect.right;
                                }
                                _elementToResize.style.width = width + 'px';
            
                                tool.events.dispatch('resizing', {
                                    handlePosition: _handlePosition,
                                    width: width,
                                    height: height,
                                    x: null,
                                    clientX: e.clientX,
                                    originalX: originalMouseX,
                                    originalWidth: originalWidth,
                                    altKey: e.altKey,
                                    shiftKey: e.shiftKey
                                });
            
                            } else if (_handlePosition == 'middleleft') {
                                height = originalHeight + (e.clientY - originalMouseY);
                                width = originalWidth - (e.clientX - originalMouseX);
            
                                let snapToBottom = containerRect.bottom - elementRect.bottom < 10 && containerRect.bottom - e.clientY < 10 && containerRect.bottom - e.clientY > -10;
                                if (snapToBottom) {
                                    width = elementRect.width + (containerRect.bottom - elementRect.bottom);
                                }
            
                                let snapToLeft = elementRect.left - containerRect.left < 10 && e.clientX - containerRect.left < 10 && e.clientX - containerRect.left > -10;
                                if (snapToLeft) {
                                    width = elementRect.width + (elementRect.x - containerRect.x);
                                }

                                let valid = sizeIsCorrect(width, null);

                                if(!valid){
                                    width = tool.state.minimalSize;
                                }

                                let preLeftPos = originalX - (width - originalWidth);
                                let leftPos = preLeftPos < originalX + originalWidth ? preLeftPos : originalX + originalWidth;

                                let leftBoundX = originalRect.right - width;
                                if(leftBoundX < containerRect.left) {
                                    console.log('containerRect rightBoundX', originalX, containerRect, leftBoundX)
                                    let offsetX = containerRect.left - leftBoundX;
                                    width -= offsetX;
                                    leftPos += offsetX;
                                }

                                _elementToResize.style.width = width + 'px'
                                _elementToResize.style.left = leftPos + 'px'
            
                                tool.events.dispatch('resizing', {
                                    handlePosition: _handlePosition,
                                    width: width,
                                    height: height,
                                    x: leftPos,
                                    originalX: originalMouseX,
                                    originalWidth: originalWidth,
                                    altKey: e.altKey,
                                    shiftKey: e.shiftKey
                                });
            
                            } else if (_handlePosition == 'middletop') {
                                height = originalHeight - (e.clientY - originalMouseY)
            
                                let snapToLeft = elementRect.left - containerRect.left < 10 && e.clientX - containerRect.left < 10 && e.clientX - containerRect.left > -10;
                                if (snapToLeft) {
                                    height = elementRect.height + (elementRect.x - containerRect.x);
                                }
            
                                let snapToTop = elementRect.top - containerRect.top < 10 && e.clientY - containerRect.top < 10 && e.clientY - containerRect.top > -10;
                                if (snapToTop) {
                                    height = elementRect.height + (elementRect.top - containerRect.top);
                                }
            
                                let preTopPos = originalY - (height - originalHeight);
                                let topPos = preTopPos < originalY + originalHeight ? preTopPos : originalY + originalHeight;

                                if(!sizeIsCorrect(null, height)){
                                    return;
                                }
            
                                _elementToResize.style.height = height + 'px'
                                _elementToResize.style.top = topPos + 'px'
            
                                tool.events.dispatch('resizing', {
                                    handlePosition: _handlePosition,
                                    width: width,
                                    height: height,
                                    y: topPos,
                                    clientY: e.clientY,
                                    originalY: originalMouseY,
                                    originalHeight: originalHeight,
                                    altKey: e.altKey,
                                    shiftKey: e.shiftKey
                                });
                            } else if (_handlePosition == 'middlebottom') {
                                height = originalHeight + (e.clientY - originalMouseY)
            
                                if (containerRect.bottom - elementRect.bottom < 10 && containerRect.bottom - e.clientY < 10 && containerRect.bottom - e.clientY > -10) {
                                    height = elementRect.height + (containerRect.bottom - elementRect.bottom)
                                }
            
                                if (containerRect.right - elementRect.right < 10 && containerRect.right - e.clientX < 10 && containerRect.right - e.clientX > -10) {
                                    height = elementRect.height + (containerRect.right - elementRect.right)
                                }

                                if(!sizeIsCorrect(null, height)){
                                    return;
                                }
            
                                _elementToResize.style.height = height + 'px';
            
                                tool.events.dispatch('resizing', {
                                    handlePosition: _handlePosition,
                                    width: width,
                                    height: height,
                                    clientY: e.clientY,
                                    originalY: originalMouseY,
                                    originalHeight: originalHeight,
                                    altKey: e.altKey,
                                    shiftKey: e.shiftKey
                                });
                            } else if (_handlePosition == 'bottomright') {
                                width = originalWidth + (e.clientX - originalMouseX);
                                height = originalHeight + (e.clientY - originalMouseY)
            
                                if (containerRect.bottom - elementRect.bottom < 10 && containerRect.bottom - e.clientY < 10 && containerRect.bottom - e.clientY > -10) {
                                    width = elementRect.width + (containerRect.bottom - elementRect.bottom);
                                    height = elementRect.height + (containerRect.bottom - elementRect.bottom)
                                }
            
                                if (containerRect.right - elementRect.right < 10 && containerRect.right - e.clientX < 10 && containerRect.right - e.clientX > -10) {
                                    width = elementRect.width + (containerRect.right - elementRect.right);
                                    height = elementRect.height + (containerRect.right - elementRect.right)
                                }

                                if(!sizeIsCorrect(width, height)){
                                    return;
                                }
            
                                _elementToResize.style.width = width + 'px';
                                _elementToResize.style.height = height + 'px';
            
                                tool.events.dispatch('resizing', {
                                    handlePosition: _handlePosition,
                                    width: width,
                                    height: height,
                                    clientY: e.clientX,
                                    originalX: originalMouseX,
                                    originalWidth: originalWidth,
                                    clientY: e.clientY,
                                    originalY: originalMouseY,
                                    originalHeight: originalHeight,
                                    altKey: e.altKey,
                                    shiftKey: e.shiftKey
                                });
            
                            } else if (_handlePosition == 'topright') {
                                width = originalWidth + (e.clientX - originalMouseX)
                                height = originalHeight - (e.clientY - originalMouseY)
            
                                let snapToTop = elementRect.top - containerRect.top < 10 && e.clientY - containerRect.top < 10 && e.clientY - containerRect.top > -10;
                                if (snapToTop) {
                                    width = elementRect.width + (elementRect.top - containerRect.top);
                                    height = elementRect.height + (elementRect.top - containerRect.top);
                                }
            
                                if (containerRect.right - elementRect.right < 10 && containerRect.right - e.clientX < 10 && containerRect.right - e.clientX > -10) {
                                    width = elementRect.width + (containerRect.right - elementRect.right);
                                    height = elementRect.height + (containerRect.right - elementRect.right)
                                }

                                if(!sizeIsCorrect(width, height)){
                                    return;
                                }
            
                                _elementToResize.style.width = width + 'px'
            
                                let topPos = originalY + originalHeight - height;
            
                                _elementToResize.style.height = height + 'px'
                                _elementToResize.style.top = topPos + 'px'
            
                                tool.events.dispatch('resizing', {
                                    handlePosition: _handlePosition,
                                    width: width,
                                    height: height,
                                    x: null,
                                    y: topPos,
                                    clientX: e.clientX,
                                    originalX: originalMouseX,
                                    originalWidth: originalWidth,
                                    clientY: e.clientY,
                                    originalY: originalMouseY,
                                    originalHeight: originalHeight,
                                    altKey: e.altKey,
                                    shiftKey: e.shiftKey
                                });
            
            
                            } else if (_handlePosition == 'topleft') {
                                width = originalWidth - (e.clientX - originalMouseX)
                                height = originalHeight - (e.clientY - originalMouseY)
            
                                let snapToLeft = elementRect.left - containerRect.left < 10 && e.clientX - containerRect.left < 10 && e.clientX - containerRect.left > -10;
                                if (snapToLeft) {
                                    width = elementRect.width + (elementRect.x - containerRect.x);
                                    height = elementRect.height + (elementRect.x - containerRect.x);
                                }
            
                                let snapToTop = elementRect.top - containerRect.top < 10 && e.clientY - containerRect.top < 10 && e.clientY - containerRect.top > -10;
                                if (snapToTop) {
                                    width = elementRect.width + (elementRect.top - containerRect.top);
                                    height = elementRect.height + (elementRect.top - containerRect.top);
                                }
            
                                let preLeftPos = originalX - (width - originalWidth);
                                let leftPos = preLeftPos < originalX + originalWidth ? preLeftPos : originalX + originalWidth;
            
                                if(!sizeIsCorrect(width, height)){
                                    return;
                                }

                                _elementToResize.style.width = width + 'px'
                                _elementToResize.style.left = leftPos + 'px'
            
            
                                let preTopPos = originalY - (height - originalHeight);
                                let topPos = preTopPos < originalY + originalHeight ? preTopPos : originalY + originalHeight;
            
                                _elementToResize.style.height = height + 'px'
                                _elementToResize.style.top = topPos + 'px'
            
                                tool.events.dispatch('resizing', {
                                    handlePosition: _handlePosition,
                                    width: width,
                                    height: height,
                                    x: leftPos,
                                    y: topPos,
                                    originalX: originalMouseX,
                                    originalWidth: originalWidth,
                                    originalY: originalMouseY,
                                    originalHeight: originalHeight,
                                    altKey: e.altKey,
                                    shiftKey: e.shiftKey
                                });
                            } else if (_handlePosition == 'bottomleft') {
                                height = originalHeight + (e.clientY - originalMouseY)
                                width = originalWidth - (e.clientX - originalMouseX)
            
                                let snapToBottom = containerRect.bottom - elementRect.bottom < 10 && containerRect.bottom - e.clientY < 10 && containerRect.bottom - e.clientY > -10;
                                if (snapToBottom) {
                                    width = elementRect.width + (containerRect.bottom - elementRect.bottom);
                                    height = elementRect.height + (containerRect.bottom - elementRect.bottom)
                                }
            
                                let snapToLeft = elementRect.left - containerRect.left < 10 && e.clientX - containerRect.left < 10 && e.clientX - containerRect.left > -10;
                                if (snapToLeft) {
                                    width = elementRect.width + (elementRect.x - containerRect.x);
                                    height = elementRect.height + (elementRect.x - containerRect.x);
                                }

                                if(!sizeIsCorrect(width, height)){
                                    return;
                                }
            
                                _elementToResize.style.height = height + 'px';
                                let preLeftPos = originalX - (width - originalWidth);
                                let leftPos = preLeftPos < originalX + originalWidth ? preLeftPos : originalX + originalWidth;
            
                                _elementToResize.style.width = width + 'px'
                                _elementToResize.style.left = leftPos + 'px'
            
                                tool.events.dispatch('resizing', {
                                    handlePosition: _handlePosition,
                                    width: width,
                                    height: height,
                                    x: leftPos,
                                    originalX: originalMouseX,
                                    originalWidth: originalWidth,
                                    originalY: originalMouseY,
                                    originalHeight: originalHeight,
                                    altKey: e.altKey,
                                    shiftKey: e.shiftKey
                                });
            
                            }
                        }
            
                        tool.state.appliedRecently = true;
            
                    }
            
                    function stopResizing(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        window.removeEventListener('mousemove', startResizing);
                        window.removeEventListener('mouseup', stopResizing);
                        _ratio = null;
                        tool.state.isResizing = false;
            
                        if (tool.state.appliedRecently) {
                            tool.events.dispatch('resized');
                            setTimeout(function () {
                                tool.state.appliedRecently = false;
                            }, 200)
                        }
                    }
            
                    function resizeByPinchGesture() {
                        _elementToResize.addEventListener('touchstart', startResizingByPinch);
                    }
            
                    function startResizingByPinch(e) {
                        _elementPosition = _elementToResize.style.position || elementComputedStyle.position;
                        var elementRect = _elementToResize.getBoundingClientRect();

                        if (_elementPosition == 'fixed') {
                            _centerPosition = elementRect.left + elementRect.width / 2;
                        } else /*if (_elementPosition == 'absolute')*/ {
                            _centerPosition = _elementToResize.offsetLeft + elementRect.width / 2;
                        }
            
                        if (_elementPosition == 'fixed') {
                            _centerPositionFromTop = elementRect.top + elementRect.height / 2;
                        } else /*if (_elementPosition == 'absolute')*/ {
                            _centerPositionFromTop = _elementToResize.offsetTop + elementRect.height / 2;
                        }

                        _currentActiveTouches = e.touches.length;
                        ratio = _elementToResize.offsetWidth / _elementToResize.offsetHeight;
                        window.addEventListener('touchend', stopResizingByPinch);
                        window.addEventListener('touchmove', resizeByPinch);
                    }
            
                    function stopResizingByPinch(e) {
                        if (_currentActiveTouches != e.changedTouches.length) {
                            _currentActiveTouches = _currentActiveTouches - e.changedTouches.length;
                            return;
                        }
                        _currentActiveTouches = 0;
                        touch1 = touch2 = prevPosOfTouch1 = prevPosOfTouch2 = _latestHeightValue = _latestWidthValue = ratio = null;
                        window.removeEventListener('touchend', stopResizingByPinch);
                        window.removeEventListener('touchmove', resizeByPinch);
                        tool.events.dispatch('moved');
                        tool.state.isResizing = false;
                    }
            
                    var touch1, touch2, prevPosOfTouch1, prevPosOfTouch2, ratio;
                    function resizeByPinch(e) {
                        if (e.touches.length != 2) return;
                        tool.state.isResizing = true;
            
                        var changedTouches = Array.prototype.slice.call(e.changedTouches);
                        for (var i in changedTouches) {
                            var touch = changedTouches[i];
                            if (touch1 != null && touch.identifier == touch1.identifier || (touch1 == null && (touch2 == null || touch.identifier != touch2.identifier))) {
                                touch1 = { identifier: touch.identifier, clientX: touch.clientX, clientY: touch.clientY };
                            }
                            if (touch2 != null && touch.identifier == touch2.identifier || (touch2 == null && (touch1 == null || touch.identifier != touch1.identifier))) {
                                touch2 = { identifier: touch.identifier, clientX: touch.clientX, clientY: touch.clientY };
                            }
                        }
            
                        var touches = Array.prototype.slice.call(e.touches);
                        for (var i in touches) {
                            var touch = touches[i];
                            if (touch1 != null && touch.identifier == touch1.identifier || (touch1 == null && (touch2 == null || touch.identifier != touch2.identifier))) {
                                touch1 = { identifier: touch.identifier, clientX: touch.clientX, clientY: touch.clientY };
                            }
                            if (touch2 != null && touch.identifier == touch2.identifier || (touch2 == null && (touch1 == null || touch.identifier != touch1.identifier))) {
                                touch2 = { identifier: touch.identifier, clientX: touch.clientX, clientY: touch.clientY };
                            }
                        }
            
                        if (prevPosOfTouch1 == null) prevPosOfTouch1 = { x: touch1.clientX, y: touch1.clientY, moveDist: 0 };
                        if (prevPosOfTouch2 == null) prevPosOfTouch2 = { x: touch2.clientX, y: touch2.clientY, moveDist: 0 };
            
                        //if(touch1.clientX >= docRect.right-(docStyles.paddingRight ? docStyles.paddingRight : '0').replace('px', '')) return;
                        var elRect = _elementToResize.getBoundingClientRect();
                        if (_latestWidthValue == null) _latestWidthValue = elRect.width;
                        if (_latestHeightValue == null) _latestHeightValue = elRect.height;
            
                        var elementRect = _elementToResize.getBoundingClientRect().height;
            
                        var elementWidth, elementHeight;
                        var touch1diff, touch2diff;
            
                        touch1diff = Math.abs(prevPosOfTouch1.x - touch1.clientX);
                        touch2diff = Math.abs(prevPosOfTouch2.x - touch2.clientX);
            
                        var xDiff = (touch1.clientX - prevPosOfTouch1.x);
                        var yDiff = (touch1.clientY - prevPosOfTouch1.y);
            
                        var xDiff2 = (touch2.clientX - prevPosOfTouch2.x);
                        var yDiff2 = (touch2.clientY - prevPosOfTouch2.y);
                        var distance1 = Math.sqrt(xDiff * xDiff + yDiff * yDiff);
                        var distance2 = Math.sqrt(xDiff2 * xDiff2 + yDiff2 * yDiff2);
            
                        var distBetwFingers = Math.sqrt((touch1.clientX - touch2.clientX) * (touch1.clientX - touch2.clientX) + (touch1.clientY - touch2.clientY) * (touch1.clientY - touch2.clientY))
                        var prevdistBetwFingers = Math.sqrt((prevPosOfTouch1.x - prevPosOfTouch2.x) * (prevPosOfTouch1.x - prevPosOfTouch2.x) + (prevPosOfTouch1.y - prevPosOfTouch2.y) * (prevPosOfTouch1.y - prevPosOfTouch2.y))
            
                        if (distBetwFingers > prevdistBetwFingers) {
                            elementHeight = _latestHeightValue + (distance1 + distance2);
                            elementWidth = _latestWidthValue + (distance1 + distance2);
                        } else {
                            elementHeight = _latestHeightValue - (distance1 + distance2);
                            elementWidth = _latestWidthValue - (distance1 + distance2);
                        }
            
                        if (ratio < 1) {
                            elementWidth = parseInt(elementHeight * ratio);
            
                        }
                        else {
                            elementHeight = parseInt(elementWidth / ratio);
                        }
            
                        if (elementHeight > window.innerHeight || elementWidth >= window.innerWidth) {
                            return;
                        }
            
                        let leftPos = _centerPosition - (elementWidth / 2);
                        let topPos = _centerPositionFromTop - (elementHeight / 2);
                        if (_elementPosition == 'fixed' || _elementPosition == 'absolute') {
                            _elementToResize.style.left = leftPos + 'px';
                            _elementToResize.style.top = topPos + 'px';
                        }

                        if(!sizeIsCorrect(width, height)){
                            return;
                        }
            
                        _elementToResize.style.width = elementWidth + 'px';
                        _elementToResize.style.height = elementHeight + 'px';
            
                        _latestWidthValue = elementWidth;
                        _latestHeightValue = elementHeight;
            
                        prevPosOfTouch1.x = touch1.clientX;
                        prevPosOfTouch1.y = touch1.clientY;
                        prevPosOfTouch2.x = touch2.clientX;
                        prevPosOfTouch2.y = touch2.clientY;

                        tool.events.dispatch('resizing', { width: elementWidth, height: elementHeight, x: leftPos, y: topPos, originalWidth: originalWidth });
            
                    }
            
                    function onWheel(e) {
                        if (!tool.state.active) return;
                        var elRect = _elementToResize.getBoundingClientRect();
                        var containerRect = tool.state.moveWithinArea == 'parent' ? _elementToResize.parentElement.getBoundingClientRect() : new DOMRect(0, 0, window.innerWidth, window.innerHeight);
                        var elementPosition = _elementToResize.style.position;
                        if (_centerPosition == null) {
                            if (elementPosition == 'fixed') {
                                _centerPosition = elRect.left + elRect.width / 2;
                            } else if (elementPosition == 'absolute') {
                                _centerPosition = _elementToResize.offsetLeft + elRect.width / 2;
                            }
                        }
                        if (_centerPositionFromTop == null) {
                            if (elementPosition == 'fixed') {
                                _centerPositionFromTop = elRect.top + elRect.height / 2;
                            } else if (elementPosition == 'absolute') {
                                _centerPositionFromTop = _elementToResize.offsetTop + elRect.height / 2;
                            }
            
                        }
                        
                        //e = e || window.event;
                        //var currentTarget = document.elementFromPoint(e.clientX, e.clientY);
                        //if(__elementToResize == null) __elementToResize = e.target;
                        var elRect = _elementToResize.getBoundingClientRect();
                        //if(elRect.height >= window.innerHeight || elRect.width >= window.innerWidth) return;
                        var delta = e.deltaY || e.detail || e.wheelDelta;
                        //if (delta < 0) return
            
                        if (_latestScaleValue == null) _latestScaleValue = 1;
                        var scale = (delta > 0) ? _latestScaleValue + 0.1 : _latestScaleValue - 0.1;
            
            
                        var oldWidth = elRect.width;
                        var oldHeight = elRect.height;
                        _elementToResize.style.transform = 'scale(' + scale + ')';
            
                        var elRect = _elementToResize.getBoundingClientRect();
                        ratio = _elementToResize.offsetWidth / _elementToResize.offsetHeight;
            
                        if (!tool.state.allowOverresizing && (elRect.height > document.body.offsetHeight || elRect.width >= document.body.offsetWidth)) {
            
                            _elementToResize.style.width = oldWidth + 'px';
                            _elementToResize.style.height = oldHeight + 'px';
                            _elementToResize.style.transform = '';
            
                            return;
                        }

                        var elRect = _elementToResize.getBoundingClientRect();
                        if (elementPosition == 'fixed' || elementPosition == 'absolute') {

                            _elementToResize.style.left = _centerPosition - (elRect.width / 2) + 'px';
                            _elementToResize.style.top = _centerPositionFromTop - (elRect.height / 2) + 'px';            
                        }
            
                        var elementWidth = elRect.width;
                        var elementHeight = elRect.height;
                        if (tool.state.keepRatioBasedOnElement != null) {

                            var baseEl = tool.state.keepRatioBasedOnElement;

                            var srcWidth, srcHeight, ratio
                            if (typeof baseEl == 'object' && !(baseEl instanceof HTMLVideoElement)) {
                                srcWidth = baseEl.width;
                                srcHeight = baseEl.height;
                                ratio = srcWidth / srcHeight;
                            } else if (baseEl instanceof HTMLVideoElement) {
                                srcWidth = baseEl.videoWidth;
                                srcHeight = baseEl.videoHeight;
                                ratio = srcWidth / srcHeight;
                            }
            
                            if (ratio < 1) {
                                elementWidth = elementHeight * ratio;
                            } else {
                                elementHeight = elementWidth / ratio;
                            }
                        }

                        if(!sizeIsCorrect(elementWidth, elementHeight)){
                            _elementToResize.style.width = oldWidth + 'px';
                            _elementToResize.style.height = oldHeight + 'px';
                            _elementToResize.style.transform = '';
                            return;
                        }

                        _elementToResize.style.width = elementWidth + 'px';
                        _elementToResize.style.height = elementHeight + 'px';
                        _elementToResize.style.top = elRect.top - containerRect.top + 'px';
                        _elementToResize.style.left = elRect.left - containerRect.left + 'px';
                        
                        _elementToResize.style.transform = '';
                        //}, 100);
                        tool.events.dispatch('resizing', { width: elementWidth, height: elementHeight, x: elRect.left - containerRect.left, y: elRect.top - containerRect.top });
                        e.preventDefault ? e.preventDefault() : (e.returnValue = false);
            
                        if (_resetInitPosTimeout != null) {
                            clearTimeout(_resetInitPosTimeout);
                            _resetInitPosTimeout = null
                        }
                        _resetInitPosTimeout = setTimeout(function () {
                            _centerPosition = null;
                            _centerPositionFromTop = null;
                            tool.events.dispatch('moved');
                        }, 1000)
                    }
            
                    function bindMouseWheelEvent(elem) {
                        _elementToResize = elem;
                        if (elem.addEventListener) {
                            if ('onwheel' in document) {
                                // IE9+, FF17+, Ch31+
                                elem.addEventListener("wheel", onWheel);
                            } else if ('onmousewheel' in document) {
                                elem.addEventListener("mousewheel", onWheel);
                            } else {
                                elem.addEventListener("MozMousePixelScroll", onWheel);
                            }
                        } else { // IE8-window.addEventListener
                            elem.attachEvent("onmousewheel", onWheel);
                        }
                    }
            
                    function initResizeTool() {
                        if (_isTouchScreen) {
                            resizeByPinchGesture();
                            return;
                        }

                        function Handle(options) {
                            this.key = options.key;
                            this.cursor = options.cursor;
                            this.show = tool.state.handles[this.key] && tool.state.handles[this.key].show != null ? tool.state.handles[this.key].show : true;
                            this.width = function () { return tool.state.handles[this.key] && tool.state.handles[this.key].width ? tool.state.handles[this.key].width : 6; }
                            this.height = function () { return tool.state.handles[this.key] && tool.state.handles[this.key].height ? tool.state.handles[this.key].height : 6; }
                            this.top = tool.state.handles[this.key] && tool.state.handles[this.key].top ? tool.state.handles[this.key].top : options.top;
                            this.right = tool.state.handles[this.key] && tool.state.handles[this.key].right ? tool.state.handles[this.key].right : options.right;
                            this.bottom = tool.state.handles[this.key] && tool.state.handles[this.key].bottom ? tool.state.handles[this.key].bottom : options.bottom;
                            this.left = tool.state.handles[this.key] && tool.state.handles[this.key].left ? tool.state.handles[this.key].left : options.left;
                        }
                        let handles = [
                            new Handle({
                                key: 'bottomright',
                                cursor: 'nwse-resize',
                                right: function () { return '-' + (this.width() / 2) + 'px'; },
                                bottom: function () { return '-' + (this.height() / 2) + 'px'; }
                            }),
                            new Handle({
                                key: 'bottomleft',
                                cursor: 'nesw-resize',
                                left: function () { return '-' + (this.width() / 2) + 'px'; },
                                bottom: function () { return '-' + (this.height() / 2) + 'px'; }
                            }),
                            new Handle({
                                key: 'topright',
                                cursor: 'nesw-resize',
                                right: function () { return '-' + (this.width() / 2) + 'px'; },
                                top: function () { return '-' + (this.height() / 2) + 'px'; }
                            }),
                            new Handle({
                                key: 'topleft',
                                cursor: 'nwse-resize',
                                left: function () { return '-' + (this.width() / 2) + 'px'; },
                                top: function () { return '-' + (this.height() / 2) + 'px'; }
                            }),
                            new Handle({
                                key: 'middleright',
                                cursor: 'ew-resize',
                                right: function () { return '-' + (this.width() / 2) + 'px'; },
                                bottom: function () { return 'calc(50% - ' + this.height() / 2 + 'px)'; }
                            }),
                            new Handle({
                                key: 'middleleft',
                                cursor: 'ew-resize',
                                left: function () { return '-' + (this.width() / 2) + 'px'; },
                                bottom: function () { return 'calc(50% - ' + this.height() / 2 + 'px)'; }
                            }),
                            new Handle({
                                key: 'middletop',
                                cursor: 'ns-resize',
                                left: function () { return 'calc(50% - ' + this.width() / 2 + 'px)'; },
                                top: function () { return '-' + (this.height() / 2) + 'px'; }
                            }),
                            new Handle({
                                key: 'middlebottom',
                                cursor: 'ns-resize',
                                left: function () { return 'calc(50% - ' + this.width() / 2 + 'px)'; },
                                bottom: function () { return '-' + (this.height() / 2) + 'px'; }
                            }),
                        ];

                        _elementToResize.draggable = false;
                        for (let i in handles) {
                            if(handles[i].show === false) continue;
                            let resizeHandler = document.createElement('DIV');
                            resizeHandler.style.position = 'absolute';
                            if(handles[i].top) resizeHandler.style.top = handles[i].top();
                            if(handles[i].right) resizeHandler.style.right = handles[i].right();
                            if(handles[i].bottom) resizeHandler.style.bottom = handles[i].bottom();
                            if(handles[i].left) resizeHandler.style.left = handles[i].left();
                            resizeHandler.style.width = handles[i].width() + 'px';
                            resizeHandler.style.height =  handles[i].height() + 'px';
                            resizeHandler.style.cursor = handles[i].cursor;
                            resizeHandler.style.zIndex = '3';
                            if (tool.state.showResizeHandles) resizeHandler.style.background = 'red';
                            resizeHandler.dataset.position = handles[i].key;
                            resizeHandler.draggable = false;
                            _elementToResize.appendChild(resizeHandler);
                            resizeHandler.addEventListener('mousedown', startResizingByHendle);

                        }
            
                        if (tool.state.resizeByWheel) bindMouseWheelEvent(_elementToResize);
                    }
            
                    return {
                        initResizeTool: initResizeTool,
                    }
                })();

                function distance(x1, y1, x2, y2) {
                    return Math.sqrt(Math.pow(x2 - x1, 2) + Math.pow(y2 - y1, 2));
                }
            
                function EventSystem() {
            
                    var events = {};
            
                    var CustomEvent = function (eventName) {
            
                        this.eventName = eventName;
                        this.callbacks = [];
            
                        this.registerCallback = function (callback) {
                            this.callbacks.push(callback);
                        }
            
                        this.unregisterCallback = function (callback) {
                            const index = this.callbacks.indexOf(callback);
                            if (index > -1) {
                                this.callbacks.splice(index, 1);
                            }
                        }
            
                        this.fire = function (data) {
                            const callbacks = this.callbacks.slice(0);
                            callbacks.forEach((callback) => {
                                callback(data);
                            });
                        }
                    }
            
                    var dispatch = function (eventName, data) {
                        const event = events[eventName];
                        if (event) {
                            event.fire(data);
                        }
                    }
            
                    var on = function (eventName, callback) {
                        let event = events[eventName];
                        if (!event) {
                            event = new CustomEvent(eventName);
                            events[eventName] = event;
                        }
                        event.registerCallback(callback);
                    }
            
                    var off = function (eventName, callback) {
                        const event = events[eventName];
                        if (event && event.callbacks.indexOf(callback) > -1) {
                            event.unregisterCallback(callback);
                            if (event.callbacks.length === 0) {
                                delete events[eventName];
                            }
                        }
                    }
            
                    var removeAllHandlers = function (eventName) {
                        const event = events[eventName];
                        if (event) {
                            event.callbacks = []
                        }
                    }
            
                    var destroy = function () {
                        events = {};
                    }
            
                    return {
                        dispatch: dispatch,
                        on: on,
                        off: off,
                        removeAllHandlers: removeAllHandlers,
                        destroy: destroy
                    }
                }
            
                function isTouchDevice() {
                    return (('ontouchstart' in window) ||
                        (navigator.maxTouchPoints > 0) ||
                        (navigator.msMaxTouchPoints > 0));
                }

                if (tool.state.move) {
                    _dragElementTool.initDragTool();
                }
            
                if (tool.state.resize) {
                    _resizeElementTool.initResizeTool();
                }
            },
        }

    );

    (function(e){
        e.closest = e.closest || function(css){
            var node = this;

            while (node) {
                if (node.matches(css)) return node;
                else node = node.parentElement;
            }
            return null;
        }
    })(Element.prototype);

})(window.jQuery, window);