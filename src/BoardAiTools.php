<?php

declare(strict_types=1);

/**
 * Board actions for the AI Actions plugin, registered through its signal
 * ai-actions.tools (see that plugin's docs/EXTENSIONS.md). Only actions the
 * quick view has and the assistant doesn't: release an assignment, mark a
 * ticket answered or unanswered. They run through BoardController::act(),
 * so the quick view's permission checks apply to the agent who confirms.
 *
 * Without the AI Actions plugin nobody sends the signal and this does
 * nothing.
 */
final class BoardAiTools
{
    public static function register($registry): void
    {
        if (!is_object($registry) || !method_exists($registry, 'add')) {
            return;
        }
        $registry->add(array(
            'name'         => 'x_board_release',
            'label'        => 'Release assignment',
            'description'  => 'Plan to release the ticket\'s current assignment (agent and team), as the board\'s '
                            . '"Release" button does. Only when the agent asks to unassign or release the ticket.',
            'input_schema' => array(
                'type'                 => 'object',
                'properties'           => array('confirm' => array('type' => 'string', 'enum' => array('yes'))),
                'required'             => array('confirm'),
                'additionalProperties' => false,
            ),
            'validate'     => static function (array $input, $ticket, $staff) {
                return ($input['confirm'] ?? '') === 'yes' && self::mayRelease($ticket, $staff)
                    ? array('confirm' => 'yes') : null;
            },
            'run'          => static function (array $input, $ticket, $staff) {
                return self::result(BoardController::act($ticket, $staff, 'release'));
            },
            'summary'      => static function (array $input) {
                return 'agent and team';
            },
        ));
        $registry->add(array(
            'name'         => 'x_board_answered',
            'label'        => 'Mark as',
            'description'  => 'Plan to mark the ticket as answered or unanswered, as the board\'s buttons do.',
            'input_schema' => array(
                'type'                 => 'object',
                'properties'           => array('mark' => array('type' => 'string', 'enum' => array('answered', 'unanswered'))),
                'required'             => array('mark'),
                'additionalProperties' => false,
            ),
            'validate'     => static function (array $input, $ticket, $staff) {
                $mark = $input['mark'] ?? '';
                return in_array($mark, array('answered', 'unanswered'), true) && self::mayMark($ticket, $staff)
                    ? array('mark' => $mark) : null;
            },
            'run'          => static function (array $input, $ticket, $staff) {
                return self::result(BoardController::act($ticket, $staff, $input['mark']));
            },
            'summary'      => static function (array $input) {
                return $input['mark'];
            },
        ));
    }

    /** The checks of runAct('release'), so the model isn't offered what would be refused. */
    private static function mayRelease($ticket, $staff): bool
    {
        return ($ticket->checkStaffPerm($staff, Ticket::PERM_RELEASE) || $staff->isManager())
            && ($ticket->getStaff() || $ticket->getTeam());
    }

    /** The checks of runAct('answered'). */
    private static function mayMark($ticket, $staff): bool
    {
        return ($ticket->checkStaffPerm($staff, Ticket::PERM_MARKANSWERED) || $staff->isManager($ticket->getDept()))
            && $ticket->isOpen();
    }

    /** runAct()'s [code, payload] as the AI plugin expects it. Public for the unit tests. */
    public static function result(array $r): array
    {
        if (($r[0] ?? 0) === 200 && !empty($r[1]['ok'])) {
            return array('ok' => true);
        }
        return array('ok' => false, 'message' => (string) ($r[1]['message'] ?? $r[1]['error'] ?? 'rejected'));
    }
}
