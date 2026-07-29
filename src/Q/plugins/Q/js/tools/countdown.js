(function (Q) {
    /**
     * Q Tools
     * @module Q-tools
     */

    /**
     * This tool makes a countdown to a certain timestamp
     *
     * @class Q countdown
     * @constructor
     * @param {Object} [options] This is an object of parameters for this tool
     *   @param {Number} [options.timestamp=new Date().getTime()/1000] Unix timestamp (in seconds).
     *   @param {Q.Event} [options.onRefresh] Occur when tool.element updated
     */
    Q.Tool.define('Q/countdown', function q_countdown () {
        var tool = this;
        var state = this.state;

        if (!state.timestamp) {
            var attribute = this.element.getAttribute("data-timestamp");
            if (!attribute) {
                throw new Q.Error("Q/countdown: timestamp not specified");
            }
            state.timestamp = parseInt(attribute);
        }
        this.ival = setInterval(function () {
            var data = Q.timeRemaining(state.timestamp * 1000);
            for (var k in data) {
                var elements = tool.element.getElementsByClassName('Q_' + k);
                if (elements && elements[0]) {
                    elements[0].innerText = data[k];
                }
            }

            Q.handle(state.onRefresh, tool);
        }, 1000);
    }, {
        timestamp: null,
        onRefresh: new Q.Event()
    }, {
        Q: {
            beforeRemove: function () {
                clearInterval(this.ival);
            }
        }
    });

})(Q);