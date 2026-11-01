# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org/).

## [Unreleased]

## [0.5.0] - 2026-11-01

### Added

- The quick view lists the ticket's tasks above the preview: number (opens the task), title, assignee, due date with overdue marked, and status. Open tasks come first, earliest due date first; completed ones are folded below. A ticket without tasks says "No tasks.". Agent board only, never on the client board.
- Agent cards with open tasks carry a badge with their number, also on closed tickets.
- Both list and badge include a task only if `Task::checkStaffPerm()` lets the agent open it, on top of the ticket visibility the quick view already checks.

## [0.4.0] - 2026-11-01

### Added

- Custom properties for the colours that were still fixed, so a dark theme can restyle the whole board: `--spb-drawer-bg` and `--spb-drawer-solid` (quick view surface, translucent and for reduced transparency), `--spb-fill` and `--spb-fill-strong` (column count, close button), `--spb-accent-fill` (accent buttons with white text, defaults to `--spb-accent`) and `--spb-soon` ("due today/tomorrow" tag). The quick view's action panel now uses `--spb-col-bg`. Defaults look as before.

- Setting "Status abbreviations" (off by default): the variable name of a property of the Ticket Status list. Agent board column heads then show the status's value for that property instead of its name; the full name is the tooltip and stays the heading's text for screen readers. Statuses without a value keep their name. The client board always shows full names.

### Fixed

- Statuses disabled in the status list (Admin Panel, Lists, Ticket Status) no longer show up as empty columns, as quick-view status buttons or in the settings' status pickers. A disabled status keeps its column, marked, while tickets still sit in it, so they don't drop off the board; cards can be moved out of it but not into it. The move and action endpoints refuse a disabled status, as osTicket's own status menu doesn't offer one.

## [0.3.3] - 2026-10-31

### Added

- The quick view lists a ticket's sub-tickets under the thread: number, subject, assignee and status. The subject opens the sub-ticket in the quick view, the number its ticket page. Only sub-tickets the agent may see are listed, at most 50.

## [0.3.2] - 2026-10-31

### Changed

- The quick view shows the ticket summary and the recent thread (osTicket's last six entries) on one page instead of behind tabs. Older entries come folded and open on click, as in osTicket's own preview.

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

[Unreleased]: https://github.com/studio-prisma/osticket-plugin-board/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/studio-prisma/osticket-plugin-board/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/studio-prisma/osticket-plugin-board/compare/v0.3.3...v0.4.0
[0.3.3]: https://github.com/studio-prisma/osticket-plugin-board/compare/v0.3.2...v0.3.3
[0.3.2]: https://github.com/studio-prisma/osticket-plugin-board/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/studio-prisma/osticket-plugin-board/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/studio-prisma/osticket-plugin-board/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/studio-prisma/osticket-plugin-board/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/studio-prisma/osticket-plugin-board/releases/tag/v0.1.0
