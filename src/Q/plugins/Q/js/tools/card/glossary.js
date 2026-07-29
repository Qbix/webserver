(function (Q, $, window, undefined) {
/**
 * @module Q-tools
 */
/**
 * Glossary / definition card.
 * Used standalone or inside Media/presentation/card/glossary.
 *
 * @class Q/card/glossary
 * @constructor
 * @param {Object} [options]
 * @param {String} options.term         Term being defined
 * @param {String} options.definition   Plain-English definition
 * @param {String} [options.context]    One-sentence usage in context
 * @param {String} [options.source]     Source name
 * @param {String} [options.url]        Source URL
 * @param {Q.Event} [options.onRefresh]
 */
Q.Tool.define("Q/card/glossary", function () {
    this.refresh();
},
{
    term: '', definition: '', context: '', source: '', url: '',
    onRefresh: new Q.Event()
},
{
    refresh: function () {
        var tool = this, s = tool.state;
        Q.Template.render('Q/card/glossary', {
            term: s.term, definition: s.definition, context: s.context,
            source: s.source, url: s.url,
            hasContext: !!s.context, hasSource: !!(s.source || s.url)
        }, function (err, html) {
            if (err) return;
            tool.element.innerHTML = html;
            Q.handle(s.onRefresh, tool);
        });
    }
});

Q.Template.set('Q/card/glossary',
    '<div class="Q_card Q_card_glossary">'
  + '  <div class="Q_card_term">{{term}}</div>'
  + '  <div class="Q_card_definition">{{definition}}</div>'
  + '  {{#if hasContext}}<div class="Q_card_context">{{context}}</div>{{/if}}'
  + '  {{#if hasSource}}<div class="Q_card_source">'
  + '    {{#if url}}<a href="{{url}}" target="_blank" rel="noopener">{{source}}</a>'
  + '    {{else}}{{source}}{{/if}}'
  + '  </div>{{/if}}'
  + '</div>'
);
})(Q, Q.jQuery, window);
