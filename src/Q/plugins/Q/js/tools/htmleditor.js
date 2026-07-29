(function (window, Q, $, undefined) {

/**
 * @module Streams-tools
 */
    
/**
 * Inline editor for HTML content
 * @class Streams html
 * @constructor
 * @param {Object} [options] this object contains function parameters
 * @param {Array} [options.colors] - Array of (strings) colors for colorpicker
 * @param {Array} [options.fonts] - Array of (strings) fonts for font choser
 */
Q.Tool.define("Q/htmleditor", function (options) {
    var tool = this;
    Q.addStylesheet([
        '{{Q}}/js/htmleditor/css/editor.css',
        '{{Q}}/js/htmleditor/css/style.css'
    ]);
    Q.addScript([
        '{{Q}}/js/htmleditor/htmleditor.js',
        '{{Q}}/js/htmleditor/htmlselector.js'
    ], function () {
        HTMLeditor(tool.element, tool.state);
    });
});

})(window, Q, Q.jQuery);