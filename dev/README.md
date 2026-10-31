# Local test stack

osTicket 1.18.4 on PHP 8.3 / Apache, MariaDB 11.4 and Mailpit, all on `127.0.0.1`. For development only.

```bash
./dev/up.sh      # build, install, seed
./dev/down.sh    # stop and delete (the database lives on tmpfs)
```

`up.sh` generates all passwords on the first run into `dev/local/credentials.env`. `dev/local/` is git-ignored; nothing secret is part of the repository. The seed writes ticket IDs and numbers to `dev/local/seed.json`, which the end-to-end suite reads.

| URL | What |
|---|---|
| `http://127.0.0.1:8088/` | Client portal |
| `http://127.0.0.1:8088/scp/` | Agent panel |
| `http://127.0.0.1:8026/` | Mailpit: every mail osTicket sends |

The repository root is mounted read-only as `include/plugins/board`, so edits show up without a rebuild.

## Test data

`seed/seed.php` creates two organisations, three customers and two agents, chosen so every visibility rule has a ticket that proves it. The comment at the top of the file lists them. The most important one: a Globex ticket that's a sub-ticket of an Acme ticket. Acme customers must not see it, Globex must see it as a card of its own.

## Mounting a theme or other plugins

Create `dev/local/compose.override.yml`. `up.sh` picks it up automatically. Relative paths in it resolve against `dev/`, the project directory, so they start with `./local/`:

```yaml
services:
  osticket:
    volumes:
      - ./local/theme:/var/www/html/assets/custom/css:ro      # your CSS files
      - ./local/plugins/some-plugin:/var/www/html/include/plugins/some-plugin:ro
      - ./local/seed:/seed-local:ro                           # optional seed hook
```

If `dev/local/seed/seed.php` exists, the main seed runs it at the end, with the same helpers (`q()`, `scalar()`, `fail()`) and `$ost` in scope. That's the place to install extra plugins. It must not write to stdout.

With an override file in place, `tests/e2e/3-theme.spec.js` runs too and saves screenshots of both boards to `dev/local/screens/`.
