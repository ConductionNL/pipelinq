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
use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Contract lifecycle service.
 *
 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
 * @spec openspec/specs/openregister-integration/spec.md
 */
class ContractService {
	/**
	 * Schema slug whose `x-openregister-lifecycle` declares the contract status graph.
	 *
	 * @var string
	 */
	private const CONTRACT_SCHEMA_SLUG = 'salesContract';

	/**
	 * Fallback terminal lifecycle states used only when the schema declaration is
	 * unreadable. The canonical source of truth is the contract schema's
	 * `x-openregister-lifecycle.terminal` (ADR-031); this constant mirrors it.
	 *
	 * @var string[]
	 */
	public const TERMINAL_STATES = ['renewed', 'churned', 'cancelled'];

	/**
	 * All valid contract lifecycle states.
	 *
	 * Mirrors the contract schema's `status` enum; used as a fast set-membership
	 * check for the "unknown status" rejection.
	 *
	 * @var string[]
	 */
	public const VALID_STATES = ['draft', 'active', 'expiring', 'renewed', 'churned', 'cancelled'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param ContainerInterface $container The DI container (ObjectService lookup).
	 * @param LoggerInterface $logger The logger.
	 * @param SchemaLifecycleGraph $lifecycleGraph Reads the contract status graph from its schema.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private SchemaLifecycleGraph $lifecycleGraph = new SchemaLifecycleGraph(),
	) {
	}//end __construct()

	/**
	 * Resolve the contract status-transition graph from the schema declaration.
	 *
	 * The graph lives in the contract schema's `x-openregister-lifecycle`
	 * annotation (ADR-031), which OpenRegister's LifecycleValidationListener also
	 * enforces on save. Falls back to an empty map (no from-restriction beyond the
	 * conditional PHP guards) only when the declaration is unreadable, so a broken
	 * register file never regresses behavior.
	 *
	 * @return array<string, array<int, string>> The `from => [to, ...]` map.
	 */
	private function transitionGraph(): array {
		return $this->lifecycleGraph->adjacencyFor(schemaSlug: self::CONTRACT_SCHEMA_SLUG);
	}//end transitionGraph()

	/**
	 * Resolve the terminal contract states from the schema declaration.
	 *
	 * Reads `x-openregister-lifecycle.terminal` (with a `final` alias) from the
	 * contract schema. Falls back to {@see TERMINAL_STATES} when the declaration is
	 * unreadable so terminal immutability never silently disappears.
	 *
	 * @return array<int, string> The terminal states.
	 */
	private function terminalStates(): array {
		$lifecycle = $this->lifecycleGraph->lifecycleFor(schemaSlug: self::CONTRACT_SCHEMA_SLUG);
		if ($lifecycle === null) {
			return self::TERMINAL_STATES;
		}

		$terminal = ($lifecycle['terminal'] ?? ($lifecycle['final'] ?? null));
		if (is_array($terminal) === false || $terminal === []) {
			return self::TERMINAL_STATES;
		}

		return array_map(static fn ($value): string => (string)$value, $terminal);
	}//end terminalStates()

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
	 * @param array<string,mixed> $contract The current contract object.
	 * @param string $newStatus The proposed status.
	 * @param bool $byEngine Whether the caller is the renewal engine.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the transition is not allowed.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  $byEngine marks renewal-engine callers; part of the lifecycle contract, not a branch flag.
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential transition guards; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Sequential transition guards; extraction adds no clarity.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
	 */
	public function assertTransitionAllowed(array $contract, string $newStatus, bool $byEngine = false): void {
		if (in_array($newStatus, self::VALID_STATES, true) === false) {
			throw new InvalidArgumentException(sprintf('Unknown contract status "%s".', $newStatus));
		}

		$current = (string)($contract['status'] ?? 'draft');

		if (in_array($current, $this->terminalStates(), true) === true) {
			throw new InvalidArgumentException(
				sprintf('Contract is in terminal state "%s" and cannot transition.', $current)
			);
		}

		if ($newStatus === 'expiring' && $byEngine === false) {
			throw new InvalidArgumentException('Status "expiring" may only be set by the renewal engine.');
		}

		if ($newStatus === 'renewed' && ((string)($contract['renewalLeadOutcome'] ?? '')) !== 'won') {
			throw new InvalidArgumentException('Status "renewed" requires a won renewal lead.');
		}

		if ($newStatus === 'cancelled' && trim((string)($contract['cancellationReason'] ?? '')) === '') {
			throw new InvalidArgumentException('Cancelling a contract requires a cancellationReason.');
		}

		// Schema-declared adjacency mirror (ADR-031). The contract schema's
		// `x-openregister-lifecycle` is the single source of truth for the
		// from->to graph and OpenRegister's LifecycleValidationListener enforces
		// it on save; this PHP check rejects the same illegal edges *before* save
		// with the contract's own message. Same-value transitions are skipped
		// (OR skips them too). When the declaration is unreadable the graph is
		// empty and this check is a no-op, leaving the conditional guards above as
		// the sole gate (never regresses).
		$graph = $this->transitionGraph();
		if ($graph !== [] && $current !== $newStatus && isset($graph[$current]) === true) {
			if (in_array($newStatus, $graph[$current], true) === false) {
				throw new InvalidArgumentException(
					sprintf('Transition from "%s" to "%s" is not allowed.', $current, $newStatus)
				);
			}
		}
	}//end assertTransitionAllowed()

