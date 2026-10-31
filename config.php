<?php

declare(strict_types=1);

/**
 * Configuration of the Ticket Board plugin.
 *
 * The client board defaults to OFF. It exposes a new view on customer data,
 * so it is switched on deliberately after the smoke test in docs/INSTALL.md.
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class BoardConfig extends PluginConfig
{
    public function getOptions()
    {
        return array(

            'sec_scope' => new SectionBreakField(array(
                'label' => 'Where the board appears',
            )),

            'enable_staff' => new BooleanField(array(
                'label'         => 'Agent board',
                'default'       => true,
                'configuration' => array(
                    'desc' => 'Adds "Board" under Applications in the agent panel. '
                            . 'Agents see exactly the tickets their departments, '
                            . 'assignments and referrals already allow.',
                ),
            )),

            'staff_landing' => new BooleanField(array(
                'label'         => 'Board as start page',
                'default'       => true,
                'configuration' => array(
                    'desc' => 'The board replaces the ticket list as the agent panel\'s start '
                            . 'page: after logging in and when clicking the logo. A login from '
                            . 'a link, e.g. a ticket in an alert mail, still opens that link. '
                            . 'The Tickets tab still opens the ticket list.',
                ),
            )),

            'enable_client' => new BooleanField(array(
                'label'         => 'Client board',
                'default'       => false,
                'configuration' => array(
                    'desc' => 'Read-only board in the client portal. Shows the same '
                            . 'tickets as "Tickets" in the portal: own tickets, '
                            . 'organisation tickets if the organisation shares them, '
                            . 'and collaborations if that setting is on.',
                ),
            )),

            'client_nav' => new BooleanField(array(
                'label'         => 'Client navigation link',
                'default'       => true,
                'configuration' => array(
                    'desc' => 'Adds a "Board" entry to the portal navigation. Off: the '
                            . 'board is still reachable at /apps/board, but not linked.',
                ),
            )),

            'next_after_close' => new BooleanField(array(
                'label'         => 'Next ticket after closing',
                'default'       => true,
                'configuration' => array(
                    'desc' => 'When a ticket is closed from the quick view, open the next '
                            . 'open card on the board instead of staying on the closed one.',
                ),
            )),

            'next_to_statuses' => new ChoiceField(array(
                'label'         => 'Next ticket after changing to',
                'default'       => '',
                'choices'       => self::openStatuses(),
                'hint'          => 'Open statuses that, picked in the quick view, move on to the next '
                                 . 'open card on the board, wherever the ticket came from. Closed '
                                 . 'statuses are covered by the setting above.',
                'configuration' => array('multiselect' => true, 'prompt' => 'None'),
            )),

            'next_from_statuses' => new ChoiceField(array(
                'label'         => 'Next ticket from these columns',
                'default'       => '',
                'choices'       => self::openStatuses(),
                'hint'          => 'Work-through columns such as a triage status: an action in the '
                                 . 'quick view on a ticket in one of them opens the next card of the '
                                 . 'same column. When the column is empty, the quick view closes. '
                                 . 'Takes precedence over the two settings above.',
                'configuration' => array('multiselect' => true, 'prompt' => 'None'),
            )),

            'next_from_actions' => new ChoiceField(array(
                'label'         => 'Actions that move on in these columns',
                'default'       => '',
                'choices'       => array(
                    'status'   => 'Status change',
                    'assign'   => 'Assign, claim, release',
                    'transfer' => 'Transfer to department',
                    'priority' => 'Change priority',
                    'mark'     => 'Mark answered or unanswered',
                ),
                'hint'          => 'Which quick-view actions count as done in the columns above. '
                                 . 'None selected: status change only.',
                'configuration' => array('multiselect' => true, 'prompt' => 'Status change only'),
            )),

            'sec_limits' => new SectionBreakField(array(
                'label' => 'Limits',
            )),

            'closed_days' => new TextboxField(array(
                'label'         => 'Closed tickets, days',
                'default'       => '14',
                'hint'          => 'Closed tickets are shown if they were closed within '
                                 . 'this many days. 0 hides closed columns entirely.',
                'configuration' => array('size' => 6, 'length' => 4, 'validator' => 'number'),
            )),

            'wip_limits' => new TextboxField(array(
                'label'         => 'Column limits',
                'default'       => '',
                'hint'          => 'Optional. Status name and limit, e.g. "In Progress=5; Open=20". '
                                 . 'A column over its limit is marked on the agent board; nothing '
                                 . 'is blocked.',
                'configuration' => array('size' => 60, 'length' => 500),
            )),

            'refresh_seconds' => new TextboxField(array(
                'label'         => 'Live refresh, seconds',
                'default'       => '60',
                'hint'          => 'How often the agent board checks for changes made elsewhere. '
                                 . '0 switches it off. Between 15 and 600.',
                'configuration' => array('size' => 6, 'length' => 4, 'validator' => 'number'),
            )),

            'max_cards' => new TextboxField(array(
                'label'         => 'Maximum cards per board',
                'default'       => '300',
                'hint'          => 'Hard cap on tickets loaded per request, newest '
                                 . 'activity first. Between 10 and 2000.',
                'configuration' => array('size' => 6, 'length' => 5, 'validator' => 'number'),
            )),
        );
    }

    /**
     * Open statuses for the pickers, id => name. Plain SQL on purpose: the
     * config is built during plugin bootstrap, before osTicket has loaded
     * what TicketStatus::getLocalName() needs.
     */
    private static function openStatuses(): array
    {
        $out = array();
        if (!function_exists('db_query') || !defined('TICKET_STATUS_TABLE')) {
            return $out;
        }
        $res = db_query("SELECT id, name FROM " . TICKET_STATUS_TABLE
            . " WHERE state = 'open' ORDER BY sort, name");
        while ($res && ($row = db_fetch_array($res))) {
            $out[(string) (int) $row['id']] = (string) $row['name'];
        }
        return $out;
    }
}
