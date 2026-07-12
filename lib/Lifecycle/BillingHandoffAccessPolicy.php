<?php

/**
 * Pipelinq BillingHandoffAccessPolicy.
 *
 * Authorization predicate for the manual "Send to billing" time-intake
 * handoff (time-billing-handoff-emit). A manager is a member of the
 * configured manager group (`billing_handoff_manager_group`) or a Nextcloud
 * administrator. Fails closed: when no group is configured, only NC admins
 * qualify — mirrors {@see \OCA\Pipelinq\Lifecycle\PosAccessPolicy::isManager()}
 * and {@see \OCA\Pipelinq\Lifecycle\ForecastAccessPolicy}.
 *
 * @category Lifecycle
 * @package  OCA\Pipelinq\Lifecycle
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/time-billing-handoff-emit/specs/time-approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Authorization predicate for the billing-handoff trigger endpoint.
 *
 * @spec openspec/changes/time-billing-handoff-emit/specs/time-approval-workflow/spec.md
 */
class BillingHandoffAccessPolicy
{
    /**
     * App-config key for the billing-handoff manager group.
     *
     * @var string
     */
    public const MANAGER_GROUP_KEY = 'billing_handoff_manager_group';

    /**
     * Constructor.
     *
     * @param IAppConfig    $appConfig    The app config.
     * @param IGroupManager $groupManager The group manager.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * Whether a user may trigger the "Send to billing" handoff.
     *
     * A manager is a member of the configured manager group
     * (`billing_handoff_manager_group`) or a Nextcloud administrator. Fails
     * closed: an empty user or an unconfigured group never grants access
     * beyond the explicit admin check (closes the IDOR — any authenticated
     * user could otherwise trigger a billing batch for any client).
     *
     * @param string $userId The acting user UID.
     *
     * @return bool Whether the user may trigger the billing handoff.
     *
     * @spec openspec/changes/time-billing-handoff-emit/specs/time-approval-workflow/spec.md
     */
    public function isManager(string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        if ($this->groupManager->isAdmin($userId) === true) {
            return true;
        }

        $managerGroup = $this->appConfig->getValueString(Application::APP_ID, self::MANAGER_GROUP_KEY, '');
        if ($managerGroup === '') {
            return false;
        }

        return $this->groupManager->isInGroup($userId, $managerGroup);
    }//end isManager()
}//end class
