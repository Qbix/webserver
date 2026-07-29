(function (Q, $, window, undefined) {
/**
 * @module Q-tools
 */
/**
 * Time-series line chart using D3 with stroke-dasharray draw animation.
 * Handles Streams/highlight { elementId } to spotlight a data point.
 * Handles Streams/zoom { scale } to change Y-axis range.
 *
 * @class Q/chart/line
 * @constructor
 * @param {Object} [options]
 * @param {Array}  options.series    [{label, data: [{x, y}, ...], color?}, ...]
 * @param {String} [options.title]
 * @param {String} [options.xLabel]
 * @param {String} [options.yLabel]
 * @param {String} [options.unit]   Appended to Y axis labels
 * @param {Boolean}[options.animate=true]
 * @param {Number} [options.animateMs=1000]
 * @param {String} [options.source]
 * @param {String} [options.url]
 * @param {Q.Event} [options.onRefresh]
 */
Q.Tool.define("Q/chart/line", function () {
    var tool = this;
    // Load vendored D3 v7; fall back to CDN if not available
    if (typeof d3 !== 'undefined') {
        tool.refresh();
    } else {
        Q.addScript('{{Q}}/js/d3.min.js', function () {
            if (typeof d3 !== 'undefined') {
                tool.refresh();
            } else {
                Q.addScript('{{Q}}/js/d3.min.js', function () {
                    tool.refresh();
                });
            }
        });
    }
},
{
    series: [], title: '', xLabel: '', yLabel: '', unit: '',
    animate: true, animateMs: 1000, source: '', url: '',
    onRefresh: new Q.Event()
},
{
    refresh: function () {
        var tool = this, s = tool.state;
        if (typeof d3 === 'undefined') return;
        if (typeof s.series === 'string') { try { s.series = JSON.parse(s.series); } catch (e) { s.series = []; } }
        if (!s.series.length) return;

        var margin = { top: 20, right: 20, bottom: 40, left: 50 };
        var width  = tool.element.clientWidth  || 600;
        var height = tool.element.clientHeight || 300;
        var iw = width  - margin.left - margin.right;
        var ih = height - margin.top  - margin.bottom;

        tool.element.innerHTML = '';
        var svg = d3.select(tool.element).append('svg')
            .attr('width', width).attr('height', height)
            .attr('class', 'Q_chart Q_chart_line');
        var g = svg.append('g').attr('transform', 'translate(' + margin.left + ',' + margin.top + ')');

        var allData = [].concat.apply([], s.series.map(function (sr) { return sr.data; }));
        var xScale = d3.scaleLinear()
            .domain(d3.extent(allData, function (d) { return +d.x; }))
            .range([0, iw]);
        var yMax = d3.max(allData, function (d) { return +d.y; }) * 1.1;
        // Apply zoom: _zoomScale > 1 zooms in (tighter Y range), < 1 zooms out
        if (s._zoomScale && s._zoomScale !== 1) {
            yMax = yMax / s._zoomScale;
        }
        var yScale = d3.scaleLinear()
            .domain([0, yMax])
            .range([ih, 0]);

        g.append('g').attr('transform', 'translate(0,' + ih + ')').call(d3.axisBottom(xScale).ticks(6));
        g.append('g').call(d3.axisLeft(yScale).ticks(5).tickFormat(function (v) { return v + (s.unit || ''); }));

        var line = d3.line()
            .x(function (d) { return xScale(+d.x); })
            .y(function (d) { return yScale(+d.y); })
            .curve(d3.curveCatmullRom);

        s.series.forEach(function (sr, i) {
            var color = sr.color || d3.schemeTableau10[i % 10];
            var path = g.append('path')
                .datum(sr.data)
                .attr('fill', 'none')
                .attr('stroke', color)
                .attr('stroke-width', 2.5)
                .attr('d', line);

            if (s.animate) {
                var totalLength = path.node().getTotalLength();
                path.attr('stroke-dasharray', totalLength + ' ' + totalLength)
                    .attr('stroke-dashoffset', totalLength)
                    .transition().duration(s.animateMs).ease(d3.easeLinear)
                    .attr('stroke-dashoffset', 0);
            }
        });

        if (s.title) {
            svg.append('text').attr('x', width / 2).attr('y', 14)
                .attr('text-anchor', 'middle').attr('class', 'Q_chart_title')
                .text(s.title);
        }

        tool._svg = svg;
        tool._xScale = xScale;
        tool._yScale = yScale;
        tool._g = g;
        // Trigger CSS line-draw animation by adding class to wrapper
        var svgNode = tool._svg && tool._svg.node();
        if (svgNode && svgNode.closest) {
            var wrapper = svgNode.closest('.Media_presentation_chart_screen, .Q_chart_line');
            if (wrapper) wrapper.classList.add('Media_chart_line_animate');
        }
        Q.handle(s.onRefresh, tool);
    },

    highlight: function (elementId) {
        // elementId can be an x value; draw a vertical rule
        var tool = this;
        if (!tool._g) return;
        tool._g.selectAll('.Q_chart_highlight_rule').remove();
        var x = parseFloat(elementId);
        if (!isNaN(x) && tool._xScale) {
            tool._g.append('line')
                .attr('class', 'Q_chart_highlight_rule')
                .attr('x1', tool._xScale(x)).attr('x2', tool._xScale(x))
                .attr('y1', 0).attr('y2', tool.element.clientHeight - 60)
                .attr('stroke', '#f59e0b').attr('stroke-width', 2)
                .attr('stroke-dasharray', '4 3');
        }
    },

    zoom: function (scale) {
        // scale > 1 = zoom in (tighter Y range), scale < 1 = zoom out
        var tool = this, s = tool.state;
        if (!tool._yScale) return;
        s._zoomScale = scale;
        tool.refresh();
    }
});
})(Q, Q.jQuery, window);
