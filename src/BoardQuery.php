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

    /**
     * Open and closed statuses, disabled ones included and marked: the board
     * still needs them for tickets that sit in them (BoardModel::build()).
     * Choice lists filter on `enabled`.
     *
     * "Enabled" is the ENABLED bit of the `mode` column, read through
     * TicketStatus::isEnabled(), the same bit TicketStatusList::getItems()
     * filters on for the core status menus.
     *
     * @param string $abbrevVar  variable name of a Ticket Status list
     *        property whose value becomes `abbrev`; '' for none
     * @return array<int, array{id:int, name:string, state:string, sort:int, enabled:bool, abbrev:string}>
     */
    public static function statuses(int $closedDays, string $abbrevVar = ''): array
    {
        $field = $abbrevVar !== '' ? self::propertyFieldId($abbrevVar) : 0;
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
                'id'      => (int) $s->getId(),
                'name'    => (string) $s->getLocalName(),
                'state'   => $state,
                'sort'    => (int) $s->getSortOrder(),
                'enabled' => (bool) $s->isEnabled(),
                'abbrev'  => $field > 0 ? BoardModel::abbrevFrom($s->get('properties'), $field, $abbrevVar) : '',
            );
        }
        return $out;
    }

    /**
     * ID of the Ticket Status list property with this variable name, 0 if
     * there is none. Properties are fields of the list's own form (type
     * "L<list id>"), which is where Admin Panel, Lists, Ticket Status,
     * Properties adds them. Request path only: the plugin bootstrap runs
     * before osTicket can load lists.
     */
    private static function propertyFieldId(string $name): int
    {
        if (!class_exists('DynamicList') || !class_exists('DynamicFormField')
            || !($list = DynamicList::lookup(array('type' => 'ticket-status')))) {
            return 0;
        }
        foreach (DynamicFormField::objects()
            ->filter(array('form__type' => 'L' . (int) $list->getId(), 'name' => $name))
            ->values_flat('id', 'name') as $row) {
            if (strcasecmp((string) $row[1], $name) === 0) {
                return (int) $row[0];
            }
        }
        return 0;
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

    // ------------------------------------------------------------------
    // Queues and saved searches as board filters
    // ------------------------------------------------------------------

    /**
     * Queues and saved searches the agent may open, in the order and
     * nesting of the agent panel's navigation. Same source and the same
     * checkAccess() as include/staff/templates/queue-navigation.tmpl.php and
     * queue-savedsearches-nav.tmpl.php.
     *
     * @return array<int, array{id:int, label:string, depth:int, group:string}>
     */
    public static function queueOptions(Staff $staff): array
    {
        $out = array();
        $walk = static function (array $nodes, int $depth, string $group) use (&$walk, &$out, $staff) {
            foreach ($nodes as $node) {
                list($q, $children) = $node;
                if (!$q->checkAccess($staff) || ((int) $q->get('flags') & CustomQueue::FLAG_DISABLED)) {
                    continue;
                }
                $out[] = array(
                    'id'    => (int) $q->getId(),
                    'label' => (string) $q->getName(),
                    'depth' => $depth,
                    'group' => $group,
                );
                $walk($children ?: array(), $depth + 1, $group);
            }
        };
        $walk(CustomQueue::getHierarchicalQueues($staff), 0, 'queue');
        $walk(SavedQueue::getHierarchicalQueues($staff), 0, 'search');
        return $out;
    }

    /**
     * The queue or saved search with this ID, if the agent may use it.
     * Unknown and foreign-private answer the same (null), so the board can't
     * be used to probe for other agents' searches.
     */
    public static function queueFor(Staff $staff, int $id)
    {
        if ($id <= 0 || !($q = SavedQueue::lookup($id))) {
            return null;
        }
        if (!$q->checkAccess($staff) || ((int) $q->get('flags') & CustomQueue::FLAG_DISABLED)) {
            return null;
        }
        // Ticket queues only ('T'). getRoot() maps every root to 'Ticket',
        // so the raw column is checked.
        return in_array((string) $q->get('root'), array('T', ''), true) ? $q : null;
    }

    /**
     * Tickets of a queue or saved search, with the visibility rules of
     * include/staff/templates/queue-tickets.tmpl.php:
     *
     *   - the agent's visibility applies, except for the agent's own saved
     *     search when the agent may search everything;
     *   - queues hide sub-tickets as rows.
     *
     * Hidden sub-tickets are then loaded for the parents on the board, so the
     * cards still carry them. Those always get the agent's visibility; a
     * sub-ticket is only context, never a reason to widen what an agent sees.
     *
     * @return array{rows: array, truncated: bool}
     */
    public static function forStaffQueue(Staff $staff, $queue, int $limit): array
    {
        $q = $queue->getBasicQuery();

        $isQueue = $queue->isAQueue() || $queue->isASubQueue();
        if (!$queue->ignoreVisibilityConstraints($staff) || $isQueue) {
            $q->filter($staff->getTicketsVisibility());
        }
        if ($isQueue) {
            $q->filter(Q::any(array('ticket_pid' => null, 'flags__hasbit' => Ticket::FLAG_LINKED)));
        }

        $result = self::fetch($q, $limit, true);

        $parents = array_column($result['rows'], 'id');
        if ($parents) {
            $have = array_flip($parents);
            $kids = Ticket::objects()
                ->filter(array('ticket_pid__in' => $parents))
                ->filter($staff->getTicketsVisibility());
            foreach (self::fetch($kids, $limit, true)['rows'] as $row) {
                if (!isset($have[$row['id']])) {
                    $result['rows'][] = $row;
                }
            }
        }
        return $result;
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
            // Due dates, priority, organisation and team are agent-only: the
            // client portal doesn't show them either.
            $fields = array_merge($fields, array('user__name', 'staff__firstname', 'staff__lastname',
                'created', 'duedate', 'est_duedate', 'team__name', 'user__org__name',
                'cdata__:priority__priority_desc', 'cdata__:priority__priority_urgency'));
            if (class_exists('TicketForm')) {
                TicketForm::ensureDynamicDataView();
            }
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
            if ($withStaffFields) {
                $last = count($rows) - 1;
                $rows[$last] += array(
                    'created_raw' => (string) $r['created'],
                    // A manual due date wins over the SLA one, as in the
                    // ticket view.
                    'due_raw'     => (string) ($r['duedate'] ?: $r['est_duedate']),
                    'due'         => self::date($r['duedate'] ?: $r['est_duedate']),
                    'priority'    => (string) $r['cdata__:priority__priority_desc'],
                    'urgency'     => (int) $r['cdata__:priority__priority_urgency'],
                    'org'         => (string) $r['user__org__name'],
                    'team'        => (string) $r['team__name'],
                );
            }
        }

        $truncated = count($rows) > $limit;
        return array('rows' => array_slice($rows, 0, $limit), 'truncated' => $truncated);
    }

    /** The database clock, so due dates compare in the same time zone they're stored in. */
    public static function dbNow(): string
    {
        $res = db_query('SELECT NOW()');
        $row = $res ? db_fetch_row($res) : null;
        return $row ? (string) $row[0] : date('Y-m-d H:i:s');
    }

    private static function date($value): string
    {
        if (!$value) {
            return '';
        }
        return class_exists('Format') ? (string) Format::datetime($value) : (string) $value;
    }
}
