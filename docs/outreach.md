# Qbix Server — Outreach Targets

**Repo:** github.com/Qbix/webserver
**Version:** 2.0.0 — "Mesh"
**Pitch:** Pure PHP server with built-in encrypted mesh networking. Peers discover each other over Bluetooth and Wi-Fi, establish ECDH-encrypted sessions, and sync data peer-to-peer — no central server required. Runs on Linux, macOS, Windows, iOS, and Android. Also replaces nginx + php-fpm: COW-forked workers at 120KB each, octane mode, WebSocket + rooms, 14-tab control panel, metrics/clickstream, ECDSA M-of-N signing with Sigstore Rekor, `--pack` single-binary. 464 tests, 0 failures.

---

## PHP Ecosystem — Individuals

These are the people who shape what PHP developers use. They'll evaluate the technical merits and amplify to the right audience.

| Name | Role | Why them | Twitter/X | Other contact |
|---|---|---|---|---|
| **Jakub Zelenka** | PHP Foundation core dev (streams, FPM, JSON, OpenSSL) | Wrote BOTH Io\Poll RFC (epoll/kqueue) AND the sendfile/splice PR for PHP 8.6. Qbix Server is the first userland project benefiting from both simultaneously. He'd want to showcase it. | @bukka | Via PHP internals list or GitHub php/php-src |
| **Roman Pronskiy** | PHP Foundation operations lead | Runs PHP Foundation's public communications, transparency reports, social presence. The "pure PHP server matches nginx thanks to Foundation work" is a story he'd amplify. | @pronskiy | Via PHP Foundation · pronskiy.com |
| **Kévin Dunglas** | Creator of FrankenPHP, Symfony core team, Les-Tilleuls.coop founder | Direct competitor — built FrankenPHP (Go+C). Will either see Qbix Server as a threat or a validation of the "persistent PHP workers" thesis. Either way, he'll engage. FrankenPHP is now PHP Foundation-endorsed. | @dunglas | kevin@dunglas.dev · dunglas.dev · github.com/dunglas |
| **Derick Rethans** | PHP Foundation core dev, Xdebug author, PHP Internals News podcast host | The podcast is THE venue for PHP technical deep-dives. He migrated php.net infra and expanded WASM support. Would understand the snapshot/COW model immediately. | @deaborr (Mastodon: @derickr@phpc.social) | derick@php.net · derickrethans.nl |
| **Joe Watkins** | PHP Foundation core dev, ORT tensor library author | Just joined the Foundation. Built a C-level PHP extension for ML. Would appreciate the Snapshot.php introspection approach and the octane reset model. | @kaborr (X) | Via PHP Foundation |
| **Nikita Popov** | Former PHP core (7.4–8.1), now LLVM at Red Hat | Moved to LLVM but still deeply respected in PHP. His blog (npopov.com) drives technical discourse. A mention from him carries weight even though he's no longer active in PHP. | @nikita_ppv | nikic.github.io · npopov.com |
| **Taylor Otwell** | Laravel creator | Laravel Octane (RoadRunner/Swoole backend) is the mainstream answer to persistent PHP workers. Qbix Server's snapshot restore is a direct alternative — pure PHP, no Go, no C extension. He has 200K+ followers. | @taylorotwell | taylor@laravel.com |
| **Nuno Maduro** | Laravel core, Pest PHP creator | Prolific Laravel contributor, huge Twitter following in PHP. If Laravel Octane is the incumbent, Nuno is the person who'd benchmark an alternative. | @enunomaduro | Via Twitter DM |
| **Marcel Pociot** | Beyond Code, Laravel Herd creator | Built Laravel Herd (local dev server). Directly adjacent problem space. Would understand the "one binary, zero config" pitch. | @marcelpociot | marcel@beyondco.de |
| **Brent Roose** | Stitcher.io, PHP author | Writes the most-read PHP blog. His "PHP in 202X" posts reach hundreds of thousands. A mention in his roundup would be huge. | @brendt_gd | brendt@stitcher.io · stitcher.io |
| **Cal Evans** | Voices of the ElePHPant podcast | The PHP community podcast. Interviews PHP project creators. Already in previous outreach list. | @calevans | cal@calevans.com |
| **Sergey Zhuk** | ReactPHP advocate, async PHP author | Wrote the book on async PHP. Would understand the event loop / stream_select architecture and how it compares to ReactPHP/AmPHP. | @saborodin | Via GitHub |
| **Alexander Makarov** | Yii framework lead | Yii is one of the frameworks FrankenPHP officially supports in worker mode. Alternative framework perspective outside the Laravel/Symfony bubble. | @sam_dark | Via GitHub |

