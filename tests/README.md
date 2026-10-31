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
| `2-staff.spec.js` | Entry under Applications, full ticket set for the admin, drag and drop with reload, keyboard move, close confirmation (cancel and accept), CSRF refusal, archived/deleted/unknown status refused, view-only agent sees only their department, gets `403` on a visible ticket and the same `404` body for an invisible and a non-existent one |
| `3-theme.spec.js` | Runs only with `dev/local/compose.override.yml`: board inside the themed chrome, columns side by side, screenshots desktop and mobile |

The close test writes `dev/local/mail-check.json`: how many mails Mailpit received for the requester before and after the status change. It records the behaviour; it doesn't assert it, because that depends on osTicket's alert settings, not on the plugin.

## Not covered

- osTicket 1.17, Nginx, MySQL
- Browsers other than Chromium
- Very large installations (the card limit is tested as a clamp, not under load)
