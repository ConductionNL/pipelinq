<?php

/**
 * Pipelinq AvgAccessService.
 *
 * Server-side access-control for the AVG workflow (ADR-005). Maps the four
 * workflow roles (handler, team lead, FG/DPO, admin) onto configurable
 * Nextcloud groups and answers the per-request authorization questions the
 * services ask: who may view / edit a request, who may export or escalate a
 * dossier, and who may override the retention guard. Fails closed.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Avg
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Access-control policy for AVG requests.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
 */
class AvgAccessService
{
    /**
     * App-config key naming the AVG handler group.
     *
     * @var string
     */
    public const HANDLER_GROUP_KEY = 'avg_handler_group';

    /**
     * App-config key naming the AVG team-lead group.
     *
     * @var string
     */
    public const TEAMLEAD_GROUP_KEY = 'avg_teamlead_group';

    /**
     * App-config key naming the FG / DPO group.
     *
     * @var string
     */
    public const DPO_GROUP_KEY = 'avg_dpo_group';

    /**
     * Constructor.
     *
     * @param IGroupManager $groupManager The group manager.
     * @param IAppConfig    $appConfig    The app config.
     */
    public function __construct(
        private IGroupManager $groupManager,
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Whether a user is a Nextcloud admin.
     *
     * @param string $userId The acting user UID.
     *
     * @return bool True when the user is an admin.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    public function isAdmin(string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        return $this->groupManager->isAdmin($userId);
    }//end isAdmin()

    /**
     * Whether a user is an FG / Data Protection Officer (or admin).
     *
     * The DPO has read access to every request and may export dossiers, escalate
     * to the AP and override the retention guard. Fails closed: with no DPO group
     * configured only admins qualify.
     *
     * @param string $userId The acting user UID.
     *
     * @return bool True when the user is a DPO / admin.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.8
     */
    public function isDpo(string $userId): bool
    {
        return $this->inRoleGroup(userId: $userId, key: self::DPO_GROUP_KEY);
    }//end isDpo()

    /**
     * Whether a user is an AVG team lead / coordinator (or admin).
     *
     * A team lead sees and may reassign every request in the team.
     *
     * @param string $userId The acting user UID.
     *
     * @return bool True when the user is a team lead / admin.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    public function isTeamLead(string $userId): bool
    {
        return $this->inRoleGroup(userId: $userId, key: self::TEAMLEAD_GROUP_KEY);
    }//end isTeamLead()

    /**
     * Whether a user is an AVG handler (or a higher role).
     *
     * Any handler may register intake and work the requests assigned to them.
     *
     * @param string $userId The acting user UID.
     *
     * @return bool True when the user may act as a handler.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    public function isHandler(string $userId): bool
    {
        if ($this->isTeamLead(userId: $userId) === true || $this->isDpo(userId: $userId) === true) {
            return true;
        }

        return $this->inRoleGroup(userId: $userId, key: self::HANDLER_GROUP_KEY);
    }//end isHandler()

    /**
     * Whether a user may view a specific request (IDOR guard).
     *
     * Team leads, DPOs and admins see all requests; a plain handler sees only the
     * requests they are the assigned behandelaar of. Fails closed for an empty
     * user.
     *
     * @param array<string, mixed> $request The request payload.
     * @param string               $userId  The acting user UID.
     *
     * @return bool True when the user may view the request.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    public function canView(array $request, string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        if ($this->isTeamLead(userId: $userId) === true || $this->isDpo(userId: $userId) === true) {
            return true;
        }

        return ((string) ($request['behandelaar'] ?? '') === $userId);
    }//end canView()

    /**
     * Whether a user may edit a specific request.
     *
     * The assigned handler, a team lead, or an admin may edit. The DPO has a
     * monitoring (read-only) posture and may not edit handler fields.
     *
     * @param array<string, mixed> $request The request payload.
     * @param string               $userId  The acting user UID.
     *
     * @return bool True when the user may edit the request.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    public function canEdit(array $request, string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        if ($this->isTeamLead(userId: $userId) === true) {
            return true;
        }

        return ((string) ($request['behandelaar'] ?? '') === $userId
            && $this->isHandler(userId: $userId) === true);
    }//end canEdit()

    /**
     * Resolve membership of a configurable role group, with admin override.
     *
     * @param string $userId The acting user UID.
     * @param string $key    The app-config key naming the group.
     *
     * @return bool True when the user is in the group or is an admin.
     */
    private function inRoleGroup(string $userId, string $key): bool
    {
        if ($userId === '') {
            return false;
        }

        if ($this->groupManager->isAdmin($userId) === true) {
            return true;
        }

        $group = $this->appConfig->getValueString(Application::APP_ID, $key, '');
        if ($group === '') {
            return false;
        }

        return $this->groupManager->isInGroup($userId, $group);
    }//end inRoleGroup()
}//end class