## PHP Ecosystem — Organizations

| Organization | Why | Contact |
|---|---|---|
| **The PHP Foundation** | Officially endorses FrankenPHP. A pure-PHP alternative that needs no C extension or Go binary is interesting to them. Their work on Io\Poll and sendfile/splice is what makes this possible. | contact@thephp.foundation · thephp.foundation |
| **JetBrains (PhpStorm team)** | PhpStorm is the dominant PHP IDE. A PHP Foundation founding member. They'd want to know about a new execution model for debugging/profiling support. | Via PhpStorm blog or Twitter @phpstorm |
| **Automattic (WordPress)** | WordPress.com runs on PHP. WordPress Playground already compiles PHP to WASM. A pure-PHP server that eliminates nginx is directly relevant to their hosting stack. PHP Foundation founding member. | Via WordPress.org Trac or make.wordpress.org |

## Framework Authors — Not Yet Contacted

These are the people who decide what their framework recommends for deployment. A nod from any of them changes the conversation.

| Name | Framework/Project | Why they care | Twitter/X | Contact |
|---|---|---|---|---|
| **Fabien Potencier** | Symfony creator | Symfony recommends FrankenPHP. A pure-PHP alternative with no Go dependency is relevant. Fabien cares deeply about the PHP ecosystem and is a PHP Foundation board member. | @fabpot | fabien@symfony.com · symfony.com |
| **Rasmus Lerdorf** | PHP creator | Created PHP. Still speaks at conferences. A pure-PHP server that leverages 8.6 features to match nginx would interest him as validation of the language's direction. | @rasmus | Via PHP Foundation |
| **Matt Mullenweg** | WordPress co-creator, Automattic CEO | WordPress powers 40%+ of the web. A one-binary PHP server that runs WordPress out of the box would interest his hosting division (WordPress.com, Pressable). | @photomatt | Via Automattic or ma.tt |
| **Aaron Francis** | PlanetScale, Try Hard Studios, Laravel voice | Huge YouTube/Twitter following in Laravel. Creates viral developer content. The "replace your entire stack with one PHP command" demo would make a great video. | @aarondfrancis | Via Twitter DM or aaronfrancis.com |
| **Jess Archer** | Laravel core team | Active Laravel core contributor, conference speaker. Would evaluate the Octane alternative angle from the framework integration side. | @jessarchercodes | Via Twitter |
| **Tobias Nyholm** | Symfony core, PHP-HTTP, Bref (serverless PHP) | Built Bref for serverless PHP on Lambda. Qbix Server's `--pack` binary is the opposite thesis — ship a self-contained binary instead of going serverless. Interesting contrast. | @TobiasNyholm | Via GitHub or Twitter |
| **Ben Ramsey** | php[architect] editor, PHP release manager | PHP 8.2/8.3 release manager. Writes for and edits php[architect] magazine. A technical article about COW fork + Io\Poll + sendfile would be perfect for the magazine. | @ramsey | Via php[architect] or Twitter |
| **Matteo Beccati** | PHP 8.6 release manager | One of three PHP 8.6 release managers. The server showcases 8.6 features he's shipping. | Via PHP internals or GitHub |
| **Daniel Scherzer** | PHP 8.6 release manager | Co-release-manager for 8.6. Same angle as Matteo. | Via PHP internals or GitHub |
| **Kévin Dunglas** (Mercure) | Real-time/SSE for PHP | Also created Mercure (real-time hub). Qbix Server has built-in WebSocket + Socket.IO — direct overlap. | @dunglas | (same as above) |

## PHP Journalists & Newsletter Editors

