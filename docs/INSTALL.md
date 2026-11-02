# Install: Ticket Board for osTicket

Order matters. Every step ends with a check; if it fails, stop there.

**[Deutsche Fassung →](INSTALL.de.md)**

---

## 0. Before you start

You need:

- osTicket 1.18.x with admin access to the Admin Panel
- write access to `include/plugins/` on the server
- a test agent account **without** admin rights, and a test client account in an organisation. The smoke test uses both.

**Kill switch, before anything else.** If the board causes trouble, either

- Admin Panel → Manage → Plugins → Ticket Board → uncheck the instance → Save, or
- delete the folder `include/plugins/board/` on the server.

Both remove every route and the navigation entry immediately. The plugin stores no data of its own and changes no table, so there's nothing to clean up.

Stop if: you can't get shell or SFTP access to `include/plugins/`. Without it, the second kill switch isn't available.

---

## 1. Upload

1. Download `board-<version>.zip` from the latest release.
2. Optional: `gh attestation verify board-<version>.zip --repo studio-prisma/osticket-plugin-board`
3. Unzip into `include/plugins/`. Result:

```
include/plugins/board/plugin.php
include/plugins/board/class.BoardPlugin.php
include/plugins/board/src/...
include/plugins/board/assets/board.css
include/plugins/board/assets/board.js
```

Check: `include/plugins/board/plugin.php` exists and is readable by the web server user.

---

## 2. Install and add an instance

1. Admin Panel → Manage → Plugins → **Add New Plugin** → *Ticket Board* → **Install**.
2. Open *Ticket Board* → **Instances** → **Add New Instance**, name it e.g. `default`.
3. Leave **Client board off**. Check the instance as enabled. Save.
4. On the plugin list, make sure the plugin itself is **Enabled**.

Check: the Admin Panel shows no error, and the plugin list shows Ticket Board as active with 1 instance.

Stop if: the plugin doesn't appear under Add New Plugin. Then osTicket can't read `plugin.php` (permissions) or the folder isn't directly under `include/plugins/`.

---

## 3. Reachability

The routes run through osTicket's own `scp/ajax.php` and `ajax.php`. Nothing to configure on the web server.

Check in a browser, logged in as an agent: `https://support.example.com/scp/ajax.php/board` shows the board.

Stop if: you see `URL not supported`. Then the plugin instance isn't enabled, or the agent board is switched off in its settings. Without an agent session the same URL answers `403 Must login`; that's osTicket's AJAX endpoint, not an error.

---

## 4. Smoke test, agent board

As the **non-admin** test agent:

| # | Action | Expected |
|---|---|---|
| 4.0 | Log out, log in again as an agent; then click the Tickets tab, then the logo | Login and logo open the board, the Tickets tab opens the ticket list |
| 4.1 | Agent panel → Applications | Entry "Board" |
| 4.2 | Open it | Columns for every open status and, unless disabled, closed statuses |
| 4.3 | Compare with the agent's ticket queue | Same tickets, no ticket from a department the agent can't access |
| 4.4 | Drag a test ticket to another open status | Message "Ticket #… moved", card stays there after reload, ticket history shows the status change |
| 4.5 | Drag it to a closed status, click Cancel | Nothing changes |
| 4.6 | With a role that may not close tickets: drag to a closed status and confirm | Error message, card stays |
| 4.7 | Pick a queue under "View", e.g. "My Tickets", then one of your saved searches | Same tickets as that queue or search under Tickets; the choice is still set after reload |
| 4.8 | Open `/scp/ajax.php/board?queue=<ID of another agent's private search>` | Notice "not available", full default board, nothing of that search shown |
| 4.9 | Click a card | Quick view with the ticket preview and only the actions the agent's role allows |
| 4.9b | Open the quick view of a ticket with tasks, as an agent without access to the department of one of them | Tasks above the preview; the task from the other department is missing unless it is assigned to the agent or their team, and the card badge counts the same. A ticket without tasks shows "No tasks." |
| 4.10 | In the quick view: Assign to agent, pick someone from the list | No dialog; the quick view shows the new assignee, ticket history lists the assignment |
| 4.12 | Group by organisation, then try to drag a card into another organisation's row | Rows per organisation; the card stays in its own row |
| 4.13 | Change a ticket's status in a second browser window, leave the board alone | Within the refresh interval the board updates and says "Board updated" |
| 4.10c | Optional: put a triage status in "Next ticket from these columns", then change the status of a ticket in that column from the quick view | The quick view opens the next card of the same column; on the last card it closes with "Nothing left in …" |
| 4.10b | In the quick view, write a line in the note field, then click a closed status on a test ticket | No dialog; the ticket is closed, the note is in its thread, the quick view shows the next open card. "Undo" in the toast reopens it |
| 4.11 | Post an internal note with `<b>test</b>` | Note in the ticket view as literal text, not bold; not visible in the client portal |

Stop if: 4.3 shows a ticket the agent can't open in the normal ticket view. Disable the instance and report it (see SECURITY.md).

---

## 5. Switch on the client board

Only after section 4 passed.

1. Plugin instance → **Client board** on → Save.
2. Log in to the portal as the test client.

| # | Action | Expected |
|---|---|---|
| 5.1 | Portal navigation | Entry "Board" |
| 5.2 | Open it | Same tickets as "Tickets" in the portal (open and, within the configured days, closed) |
| 5.3 | Organisation sharing off for the client's org | Only the client's own tickets (and collaborations, if enabled) |
| 5.4 | A sub-ticket of the client's ticket that belongs to someone outside the org | Not shown |
| 5.5 | Page source | No internal note, no agent name, no `draggable` |
| 5.6 | Log out, open `/ajax.php/board` | Redirect to the login page |

Stop if: 5.2 or 5.4 shows a ticket the client can't open via "Tickets". Switch the client board off immediately.

---

## 6. Security gate

| Check | How | Expected |
|---|---|---|
| Client cannot change status | As a client, POST to `/scp/ajax.php/board/move` | `403`, status unchanged |
| CSRF | As an agent, POST to `/scp/ajax.php/board/move` without `X-CSRFToken` | `400`, status unchanged |
| Invisible ticket | As an agent, POST a ticket ID from a foreign department | `404`, same body as for a non-existent ID |
| Status outside open/closed | POST the ID of *Archived* or *Deleted* | `422` |
| Markup in subjects | Create a ticket with `<b>x</b>` in the subject | Shown as text on both boards |

The end-to-end suite in `tests/e2e/` runs all of these against a local instance.

---

## Updating

Replace the folder `include/plugins/board/` with the new release. Settings live in osTicket's config table and stay. Read the CHANGELOG first: a major version bump means a changed behaviour or setting.

## Uninstalling

Admin Panel → Plugins → Ticket Board → Delete, then remove `include/plugins/board/`. Nothing else is left behind.
