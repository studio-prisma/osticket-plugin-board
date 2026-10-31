<?php
/**
 * Seeds the local test instance. Runs inside the osticket container:
 *
 *   php /seed/seed.php
 *
 * Idempotent per stack: refuses to run twice (the database lives on tmpfs,
 * dev/down.sh + dev/up.sh starts over).
 *
 * Data set, designed so every visibility rule has a ticket that proves it:
 *
 *   Acme (shares tickets with all members)       Globex (no sharing)
 *     alice  A1 parent (Open)                       carol  G1 (Open)
 *            A2 child of A1 (In Progress)                  G2 child of A1 (Open)  <- cross-org child
 *            A4 child of A1 (Closed, recent)               S1 Sales dept (Open)
 *            X1 subject with markup (Open)
 *     bob    A3 child of A1 (Open)
 *            A5 (Open)
 *
 *   A1 carries an internal note with a marker string that must never show up.
 *   Agents: the installer's admin (all departments), salesagent (Sales only,
 *   role "View only": may look, may not change status).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

chdir('/var/www/html');
$_SERVER['SCRIPT_NAME'] = '/seed.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
require 'main.inc.php';

function fail(string $msg): void
{
    fwrite(STDERR, "seed: $msg\n");
    fwrite(STDERR, "seed: partial data is left behind, start over with dev/down.sh and dev/up.sh\n");
    exit(1);
}

function q(string $sql): void
{
    if (!db_query($sql)) {
        fail('query failed: ' . $sql . ': ' . db_error());
    }
}

function scalar(string $sql)
{
    $res = db_query($sql);
    $row = $res ? db_fetch_row($res) : null;
    return $row ? $row[0] : null;
}

$agentUser = getenv('SPB_AGENT_USER') ?: fail('SPB_AGENT_USER missing');
$agentPass = getenv('SPB_AGENT_PASS') ?: fail('SPB_AGENT_PASS missing');
$clientPass = getenv('SPB_CLIENT_PASS') ?: fail('SPB_CLIENT_PASS missing');

if (scalar("SELECT COUNT(*) FROM " . ORGANIZATION_TABLE . " WHERE name='Acme Corp'") > 0) {
    echo "seed: already seeded, nothing to do\n";
    exit(0);
}

// The installer creates a welcome ticket. It would sit on every board and
// make the expected ticket sets fuzzy, so it goes.
foreach (Ticket::objects() as $t) {
    $t->delete();
}
if (scalar("SELECT COUNT(*) FROM " . TICKET_TABLE) > 0) {
    fail('could not remove the installer ticket');
}

// ------------------------------------------------------------- settings

// Agent panel: no forced password change, no lockout surprises in tests.
$cfg->updateAll(array(
    'enable_captcha'   => 0,
    'client_registration' => 'public',
    'collaborator_ticket_visibility' => 1,
));

// --------------------------------------------------------------- status

q("INSERT INTO " . TICKET_STATUS_TABLE . " (name, state, mode, flags, sort, properties, created, updated)
   VALUES ('In Progress', 'open', 3, 0, 2, '{\"description\":\"Being worked on\"}', NOW(), NOW())");
$inProgress = (int) db_insert_id();
$open = (int) scalar("SELECT id FROM " . TICKET_STATUS_TABLE . " WHERE name='Open'");
$closed = (int) scalar("SELECT id FROM " . TICKET_STATUS_TABLE . " WHERE name='Closed'");

// ------------------------------------------------------- orgs and users

function org(string $name, int $flags): int
{
    $org = Organization::fromVars(array('name' => $name));
    if (!$org) {
        fail("org $name");
    }
    // Sharing bits live in the "status" column (Organization::check()).
    $org->set('status', (int) $org->get('status') | $flags);
    $org->save();
    return (int) $org->getId();
}

$acme = org('Acme Corp', Organization::SHARE_EVERYBODY);
$globex = org('Globex', 0);

function client(string $email, string $name, int $orgId, string $pass): User
{
    $user = User::fromVars(array('email' => $email, 'name' => $name));
    if (!$user) {
        fail("user $email");
    }
    $user->org_id = $orgId;
    $user->save();
    $errors = array();
    if (!UserAccount::register($user, array(
        'passwd1' => $pass, 'passwd2' => $pass, 'timezone' => 'UTC', 'backend' => 'client',
    ), $errors)) {
        fail("account $email: " . json_encode($errors));
    }
    return $user;
}

client('alice@acme.example', 'Alice Acme', $acme, $clientPass);
client('bob@acme.example', 'Bob Acme', $acme, $clientPass);
client('carol@globex.example', 'Carol Globex', $globex, $clientPass);

// --------------------------------------------------------------- tickets

$support = (int) scalar("SELECT id FROM " . DEPT_TABLE . " WHERE name='Support'");
$sales = (int) scalar("SELECT id FROM " . DEPT_TABLE . " WHERE name='Sales'");

$ids = array();
function ticket(string $key, string $email, string $subject, int $dept): int
{
    global $ids;
    $errors = array();
    $t = Ticket::create(array(
        'email'   => $email,
        'subject' => $subject,
        'message' => "Seed ticket $key.",
        'deptId'  => $dept,
        'ip'      => '127.0.0.1',
        'source'  => 'API',
    ), $errors, 'api', false, false);
    if (!$t) {
        fail("ticket $key: " . json_encode($errors));
    }
    $ids[$key] = (int) $t->getId();
    return $ids[$key];
}

ticket('A1', 'alice@acme.example', 'Website relaunch', $support);
ticket('A2', 'alice@acme.example', 'Design mockups', $support);
ticket('A3', 'bob@acme.example', 'Content migration', $support);
ticket('A4', 'alice@acme.example', 'Old DNS cleanup', $support);
ticket('A5', 'bob@acme.example', 'Invoice question', $support);
ticket('X1', 'alice@acme.example', '<img src=x onerror="window.__spbXss=1"> markup in subject', $support);
ticket('G1', 'carol@globex.example', 'Globex confidential roadmap', $support);
ticket('G2', 'carol@globex.example', 'Globex child of an Acme ticket', $support);
ticket('S1', 'carol@globex.example', 'Sales inquiry from Globex', $sales);

// Hierarchy the way sub-ticket plugins store it: ticket_pid only.
// osTicket strips tags from subjects on create. Markup can still reach the
// table through imports, the API of other plugins or direct SQL, and the
// board must render it as text. So X1 gets it written in raw.
q(sprintf("UPDATE %s SET subject=%s WHERE ticket_id=%d", TABLE_PREFIX . 'ticket__cdata',
    db_input('<img src=x onerror="window.__spbXss=1"> markup in subject'), $ids['X1']));

foreach (array('A2', 'A3', 'A4', 'G2') as $child) {
    q(sprintf("UPDATE %s SET ticket_pid=%d WHERE ticket_id=%d", TICKET_TABLE, $ids['A1'], $ids[$child]));
}
q(sprintf("UPDATE %s SET status_id=%d WHERE ticket_id=%d", TICKET_TABLE, $inProgress, $ids['A2']));
q(sprintf("UPDATE %s SET status_id=%d, closed=NOW() WHERE ticket_id=%d", TICKET_TABLE, $closed, $ids['A4']));

// Distinct activity times so card order is deterministic.
$order = array('S1', 'G2', 'G1', 'X1', 'A5', 'A4', 'A3', 'A2', 'A1');
foreach ($order as $i => $key) {
    q(sprintf("UPDATE %s SET lastupdate=NOW() - INTERVAL %d MINUTE WHERE ticket_id=%d",
        TICKET_TABLE, ($i + 1) * 10, $ids[$key]));
}

Ticket::lookup($ids['A1'])->logNote('Internal', 'INTERNAL-NOTE-MARKER-7f3a do not show to customers', 'SYSTEM', false);

// ---------------------------------------------------------------- agents

$adminId = (int) scalar("SELECT staff_id FROM " . STAFF_TABLE . " ORDER BY staff_id LIMIT 1");
// The admin gets every department, so the agent board shows the full set.
foreach (array($sales, (int) scalar("SELECT id FROM " . DEPT_TABLE . " WHERE name='Maintenance'")) as $d) {
    q(sprintf("INSERT IGNORE INTO %s (staff_id, dept_id, role_id, flags) VALUES (%d, %d, 1, 1)",
        STAFF_DEPT_TABLE, $adminId, $d));
}

$viewOnly = (int) scalar("SELECT id FROM " . ROLE_TABLE . " WHERE name='View only'");
$errors = array();
$agent = Staff::create(array(
    'username'  => $agentUser,
    'firstname' => 'Sales',
    'lastname'  => 'Agent',
    'email'     => 'sales.agent@example.com',
    'dept_id'   => $sales,
    'role_id'   => $viewOnly,
    'isactive'  => 1,
    'isadmin'   => 0,
    'isvisible' => 1,
));
// Saved first: setPassword() clears reset tokens by staff ID.
if (!$agent->save()) {
    fail('agent save');
}
$agent->setPassword($agentPass);
$agent->save();

// ---------------------------------------------------------------- plugin

$plugin = $ost->plugins->install('board');
if (!$plugin) {
    fail('plugin install');
}
$pid = (int) $plugin->getId();
q(sprintf("UPDATE %s SET isactive=1 WHERE id=%d", PLUGIN_TABLE, $pid));
q(sprintf("INSERT INTO %s (plugin_id, flags, name, notes, created, updated) VALUES (%d, 1, 'default', '', NOW(), NOW())",
    PLUGIN_INSTANCE_TABLE, $pid));
$iid = (int) db_insert_id();
$ns = sprintf('plugin.%d.instance.%d', $pid, $iid);
foreach (array('enable_staff' => '1', 'enable_client' => '1', 'client_nav' => '1',
               'closed_days' => '14', 'max_cards' => '300') as $k => $v) {
    q(sprintf("INSERT INTO %s (namespace, `key`, value, updated) VALUES ('%s', '%s', '%s', NOW())",
        CONFIG_TABLE, $ns, $k, $v));
}

// Optional local additions (other plugins, a theme), mounted by
// dev/local/compose.override.yml. Must not write to stdout.
if (is_file('/seed-local/seed.php')) {
    require '/seed-local/seed.php';
}

echo json_encode(array(
    'tickets' => $ids,
    'numbers' => array_map(fn($id) => Ticket::lookup($id)->getNumber(), $ids),
    'status'  => array('open' => $open, 'in_progress' => $inProgress, 'closed' => $closed),
    'plugin'  => array('id' => $pid, 'instance' => $iid),
), JSON_PRETTY_PRINT) . "\n";
