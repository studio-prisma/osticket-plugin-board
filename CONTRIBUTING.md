# Contributing

Thanks for taking the time. This plugin decides which helpdesk tickets a customer gets to see, so the bar for changes is verification, not volume.

## Before you open a pull request

1. **Open an issue first** for anything beyond a typo or a documentation fix. Discussing an approach is cheaper than reviewing a rewrite.
2. **Visibility changes need a threat-model note.** If your change touches `BoardQuery`, `BoardController`, the routes or the renderer, describe in the PR who can now see or change what they couldn't before, and why that's acceptable.
3. **Never report a vulnerability as a pull request.** See [SECURITY.md](SECURITY.md).

## Development setup

No build step for the plugin and no PHP dependencies.

```bash
git clone https://github.com/studio-prisma/osticket-plugin-board.git
cd osticket-plugin-board
php tests/unit.php
```

For the end-to-end suite you need Docker and Node.js 20 or newer:

```bash
./dev/up.sh                         # osTicket 1.18.4 + MariaDB + Mailpit on 127.0.0.1
cd tests/e2e && npm ci && npx playwright install chromium && npx playwright test
./dev/down.sh
```

Or in one go, ending in a browser to test by hand: `./dev/try.sh`. `dev/README.md` explains the stack and how to mount a theme or other plugins for a local run.

To build the install package:

```bash
./tools/build-zip.sh                # writes dist/board-<version>.zip
```

## What a change has to pass

| Check | Command |
|---|---|
| Syntax, all production files | `find . -name '*.php' -not -path './tests/*' -not -path './dev/*' -exec php -l {} \;` |
| JavaScript syntax | `node --check assets/board.js` |
| Unit tests | `php tests/unit.php` |
| End-to-end | `npx playwright test` in `tests/e2e/` against `dev/up.sh` |

CI runs syntax and unit tests on PHP 8.0 to 8.4, plus the full end-to-end suite, on every push and pull request. A red pipeline isn't mergeable.

## Code conventions

- **PHP 8.0 compatible.** No `match`, no enums, no readonly properties, no named arguments. CI lints on 8.0.
- **No runtime dependencies.** No Composer packages, no JavaScript library, nothing from a CDN. The board loads two files of its own.
- **Visibility comes from osTicket.** Agent queries go through `Staff::getTicketsVisibility()`. Client queries mirror `include/client/tickets.inc.php`. Don't write a third rule.
- **One escaping point.** Ticket data reaches HTML only through `BoardRenderer::e()`. The JavaScript moves existing nodes and sets `textContent`; it never builds markup from data.
- **No hardcoded hostnames, credentials, absolute paths or customer data**, tests included. The test stack generates its passwords at runtime.
- **Every new setting** needs a clamp in `BoardController::normalise()`, a unit test, and a row in the configuration table of both READMEs.

## Documentation

User-facing changes need updates in all four documents that describe behaviour:

- `README.md` and `README.de.md`
- `docs/INSTALL.md` and `docs/INSTALL.de.md`

If you're not comfortable writing the German version, write the English one and say so in the PR. The translation gets done during review.

Add a `CHANGELOG.md` entry under `## [Unreleased]` using the Keep a Changelog categories (`Added`, `Changed`, `Fixed`, `Removed`, `Security`).

## Commit messages

Conventional Commits, imperative mood:

```
fix(query): drop children whose parent is outside the client's organisation
feat(board): add a filter by department
docs(install): add the Nginx rewrite
ci: pin actions to major versions
```

## Release process

Maintainers only:

1. Move the `## [Unreleased]` entries into a new `## [x.y.z] - YYYY-MM-DD` section.
2. Bump `'version'` in `plugin.php` and `BoardPlugin::VERSION` to the same number. CI fails the release if tag, manifest and constant disagree.
3. Commit, then tag: `git tag -a vx.y.z -m "vx.y.z"` and push the tag.
4. The `Release` workflow builds `dist/board-x.y.z.zip`, attests it, extracts the changelog section and publishes the GitHub release.

## License

By contributing you agree that your contribution is licensed under the [GNU General Public License, version 2 or later](LICENSE).
