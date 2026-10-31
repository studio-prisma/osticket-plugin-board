# Ticket Board for osTicket

[![Validate](https://github.com/studio-prisma/osticket-plugin-board/actions/workflows/validate.yml/badge.svg)](https://github.com/studio-prisma/osticket-plugin-board/actions/workflows/validate.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE)

A Kanban-style board for osTicket. One column per ticket status, sub-tickets folded under their parent. Agents drag cards to change the status. Clients get a read-only board of the tickets they can already see in the portal.

**[Deutsche Fassung →](README.de.md)**

---

## What it does

| | Agent panel | Client portal |
|---|---|---|
| Where | Applications → Board (`/scp/ajax.php/board`), and the start page after login (setting) | "Board" in the portal navigation (`/ajax.php/board`) |
| Which tickets | Exactly what the agent's departments, assignments and referrals allow (`Staff::getTicketsVisibility()`, same as the queues) | Own tickets, organisation tickets if the organisation shares them, collaborations if enabled (same three sources as the portal's ticket list) |
| Filter | Any queue or saved search of the agent panel, picked above the board, remembered per session | No |
| Change status | Drag and drop, or `Alt+←` / `Alt+→` on a focused card. Closing asks first | No |
| Quick view | Click or `Enter` on a card: osTicket's ticket preview plus the actions the agent's role allows. Closing a ticket from it moves straight on to the next open card; work-through columns such as a triage status move on within the column (settings) | No |
| Actions | One click for status, claim, release, mark answered. Pickers in the panel for agent, team, department and priority. Refer and add task open osTicket's dialogs. A status change can be undone from the toast | No |
| Internal note | From the quick view, as plain text. Text in the note field is saved with whichever action you click next | No |
| Due and priority | Tags for overdue, due today, due tomorrow, and High/Emergency priority | No |
| Sort | Last updated, due date, priority, newest, number; remembered per session | No |
| Group | Rows per organisation, assignee (or team) or department, column heads with totals on top | No |
| Column limits | Optional per status; a column over its limit is marked | No |
| Live refresh | Changes made elsewhere appear by themselves while you're idle, or behind a "Show updates" button while you're working | No |
| Shown per card | Number, subject, requester, department, assignee, last update, overdue flag | Number, subject, department, last update |
| Sub-tickets | Folded list under the parent, each with its own status | Same, limited to sub-tickets the client may see |

**Filters are osTicket's own.** The board has no filter system of its own. The "View" picker lists the queues and saved searches the agent sees under Tickets, with the same access check (public queues, own private searches, never another agent's). Filters are created and saved where they always are: Tickets, then Search, then Save. The board applies their criteria and the same visibility rules as the ticket list. Sub-tickets the queue hides as rows still show up folded under their parent, limited to what the agent may see.

**Actions run through osTicket's own code.** The quick view only offers what the ticket view would offer the same agent. Status, claim, release, mark answered, assign, transfer and priority run on click through `/board/act`, which checks the same role permissions as the core dialog and then calls the same core methods with the same forms (`setStatus()`, `claim()`, `release()`, `markAnswered()`, `assign()`, `transfer()`, `updateField()`). Pickers only list what the core dialog would list, and the endpoint refuses anything else. Alerts and the ticket history come from osTicket. Refer and add task still open the core dialogs. Notes end in the core's `Ticket::postNote()` and are stored as plain text.

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
| Board as start page | on | After logging in, agents land on the board instead of the ticket list. A login from a link (e.g. a ticket in an alert mail) still opens that link; a pending password change or 2FA step comes first. The Tickets tab is unchanged |
| Client board | **off** | Read-only board in the portal. Off means the route answers 404 |
| Client navigation link | on | "Board" in the portal navigation. Off: reachable by URL only |
| Closed tickets, days | 14 | In the default view, closed columns show tickets closed within this window. `0` hides closed columns. With a queue or search selected, its criteria decide instead |
| Column limits | empty | Status name and limit, e.g. `In Progress=5; Open=20`. Marked only, nothing is blocked |
| Next ticket after closing | On | After closing a ticket from the quick view, the panel opens the next open card. Off: it stays on the closed ticket |
| Next ticket after changing to | none | Open statuses that, picked in the quick view, also move on to the next open card, wherever the ticket came from |
| Next ticket from these columns | none | Work-through columns, e.g. a triage status. An action on a ticket in one of them opens the next card of the same column; when the column is empty, the quick view closes. Takes precedence over the two settings above |
| Actions that move on in these columns | status change | Which actions count in those columns: status change, assign/claim/release, transfer, priority, mark answered |
| Live refresh, seconds | 60 | How often the agent board checks for changes. `0` switches it off; otherwise 15 to 600 |
| Maximum cards per board | 300 | Tickets loaded per page view, newest activity first (10 to 2000) |

Colours and radii are CSS custom properties on `.spb-wrap`. Override them from your own stylesheet:

```css
.spb-wrap { --spb-accent: #330066; --spb-radius: 8px; }
```

All properties: top of [assets/board.css](assets/board.css).

The board follows the system settings for reduced motion (the quick view fades instead of sliding), reduced transparency (solid panel) and increased contrast.

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
- **A moved card stays until reload.** If a status change takes a ticket out of the selected queue (say, closing it in "Open"), the card stays in its new column until the page is reloaded.
- **Search criteria osTicket doesn't support are ignored**, on the board as in the ticket list. That's osTicket's behaviour for saved searches, not a filter of the plugin.
- **No reply to the customer from the board.** Replies stay in the ticket view (the quick view links to it). Mark as overdue, merge, link and the full edit form aren't offered either; they're one click away in the ticket view.
- **Direct actions skip the core dialog's comment field.** A status change from the quick view has no comment of its own; write it in the note field first and it is saved as an internal note with the change. The reply to the customer stays in the ticket view.
- **After an action the quick view stays on the ticket**, or, after closing it and with "Next ticket after closing" on, moves to the next open card in board order (further down the column, then the next open column). With no open card left, the panel closes. Undo sets the previous status again; it doesn't remove a note saved with the change.
- **Notes from the board skip the ticket lock.** osTicket's lock only guards the ticket view's own forms against double posting; the note itself goes through the same `Ticket::postNote()`.
- **Rows don't change ownership.** In a grouped view a card moves between statuses within its row; dropping it into another organisation's or agent's row does nothing. Reassign through the quick view.
- **Due tags are for what needs attention:** overdue, today, tomorrow. A due date days away (often just the default SLA) gets no tag; "Sort: Due date" still orders by it.
- **Live refresh reloads the whole board** in the background when something changed, and it pauses in background tabs. With many agents and short intervals that's one extra page render per agent per change; 60 seconds is the default for a reason.
- **Dragging changes the status only.** Assignment, priority and the rest go through the quick view. The order of cards within a column isn't stored.
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
