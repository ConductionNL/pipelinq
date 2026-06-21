<?php

/**
 * Pipelinq ContractService.
 *
 * App-logic operations on contracts where plain OR CRUD is insufficient:
 *   - guarded lifecycle transitions (renewed/expiring/cancelled rules + terminal immutability)
 *   - unique contract-number generation (C-{year}-{seq})
 *   - successor-contract drafting on a won renewal
 *
 * Plain reads/writes go through OpenRegister directly via the frontend
 * `useObjectStore` (ADR-022); this service exposes NO pass-through CRUD wrappers.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Contract lifecycle service.
 *
 * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
 */
class ContractService
{
    /**
     * Terminal lifecycle states that reject any further transition.
     *
     * @var string[]
     */
    public const TERMINAL_STATES = ['renewed', 'churned', 'cancelled'];

    /**
     * All valid contract lifecycle states.
     *
     * @var string[]
     */
    public const VALID_STATES = ['draft', 'active', 'expiring', 'renewed', 'churned', 'cancelled'];

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig The app config.
     * @param ContainerInterface $container The DI container (ObjectService lookup).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Validate a proposed status transition.
     *
     * Guards (REQ Contract Lifecycle Management):
     *   - any transition out of a terminal state (renewed/churned/cancelled) is rejected
     *   - `renewed` requires a won renewal lead (renewalLeadOutcome === 'won')
     *   - `expiring` may only be set by the renewal engine ($byEngine === true)
     *   - `cancelled` requires a non-empty cancellationReason
     *   - the target must be a valid state
     *
     * @param array<string,mixed> $contract  The current contract object.
     * @param string              $newStatus The proposed status.
     * @param bool                $byEngine  Whether the caller is the renewal engine.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the transition is not allowed.
     *
     * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
     */
    public function assertTransitionAllowed(array $contract, string $newStatus, bool $byEngine=false): void
    {
        if (in_array($newStatus, self::VALID_STATES, true) === false) {
            throw new InvalidArgumentException(sprintf('Unknown contract status "%s".', $newStatus));
        }

        $current = (string) ($contract['status'] ?? 'draft');

        if (in_array($current, self::TERMINAL_STATES, true) === true) {
            throw new InvalidArgumentException(
                sprintf('Contract is in terminal state "%s" and cannot transition.', $current)
            );
        }

        if ($newStatus === 'expiring' && $byEngine === false) {
            throw new InvalidArgumentException('Status "expiring" may only be set by the renewal engine.');
        }

        if ($newStatus === 'renewed' && ((string) ($contract['renewalLeadOutcome'] ?? '')) !== 'won') {
            throw new InvalidArgumentException('Status "renewed" requires a won renewal lead.');
        }

        if ($newStatus === 'cancelled' && trim((string) ($contract['cancellationReason'] ?? '')) === '') {
            throw new InvalidArgumentException('Cancelling a contract requires a cancellationReason.');
        }
    }//end assertTransitionAllowed()

    /**
     * Generate the next unique contract number (C-{year}-{seq}).
     *
     * Sequence is derived from the count of existing contracts in the current
     * calendar year plus one; uniqueness is re-checked against existing numbers.
     *
     * @param array<int, array<string,mixed>> $existing Existing contract objects.
     * @param int|null                        $year     The year (defaults to current).
     *
     * @return string The next contract number.
     *
     * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
     */
    public function generateContractNumber(array $existing, ?int $year=null): string
    {
        $year ??= (int) date('Y');
        $prefix = sprintf('C-%d-', $year);

        $maxSeq = 0;
        $taken  = [];
        foreach ($existing as $contract) {
            $number         = (string) ($contract['contractNumber'] ?? '');
            $taken[$number] = true;
            if (str_starts_with($number, $prefix) === true) {
                $seq = (int) substr($number, strlen($prefix));
                if ($seq > $maxSeq) {
                    $maxSeq = $seq;
                }
            }
        }

        do {
            $maxSeq++;
            $candidate = sprintf('%s%03d', $prefix, $maxSeq);
        } while (isset($taken[$candidate]) === true);

        return $candidate;
    }//end generateContractNumber()