| Name / Outlet | Type | Why | Twitter/X | Contact |
|---|---|---|---|---|
| **Brent Roose** | stitcher.io — most-read PHP blog | His "PHP in 202X" and "What's new in PHP 8.X" posts reach hundreds of thousands. A mention in his roundup is massive organic reach. | @brendt_gd | brendt@stitcher.io |
| **Eric L. Barnes** | Laravel News (editor) | Laravel News is the #1 Laravel publication. The "pure PHP Octane alternative" angle plays here. | @ericlbarnes | Via laravelnews.com |
| **php[architect]** | PHP trade magazine | The only dedicated PHP magazine. A technical deep-dive on COW fork + snapshot restore + Io\Poll would be a feature article. | — | Via phparch.com/editorial |
| **PHP Weekly** | Newsletter | Curates PHP content weekly. Submit the GitHub link or a blog post. | — | Via phpweekly.com |
| **PHP Annotated** | JetBrains monthly roundup | Monthly PHP roundup on the JetBrains blog. If the repo gets noticed, they'll include it. Roman Pronskiy edits it. | @pronskiy | Via JetBrains blog |
| **Freek Van der Herten** | Spatie, Laravel package author | Prolific Laravel package creator (350+ packages). His blog reaches the Laravel ecosystem. Would evaluate and potentially benchmark. | @freekmurze | freek@spatie.be · spatie.be |
| **Mohammed Said** | Laravel team, author | Laravel core team member, writes about Laravel internals. The Octane comparison would be in his wheelhouse. | @themsaid | Via Twitter |
| **Christoph Rumpel** | PHP developer, author, content creator | Runs a PHP newsletter, speaks at conferences, creates PHP educational content. | @christophrumpel | christoph-rumpel.com |
| **Sebastian Bergmann** | PHPUnit creator, PHP Foundation fellow | Creator of the most-used PHP testing framework. The 71-test suite aspect and the testing approach would resonate. | @s_bergmann | Via thePHP.cc or GitHub |

## Mobile PHP — Potential Integration Partners

These projects embed PHP runtimes on iOS and Android. Qbix Server's `--pack` binary + their native shells = full-stack PHP apps on every platform.

| Name | Project | Why they care | Twitter/X | Contact |
|---|---|---|---|---|
| **Marcel Pociot** | NativePHP (desktop + mobile), Laravel Herd, Beyond Code | Built NativePHP which embeds PHP in Swift/Kotlin shells for App Store distribution. Qbix Server's `--pack` binary is the server-side complement — same architecture (embedded PHP + SQLite + persistent mode). Integration would give NativePHP apps a built-in web server, control panel, and WebSocket. | @marcelpociot | marcel@beyondco.de · beyondco.de |
| **Simon Hamp** | NativePHP co-creator | Co-leads NativePHP with Marcel. Focuses on the developer experience and Blade-to-native UI bridge. | @simonhamp | Via GitHub nativephp/nativephp |
| **Steven Rojas** | Phphone (phphone.xyz) | New project (September 2026) embedding PHP 8.4 on Android via JNI and iOS via Swift bridge, with local SQLite. Lighter than NativePHP, more DIY. Would be interested in Qbix Server as the app backbone. | Via dev.to @steven_rojas | phphone.xyz |
| **Dmi3yy** | php-ios (SwiftPM wrapper) | Built a static PHP runtime as a Swift Package for iOS. App Store compliant — all code bundled, no runtime downloads. Qbix Server's packed binary fits this model exactly. | Via GitHub Dmi3yy/php-ios | GitHub |

## Hosting & Control Panel Companies

| Company | Why they care | Contact path |
|---|---|---|
| **WebPros** (owns cPanel + Plesk + WHMCS) | 815K+ server licenses, 85M+ websites. A faster PHP backend as a single binary simplifies their stack. | webpros.com — via LinkedIn or partner program |
| **cPanel** | Their middleware is Apache/nginx + php-fpm. Replacing that with a single binary simplifies their architecture. | cpanel.net — via developer/partner program |
| **Plesk** | European market leader. Same argument as cPanel. | plesk.com — via partner program |
| **CloudLinux** | Makes the OS most shared hosts run. PHP performance is their selling point. LiteSpeed is their web server — Qbix Server is an alternative angle. | cloudlinux.com |
| **Softaculous** | Auto-installer bundled with cPanel. If Qbix Server becomes a thing, they'd add a one-click installer. | softaculous.com |
| **Hostinger** | Largest commodity host globally. PHP performance = competitive differentiation. | Via hostinger.com partnerships |
| **DigitalOcean** | App Platform could offer Qbix Server as a PHP runtime. The "one binary on a $5 droplet" story is their brand. | Via DO partnerships or community |
| **Vultr / Linode** | VPS providers where the "$5 VPS" story plays best. | Via marketplace programs |
| **Cloudways** | Managed PHP hosting. Would evaluate as a backend alternative to their nginx+fpm stack. | cloudways.com |
| **Laravel Forge** | Taylor Otwell's server management tool. Currently provisions nginx+fpm. Could add a Qbix Server option. | Via Taylor Otwell or forge.laravel.com |

