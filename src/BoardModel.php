<?php

declare(strict_types=1);

/**
 * Turns flat ticket rows into board columns.
 *
 * Pure: no osTicket classes, no database. Everything that decides which
 * ticket ends up where lives here, so it can be tested without an install.
 *
 * Hierarchy comes from ost_ticket.ticket_pid only. The core FLAG_PARENT bit
 * is not consulted: plugins that link sub-tickets (e.g. a sub-ticket manager
 * or an API endpoint) commonly set ticket_pid without touching the flag.
 */
final class BoardModel
{
    /**
     * @param array<int, array{id:int|string, name:string, state:string, sort?:int|string}> $statuses
     * @param array<int, array<string, mixed>> $rows  keys: id, number, pid, status_id,
     *        subject, updated, dept, assignee, user, overdue
     * @return array{columns: array<int, array{status: array, cards: array}>, count: int}
     */
    public static function build(array $statuses, array $rows): array
    {
        $columns = array();
        foreach (self::sortStatuses($statuses) as $s) {
            $id = (int) $s['id'];
            $columns[$id] = array(
                'status' => array(
                    'id'    => $id,
                    'name'  => (string) $s['name'],
                    'state' => (string) $s['state'],
                ),
                'cards'  => array(),
            );
        }

        // Only tickets whose status has a column take part. A ticket in an
        // archived or deleted state therefore neither shows up nor anchors
        // children.
        $byId = array();
        foreach ($rows as $r) {
            $r = self::normalise($r);
            if ($r['id'] > 0 && isset($columns[$r['status_id']])) {
                $byId[$r['id']] = $r;
            }
        }

        $children = array();
        $roots = array();
        foreach ($byId as $id => $r) {
            $root = self::rootOf($id, $byId);
            if ($root === $id) {
                $roots[$id] = true;
            } else {
                $children[$root][] = $r;
            }
        }

        foreach ($byId as $id => $r) {
            if (!isset($roots[$id])) {
                continue;
            }
            $kids = $children[$id] ?? array();
            usort($kids, static function ($a, $b) {
                return strcmp($a['number'], $b['number']);
            });
            $r['children'] = array_map(static function ($k) use ($columns) {
                $k['status_name'] = $columns[$k['status_id']]['status']['name'];
                $k['status_state'] = $columns[$k['status_id']]['status']['state'];
                return $k;
            }, $kids);
            $columns[$r['status_id']]['cards'][] = $r;
        }

        foreach ($columns as &$col) {
            usort($col['cards'], static function ($a, $b) {
                return strcmp($b['updated_raw'], $a['updated_raw']) ?: strcmp($a['number'], $b['number']);
            });
        }
        unset($col);

        return array('columns' => array_values($columns), 'count' => count($byId));
    }

    /**
     * Walks ticket_pid upwards to the top-most ancestor that is part of the
     * board. A parent the viewer cannot see is skipped, so its children stand
     * on their own.
     *
     * A cycle in bad data (A -> B -> A) would leave every member without a
     * root and hide them all. The walk resolves it to the lowest ticket ID it
     * visited, which is the same answer from every member of the cycle.
     */
    private static function rootOf(int $id, array $byId): int
    {
        $seen = array($id => true);
        $current = $id;
        while (true) {
            $pid = $byId[$current]['pid'];
            if (!$pid || !isset($byId[$pid])) {
                return $current;
            }
            if (isset($seen[$pid])) {
                return min(array_keys($seen));
            }
            $seen[$pid] = true;
            $current = $pid;
        }
    }

    private static function sortStatuses(array $statuses): array
    {
        usort($statuses, static function ($a, $b) {
            $rank = array('open' => 0, 'closed' => 1);
            $ra = $rank[$a['state']] ?? 2;
            $rb = $rank[$b['state']] ?? 2;
            return ($ra <=> $rb)
                ?: ((int) ($a['sort'] ?? 0) <=> (int) ($b['sort'] ?? 0))
                ?: ((int) $a['id'] <=> (int) $b['id']);
        });
        return $statuses;
    }

    private static function normalise(array $r): array
    {
        return array(
            'id'        => (int) ($r['id'] ?? 0),
            'number'    => (string) ($r['number'] ?? ''),
            'pid'       => isset($r['pid']) && $r['pid'] !== '' ? (int) $r['pid'] : 0,
            'status_id' => (int) ($r['status_id'] ?? 0),
            'subject'   => (string) ($r['subject'] ?? ''),
            'updated'   => (string) ($r['updated'] ?? ''),
            // Sort key: a database timestamp (Y-m-d H:i:s), which orders
            // correctly as a string. 'updated' is the localised display text.
            'updated_raw' => (string) ($r['updated_raw'] ?? $r['updated'] ?? ''),
            'dept'    => (string) ($r['dept'] ?? ''),
            'assignee'  => (string) ($r['assignee'] ?? ''),
            'user'      => (string) ($r['user'] ?? ''),
            'overdue'   => !empty($r['overdue']),
        );
    }
}
