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
     * @param string $sort  one of self::SORTS; unknown values sort by update
     * @return array{columns: array<int, array{status: array, cards: array}>, count: int}
     */
    public static function build(array $statuses, array $rows, string $sort = 'updated'): array
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

        $cmp = self::comparator($sort);
        foreach ($columns as &$col) {
            usort($col['cards'], $cmp);
        }
        unset($col);

        return array('columns' => array_values($columns), 'count' => count($byId));
    }

    /** Sort modes for cards within a column. The first is the default. */
    public const SORTS = array('updated', 'due', 'priority', 'created', 'number');

    /** Swimlane modes. '' means no lanes. */
    public const GROUPS = array('', 'org', 'agent', 'dept');

    private static function comparator(string $sort): callable
    {
        $updated = static function ($a, $b) {
            return strcmp($b['updated_raw'], $a['updated_raw']) ?: strcmp($a['number'], $b['number']);
        };
        // Empty values sort after everything else in ascending orders.
        $asc = static function (string $x, string $y): int {
            if ($x === $y) {
                return 0;
            }
            if ($x === '') {
                return 1;
            }
            if ($y === '') {
                return -1;
            }
            return strcmp($x, $y);
        };
        switch ($sort) {
            case 'due':
                return static function ($a, $b) use ($asc, $updated) {
                    return $asc($a['due_raw'], $b['due_raw']) ?: $updated($a, $b);
                };
            case 'priority':
                // Urgency 1 is the most urgent; 0 means unknown and goes last.
                return static function ($a, $b) use ($asc, $updated) {
                    $ua = $a['urgency'] ?: PHP_INT_MAX;
                    $ub = $b['urgency'] ?: PHP_INT_MAX;
                    return ($ua <=> $ub) ?: $asc($a['due_raw'], $b['due_raw']) ?: $updated($a, $b);
                };
            case 'created':
                return static function ($a, $b) use ($updated) {
                    return strcmp($b['created_raw'], $a['created_raw']) ?: $updated($a, $b);
                };
            case 'number':
                return static function ($a, $b) {
                    return strnatcmp($a['number'], $b['number']);
                };
            default:
                return $updated;
        }
    }

    /**
     * Splits a built board into swimlanes: one row per organisation, agent
     * (or team, if no agent) or department, each with every column. Lanes
     * are sorted by name; the lane without a value comes last.
     *
     * @return array<int, array{key:string, label:string, count:int, columns:array}>
     */
    public static function lanes(array $board, string $by, string $emptyLabel): array
    {
        $key = static function (array $card) use ($by): string {
            switch ($by) {
                case 'org':
                    return $card['org'];
                case 'agent':
                    return $card['assignee'] !== '' ? $card['assignee'] : $card['team'];
                case 'dept':
                    return $card['dept'];
            }
            return '';
        };

        $lanes = array();
        foreach ($board['columns'] as $ci => $col) {
            foreach ($col['cards'] as $card) {
                $k = $key($card);
                if (!isset($lanes[$k])) {
                    $lanes[$k] = array(
                        'key'     => $k,
                        'label'   => $k !== '' ? $k : $emptyLabel,
                        'count'   => 0,
                        'columns' => array_map(static function ($c) {
                            return array('status' => $c['status'], 'cards' => array());
                        }, $board['columns']),
                    );
                }
                $lanes[$k]['columns'][$ci]['cards'][] = $card;
                $lanes[$k]['count']++;
            }
        }

        uasort($lanes, static function ($a, $b) {
            if (($a['key'] === '') !== ($b['key'] === '')) {
                return $a['key'] === '' ? 1 : -1;
            }
            return strcasecmp($a['key'], $b['key']);
        });
        return array_values($lanes);
    }

    /**
     * How a due date reads relative to now, by calendar day. Null when there
     * is no due date. Both values are database timestamps in the same zone.
     *
     * @return array{kind:string, days:int}|null  kind: overdue, today,
     *         tomorrow, soon (2-7 days), later
     */
    public static function dueState(string $dueRaw, string $nowRaw, bool $overdue): ?array
    {
        if ($dueRaw === '' || ($due = strtotime(substr($dueRaw, 0, 10))) === false
            || ($now = strtotime(substr($nowRaw, 0, 10))) === false) {
            return $overdue ? array('kind' => 'overdue', 'days' => 0) : null;
        }
        $days = (int) round(($due - $now) / 86400);
        if ($overdue || $days < 0 || ($days === 0 && $dueRaw < $nowRaw)) {
            return array('kind' => 'overdue', 'days' => $days);
        }
        if ($days === 0) {
            return array('kind' => 'today', 'days' => 0);
        }
        if ($days === 1) {
            return array('kind' => 'tomorrow', 'days' => 1);
        }
        return array('kind' => $days <= 7 ? 'soon' : 'later', 'days' => $days);
    }

    /**
     * Fingerprint of what a board shows, for the live refresh. Changes when
     * a ticket appears, disappears, moves, gets reassigned, re-prioritised,
     * re-dated or re-parented; not on fields the board doesn't show.
     */
    public static function signature(array $rows): string
    {
        $parts = array();
        foreach ($rows as $r) {
            $r = self::normalise($r);
            $parts[$r['id']] = array($r['status_id'], $r['pid'], $r['updated_raw'], $r['assignee'], $r['team'],
                $r['due_raw'], $r['urgency'], $r['dept'], $r['org'], $r['subject'], $r['overdue']);
        }
        ksort($parts);
        return sha1((string) json_encode($parts));
    }

    /**
     * Column limits from the plugin setting, e.g. "In Progress=5; Open=20".
     * Matched by status name, case-insensitive; separators ; , or newline,
     * = or :. Anything unreadable is skipped.
     *
     * @return array<int, int>  status id => limit
     */
    public static function parseLimits(string $raw, array $statuses): array
    {
        $byName = array();
        foreach ($statuses as $s) {
            $byName[strtolower(trim((string) $s['name']))] = (int) $s['id'];
        }
        $out = array();
        foreach (preg_split('/[;,\n]+/', $raw) ?: array() as $part) {
            if (!preg_match('/^\s*(.+?)\s*[=:]\s*(\d{1,4})\s*$/', $part, $m)) {
                continue;
            }
            $name = strtolower($m[1]);
            if (isset($byName[$name]) && (int) $m[2] > 0) {
                $out[$byName[$name]] = (int) $m[2];
            }
        }
        return $out;
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
            // Agent-only fields; empty on the client board.
            'created_raw' => (string) ($r['created_raw'] ?? ''),
            'due_raw'   => (string) ($r['due_raw'] ?? ''),
            'due'       => (string) ($r['due'] ?? ''),
            'priority'  => (string) ($r['priority'] ?? ''),
            'urgency'   => (int) ($r['urgency'] ?? 0),
            'org'       => (string) ($r['org'] ?? ''),
            'team'      => (string) ($r['team'] ?? ''),
        );
    }
}
