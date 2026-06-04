<?php

/**
 * Pipelinq ForecastAccessPolicy.
 *
 * Group-based authorization for forecast read, override and quota actions,
 * scoped to the requester's hierarchy (ADR-005).
 *
 * @category Lifecycle
 * @package  OCA\Pipelinq\Lifecycle
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Forecast permission checks.
 *
 * The model maps the three granular permissions (forecast:read,
 * forecast:override, forecast:quota:set) onto Nextcloud groups, each scoped to a
 * level. A rep may always read their own (rep-level, own owner_id) forecast.
 * Managers (a member of the forecast manager group, or an admin) may read,
 * override and set quota at team/division/company level. Fails closed.
 */
class ForecastAccessPolicy
{
    /**
     * App-config key for the forecast manager group.
     *
     * @var string
     */
    public const MANAGER_GROUP_KEY = 'forecast_manager_group';

    /**
     * Constructor.
     *
     * @param IAppConfig    $appConfig    The app configuration.
     * @param IGroupManager $groupManager The NC group manager.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * Whether a user is a forecast manager (member of the manager group or admin).
     *
     * @param string $userId The acting user UID.
     *
     * @return bool True when the user is a forecast manager.
     */
    public function isManager(string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        if ($this->groupManager->isAdmin($userId) === true) {
            return true;
        }

        $group = $this->appConfig->getValueString(Application::APP_ID, self::MANAGER_GROUP_KEY, '');
        if ($group === '') {
            return false;
        }

        return $this->groupManager->isInGroup($userId, $group);
    }//end isManager()

    /**
     * Whether a user may read forecast snapshots at a level for an owner.
     *
     * A rep may read only their own rep-level snapshot. Anything above rep level,
     * or another owner's data, requires the manager role.
     *
     * @param string      $userId  The acting user UID.
     * @param string      $level   The requested level.
     * @param string|null $ownerId The requested owner filter (null = all).
     *
     * @return bool True when reading is permitted.
     *
     * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-011-01, REQ-FRC-010-03
     */
    public function canRead(string $userId, string $level, ?string $ownerId): bool
    {
        if ($userId === '') {
            return false;
        }

        if ($this->isManager(userId: $userId) === true) {
            return true;
        }

        return $level === 'rep' && $ownerId !== null && $ownerId === $userId;
    }//end canRead()

    /**
     * Whether a user may create a forecast override at a level.
     *
     * Only managers may override. Override is never a self-service rep action.
     *
     * @param string $userId The acting user UID.
     * @param string $level  The override level.
     *
     * @return bool True when overriding is permitted.
     *
     * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-011-02
     */
    public function canOverride(string $userId, string $level): bool
    {
        if (in_array($level, ['rep', 'team', 'division'], true) === false) {
            return false;
        }

        return $this->isManager(userId: $userId);
    }//end canOverride()

    /**
     * Whether a user may set a quota at a level.
     *
     * Only managers may set quotas.
     *
     * @param string $userId The acting user UID.
     * @param string $level  The quota level.
     *
     * @return bool True when setting the quota is permitted.
     *
     * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-011-03
     */
    public function canSetQuota(string $userId, string $level): bool
    {
        if (in_array($level, ['rep', 'team', 'division'], true) === false) {
            return false;
        }

        return $this->isManager(userId: $userId);
    }//end canSetQuota()
}//end class
