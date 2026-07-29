(function (Q, $, window, undefined) {
/**
 * Q/visualization/graph tool
 *
 * Force-directed node-edge graph using D3 forceSimulation.
 * Nodes repel each other; edges attract connected nodes.
 * The simulation runs continuously — nodes have gentle ambient drift.
 * Mutations (add/remove node or edge) animate with bounce and rebalance.
 *
 * Node types and rendering:
 *   person  — avatar circle (imageUrl) + name label
 *   company — logo circle (imageUrl) + name label
 *   concept — filled pill with label
 *   stat    — value in ring + label below
 *
 * State shape (stored on stream attribute for reconnect):
 *   { nodes: [{id, type, label, imageUrl?, value?}], edges: [{from, to, label?, weight?}] }
 *
 * Receives updates via:
 *   stream.onMessage('Media/presentation/graph/update', handler)
 *   stream.onEphemeral('Media/presentation/graph/update', handler)  ← same handler
 *
 * @module Q
 * @class Q/visualization/graph
 */
Q.Tool.define('Q/visualization/graph', function (options) {
    var tool  = this;
    var state = tool.state;

    // Initial render
    state.nodes = state.nodes || [];
    state.edges = state.edges || [];
    tool.refresh();

    // Wire stream updates — both message (durable) and ephemeral (live) paths
    if (state.stream) {
        if (state.stream.onMessage) {
            state.stream.onMessage('Media/presentation/graph/update', function (msg) {
                var d = {};
                try { d = JSON.parse(msg.fields.instructions || '{}'); } catch (e) {}
                if (d.action) tool.update(d);
            }, tool);
        }
        state.stream.onEphemeral('Media/presentation/graph/update').set(function (e) {
            if (e && e.action) tool.update(e);
        }, tool);
    }
}, {
    // Default options
    stream:   null,
    nodes:    [],
    edges:    [],
    width:    800,
    height:   520,
    nodeRadius: 32
}, {
    refresh: function () {
        var tool  = this;
        var state = tool.state;

        // Require D3
        if (!window.d3) {
            tool.element.innerHTML = '<div style="color:#f0eee8;padding:2rem;font-family:system-ui">Loading D3…</div>';
            Q.addScript('{{Q}}/js/d3.min.js', function () { tool.refresh(); });
            return;
        }

        tool.element.innerHTML = '';
        tool.element.style.setProperty('--AI-bg',      '#1a1d24');
        tool.element.style.setProperty('--AI-accent',  '#5b8ef0');
        tool.element.style.setProperty('--AI-text',    '#f0eee8');
        tool.element.style.setProperty('--AI-border',  'rgba(255,255,255,0.1)');

        var w = state.width;
        var h = state.height;

        var svg = d3.select(tool.element)
            .append('svg')
            .attr('width',  '100%')
            .attr('height', h)
            .attr('viewBox', '0 0 ' + w + ' ' + h)
            .style('background', 'var(--AI-bg)')
            .style('border-radius', '12px');

        // Arrow marker for directed edges
        svg.append('defs').append('marker')
            .attr('id',          'arrow-' + tool.prefix)
            .attr('viewBox',     '0 -5 10 10')
            .attr('refX',        28)
            .attr('markerWidth', 6)
            .attr('markerHeight',6)
            .attr('orient',      'auto')
            .append('path')
            .attr('d',    'M0,-5L10,0L0,5')
            .attr('fill', 'var(--AI-border)');

        tool._svg   = svg;
        tool._linkG = svg.append('g').attr('class','links');
        tool._nodeG = svg.append('g').attr('class','nodes');

        tool._renderGraph();
    },

    _renderGraph: function () {
        var tool  = this;
        var state = tool.state;
        // Stop existing simulation before rebuilding — prevents two simulations
        // fighting each other when update() calls _renderGraph again
        if (tool._sim) tool._sim.stop();
        if (!tool._linkG) return; // not yet initialised

        var nodes = state.nodes.slice();
        var edges = state.edges.map(function (e) {
            return { source: e.from, target: e.to, label: e.label || '' };
        });

        // Links
        var link = tool._linkG.selectAll('line').data(edges, function (d) { return d.source + '-' + d.target; });
        link.enter().append('line')
            .attr('stroke', 'var(--AI-border)')
            .attr('stroke-width', 1.5)
            .attr('marker-end', 'url(#arrow-' + tool.prefix + ')')
            .style('opacity', 0)
            .transition().duration(400)
            .style('opacity', 1);
        link.exit().transition().duration(300).style('opacity',0).remove();

        // Nodes
        var nodeGroup = tool._nodeG.selectAll('g.node').data(nodes, function (d) { return d.id; });

        var enter = nodeGroup.enter().append('g')
            .attr('class','node')
            .style('cursor', 'pointer')
            .call(d3.drag()
                .on('start', function (event, d) {
                    if (!event.active) tool._sim.alphaTarget(0.3).restart();
                    d.fx = d.x; d.fy = d.y;
                })
                .on('drag',  function (event, d) { d.fx = event.x; d.fy = event.y; })
                .on('end',   function (event, d) {
                    if (!event.active) tool._sim.alphaTarget(0);
                    d.fx = null; d.fy = null;
                })
            );

        // Draw node based on type
        enter.each(function (d) {
            var g   = d3.select(this);
            var r   = state.nodeRadius;
            var col = d.type === 'concept' ? 'var(--AI-accent)'
                    : d.type === 'stat'    ? '#e8a020'
                    : '#2a2d38';

            if (d.imageUrl) {
                // Clip circle + image
                var clipId = 'clip-' + tool.prefix + '-' + d.id;
                d3.select(tool._svg.node()).select('defs')
                    .append('clipPath').attr('id', clipId)
                    .append('circle').attr('r', r);
                g.append('circle').attr('r', r)
                    .attr('fill', col).attr('stroke','var(--AI-border)').attr('stroke-width',2);
                g.append('image')
                    .attr('href', d.imageUrl)
                    .attr('x', -r).attr('y', -r)
                    .attr('width',  r * 2).attr('height', r * 2)
                    .attr('clip-path', 'url(#' + clipId + ')');
            } else {
                g.append('circle').attr('r', r)
                    .attr('fill', col).attr('stroke','var(--AI-accent)').attr('stroke-width',2);
                if (d.type === 'stat') {
                    g.append('text').attr('dy','0.1em')
                        .attr('text-anchor','middle')
                        .attr('fill','var(--AI-text)')
                        .attr('font-size','1.1rem')
                        .attr('font-weight','700')
                        .text(d.value || '');
                } else {
                    g.append('text').attr('dy','0.35em')
                        .attr('text-anchor','middle')
                        .attr('fill','var(--AI-text)')
                        .attr('font-size','0.75rem')
                        .attr('font-weight','600')
                        .text(d.label.length > 10 ? d.label.slice(0,9) + '…' : d.label);
                }
            }

            // Label below (always)
            if (d.imageUrl || d.type === 'stat') {
                g.append('text')
                    .attr('dy', r + 16)
                    .attr('text-anchor','middle')
                    .attr('fill','var(--AI-text)')
                    .attr('font-size','0.72rem')
                    .text(d.label);
            }

            // Entry animation
            g.attr('transform','scale(0)')
                .transition().duration(400)
                .ease(d3.easeBackOut.overshoot(2))
                .attr('transform','scale(1)');
        });

        nodeGroup.exit()
            .transition().duration(300)
            .attr('transform','scale(0)')
            .remove();

        // Remove stale clipPaths from previous render to avoid leaking defs
        if (tool._svg) {
            tool._svg.select('defs').selectAll('clipPath').remove();
        }

        // Rebuild simulation with fresh forces each time
        tool._sim = d3.forceSimulation()
            .force('link',   d3.forceLink().id(function (d) { return d.id; }).distance(120))
            .force('charge', d3.forceManyBody().strength(-300))
            .force('center', d3.forceCenter(state.width / 2, (state.height || 520) / 2))
            .force('collide',d3.forceCollide((state.nodeRadius || 32) + 10));

        tool._sim
            .nodes(nodes)
            .on('tick', function () {
                tool._linkG.selectAll('line')
                    .attr('x1', function (d) { return d.source.x; })
                    .attr('y1', function (d) { return d.source.y; })
                    .attr('x2', function (d) { return d.target.x; })
                    .attr('y2', function (d) { return d.target.y; });
                tool._nodeG.selectAll('g.node')
                    .attr('transform', function (d) {
                        return 'translate(' + d.x + ',' + d.y + ')';
                    });
            });

        tool._sim.force('link').links(edges);
        tool._sim.alpha(0.5).restart();
    },

    /**
     * Apply a graph mutation (set/add/remove) and animate.
     * @param {Object} d  { action, nodes?, edges? }
     */
    update: function (d) {
        var tool  = this;
        var state = tool.state;

        if (d.action === 'set') {
            state.nodes = d.nodes || [];
            state.edges = d.edges || [];
        } else if (d.action === 'add') {
            (d.nodes || []).forEach(function (n) {
                if (!state.nodes.find(function (x) { return x.id === n.id; })) {
                    state.nodes.push(n);
                }
            });
            (d.edges || []).forEach(function (e) { state.edges.push(e); });
        } else if (d.action === 'remove') {
            var removeIds = (d.nodes || []).map(function (n) { return n.id; });
            state.nodes   = state.nodes.filter(function (n) { return removeIds.indexOf(n.id) < 0; });
            state.edges   = state.edges.filter(function (e) {
                return removeIds.indexOf(e.from) < 0 && removeIds.indexOf(e.to) < 0;
            });
        }

        tool._renderGraph();
    },

    Q: {
        beforeRemove: function () {
            if (this._sim) this._sim.stop();
        }
    }
});

})(Q, jQuery, window);
