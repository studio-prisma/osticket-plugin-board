# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-10-31

### Added

- Agent board under Applications → Board: one column per open and closed status, cards with requester, department, assignee, last update and overdue flag.
- Status change by drag and drop or `Alt+←` / `Alt+→`, with the permission checks of the core status dialog and a confirmation before closing.
- Read-only client board in the portal (off by default), limited to the tickets the portal's own ticket list shows.
- Sub-tickets from `ticket_pid`, folded under their top-most visible parent, each with its own status.
- Portal navigation entry via output buffer, without core patches.
- Settings: agent board, client board, navigation link, closed-ticket window, card limit.
- Colours and radii as CSS custom properties for themes.
- English and German UI strings.
- Local test stack (osTicket 1.18.4, MariaDB, Mailpit) and Playwright end-to-end suite.

[Unreleased]: https://github.com/studio-prisma/osticket-plugin-board/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/studio-prisma/osticket-plugin-board/releases/tag/v0.1.0
