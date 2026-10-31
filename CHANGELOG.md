# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org/).

## [Unreleased]

## [0.3.1] - 2026-10-31

### Changed

- "Board as start page" now covers the whole start page (`scp/index.php`): the logo and the "Agent Panel" link open the board too, not only the login. The decision moved to the end of the request, after osTicket has checked the agent, so an expired session, a pending password change or 2FA setup always get osTicket's own response. The login mark from 0.3.0 is gone.

## [0.3.0] - 2026-10-31

### Added

- Board as the agents' start page after login, setting "Board as start page", on by default. A login from a link keeps its target, a pending password change or 2FA step comes first, and the Tickets tab is unchanged.
- Settings "Next ticket after changing to" (open statuses that move on like closing does) and "Next ticket from these columns" (work-through columns such as triage: the quick view moves to the next card of the same column and closes when it is empty), with "Actions that move on in these columns" to count assign, transfer, priority or mark answered as well as status changes.

## [0.2.0] - 2026-10-31

### Added

- Quick-view actions run directly: status, claim, release and mark answered in one click; assign to agent or team, transfer and priority through pickers in the panel. New endpoint `/board/act` with the permission checks of the core dialogs. Refer and add task still open osTicket's dialogs.
- Text in the quick view's note field is saved as an internal note with whichever action runs next.
- "Undo" in the toast after a status change from the quick view.
- Closing a ticket from the quick view opens the next open card on the board instead of the closed one: further down the same column, then the next open column; the panel closes when no open card is left.
- Setting "Next ticket after closing", on by default.

### Changed

- The quick view no longer reloads the page after an action; it refreshes the board in place. The note field sits above the actions.

## [0.1.0] - 2026-10-31

### Added

- Agent board under Applications → Board: one column per open and closed status, cards with requester, department, assignee, last update and overdue flag.
- Status change by drag and drop or `Alt+←` / `Alt+→`, with the permission checks of the core status dialog and a confirmation before closing.
- Queues and saved searches of the agent panel as board filters: picker above the board, same access check and visibility rules as the ticket list, choice remembered per session.
- Quick view on the agent board: osTicket's ticket preview with the actions the agent's role allows (status, assign, claim, release, transfer, refer, mark answered, priority, add task), each opening the core dialog; internal note as plain text via `Ticket::postNote()`.
- Read-only client board in the portal (off by default), limited to the tickets the portal's own ticket list shows.
- Sub-tickets from `ticket_pid`, folded under their top-most visible parent, each with its own status.
- Portal navigation entry via output buffer, without core patches.
- Settings: agent board, client board, navigation link, closed-ticket window, card limit.
- Due and priority tags on agent cards (overdue, today, tomorrow; High and Emergency).
- Sort within columns by last update, due date, priority, newest or number.
- Swimlanes by organisation, assignee or department, with shared column totals.
- Optional column limits per status, marked when exceeded.
- Live refresh: the agent board picks up changes made elsewhere, quietly when idle, behind a button when busy.
- Colours and radii as CSS custom properties for themes.
- Visual design: hierarchy from type, not coloured borders; one status dot per column; quick view as a translucent panel that slides in and out on the same path; toast and an on-card confirmation after a move; press feedback; `prefers-reduced-motion`, `prefers-reduced-transparency` and `prefers-contrast` variants.
- English and German UI strings.
- Local test stack (osTicket 1.18.4, MariaDB, Mailpit) and Playwright end-to-end suite.

[Unreleased]: https://github.com/studio-prisma/osticket-plugin-board/compare/v0.3.1...HEAD
[0.3.1]: https://github.com/studio-prisma/osticket-plugin-board/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/studio-prisma/osticket-plugin-board/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/studio-prisma/osticket-plugin-board/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/studio-prisma/osticket-plugin-board/releases/tag/v0.1.0
