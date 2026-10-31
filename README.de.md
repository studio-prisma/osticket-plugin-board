# Ticket Board für osTicket

[![Validate](https://github.com/studio-prisma/osticket-plugin-board/actions/workflows/validate.yml/badge.svg)](https://github.com/studio-prisma/osticket-plugin-board/actions/workflows/validate.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE)

Ein Board im Kanban-Stil für osTicket. Eine Spalte pro Ticketstatus, Unter-Tickets eingeklappt unter ihrem Eltern-Ticket. Agenten ziehen Karten, um den Status zu ändern. Kunden bekommen ein Board nur zum Lesen, mit genau den Tickets, die sie im Portal ohnehin sehen.

**[English version →](README.md)**

---

## Was es macht

| | Agenten-Panel | Kundenportal |
|---|---|---|
| Wo | Applications → Board (`/scp/ajax.php/board`), und die Startseite nach dem Login (Einstellung) | „Board" in der Portal-Navigation (`/ajax.php/board`) |
| Welche Tickets | Genau das, was Abteilungen, Zuweisungen und Weiterleitungen des Agenten erlauben (`Staff::getTicketsVisibility()`, wie in den Queues) | Eigene Tickets, Tickets der Organisation, wenn die Organisation sie teilt, und Mitwirkungen, wenn aktiviert (dieselben drei Quellen wie die Ticketliste im Portal) |
| Filter | Jede Queue und gespeicherte Suche des Agenten-Panels, über dem Board auswählbar, pro Sitzung gemerkt | Nein |
| Status ändern | Drag and Drop, oder `Alt+←` / `Alt+→` auf einer fokussierten Karte. Vor dem Schließen kommt eine Rückfrage | Nein |
| Schnellansicht | Klick oder `Enter` auf eine Karte: die Ticket-Vorschau von osTicket plus die Aktionen, die die Rolle des Agenten erlaubt. Wer ein Ticket darin schließt, landet direkt bei der nächsten offenen Karte (Einstellung) | Nein |
| Aktionen | Ein Klick für Status, übernehmen, freigeben, als beantwortet markieren. Auswahllisten im Panel für Agent, Team, Abteilung und Priorität. Weiterleiten und Aufgabe anlegen öffnen die Dialoge von osTicket. Ein Statuswechsel lässt sich im Hinweis rückgängig machen | Nein |
| Interne Notiz | Aus der Schnellansicht, als Klartext. Text im Notizfeld wird mit der nächsten Aktion gespeichert, die du anklickst | Nein |
| Fälligkeit und Priorität | Markierungen für überfällig, heute fällig, morgen fällig sowie Priorität High/Emergency | Nein |
| Sortierung | Zuletzt geändert, Fälligkeit, Priorität, neueste, Nummer; pro Sitzung gemerkt | Nein |
| Gruppierung | Zeilen pro Organisation, zuständiger Person (oder Team) oder Abteilung, Spaltenköpfe mit Summen oben | Nein |
| Spaltenlimits | Optional pro Status; eine Spalte über ihrem Limit wird markiert | Nein |
| Live-Aktualisierung | Änderungen von anderswo erscheinen von selbst, solange du nichts tust, oder hinter einem Button „Aktualisierungen anzeigen", während du arbeitest | Nein |
| Pro Karte | Nummer, Betreff, Anfragende Person, Abteilung, Zuweisung, letzte Änderung, Überfällig-Markierung | Nummer, Betreff, Abteilung, letzte Änderung |
| Unter-Tickets | Eingeklappte Liste unter dem Eltern-Ticket, jeweils mit eigenem Status | Ebenso, beschränkt auf Unter-Tickets, die der Kunde sehen darf |

**Die Filter sind die von osTicket.** Das Board hat kein eigenes Filtersystem. Die Auswahl „Ansicht" listet die Queues und gespeicherten Suchen, die der Agent unter Tickets sieht, mit derselben Zugriffsprüfung (öffentliche Queues, eigene private Suchen, nie die eines anderen Agenten). Filter legt man an und speichert sie dort, wo man es gewohnt ist: Tickets, dann Suche, dann Speichern. Das Board übernimmt ihre Kriterien und dieselben Sichtbarkeitsregeln wie die Ticketliste. Unter-Tickets, die eine Queue als Zeilen ausblendet, erscheinen trotzdem eingeklappt unter dem Eltern-Ticket, beschränkt auf das, was der Agent sehen darf.

**Die Aktionen laufen über den Code von osTicket.** Die Schnellansicht bietet nur an, was die Ticketansicht demselben Agenten anbieten würde. Status, übernehmen, freigeben, als beantwortet markieren, zuweisen, übergeben und Priorität laufen per Klick über `/board/act`. Der Endpunkt prüft dieselben Rollenrechte wie der Core-Dialog und ruft dann dieselben Core-Methoden mit denselben Formularen auf (`setStatus()`, `claim()`, `release()`, `markAnswered()`, `assign()`, `transfer()`, `updateField()`). Die Auswahllisten zeigen nur, was auch der Core-Dialog zeigen würde, alles andere lehnt der Endpunkt ab. Benachrichtigungen und Ticket-Historie kommen aus osTicket. Weiterleiten und Aufgabe anlegen öffnen weiter die Core-Dialoge. Notizen enden im `Ticket::postNote()` des Cores und werden als Klartext gespeichert.

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
| Board as start page | an | Nach dem Login landen Agenten auf dem Board statt in der Ticketliste. Ein Login über einen Link (z. B. ein Ticket aus einer Benachrichtigung) öffnet weiter diesen Link; ein ausstehender Passwortwechsel oder 2FA-Schritt kommt zuerst. Der Tab „Tickets" bleibt unverändert |
| Client board | **aus** | Board nur zum Lesen im Portal. Aus heißt: die Route antwortet mit 404 |
| Client navigation link | an | „Board" in der Portal-Navigation. Aus: nur per URL erreichbar |
| Closed tickets, days | 14 | In der Standardansicht zeigen geschlossene Spalten Tickets, die in diesem Zeitraum geschlossen wurden. `0` blendet sie aus. Ist eine Queue oder Suche gewählt, entscheiden deren Kriterien |
| Column limits | leer | Statusname und Limit, z. B. `In Progress=5; Open=20`. Wird nur markiert, nichts wird blockiert |
| Next ticket after closing | An | Nach dem Schließen aus der Schnellansicht öffnet das Panel die nächste offene Karte. Aus: Es bleibt beim geschlossenen Ticket |
| Live refresh, seconds | 60 | Wie oft das Agenten-Board auf Änderungen prüft. `0` schaltet es ab, sonst 15 bis 600 |
| Maximum cards per board | 300 | Tickets pro Seitenaufruf, neueste Aktivität zuerst (10 bis 2000) |

Farben und Radien sind CSS-Variablen auf `.spb-wrap`. Überschreiben im eigenen Stylesheet:

```css
.spb-wrap { --spb-accent: #330066; --spb-radius: 8px; }
```

Alle Variablen stehen oben in [assets/board.css](assets/board.css).

Das Board folgt den Systemeinstellungen für reduzierte Bewegung (die Schnellansicht blendet über, statt zu gleiten), reduzierte Transparenz (deckendes Panel) und erhöhten Kontrast.

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
- **Eine verschobene Karte bleibt bis zum Neuladen.** Fällt ein Ticket durch den Statuswechsel aus der gewählten Queue (etwa beim Schließen in „Open"), bleibt die Karte bis zum Neuladen in ihrer neuen Spalte.
- **Suchkriterien, die osTicket nicht unterstützt, werden ignoriert**, auf dem Board wie in der Ticketliste. Das ist das Verhalten von osTicket bei gespeicherten Suchen, kein Filter des Plugins.
- **Keine Antwort an Kunden aus dem Board.** Antworten bleiben in der Ticketansicht (die Schnellansicht verlinkt dorthin). Als überfällig markieren, zusammenführen, verknüpfen und das komplette Bearbeiten-Formular gibt es ebenfalls nicht, sie sind einen Klick entfernt in der Ticketansicht.
- **Direkte Aktionen haben kein Kommentarfeld wie der Core-Dialog.** Ein Statuswechsel aus der Schnellansicht trägt keinen eigenen Kommentar. Wer vorher ins Notizfeld schreibt, bekommt den Text als interne Notiz zum Wechsel. Die Antwort an Kunden bleibt in der Ticketansicht.
- **Nach einer Aktion bleibt die Schnellansicht beim Ticket**, nach dem Schließen mit „Next ticket after closing" dagegen springt sie zur nächsten offenen Karte in Board-Reihenfolge (weiter unten in der Spalte, dann die nächste offene Spalte). Ist keine offene Karte mehr übrig, schließt sich das Panel. Rückgängig setzt den vorigen Status wieder; eine mitgespeicherte Notiz bleibt stehen.
- **Notizen aus dem Board übergehen den Ticket-Lock.** Der Lock von osTicket schützt nur die Formulare der Ticketansicht vor doppeltem Absenden; die Notiz selbst läuft über dasselbe `Ticket::postNote()`.
- **Zeilen wechseln nicht die Zuständigkeit.** In der gruppierten Ansicht wandert eine Karte nur innerhalb ihrer Zeile zwischen den Status; in die Zeile einer anderen Organisation oder Person fallen lassen bewirkt nichts. Neu zuweisen geht über die Schnellansicht.
- **Fälligkeits-Markierungen zeigen, was Aufmerksamkeit braucht:** überfällig, heute, morgen. Ein Termin in einigen Tagen (oft nur die Standard-SLA) bekommt keine Markierung; „Sortierung: Fälligkeit" ordnet trotzdem danach.
- **Die Live-Aktualisierung lädt das ganze Board im Hintergrund neu**, wenn sich etwas geändert hat, und pausiert in Hintergrund-Tabs. Bei vielen Agenten und kurzen Intervallen ist das ein zusätzlicher Seitenaufbau pro Agent und Änderung; 60 Sekunden sind nicht ohne Grund der Standard.
- **Ziehen ändert nur den Status.** Zuweisung, Priorität und der Rest laufen über die Schnellansicht. Die Reihenfolge der Karten innerhalb einer Spalte wird nicht gespeichert.
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
