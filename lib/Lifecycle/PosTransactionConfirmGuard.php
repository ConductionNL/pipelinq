<?php

/**
 * Pipelinq PosTransactionConfirmGuard.
 *
 * OpenRegister lifecycle guard for the posTransaction `confirm` transition.
 * Enforces (a) the cashier-owner OR POS-group OR admin access rule (closing the
 * IDOR) and (b) the non-empty-cart precondition: a transaction may only be
 * confirmed when it has at least one persisted line item. The
 * server-authoritative total RECOMPUTE itself is performed by
 * PosTransactionService immediately before the transition (a guard must be
 * read-only and may not mutate the object); this guard verifies the precondition
 * that makes that recompute meaningful. Referenced from the posTransaction
 * schema's x-openregister-lifecycle.transitions.confirm.requires.
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
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the posTransaction `confirm` transition.
 *
 * Access (owner/group/admin) + non-empty cart. Fails closed.
 *
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.3
 */
class PosTransactionConfirmGuard implements LifecycleGuardInterface
{
    /**
     * Constructor.
     *
     * @param PosAccessPolicy    $policy    The shared POS access policy.
     * @param ContainerInterface $container The DI container (OR ObjectService).
     * @param IAppConfig         $appConfig The app config.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private PosAccessPolicy $policy,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Authorise the confirm transition.
     *
     * @param array<string, mixed> $object The posTransaction payload.
     * @param string               $action The transition action ('confirm').
     * @param string               $userId The acting user UID.
     *
     * @return GuardResult Allow when the user may access the transaction AND the
     *                     cart is non-empty; deny otherwise.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the
     *  static allow()/deny() factories mandated by OpenRegister's contract.
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action is part of the
     *  LifecycleGuardInterface signature; this guard only runs for confirm.
     *
     * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.3
     */
    public function check(array $object, string $action, string $userId): GuardResult
    {
        if ($this->policy->canAccessTransaction(object: $object, userId: $userId) === false) {
            return GuardResult::deny(
                'U mag deze transactie niet bevestigen. Alleen de eigen kassamedewerker, '
                .'een lid van de POS-groep of een beheerder is gemachtigd.'
            );
        }

        $transactionId = (string) ($object['id'] ?? $object['uuid'] ?? '');
        if ($this->cartIsEmpty(transactionId: $transactionId) === true) {
            return GuardResult::deny('Voeg minimaal één artikel toe.');
        }

        return GuardResult::allow();
    }//end check()

    /**
     * Whether the transaction has no persisted line items.
     *
     * Fails closed: if the line lookup cannot be performed (OR unavailable or
     * schema unconfigured), the cart is treated as empty so the confirm is
     * denied rather than allowed on a blind guess.
     *
     * @param string $transactionId The transaction UUID.
     *
     * @return bool Whether the cart is empty (or could not be verified).
     *
     * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.3
     */
    private function cartIsEmpty(string $transactionId): bool
    {
        if ($transactionId === '') {
            return true;
        }

        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'posTransactionLine_schema', '');
        if ($register === '' || $schema === '') {
            return true;
        }

        try {
            $results = $this->container->get('OCA\OpenRegister\Service\ObjectService')->findAll(
                config: [
                    'filters' => [
                        'register'    => $register,
                        'schema'      => $schema,
                        'transaction' => $transactionId,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: confirm guard could not verify cart contents (fail closed)',
                ['exception' => $e->getMessage(), 'transaction' => $transactionId]
            );
            return true;
        }

        return count(($results ?? [])) === 0;
    }//end cartIsEmpty()
}//end class
