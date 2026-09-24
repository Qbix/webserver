# Framework Compatibility

This file has moved to [docs/compatibility.md](docs/compatibility.md), which explains how Qbix Server runs Laravel, Symfony, WordPress, Drupal, Joomla and Magento unmodified: which 27 PHP functions it rewrites as files are loaded, why the CLI SAPI makes that necessary, how the rewritten source is cached, how `.htaccess` and presets route requests, and which code the rewriter cannot reach.

SAPI emulation, `--app` mode and the test suites are in [docs/app-mode.md](docs/app-mode.md).
