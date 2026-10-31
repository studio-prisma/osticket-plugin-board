# Ticket Board for osTicket

[![Validate](https://github.com/studio-prisma/osticket-plugin-board/actions/workflows/validate.yml/badge.svg)](https://github.com/studio-prisma/osticket-plugin-board/actions/workflows/validate.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE)

A Kanban-style board for osTicket. One column per ticket status, sub-tickets folded under their parent. Agents drag cards to change the status. Clients get a read-only board of the tickets they can already see in the portal.

**[Deutsche Fassung →](README.de.md)**

---

## What it does

| | Agent panel | Client portal |
|---|---|---|
| Where | Applications → Board (`/scp/ajax.php/board`) | "Board" in the portal navigation (`/ajax.php/board`) |
| Which tickets | Exactly what the agent's departments, assignments and referrals allow (`Staff::getTicketsVisibility()`, same as the queues) | Own tickets, organisation tickets if the organisation shares them, collaborations if enabled (same three sources as the portal's ticket list) |
| Change status | Drag and drop, or `Alt+←` / `Alt+→` on a focused card. Closing asks first | No |
| Shown per card | Number, subject, requester, department, assignee, last update, overdue flag | Number, subject, department, last update |
| Sub-tickets | Folded list under the parent, each with its own status | Same, limited to sub-tickets the client may see |

Sub-tickets are read from `ost_ticket.ticket_pid`. That's the column osTicket's merge feature and the common sub-ticket plugins write. No thread entries are loaded, so internal notes can't reach either board.

No core file is patched and no file is copied outside the plugin folder. The plugin uses hooks osTicket 1.18 has: `Application::registerStaffApp()` for the menu entry, the `ajax.scp` and `ajax.client` signals for its routes. The portal navigation entry goes in through an output buffer, because the portal navigation doesn't render registered client apps.

Why `ajax.php` and not osTicket's `apps/` folder: in 1.18.4 neither app dispatcher can be reached. `apps/dispatcher.php` dies with a fatal error (it requires `client.inc.php` by a path that doesn't resolve from `apps/`), and `scp/apps/dispatcher.php` refuses direct calls and expects an `scp/apps.php` the release doesn't ship.

---

## Requirements

- osTicket **1.18.x** (tested: 1.18.4). Plugin instances, as introduced in 1.17, are required.
- PHP **8.0 to 8.4** (syntax-checked on all five; the end-to-end run uses 8.3)
- No web server changes. The board runs on the same `ajax.php` paths osTicket's own pages already call, so whatever makes osTicket work (Apache, or Nginx with `PATH_INFO`) is enough.

---

## Install

Full guide: **[docs/INSTALL.md](docs/INSTALL.md)** ([deutsch](docs/INSTALL.de.md)).

Short version:

1. Download `board-<version>.zip` from the [latest release](https://github.com/studio-prisma/osticket-plugin-board/releases/latest) and unzip it into `include/plugins/`, so you get `include/plugins/board/plugin.php`.
2. Admin Panel → Manage → Plugins → Add New Plugin → **Ticket Board** → Install.
3. Open the plugin, add an instance, enable it. Agent board on, client board off (default).
4. Run the smoke test in INSTALL.md. Only then switch on the client board.

---

## Configuration

| Setting | Default | Purpose |
|---|---|---|
| Agent board | on | Entry under Applications, routes under `/scp/ajax.php/board` |
| Client board | **off** | Read-only board in the portal. Off means the route answers 404 |
| Client navigation link | on | "Board" in the portal navigation. Off: reachable by URL only |
| Closed tickets, days | 14 | Closed columns show tickets closed within this window. `0` hides closed columns |
| Maximum cards per board | 300 | Tickets loaded per page view, newest activity first (10 to 2000) |

Colours and radii are CSS custom properties on `.spb-wrap`. Override them from your own stylesheet:

```css
.spb-wrap { --spb-accent: #330066; --spb-radius: 2px; }
```

All properties: top of [assets/board.css](assets/board.css).

---

## Testing

```bash
php tests/unit.php              # no osTicket needed
./dev/up.sh                     # local osTicket 1.18.4 in Docker, seeded
cd tests/e2e && npm ci && npx playwright test
./dev/down.sh
```

The unit tests cover grouping, nesting, escaping, the navigation injection and the config clamps. The end-to-end suite runs against a real osTicket with three customers in two organisations and two agents with different roles. It checks what each of them sees, drag and drop, CSRF, permission refusals, and that markup in a subject stays text. Details: [tests/README.md](tests/README.md).

Mail: a status change from the board goes through the same `Ticket::setStatus()` as the core status dialog, without a comment. In the end-to-end run with osTicket's default alert settings, closing a ticket sent no mail to the requester (Mailpit, 0 before, 0 after). Your alert settings decide; the plugin adds no mail of its own.

Not verified: osTicket 1.17, Nginx, MySQL instead of MariaDB, other portal themes than the stock one plus one custom theme.

---

## Known limitations

- **One page, no paging.** The board shows at most "Maximum cards" tickets, newest activity first. A notice appears when tickets were left out.
- **Status only.** Dragging changes the status. Assignment, priority, department and order within a column aren't changed from the board.
- **Children don't follow.** Moving a parent card changes the parent only. Sub-tickets keep their status.
- **One level shown.** Deeper hierarchies are flattened under the top-most visible ancestor.
- **Navigation entry depends on the portal markup.** It's placed after the last item of `<ul id="nav">`. A theme that replaces the header template without that list gets no link; the board stays reachable at `/ajax.php/board`.
- **Guest sessions** (ticket access links) don't get a board. They're bound to one ticket.
- **Not for osTicket 2.0** as long as 2.0 changes the plugin format.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see [SECURITY.md](SECURITY.md), never a public issue.

## License

[GPL-2.0-or-later](LICENSE) © studio-prisma. The plugin extends osTicket's GPL classes, so it carries osTicket's licence.
