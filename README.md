# Bunnify Frontend

> Lightweight, frontend-only BunnyCDN image delivery for WordPress.

This repository is the canonical source for the **Bunnify Frontend** WordPress
plugin. The git root holds project documentation and the development toolchain;
the installable plugin itself lives in [`bunnify-frontend/`](bunnify-frontend/)
and is the only thing shipped to WordPress.org.

## What the plugin does

Bunnify Frontend rewrites the URLs WordPress emits for media so images are
served from your [BunnyCDN](https://bunny.net/) pull zone, with width/height and
other transforms appended as query parameters. It does **not** upload, sync, or
manage files on the CDN — it assumes uploads already live in
`wp-content/uploads/` and that Bunny is configured as a pull zone.

See [`bunnify-frontend/readme.txt`](bunnify-frontend/readme.txt) for the
user-facing description and the [wiki](docs/) for developer documentation.

## Repository layout

```
.
├── bunnify-frontend/        # ← the installable plugin (ships to wordpress.org)
│   ├── bunnify-frontend.php # entry file
│   ├── readme.txt           # wordpress.org readme (source of truth for versions)
│   ├── uninstall.php
│   ├── LICENSE
│   ├── .distignore          # files excluded from the distributed zip
│   ├── autoload.php         # hand-written runtime PSR-4 loader (ships)
│   └── src/php/             # Base framework, Controllers, Library, Model
├── docs/                    # project wiki + blueprints (not shipped)
│   └── blueprints/          # design docs & roadmap
├── tests/                   # PHPUnit unit tests (Brain Monkey)
├── bin/build.sh             # assembles dist/bunnify-frontend.zip
├── .github/workflows/       # CI + wordpress.org deploy
├── composer.json            # dev toolchain (never shipped)
├── phpcs.xml.dist           # WordPress Coding Standards ruleset
├── phpstan.neon.dist        # static analysis (level 5 + baseline)
└── phpunit.xml.dist
```

## Development quickstart

Requires PHP 8.2+ and Composer.

```bash
composer install     # install dev tooling
composer check       # lint + static analysis + tests (the CI gate)

composer lint        # PHPCS (WordPress Coding Standards)
composer lint:fix    # PHPCBF auto-fix
composer analyse     # PHPStan
composer test        # PHPUnit
composer build       # build dist/bunnify-frontend.zip
```

See [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) for details.

## Documentation

The [`docs/`](docs/) folder is the project wiki:

- [Architecture](docs/ARCHITECTURE.md) — how the plugin is put together
- [Development](docs/DEVELOPMENT.md) — local setup, tooling, tests
- [Packaging & Releases](docs/PACKAGING.md) — building and shipping to wordpress.org
- [Hooks & Filters](docs/HOOKS.md) · [Image Processing Flow](docs/IMAGE-PROCESSING-FLOW.md) · [Logging](docs/LOGGING.md) · [Troubleshooting](docs/TROUBLESHOOTING.md)
- [Blueprints](docs/blueprints/) — planned work and larger design decisions

## Contributing

Please read [`CONTRIBUTING.md`](CONTRIBUTING.md) and the
[Code of Conduct](CODE_OF_CONDUCT.md). Security issues: see
[`SECURITY.md`](SECURITY.md).

## License

[GPL-2.0-or-later](LICENSE).
