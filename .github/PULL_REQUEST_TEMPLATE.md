## What changes

<!-- One or two sentences. What does this PR do, and why? -->

## Type

- [ ] Bug fix
- [ ] New feature
- [ ] Documentation
- [ ] CI / tooling
- [ ] Refactor (no behaviour change)

## Security impact

- [ ] Changes which tickets a client or agent sees (`BoardQuery`)
- [ ] Changes the status-change endpoint or its permission checks
- [ ] Changes rendering of ticket data (`BoardRenderer`, `board.js`)
- [ ] Changes routes or the portal output buffer
- [ ] No security-relevant surface touched

If any box above is checked, describe who can now see or do what:

<!-- ... -->

## Verification

- [ ] `php tests/unit.php` passes (PHP version: ______)
- [ ] `npx playwright test` passes against `dev/up.sh`
- [ ] No hardcoded hostnames, credentials or absolute paths added

## Related issues

Closes #
