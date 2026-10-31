# Tests

## Unit, no osTicket required

```bash
php tests/unit.php
```

| Area | What is checked |
|---|---|
| `BoardModel` | column order, nesting by `ticket_pid`, orphans when the parent is invisible, grandchildren, cycles in bad data, archived/deleted states, card order |
| `BoardRenderer` | escaping of every ticket field, staff vs. client fields, links, drag attributes, truncation notice |
| `NavInjector` | placement, idempotence, pages without the portal nav, escaping |
| Settings | defaults (client board off), clamps for days and card limit |
| Output buffer | which scripts get one, never under the CLI |

`stubs/` holds empty stand-ins for the osTicket classes the plugin file extends. `PLUGIN_DIR` overrides the plugin location, default is the repository root.

## End-to-end, real osTicket

```bash
./dev/up.sh
cd tests/e2e
npm ci
npx playwright install chromium
npx playwright test
```

Runs against the stack from `dev/up.sh`, see [dev/README.md](../dev/README.md). One worker, file order: the specs change ticket statuses in the shared database.

| Spec | What is checked |
|---|---|
| `1-client.spec.js` | Navigation entry, exact ticket set per customer (own, organisation, no other org), cross-org sub-ticket hidden, read-only markup, no internal note, markup in a subject stays text, anonymous redirect, a client session can't reach `/scp/ajax.php/board` or the move endpoint, the client route refuses POST |
| `2-staff.spec.js` | Entry under Applications, full ticket set for the admin, drag and drop with reload, keyboard move, close confirmation (cancel and accept), CSRF refusal, archived/deleted/unknown status refused, view-only agent sees only their department, gets `403` on a visible ticket and the same `404` body for an invisible and a non-existent one; forced password change locks board and move endpoint; login and the logo open the board while the Tickets tab opens the ticket list, a login from a ticket link keeps it, the panel root after an expired session lands on the board, a pending password change wins, the setting off restores the ticket list; queue picker lists queues and own searches only, a saved search filters and stays selected, the Closed queue shows closed tickets only, another agent's private search by URL gives the same notice as a non-existent ID |
| `3-theme.spec.js` | Runs only with `dev/local/compose.override.yml`: board inside the themed chrome, columns side by side, screenshots desktop and mobile |
| `4-quickview.spec.js` | Quick view opens from a card and closes with Escape, markup in subjects stays text, assign, transfer and priority from the panel pickers without a core dialog, status in one click with the note saved alongside and undo from the toast, closing moves on to the next open card unless the setting is off, a work-through column moves on within the column for status changes and, once selected, other actions, a status in "Next ticket after changing to" moves on across the board, refer still opens the core dialog, `/board/act` refuses missing CSRF, unknown actions and values the pickers wouldn't offer, internal note stored as text and absent from the portal, note endpoint refuses missing CSRF, empty text, unknown tickets; view-only agent gets no forbidden action buttons, 403 on actions the role doesn't allow and the same 404 for invisible and missing tickets; a client session gets 403 on panel, note and actions |
| `6-extras.spec.js` | Due and priority tags (agent only), sort by priority and due date with memory, swimlanes by organisation with row-bound moves and shared totals, grouping by agent and department, column limit marking, live refresh in two sessions (swapped in when idle, offered when busy, quick view still works after a swap), state endpoint headers and fingerprint change |
| `5-visual.spec.js` | Visual walkthrough with screenshots in `dev/local/screens/`: agent board, hover, sub-tickets, quick view, toast after a move, drop target while dragging, filtered view, phone width for both boards. Checks layout rules a screenshot hides: no sideways page scroll, columns side by side or stacked by width, cards inside their column, quick view inside the viewport, toast goes away |

The close test writes `dev/local/mail-check.json`: how many mails Mailpit received for the requester before and after the status change. It records the behaviour; it doesn't assert it, because that depends on osTicket's alert settings, not on the plugin.

## Not covered

- osTicket 1.17, Nginx, MySQL
- Browsers other than Chromium
- Very large installations (the card limit is tested as a clamp, not under load)
