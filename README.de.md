# Ticket Board für osTicket

[![Validate](https://github.com/studio-prisma/osticket-plugin-board/actions/workflows/validate.yml/badge.svg)](https://github.com/studio-prisma/osticket-plugin-board/actions/workflows/validate.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE)

Ein Board im Kanban-Stil für osTicket. Eine Spalte pro Ticketstatus, Unter-Tickets eingeklappt unter ihrem Eltern-Ticket. Agenten ziehen Karten, um den Status zu ändern. Kunden bekommen ein Board nur zum Lesen, mit genau den Tickets, die sie im Portal ohnehin sehen.

**[English version →](README.md)**

---

## Was es macht

| | Agenten-Panel | Kundenportal |
|---|---|---|
| Wo | Applications → Board (`/scp/ajax.php/board`) | „Board" in der Portal-Navigation (`/ajax.php/board`) |
| Welche Tickets | Genau das, was Abteilungen, Zuweisungen und Weiterleitungen des Agenten erlauben (`Staff::getTicketsVisibility()`, wie in den Queues) | Eigene Tickets, Tickets der Organisation, wenn die Organisation sie teilt, und Mitwirkungen, wenn aktiviert (dieselben drei Quellen wie die Ticketliste im Portal) |
| Status ändern | Drag and Drop, oder `Alt+←` / `Alt+→` auf einer fokussierten Karte. Vor dem Schließen kommt eine Rückfrage | Nein |
| Pro Karte | Nummer, Betreff, Anfragende Person, Abteilung, Zuweisung, letzte Änderung, Überfällig-Markierung | Nummer, Betreff, Abteilung, letzte Änderung |
| Unter-Tickets | Eingeklappte Liste unter dem Eltern-Ticket, jeweils mit eigenem Status | Ebenso, beschränkt auf Unter-Tickets, die der Kunde sehen darf |

Unter-Tickets kommen aus `ost_ticket.ticket_pid`. In diese Spalte schreiben die Merge-Funktion von osTicket und die gängigen Unter-Ticket-Plugins. Thread-Einträge werden nie geladen, interne Notizen können also auf keinem der beiden Boards landen.

Keine Core-Datei wird verändert, keine Datei landet außerhalb des Plugin-Ordners. Das Plugin nutzt Hooks, die osTicket 1.18 mitbringt: `Application::registerStaffApp()` für den Menüeintrag, die Signale `ajax.scp` und `ajax.client` für seine Routen. Den Navigationseintrag im Portal setzt ein Output-Buffer, weil die Portal-Navigation registrierte Client-Apps nicht anzeigt.

Warum `ajax.php` und nicht der `apps/`-Ordner von osTicket: In 1.18.4 ist keiner der beiden App-Dispatcher erreichbar. `apps/dispatcher.php` bricht mit einem Fatal Error ab (es lädt `client.inc.php` über einen Pfad, der aus `apps/` heraus nicht auflöst), und `scp/apps/dispatcher.php` verweigert direkte Aufrufe und erwartet eine `scp/apps.php`, die das Release nicht mitliefert.

---

## Voraussetzungen

- osTicket **1.18.x** (getestet: 1.18.4). Plugin-Instanzen, eingeführt mit 1.17, sind Pflicht.
- PHP **8.0 bis 8.4** (Syntaxprüfung auf allen fünf, der End-to-End-Lauf nutzt 8.3)
- Keine Änderung am Webserver. Das Board läuft über dieselben `ajax.php`-Pfade, die osTicket selbst schon aufruft. Was osTicket zum Laufen bringt (Apache, oder Nginx mit `PATH_INFO`), reicht.

---

## Installation

Vollständige Anleitung: **[docs/INSTALL.de.md](docs/INSTALL.de.md)** ([english](docs/INSTALL.md)).

Kurzfassung:

1. `board-<version>.zip` aus dem [letzten Release](https://github.com/studio-prisma/osticket-plugin-board/releases/latest) nach `include/plugins/` entpacken, sodass `include/plugins/board/plugin.php` entsteht.
2. Admin Panel → Manage → Plugins → Add New Plugin → **Ticket Board** → Install.
3. Plugin öffnen, Instanz anlegen, aktivieren. Agenten-Board an, Kunden-Board aus (Standard).
4. Smoke-Test aus INSTALL.de.md durchgehen. Erst danach das Kunden-Board einschalten.

---

## Konfiguration

| Einstellung | Standard | Zweck |
|---|---|---|
| Agent board | an | Eintrag unter Applications, Routen unter `/scp/ajax.php/board` |
| Client board | **aus** | Board nur zum Lesen im Portal. Aus heißt: die Route antwortet mit 404 |
| Client navigation link | an | „Board" in der Portal-Navigation. Aus: nur per URL erreichbar |
| Closed tickets, days | 14 | Geschlossene Spalten zeigen Tickets, die in diesem Zeitraum geschlossen wurden. `0` blendet sie aus |
| Maximum cards per board | 300 | Tickets pro Seitenaufruf, neueste Aktivität zuerst (10 bis 2000) |

Farben und Radien sind CSS-Variablen auf `.spb-wrap`. Überschreiben im eigenen Stylesheet:

```css
.spb-wrap { --spb-accent: #330066; --spb-radius: 2px; }
```

Alle Variablen stehen oben in [assets/board.css](assets/board.css).

---

## Tests

```bash
php tests/unit.php              # ohne osTicket
./dev/up.sh                     # lokales osTicket 1.18.4 in Docker, mit Testdaten
cd tests/e2e && npm ci && npx playwright test
./dev/down.sh
```

Die Unit-Tests decken Gruppierung, Verschachtelung, Escaping, den Navigationseintrag und die Grenzwerte der Konfiguration ab. Die End-to-End-Suite läuft gegen ein echtes osTicket mit drei Kunden in zwei Organisationen und zwei Agenten mit unterschiedlichen Rollen. Sie prüft, was jeder davon sieht, Drag and Drop, CSRF, abgelehnte Rechte, und dass Markup im Betreff Text bleibt. Details: [tests/README.md](tests/README.md).

Mail: Ein Statuswechsel vom Board läuft über dasselbe `Ticket::setStatus()` wie der Status-Dialog des Cores, ohne Kommentar. Im End-to-End-Lauf mit den Standard-Alarmeinstellungen von osTicket ging beim Schließen keine Mail an den Anfragenden (Mailpit, vorher 0, nachher 0). Maßgeblich sind deine Alarmeinstellungen; das Plugin verschickt selbst nichts.

Nicht geprüft: osTicket 1.17, Nginx, MySQL statt MariaDB, andere Portal-Themes als das Standard-Theme und ein eigenes Theme.

---

## Bekannte Grenzen

- **Eine Seite, kein Blättern.** Das Board zeigt höchstens „Maximum cards" Tickets, neueste Aktivität zuerst. Fehlen Tickets, erscheint ein Hinweis.
- **Nur der Status.** Ziehen ändert den Status. Zuweisung, Priorität, Abteilung und Reihenfolge innerhalb einer Spalte ändert das Board nicht.
- **Kinder ziehen nicht mit.** Eine Eltern-Karte zu verschieben ändert nur das Eltern-Ticket. Unter-Tickets behalten ihren Status.
- **Eine Ebene sichtbar.** Tiefere Hierarchien werden unter dem obersten sichtbaren Vorfahren zusammengefasst.
- **Der Navigationseintrag hängt am Portal-Markup.** Er kommt hinter den letzten Eintrag von `<ul id="nav">`. Ein Theme, das das Header-Template ohne diese Liste ersetzt, bekommt keinen Link. Das Board bleibt unter `/ajax.php/board` erreichbar.
- **Gast-Sitzungen** (Zugangslinks zu einem Ticket) bekommen kein Board. Sie sind an ein einzelnes Ticket gebunden.
- **Nicht für osTicket 2.0**, solange 2.0 das Plugin-Format ändert.

---

## Mitwirken

Siehe [CONTRIBUTING.md](CONTRIBUTING.md). Sicherheitslücken: siehe [SECURITY.md](SECURITY.md), nie als öffentliches Issue.

## Lizenz

[GPL-2.0-or-later](LICENSE) © studio-prisma. Das Plugin erweitert GPL-Klassen von osTicket und übernimmt deshalb dessen Lizenz.
