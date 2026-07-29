(function (Q, $, window, undefined) {

var Users = Q.Users;

/**
 * @module Streams-tools
 */

/**
 * Renders an album of related images
 * @class Streams/image/album
 * @constructor
 * @param {Object} [options] options for the tool
 *   @param {Object} [options.related] Options forwarded to Streams/related
 *   @param {Object} [options.previews] Options forwarded to each Streams/image/preview
 */
Q.Tool.define("Streams/image/album", function (options) {
    var tool = this;
    var state = tool.state;

    if (!Users.loggedInUser) {
        throw new Q.Error("Streams/image/album: You are not logged in.");
    }
    if ((!state.publisherId || !state.streamName)
        && (!state.stream || Q.typeOf(state.stream) !== 'Streams.Stream')) {
        throw new Q.Error("Streams/image/album: missing publisherId or streamName");
    }

    // merge related defaults with any overrides
    var relatedOpts = Q.extend({
        publisherId: state.publisherId,
        streamName: state.streamName,
        relationType: 'Streams/images',
        isCategory: true,
        realtime: false,
        editable: true,
        closeable: true,
        creatable: {
            'Streams/image': { title: "New Image" }
        },
        previewOptions: Q.extend({}, state.previews)
    }, state.related);

    // Set up the related tool inside this tool’s element
    Q.Tool.setUpElement(
        'div',
        'Streams/related',
        relatedOpts,
        tool.prefix + "_related"
    );

}, {
    // defaults
    publisherId: null,
    streamName: null,
    related: {},
    previews: {}
}, {
    // methods
    refresh: function (onUpdate) {
        var relatedTool = this.child("Streams_related");
        if (relatedTool) {
            relatedTool.refresh(onUpdate);
        }
    }
});

})(Q, Q.jQuery, window);