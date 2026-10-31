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
                    'desc' => 'After logging in, agents land on the board instead of the '
                            . 'ticket list. A login from a link, e.g. a ticket in an alert '
                            . 'mail, still opens that link. The Tickets tab is unchanged.',
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
}
