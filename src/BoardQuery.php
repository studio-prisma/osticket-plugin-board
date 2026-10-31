<?php

declare(strict_types=1);

/**
 * Loads statuses and tickets through osTicket's own ORM.
 *
 * Visibility is never computed here from scratch. The agent query applies
 * Staff::getTicketsVisibility(), the same filter the ticket queues use. The
 * client query rebuilds the three sources that include/client/tickets.inc.php
 * unions: own tickets, collaborations (if enabled) and organisation tickets
 * (if the organisation shares them). No thread entries are ever loaded, so
 * internal notes cannot reach a board.
 */
final class BoardQuery
{
    /** States that get a column. Archived and deleted never do. */
    private const STATES = array('open', 'closed');

    /** @return array<int, array{id:int, name:string, state:string, sort:int}> */
    public static function statuses(int $closedDays): array
    {
        $out = array();
        foreach (TicketStatus::objects() as $s) {
            $state = strtolower((string) $s->getState());
            if (!in_array($state, self::STATES, true)) {
                continue;
            }
            if ($state === 'closed' && $closedDays <= 0) {
                continue;
            }
            $out[] = array(
                'id'    => (int) $s->getId(),
                'name'  => (string) $s->getLocalName(),
                'state' => $state,
                'sort'  => (int) $s->getSortOrder(),
            );
        }
        return $out;
    }

    /**
     * @return array{rows: array, truncated: bool}
     */
    public static function forStaff(Staff $staff, int $closedDays, int $limit): array
    {
        $q = self::base($closedDays)
            ->filter($staff->getTicketsVisibility());
        return self::fetch($q, $limit, true);
    }

    /**
     * @return array{rows: array, truncated: bool}
     */
    public static function forClient($client, int $closedDays, int $limit): array
    {
        global $cfg;

        $ids = self::clientTicketIds($client, $cfg && $cfg->collaboratorTicketsVisibility());
        if (!$ids) {
            return array('rows' => array(), 'truncated' => false);
        }
        $q = self::base($closedDays)->filter(array('ticket_id__in' => $ids));
        return self::fetch($q, $limit, false);
    }

    /**
     * Ticket IDs a portal user may list: the same three sources as the
     * portal's own ticket list. Public for the access check in tests.
     *
     * @return int[]
     */
    public static function clientTicketIds($client, bool $collaborations): array
    {
        $uid = (int) $client->getId();
        if ($uid <= 0) {
            return array();
        }

        $ids = array();
        foreach (Ticket::objects()->filter(array('user_id' => $uid))->values_flat('ticket_id') as $row) {
            $ids[(int) $row[0]] = true;
        }
        if ($collaborations) {
            $q = Ticket::objects()
                ->filter(array('thread__collaborators__user_id' => $uid))
                ->values_flat('ticket_id');
            foreach ($q as $row) {
                $ids[(int) $row[0]] = true;
            }
        }
        if ($client->canSeeOrgTickets() && ($org = (int) $client->getOrgId()) > 0) {
            $q = Ticket::objects()
                ->filter(array('user__org_id' => $org))
                ->values_flat('ticket_id');
            foreach ($q as $row) {
                $ids[(int) $row[0]] = true;
            }
        }
        return array_keys($ids);
    }

    private static function base(int $closedDays)
    {
        $q = Ticket::objects();
        if ($closedDays > 0) {
            $q->filter(Q::any(array(
                'status__state' => 'open',
                Q::all(array(
                    'status__state' => 'closed',
                    'closed__gte'   => SqlFunction::NOW()->minus(SqlInterval::DAY($closedDays)),
                )),
            )));
        } else {
            $q->filter(array('status__state' => 'open'));
        }
        return $q;
    }

    private static function fetch($q, int $limit, bool $withStaffFields): array
    {
        $fields = array('ticket_id', 'number', 'ticket_pid', 'status_id', 'lastupdate',
            'isoverdue', 'cdata__subject', 'dept__name');
        if ($withStaffFields) {
            $fields = array_merge($fields, array('user__name', 'staff__firstname', 'staff__lastname'));
        }

        $q = $q->distinct('ticket_id')
            ->order_by('-lastupdate')
            ->limit($limit + 1);
        $q = call_user_func_array(array($q, 'values'), $fields);

        $rows = array();
        foreach ($q as $r) {
            $rows[] = array(
                'id'        => (int) $r['ticket_id'],
                'number'    => (string) $r['number'],
                'pid'       => $r['ticket_pid'] ? (int) $r['ticket_pid'] : 0,
                'status_id' => (int) $r['status_id'],
                'subject'   => (string) $r['cdata__subject'],
                'updated'   => self::date($r['lastupdate']),
                'updated_raw' => (string) $r['lastupdate'],
                'dept'      => (string) $r['dept__name'],
                'user'      => $withStaffFields ? (string) $r['user__name'] : '',
                'assignee'  => $withStaffFields
                    ? trim($r['staff__firstname'] . ' ' . $r['staff__lastname'])
                    : '',
                'overdue'   => (bool) $r['isoverdue'],
            );
        }

        $truncated = count($rows) > $limit;
        return array('rows' => array_slice($rows, 0, $limit), 'truncated' => $truncated);
    }

    private static function date($value): string
    {
        if (!$value) {
            return '';
        }
        return class_exists('Format') ? (string) Format::datetime($value) : (string) $value;
    }
}
