# Installation: Ticket Board für osTicket

Die Reihenfolge ist verbindlich. Jeder Schritt endet mit einer Prüfung; schlägt sie fehl, dort aufhören.

**[English version →](INSTALL.md)**

---

## 0. Vor dem Start

Du brauchst:

- osTicket 1.18.x mit Admin-Zugang zum Admin Panel
- Schreibzugriff auf `include/plugins/` auf dem Server
- ein Test-Agentenkonto **ohne** Admin-Rechte und ein Test-Kundenkonto in einer Organisation. Der Smoke-Test nutzt beide.

**Notausschalter, vor allem anderen.** Macht das Board Probleme, dann entweder

- Admin Panel → Manage → Plugins → Ticket Board → Haken an der Instanz entfernen → Speichern, oder
- den Ordner `include/plugins/board/` auf dem Server löschen.

Beides entfernt sofort alle Routen und den Navigationseintrag. Das Plugin speichert keine eigenen Daten und ändert keine Tabelle, es bleibt nichts aufzuräumen.

Abbruch, wenn: du keinen Shell- oder SFTP-Zugriff auf `include/plugins/` hast. Dann fehlt der zweite Notausschalter.

---

## 1. Hochladen

1. `board-<version>.zip` aus dem letzten Release herunterladen.
2. Optional: `gh attestation verify board-<version>.zip --repo studio-prisma/osticket-plugin-board`
3. Nach `include/plugins/` entpacken. Ergebnis:

```
include/plugins/board/plugin.php
include/plugins/board/class.BoardPlugin.php
include/plugins/board/src/...
include/plugins/board/assets/board.css
include/plugins/board/assets/board.js
```

Prüfung: `include/plugins/board/plugin.php` existiert und ist für den Webserver-Benutzer lesbar.

---

## 2. Installieren und Instanz anlegen

1. Admin Panel → Manage → Plugins → **Add New Plugin** → *Ticket Board* → **Install**.
2. *Ticket Board* öffnen → **Instances** → **Add New Instance**, Name z. B. `default`.
3. **Client board aus** lassen. Instanz als aktiv markieren. Speichern.
4. In der Plugin-Liste prüfen, dass das Plugin selbst **Enabled** ist.

Prüfung: Das Admin Panel zeigt keinen Fehler, die Plugin-Liste zeigt Ticket Board aktiv mit 1 Instanz.

Abbruch, wenn: das Plugin unter Add New Plugin nicht erscheint. Dann kann osTicket `plugin.php` nicht lesen (Rechte), oder der Ordner liegt nicht direkt unter `include/plugins/`.

---

## 3. Erreichbarkeit

Die Routen laufen über `scp/ajax.php` und `ajax.php` von osTicket selbst. Am Webserver ist nichts einzurichten.

Prüfung im Browser, als Agent angemeldet: `https://support.example.com/scp/ajax.php/board` zeigt das Board.

Abbruch, wenn: `URL not supported` erscheint. Dann ist die Plugin-Instanz nicht aktiv, oder das Agenten-Board ist in den Einstellungen abgeschaltet. Ohne Agenten-Sitzung antwortet dieselbe URL mit `403 Must login`. Das ist der AJAX-Endpunkt von osTicket, kein Fehler.

---

## 4. Smoke-Test Agenten-Board

Als **Nicht-Admin**-Testagent:

