<?php

declare(strict_types=1);

/**
 * UI strings. osTicket does not load plugin translation files on its own, so
 * the board carries English and German and falls back to English.
 */
final class BoardI18n
{
    private const STRINGS = array(
        'en' => array(
            'title'        => 'Board',
            'nav'          => 'Board',
            'empty'        => 'No tickets',
            'subtickets'   => '%d sub-tickets',
            'subticket'    => '1 sub-ticket',
            'requester'    => 'Requester',
            'department'   => 'Department',
            'assignee'     => 'Assigned',
            'unassigned'   => 'Unassigned',
            'updated'      => 'Updated',
            'overdue'      => 'Overdue',
            'truncated'    => 'Showing the %d most recently updated tickets.',
            'closed_hint'  => 'Closed within the last %d days.',
            'confirm_close' => 'Move ticket #%s to "%s"? This closes the ticket.',
            'move_failed'  => 'The status could not be changed.',
            'moved'        => 'Ticket #%s moved to "%s".',
            'drag_hint'    => 'Drag a card to another column to change its status.',
            'readonly'     => 'Read-only overview of your tickets.',
            'queue_label'  => 'View',
            'queue_all'    => 'All tickets I can see',
            'queue_group'  => 'Queues',
            'search_group' => 'Saved searches',
            'queue_show'   => 'Show',
            'queue_missing' => 'This queue or search is not available. Showing all tickets.',
            'queue_manage' => 'Create and save searches under Tickets, then Search.',
        ),
        'de' => array(
            'title'        => 'Board',
            'nav'          => 'Board',
            'empty'        => 'Keine Tickets',
            'subtickets'   => '%d Unter-Tickets',
            'subticket'    => '1 Unter-Ticket',
            'requester'    => 'Anfragende Person',
            'department'   => 'Abteilung',
            'assignee'     => 'Zugewiesen',
            'unassigned'   => 'Nicht zugewiesen',
            'updated'      => 'Aktualisiert',
            'overdue'      => 'Überfällig',
            'truncated'    => 'Angezeigt werden die %d zuletzt aktualisierten Tickets.',
            'closed_hint'  => 'Geschlossen in den letzten %d Tagen.',
            'confirm_close' => 'Ticket #%s nach „%s" verschieben? Damit wird das Ticket geschlossen.',
            'move_failed'  => 'Der Status konnte nicht geändert werden.',
            'moved'        => 'Ticket #%s nach „%s" verschoben.',
            'drag_hint'    => 'Karte in eine andere Spalte ziehen, um den Status zu ändern.',
            'readonly'     => 'Übersicht Ihrer Tickets, nur zum Lesen.',
            'queue_label'  => 'Ansicht',
            'queue_all'    => 'Alle Tickets, die ich sehen darf',
            'queue_group'  => 'Queues',
            'search_group' => 'Gespeicherte Suchen',
            'queue_show'   => 'Anzeigen',
            'queue_missing' => 'Diese Queue oder Suche ist nicht verfügbar. Angezeigt werden alle Tickets.',
            'queue_manage' => 'Suchen anlegen und speichern unter Tickets, dann Suche.',
        ),
    );

    /** @return array<string, string> */
    public static function strings(?string $lang): array
    {
        $code = strtolower(substr((string) $lang, 0, 2));
        return self::STRINGS[$code] ?? self::STRINGS['en'];
    }
}