	/**
	 * Generate the next unique contract number (C-{year}-{seq}).
	 *
	 * Sequence is derived from the count of existing contracts in the current
	 * calendar year plus one; uniqueness is re-checked against existing numbers.
	 *
	 * @param array<int, array<string,mixed>> $existing Existing contract objects.
	 * @param int|null $year The year (defaults to current).
	 *
	 * @return string The next contract number.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
	 */
	public function generateContractNumber(array $existing, ?int $year = null): string {
		$year ??= (int)date('Y');
		$prefix = sprintf('C-%d-', $year);

		$maxSeq = 0;
		$taken = [];
		foreach ($existing as $contract) {
			$number = (string)($contract['contractNumber'] ?? '');
			$taken[$number] = true;
			if (str_starts_with($number, $prefix) === true) {
				$seq = (int)substr($number, strlen($prefix));
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
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-renewal-lead-automation
	 */
	public function buildSuccessorDraft(array $predecessor): array {
		$successorStart = '';
		$endDate = (string)($predecessor['endDate'] ?? '');
		if ($endDate !== '') {
			$timestamp = strtotime($endDate . ' +1 day');
			if ($timestamp !== false) {
				$successorStart = date('Y-m-d', $timestamp);
			}
		}

		$valuePerInterval = (float)($predecessor['valuePerInterval'] ?? 0);

		return [
			'title' => (string)($predecessor['title'] ?? ''),
			'clientRef' => (string)($predecessor['clientRef'] ?? ''),
			'lineItems' => $predecessor['lineItems'] ?? [],
			'billingInterval' => (string)($predecessor['billingInterval'] ?? 'monthly'),
			'valuePerInterval' => $valuePerInterval,
			'value' => $valuePerInterval,
			'currency' => (string)($predecessor['currency'] ?? 'EUR'),
			'startDate' => $successorStart,
			'autoRenew' => (bool)($predecessor['autoRenew'] ?? false),
			'noticePeriodDays' => (int)($predecessor['noticePeriodDays'] ?? 0),
			'status' => 'draft',
			'ownerId' => (string)($predecessor['ownerId'] ?? ''),
			'predecessorContractRef' => (string)($predecessor['id'] ?? ($predecessor['uuid'] ?? '')),
			'renewalLeadOutcome' => '',
			'noticeReminderSent' => false,
		];
	}//end buildSuccessorDraft()

	/**
	 * Resolve the configured register and contract-schema IDs.
	 *
	 * Fails closed: '' on either id means "unconfigured", and every caller
	 * refuses the OpenRegister call on it. An empty id must never be handed to
	 * OpenRegister — ObjectService skips setRegister()/setSchema() for an empty
	 * value, so the query silently inherits whatever context an earlier call in
	 * the same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @return array{0:string,1:string} [registerId, schemaId], each '' when
	 *                                  unconfigured.
	 *
	 * @spec exclude config-key plumbing — resolves the OR register/schema ids the lifecycle ops scope to
	 */
	public function getRegisterAndSchema(): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'contract_schema', '');
		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning(
				'Pipelinq: register/contract_schema not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return [$registerId, $schemaId];
	}//end getRegisterAndSchema()

	/**
	 * Persist a contract object via OpenRegister.
	 *
	 * @param array<string,mixed> $data The contract payload.
	 * @param string|null $uuid The existing UUID, or null to create.
	 *
	 * @return array<string,mixed>|null The saved object (array), or null on failure.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
	 */
	public function save(array $data, ?string $uuid = null): ?array {
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
			$saved = $objectService->saveObject($data, [], $registerId, $schemaId, $uuid);
			if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
				return $saved->jsonSerialize();
			}

			return (array)$saved;
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
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
	 */
	public function loadAll(): array {
		[$registerId, $schemaId] = $this->getRegisterAndSchema();
		if ($registerId === '' || $schemaId === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$results = $objectService->findAll(
				[
					'filters' => ['register' => $registerId, 'schema' => $schemaId],
					'limit' => 10000,
				]
			);

			$contracts = [];
			foreach ($results as $row) {
				if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
					$contracts[] = $row->jsonSerialize();
				} elseif (is_array($row) === true) {
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