    /**
     * Build a successor-contract draft from a renewed predecessor.
     *
     * StartDate = predecessor endDate + 1 day; status `draft`;
     * predecessorContractRef set; renewal-specific fields reset.
     *
     * @param array<string,mixed> $predecessor The renewed contract.
     *
     * @return array<string,mixed> The successor draft payload.
     *
     * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-renewal-lead-automation
     */
    public function buildSuccessorDraft(array $predecessor): array
    {
        $successorStart = '';
        $endDate        = (string) ($predecessor['endDate'] ?? '');
        if ($endDate !== '') {
            $ts = strtotime($endDate.' +1 day');
            if ($ts !== false) {
                $successorStart = date('Y-m-d', $ts);
            }
        }

        $valuePerInterval = (float) ($predecessor['valuePerInterval'] ?? 0);

        return [
            'title'                  => (string) ($predecessor['title'] ?? ''),
            'clientRef'              => (string) ($predecessor['clientRef'] ?? ''),
            'lineItems'              => $predecessor['lineItems'] ?? [],
            'billingInterval'        => (string) ($predecessor['billingInterval'] ?? 'monthly'),
            'valuePerInterval'       => $valuePerInterval,
            'value'                  => $valuePerInterval,
            'currency'               => (string) ($predecessor['currency'] ?? 'EUR'),
            'startDate'              => $successorStart,
            'autoRenew'              => (bool) ($predecessor['autoRenew'] ?? false),
            'noticePeriodDays'       => (int) ($predecessor['noticePeriodDays'] ?? 0),
            'status'                 => 'draft',
            'ownerId'                => (string) ($predecessor['ownerId'] ?? ''),
            'predecessorContractRef' => (string) ($predecessor['id'] ?? ($predecessor['uuid'] ?? '')),
            'renewalLeadOutcome'     => '',
            'noticeReminderSent'     => false,
        ];
    }//end buildSuccessorDraft()

    /**
     * Resolve the configured register and contract-schema IDs.
     *
     * @return array{0:string,1:string} [registerId, schemaId].
     *
     * @spec exclude config-key plumbing — resolves the OR register/schema ids the lifecycle ops scope to
     */
    public function getRegisterAndSchema(): array
    {
        return [
            $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
            $this->appConfig->getValueString(Application::APP_ID, 'contract_schema', ''),
        ];
    }//end getRegisterAndSchema()

    /**
     * Persist a contract object via OpenRegister.
     *
     * @param array<string,mixed> $data The contract payload.
     * @param string|null         $uuid The existing UUID, or null to create.
     *
     * @return array<string,mixed>|null The saved object (array), or null on failure.
     *
     * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
     */
    public function save(array $data, ?string $uuid=null): ?array
    {
        [$registerId, $schemaId] = $this->getRegisterAndSchema();
        if ($registerId === '' || $schemaId === '') {
            $this->logger->warning('ContractService: register/contract_schema not configured');
            return null;
        }

        // Keep the portal-readable `value` alias in sync with valuePerInterval.
        if (isset($data['valuePerInterval']) === true) {
            $data['value'] = $data['valuePerInterval'];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $saved         = $objectService->saveObject($data, [], $registerId, $schemaId, $uuid);
            if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
                return $saved->jsonSerialize();
            }

            return (array) $saved;
        } catch (Throwable $e) {
            $this->logger->error('ContractService: save failed', ['error' => $e->getMessage()]);
            return null;
        }//end try
    }//end save()

    /**
     * Load all contracts from OpenRegister ([] on failure).
     *
     * @return array<int, array<string,mixed>> The contract objects.
     *
     * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
     */
    public function loadAll(): array
    {
        [$registerId, $schemaId] = $this->getRegisterAndSchema();
        if ($registerId === '' || $schemaId === '') {
            return [];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $results       = $objectService->findAll(
                [
                    'filters' => ['register' => $registerId, 'schema' => $schemaId],
                    'limit'   => 10000,
                ]
            );

            $contracts = [];
            foreach ($results as $row) {
                if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                    $contracts[] = $row->jsonSerialize();
                } else if (is_array($row) === true) {
                    $contracts[] = $row;
                }
            }

            return $contracts;
        } catch (Throwable $e) {
            $this->logger->warning('ContractService: loadAll failed', ['error' => $e->getMessage()]);
            return [];
        }//end try
    }//end loadAll()
}//end class