## Competitor/Adjacent Project Leads

Reaching these people positions Qbix Server in the comparison landscape. Even if they don't adopt it, their engagement creates visibility.

| Name | Project | Why reach out | Twitter/X | Contact |
|---|---|---|---|---|
| **Wolfy-J (Valery Wolkov)** | RoadRunner (Go-based PHP app server) | RoadRunner is the closest competitor architecture. Worker pools, persistent processes, gRPC. Qbix Server's pure-PHP approach with no Go dependency is the differentiator. | @wolaborj | Via GitHub roadrunner-server/roadrunner |
| **Han Xiao** | Swoole maintainer | Swoole is the C-extension async PHP engine. 18K+ GitHub stars. Highest raw performance. Qbix Server's pitch: no C extension install, works on any PHP 8 host. | Via GitHub swoole/swoole-src | swoole.com |
| **Matt Holt** | Caddy web server creator | FrankenPHP is built on Caddy. Matt would be interested in the "pure PHP, no reverse proxy" alternative thesis. | @maborholt | Via GitHub caddyserver/caddy |
| **Adam Wathan** | Tailwind CSS creator, Laravel ecosystem | Massive following (300K+). If he mentions any PHP tooling it gets attention. Unlikely to deeply engage but a RT goes far. | @adamwathan | Via Twitter |

## Forums & Communities

| Platform | Where | Strategy |
|---|---|---|
| **Hacker News** | news.ycombinator.com | Post as "Show HN: Pure PHP webserver that replaces nginx+php-fpm — 100× faster cold starts, built-in WebSocket." HN loves "I replaced X with Y" posts. Lead with the benchmark numbers. |
| **r/PHP** | reddit.com/r/PHP (~210K members) | The primary PHP subreddit. Post the README intro with benchmark table. The "no nginx, no php-fpm, no Node.js" pitch resonates here. Expect FrankenPHP/Swoole/RoadRunner comparisons. |
| **r/laravel** | reddit.com/r/laravel (~110K) | Laravel developers already know Octane. Pitch: "Like Octane but pure PHP — no RoadRunner, no Swoole install. Drop-in." |
| **r/selfhosted** | reddit.com/r/selfhosted (~400K) | "Run your PHP app with a single command, no nginx config, no docker-compose, SQLite included." This sub loves single-binary deployments. |
| **r/webdev** | reddit.com/r/webdev (~2.3M) | Broader audience. Lead with the "replace your entire LEMP stack with one PHP command" angle. |
| **PHP community on Mastodon** | phpc.social | The PHP community Mastodon instance. Many PHP core devs are here (Derick, James Titcumb, etc.). Post and tag relevant people. |
| **PHP on Discord** | The PHP Community Discord (discord.gg/phpc) | Active real-time discussion. Good for getting fast feedback and early adopters. |
| **Lobsters** | lobste.rs | HN alternative with invite-only quality. Tag with `php`, `performance`, `web`. More technical audience, less hostile than HN comments. |
| **dev.to** | dev.to | Write a tutorial-style post: "How I replaced nginx + php-fpm with a single PHP file." dev.to has strong PHP readership. |
| **PHP Architect** | phparch.com | The PHP trade magazine. Pitch an article about the COW fork model and snapshot reset. Contact via their submissions page. |
| **PHP Weekly** | phpweekly.com | Newsletter that curates PHP content. Submit the GitHub repo or a blog post about it. |
| **PHP Annotated** | JetBrains blog | Monthly PHP roundup by JetBrains. If you get the repo noticed, they'll include it. |

## Podcasts

