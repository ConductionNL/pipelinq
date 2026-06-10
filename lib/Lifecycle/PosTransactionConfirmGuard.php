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
        // Fall back to the numeric schema id resolved from the canonical slug
        // when the deployed app-config leaves posTransactionLine_schema blank.
        // findAll()'s `@self.schema` filter requires a numeric id (a slug
        // silently matches nothing), so resolve via OpenRegister's SchemaMapper.
        // Mirrors PosTransactionService::config() so the non-empty-cart
        // precondition can be verified instead of failing closed on a blank
        // config (which previously denied every confirm on such a box).
        if ($schema === '') {
            $schema = $this->resolveSchemaIdBySlug(slug: 'posTransactionLine');
        }

        if ($register === '' || $schema === '') {
            return true;
        }

        try {
            // Register + schema scope MUST be nested under `@self` (flat
            // filters.register / filters.schema are treated as ordinary property
            // filters and match nothing, which made the guard see every cart as
            // empty and deny every confirm). Transaction stays a top-level filter.
            $results = $this->container->get('OCA\OpenRegister\Service\ObjectService')->findAll(
                config: [
                    'filters' => [
                        '@self'       => [
                            'register' => $register,
                            'schema'   => $schema,
                        ],
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
        }//end try

        return count(($results ?? [])) === 0;
    }//end cartIsEmpty()

    /**
     * Resolve a schema slug to its numeric OpenRegister schema id.
     *
     * Fallback for when the `<slug>_schema` app-config key is empty on a
     * deployed instance. OpenRegister's SchemaMapper::find() accepts an id, uuid
     * or slug; the numeric id is required by the findAll() `@self.schema` filter.
     * Returns '' when unresolvable (the caller then fails closed as before).
     *
     * @param string $slug The canonical schema slug (e.g. 'posTransactionLine').
     *
     * @return string The numeric schema id, or '' when it cannot be resolved.
     *
     * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.3
     */
    private function resolveSchemaIdBySlug(string $slug): string
    {
        if ($slug === '') {
            return '';
        }

        try {
            $schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
            // RBAC + multi-tenancy disabled: server-authoritative internal id
            // lookup; the POS schema may be owned by another organisation than
            // the acting cashier, where the default org filter would throw.
            // OpenRegister's Schema uses magic getters, so method_exists(...,
            // 'getId') is false even though getId() resolves — call it directly.
            $schema = $schemaMapper->find($slug, [], null, false, false);
            if (is_object($schema) === true) {
                return (string) $schema->getId();
            }

            return '';
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: confirm guard could not resolve line schema slug (fail closed)',
                ['slug' => $slug, 'exception' => $e->getMessage()]
            );
            return '';
        }
    }//end resolveSchemaIdBySlug()
}//end class