| # | Aktion | Erwartet |
|---|---|---|
| 4.0 | Abmelden, als Agent neu anmelden; dann den Tab „Tickets", dann das Logo anklicken | Login und Logo öffnen das Board, der Tab „Tickets" die Ticketliste |
| 4.1 | Agenten-Panel → Applications | Eintrag „Board" |
| 4.2 | Öffnen | Spalten für jeden offenen Status und, sofern nicht abgeschaltet, geschlossene Status |
| 4.3 | Mit der Ticket-Queue des Agenten vergleichen | Dieselben Tickets, keines aus einer Abteilung ohne Zugriff |
| 4.4 | Testticket in einen anderen offenen Status ziehen | Meldung „Ticket #… verschoben", Karte bleibt nach Neuladen dort, die Ticket-Historie zeigt den Statuswechsel |
| 4.5 | In einen geschlossenen Status ziehen, Abbrechen | Nichts ändert sich |
| 4.6 | Mit einer Rolle ohne Schließen-Recht: in geschlossenen Status ziehen und bestätigen | Fehlermeldung, Karte bleibt |
| 4.7 | Unter „Ansicht" eine Queue wählen, z. B. „My Tickets", dann eine eigene gespeicherte Suche | Dieselben Tickets wie diese Queue oder Suche unter Tickets; die Auswahl bleibt nach Neuladen erhalten |
| 4.8 | `/scp/ajax.php/board?queue=<ID der privaten Suche eines anderen Agenten>` öffnen | Hinweis „nicht verfügbar", volles Standard-Board, nichts aus dieser Suche sichtbar |
| 4.9 | Karte anklicken | Schnellansicht mit Ticket-Vorschau und nur den Aktionen, die die Rolle des Agenten erlaubt |
| 4.10 | In der Schnellansicht: An Agent zuweisen, jemanden aus der Liste wählen | Kein Dialog; die Schnellansicht zeigt die neue Zuweisung, die Ticket-Historie listet sie |
| 4.12 | Nach Organisation gruppieren, dann eine Karte in die Zeile einer anderen Organisation ziehen | Zeilen pro Organisation; die Karte bleibt in ihrer Zeile |
| 4.13 | In einem zweiten Browserfenster den Status eines Tickets ändern, das Board nicht anfassen | Innerhalb des Intervalls aktualisiert sich das Board und meldet „Board updated" |
| 4.10c | Optional: einen Triage-Status unter „Next ticket from these columns" eintragen, dann bei einem Ticket in dieser Spalte den Status aus der Schnellansicht ändern | Die Schnellansicht öffnet die nächste Karte derselben Spalte; bei der letzten schließt sie sich mit „In … ist nichts mehr übrig." |
| 4.10b | In der Schnellansicht eine Zeile ins Notizfeld schreiben, dann bei einem Testticket einen geschlossenen Status anklicken | Kein Dialog; das Ticket ist geschlossen, die Notiz steht im Verlauf, die Schnellansicht zeigt die nächste offene Karte. „Rückgängig" im Hinweis öffnet es wieder |
| 4.11 | Interne Notiz mit `<b>test</b>` speichern | Notiz in der Ticketansicht als wörtlicher Text, nicht fett; im Kundenportal nicht sichtbar |

Abbruch, wenn: 4.3 ein Ticket zeigt, das der Agent in der normalen Ticketansicht nicht öffnen kann. Instanz deaktivieren und melden (siehe SECURITY.md).

---

## 5. Kunden-Board einschalten

Erst nach bestandenem Abschnitt 4.

1. Plugin-Instanz → **Client board** an → Speichern.
2. Im Portal als Testkunde anmelden.

| # | Aktion | Erwartet |
|---|---|---|
| 5.1 | Portal-Navigation | Eintrag „Board" |
| 5.2 | Öffnen | Dieselben Tickets wie unter „Tickets" im Portal (offen und, im eingestellten Zeitraum, geschlossen) |
| 5.3 | Teilen für die Organisation des Kunden aus | Nur eigene Tickets (und Mitwirkungen, falls aktiviert) |
| 5.4 | Ein Unter-Ticket des Kunden-Tickets, das jemandem außerhalb der Organisation gehört | Nicht sichtbar |
| 5.5 | Seitenquelltext | Keine interne Notiz, kein Agentenname, kein `draggable` |
| 5.6 | Abmelden, `/ajax.php/board` öffnen | Weiterleitung zur Anmeldung |

Abbruch, wenn: 5.2 oder 5.4 ein Ticket zeigt, das der Kunde unter „Tickets" nicht öffnen kann. Kunden-Board sofort abschalten.

---

## 6. Security-Gate

| Prüfung | Wie | Erwartet |
|---|---|---|
| Kunde kann keinen Status ändern | Als Kunde POST an `/scp/ajax.php/board/move` | `403`, Status unverändert |
| CSRF | Als Agent POST an `/scp/ajax.php/board/move` ohne `X-CSRFToken` | `400`, Status unverändert |
| Unsichtbares Ticket | Als Agent POST mit Ticket-ID aus fremder Abteilung | `404`, gleicher Body wie bei einer nicht existierenden ID |
| Status außerhalb von offen/geschlossen | POST mit der ID von *Archived* oder *Deleted* | `422` |
| Markup im Betreff | Ticket mit `<b>x</b>` im Betreff anlegen | Auf beiden Boards als Text |

Die End-to-End-Suite in `tests/e2e/` fährt alle Prüfungen gegen eine lokale Instanz.

---

## Aktualisieren

Den Ordner `include/plugins/board/` durch das neue Release ersetzen. Die Einstellungen liegen in der Config-Tabelle von osTicket und bleiben erhalten. Vorher das CHANGELOG lesen: Ein Major-Sprung bedeutet geändertes Verhalten oder eine geänderte Einstellung.

## Deinstallieren

Admin Panel → Plugins → Ticket Board → Delete, danach `include/plugins/board/` löschen. Es bleibt nichts zurück.
