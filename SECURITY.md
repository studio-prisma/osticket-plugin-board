# Security Policy

## Supported Versions

Only the latest minor release receives security fixes. Older tags are archived, not maintained.

| Version | Supported |
|---|---|
| `0.1.x` | yes |
| everything older | no |

## Reporting a Vulnerability

Use **[GitHub Security Advisories](https://github.com/studio-prisma/osticket-plugin-board/security/advisories/new)**. Reports there are private by default. Don't open a public issue for a suspected vulnerability.

Include:

- plugin version and osTicket version
- what an attacker gains, not only what misbehaves
- reproduction steps, ideally against a clean install (`dev/up.sh` builds one)

Expected response: an acknowledgement within 7 days, an assessment within 30. This is a spare-time project, not a vendor with an on-call rotation. That's the honest number, not a target.

## In Scope

- **A client sees a ticket on the board they can't open under "Tickets" in the portal.** Including sub-tickets nested under a card, and tickets of another organisation.
- **An agent sees a ticket on the board they can't open in the agent ticket view**, or changes the status of one.
- **A status change without the role permission** the core status dialog requires (close, reopen), or to a state other than open/closed.
- **A status change without a valid CSRF token**, or triggered from the client portal.
- **Ticket data rendered as markup** (subject, requester, department, status names) on either board.
- **Internal notes, agent names or other agent-only fields** reaching the client board.
- **Response bodies that tell an existing-but-invisible ticket apart from a non-existent one** on `/scp/ajax.php/board/move`.
- **Using a queue or saved search the agent has no access to**, e.g. another agent's private search via `?queue=<id>`, or telling such a search apart from a non-existent ID.
- **The live-refresh endpoint** (`/scp/ajax.php/board/state`) revealing anything about tickets outside the agent's board, or answering a client session.
- **Path traversal or arbitrary file reads** through the asset route.
- **Quick view of a ticket the agent can't open**, or a quick view that tells such a ticket apart from a non-existent ID.
- **An internal note from the board** on a ticket the agent can't open, without a valid CSRF token, from a client session, or rendered as markup anywhere.
- **An action button that opens a core dialog the agent's role doesn't allow** and the dialog then accepts.

## Out of Scope

Documented design decisions are not findings.

- **The client board shows what the portal shows.** Organisation sharing and collaborator visibility are osTicket settings. If they're on, organisation members see each other's tickets on the board, same as under "Tickets".
- **Department names are visible to clients.** The portal's own ticket list shows them too.
- **Ticket numbers and subjects on the agent board** for every ticket the agent's queues already list.
- **The output buffer on portal pages.** It's how the navigation entry gets in without a core patch. It is skipped for downloads, the API, AJAX and the agent panel.
- **Bugs in osTicket itself**, including its visibility rules. Report those [upstream](https://github.com/osTicket/osTicket/security).
- Anything that requires admin access to osTicket or write access to the server.
- Missing rate limits on the board page. osTicket has none on its own ticket lists either; that belongs in front of the application.
- Automated scanner output without a working proof of concept.

## Handling of Secrets

The plugin stores no credentials and calls no external service. The test stack generates its passwords at runtime into `dev/local/` (git-ignored). A secret that reaches a commit anyway is rotated first and removed second.

## Disclosure

Coordinated. A fix ships first, the advisory follows once a patched release is available. Reporters are credited unless they ask not to be.