| Podcast | Host | Why | Contact |
|---|---|---|---|
| **PHP Internals News** | Derick Rethans | THE technical PHP podcast. Perfect venue for discussing snapshot restore, COW memory, and the event loop architecture. | derick@phpinternals.news |
| **Voices of the ElePHPant** | Cal Evans | Community-focused PHP podcast. Good for the "why I built this" story. | cal@calevans.com |
| **Laravel News Podcast** | Jake Bennett, Michael Dyrynda | Laravel audience = Octane users = direct comparison opportunity. | Via laravelnews.com |
| **Latent Space** | swyx, Alessio Fanelli | AI + developer tools. The "built with Claude" angle + the WASM/browser vision story. | swyx@swyx.io |
| **PHP Ugly** | Eric Van Johnson, Thomas Rideout | Opinionated PHP podcast. They'd debate the "do we need another PHP server" question loudly. | Via phpugly.com |
| **No Priors** | Elad Gil, Sarah Guo | AI investor/builder podcast. The broader story: AI-assisted solo founder ships production PHP server with 161 tests. | Via show site |

## Pitch Angles by Audience

**To PHP developers (r/PHP, HN, forums):**
> Pure PHP webserver. No nginx. No php-fpm. No Go. No C extensions. One `php qbixserver.php` command. COW-forked workers at 120KB each. On PHP 8.6: native epoll via Io\Poll, sendfile for static files matching nginx throughput. Built-in WebSocket, control panel, metrics, binary signing. 71 tests. MIT license.

**To Laravel/Symfony developers:**
> Like Laravel Octane but without installing RoadRunner or Swoole. Pure PHP, same persistent-worker model, works on any PHP 8 host. `--pack` bundles your app into a single binary with SQLite auto-provisioned. Built-in WebSocket so you don't need Pusher or Soketi.

**To PHP 8.6 / PHP Foundation audience:**
> First webserver to ship with native Io\Poll (epoll/kqueue) and sendfile/splice support. Pure PHP, zero extensions. On 8.6, static file serving matches nginx kernel paths. Validates the Foundation's investment in stream modernization.

**To DevOps / self-hosted:**
> Replace your nginx.conf + php-fpm.conf + supervisord.conf + node server.js with a single command. One process, one port, zero config files. Ships as a 5MB binary. Built-in ACME certs, metrics with Prometheus endpoint, log rotation, worker recycling, anomaly webhooks.

**To security-minded audience:**
> ECDSA M-of-N binary signing with Sigstore Rekor transparency log. `/Q/attestation` endpoint serves hash + signatures + Rekor reference. Mesh identity is sha256(public_key) — Ethereum-grade. All peer-to-peer traffic encrypted end-to-end with AES-256-GCM via ECDH key agreement.

**To mobile PHP developers (NativePHP, Phphone audience):**
> Qbix Server 2.0 runs on iOS and Android with automatic peer-to-peer networking. Phones discover each other over Bluetooth and Wi-Fi, exchange encrypted HTTP requests, and sync data — no internet required. Transport priority: TCP > MultipeerConnectivity > BLE, with auto-fallback. A classroom of phones running PHP apps, syncing peer-to-peer. App Store compliant (PocketServer, BitChat precedent).

**To the mesh / P2P / local-first audience:**
> First PHP server with encrypted peer-to-peer mesh. Distance-vector routing, multi-hop relay, Bloom filter sync, prolly tree diffing for large datasets. More sophisticated than BitChat (which only floods short messages) — this does routed encrypted HTTP with data synchronization. 464 tests across the mesh stack.

**To the "built with AI" audience:**
> Solo developer built a production PHP server with an encrypted mesh network — identity, handshake, routing, sync, BLE transport, iOS + Android — in a series of Claude conversations. 18,000+ lines of PHP, 1,500 lines of Swift/Kotlin. 464 tests, 0 failures. Competes with FrankenPHP on benchmarks without any Go, Rust, or C.

---

## Priority Order

1. **r/PHP + Hacker News** — highest leverage, free, immediate. The mesh angle is a new category — "PHP server with Bluetooth mesh" has zero competition. Post the same week.
2. **Marcel Pociot / NativePHP** — the mobile PHP story is directly relevant to his community.
3. **Derick Rethans** — podcast interview legitimizes the project technically.
4. **Kévin Dunglas** — engagement from the FrankenPHP creator creates credibility whether positive or critical.
5. **Brent Roose / stitcher.io** — a blog mention reaches the PHP mainstream.
6. **Laravel News** — the Octane comparison angle reaches the largest PHP framework community.
7. **PHP Weekly + PHP Annotated** — newsletter inclusion is free sustained traffic.
