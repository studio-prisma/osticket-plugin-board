<?php

/**
 * osTicket plugin manifest: Ticket Board
 *
 * Tested against osTicket 1.18.x. PluginManager splits the 'plugin' key with
 * explode(':'), see include/class.plugin.php:698.
 */

return array(
    'id'          => 'studio-prisma:board',
    'version'     => '0.5.0',
    'ost_version' => '1.18',
    'name'        => 'Ticket Board',
    'author'      => 'Studio Prisma',
    'url'         => 'https://github.com/studio-prisma/osticket-plugin-board',
    'description' => 'Kanban-style board grouped by ticket status, with sub-tickets '
                   . 'nested under their parent. Agents move cards between statuses; '
                   . 'clients get a read-only board of the tickets they can already see.',
    'plugin'      => 'class.BoardPlugin.php:BoardPlugin',
);
