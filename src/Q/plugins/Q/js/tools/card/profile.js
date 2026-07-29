(function (Q, $, window, undefined) {
/**
 * @module Q-tools
 */
/**
 * Person or entity profile card.
 *
 * When userId is provided, delegates icon + name rendering to Users/avatar
 * (the standard Qbix avatar tool), which already handles icon streams,
 * optimistic updates, and community vs individual users.
 * Extra fields (handle, bio, tags) are rendered alongside it.
 *
 * When userId is absent, renders from the explicit name/iconUrl options.
 * This is the path used when the LLM proposes a card for someone not yet
 * in the Qbix Users table (e.g. a Twitter guest before ingestion).
 *
 * Streams/reveal ephemeral: revealIndex 0=header only, 1=+bio, 2=+tags
 *
 * @class Q/card/profile
 * @constructor
 * @param {Object} [options]
 * @param {String} [options.userId]    Qbix userId — renders via Users/avatar
 * @param {Number} [options.icon=80]   Icon size passed to Users/avatar
 * @param {String} [options.name]      Display name (used when no userId)
 * @param {String} [options.handle]    Social handle e.g. "@karpathy"
 * @param {String} [options.iconUrl]   Avatar URL (used when no userId)
 * @param {String} [options.bio]
 * @param {Array}  [options.tags]      Topic tag strings
 * @param {String} [options.source]
 * @param {String} [options.url]
 * @param {Number} [options.revealIndex=2]  Initial reveal depth (0-2)
 * @param {Q.Event} [options.onRefresh]
 */
Q.Tool.define("Q/card/profile", function () {
    this.refresh();
},
{
    userId:      null,
    icon:        80,
    name:        '',
    handle:      '',
    iconUrl:     '',
    bio:         '',
    tags:        [],
    source:      '',
    url:         '',
    revealIndex: 2,
    onRefresh:    new Q.Event()
},
{
    refresh: function () {
        var tool = this, s = tool.state;
        Q.Template.render('Q/card/profile', {
            hasUserId:  !!s.userId,
            userId:     s.userId,
            iconSize:   s.icon,
            name:       s.name,
            handle:     s.handle,
            iconUrl:    s.iconUrl,
            bio:        s.bio,
            tags:       s.tags,
            hasTags:    !!(s.tags && s.tags.length),
            hasIcon:    !!s.iconUrl,
            source:     s.source,
            url:        s.url,
            hasSource:  !!(s.source || s.url),
            revealed:   s.revealIndex
        }, function (err, html) {
            if (err) return;
            tool.element.innerHTML = html;
            // Activate Users/avatar sub-tool if userId provided
            if (s.userId) {
                Q.activate(tool.element);
            }
            tool.reveal(s.revealIndex);
            Q.handle(s.onRefresh, tool);
        });
    },

    /**
     * Progressive reveal: 0=header only, 1=header+bio, 2=all
     * Called by Media/presentation/card/profile on Streams/reveal ephemeral.
     * @method reveal
     * @param {Number} index
     */
    reveal: function (index) {
        var $el = $(this.element);
        this.state.revealIndex = index;
        $el.find('.Q_card_profile_bio').toggle(index >= 1);
        $el.find('.Q_card_profile_tags').toggle(index >= 2);
        $el.find('.Q_card_source').toggle(index >= 2);
    }
});

// Template: if userId provided, use Users/avatar sub-tool for icon+name.
// If not, use the plain img + name approach.
Q.Template.set('Q/card/profile',
    '<div class="Q_card Q_card_profile" data-reveal="{{revealed}}">'
  + '  <div class="Q_card_profile_header">'
  + '    {{#if hasUserId}}'
  // Users/avatar handles icon, name, stream subscriptions — the right tool for the job
  + '    {{{tool "Users/avatar" userId=userId icon=iconSize short=true}}}'
  + '    {{else}}'
  + '    {{#if hasIcon}}<img class="Q_card_profile_icon" src="{{iconUrl}}" alt="{{name}}" />{{/if}}'
  + '    <div class="Q_card_profile_name">{{name}}</div>'
  + '    {{/if}}'
  + '    {{#if handle}}<div class="Q_card_profile_handle">{{handle}}</div>{{/if}}'
  + '  </div>'
  + '  <div class="Q_card_profile_bio">{{bio}}</div>'
  + '  {{#if hasTags}}<div class="Q_card_profile_tags">'
  + '    {{#each tags}}<span class="Q_card_profile_tag">{{this}}</span>{{/each}}'
  + '  </div>{{/if}}'
  + '  {{#if hasSource}}<div class="Q_card_source">'
  + '    {{#if url}}<a href="{{url}}" target="_blank" rel="noopener">{{source}}</a>'
  + '    {{else}}{{source}}{{/if}}'
  + '  </div>{{/if}}'
  + '</div>'
);
})(Q, Q.jQuery, window);
