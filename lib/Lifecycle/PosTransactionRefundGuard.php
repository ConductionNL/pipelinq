<?php

/**
 * Pipelinq PosTransactionRefundGuard.
 *
 * OpenRegister lifecycle guard for the posTransaction `refund` transition.
 * Enforces that the caller is a POS manager (member of the configured manager
 * group) or a Nextcloud admin — a confirmed/settled transaction may only be
 * voided/refunded by a manager. Referenced from the posTransaction schema's
 * x-openregister-lifecycle.transitions.refund.requires.
 *
 * @category Lifecycle
 * @package  OCA\Pipelinq\Lifecycle
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Guards the posTransaction `refund` transition (manager only).
 *
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.4
 */
class PosTransactionRefundGuard implements LifecycleGuardInterface
{
    /**
     * Constructor.
     *
     * @param PosAccessPolicy $policy The shared POS access policy.
     */
    public function __construct(private PosAccessPolicy $policy)
    {
    }//end __construct()

    /**
     * Authorise the refund transition.
     *
     * @param array<string, mixed> $object The posTransaction payload.
     * @param string               $action The transition action ('refund').
     * @param string               $userId The acting user UID.
     *
     * @return GuardResult Allow only for a POS manager or admin; deny otherwise.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the
     *  static allow()/deny() factories mandated by OpenRegister's contract.
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $object and $action are part
     *  of the LifecycleGuardInterface signature; the refund verdict depends only
     *  on the caller's manager status.
     *
     * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.4
     */
    public function check(array $object, string $action, string $userId): GuardResult
    {
        if ($this->policy->isManager(userId: $userId) === false) {
            return GuardResult::deny('Alleen een beheerder mag een transactie terugboeken.');
        }

        return GuardResult::allow();
    }//end check()
}//end class
