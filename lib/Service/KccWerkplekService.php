<?php

/**
 * Pipelinq KccWerkplekService.
 *
 * Server-side aggregation of the KCC agent workspace state — the assigned
 * requests, open tasks, queue counts and the current agent's profile — into
 * a single payload so the unified workspace UI does not have to perform
 * N+1 client-side queries on every page load (REQ-KWP-010 / REQ-KWP-020 /
 * REQ-KWP-050).
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * KCC Werkplek workspace state aggregator.
 *
 * Returns a single merged payload for the workspace page (assigned requests,
 * open tasks, queue counts, agent profile) and applies idempotent availability
 * toggles to the user's `agentProfile`.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Aggregator fans out over several
 *   independent OR read facades; each branch is guard-heavy but flat.
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
 */
class KccWerkplekService {
	/**
	 * Request statuses considered "open" for the inbox (REQ-KWP-020).
	 *
	 * @var array<int, string>
	 */
	private const OPEN_REQUEST_STATUSES = ['new', 'in_progress'];

	/**
	 * Task statuses considered "open" for the inbox (REQ-KWP-020).
	 *
	 * @var array<int, string>
	 */
	private const OPEN_TASK_STATUSES = ['open', 'in_behandeling'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — used to resolve the
	 *                                      OpenRegister `ObjectService` lazily.
	 * @param IAppConfig $appConfig App config — used to read the
	 *                              register slug and schema slugs.
	 * @param LoggerInterface $logger Logger.
	 * @param TicketService $ticketService Resolver for the unified `ticket`
	 *                                     supertype — the workspace inbox reads
	 *                                     `ticket` + `ticketType=request` instead
	 *                                     of the retired `request` schema.
	 *
	 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private TicketService $ticketService,
	) {
	}//end __construct()

	/**
	 * Resolve the OpenRegister ObjectService lazily.
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface The OpenRegister object service.
	 *
	 * @throws RuntimeException If the OpenRegister app is not installed.
	 *
	 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
	 */
	private function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Throwable $e) {
			throw new RuntimeException(
				'OpenRegister ObjectService is not available',
				0,
				$e
			);
		}
	}//end getObjectService()

	/**
	 * Resolve the OpenRegister ad-hoc AggregationRunner lazily.
	 *
	 * Resolved from the DI container the same way the ObjectService is, so the
	 * workspace can push grouped COUNT work (open requests per queue) down into
	 * OpenRegister (ADR-022) instead of hydrating every request and counting in
	 * PHP.
	 *
	 * @return object The OpenRegister aggregation runner.
	 *
	 * @throws RuntimeException If the OpenRegister app is not installed.
	 *
	 * @spec openspec/changes/pipelinq-query-pushdown-batch-3/tasks.md#task-2.1
	 */
	private function getAggregationRunner(): object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\Aggregation\\AggregationRunner');
		} catch (\Throwable $e) {
			throw new RuntimeException(
				'OpenRegister AggregationRunner is not available',
				0,
				$e
			);
		}
	}//end getAggregationRunner()

	/**
	 * Read a schema slug from the app config, falling back to a static key.
	 *
	 * @param string $schemaKey App config key (e.g. `task_schema`).
	 *
	 * @return string Resolved schema slug, or empty string when missing.
	 *
	 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
	 */
	private function getSchema(string $schemaKey): string {
		return $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
	}//end getSchema()

	/**
	 * Read the pipelinq register slug from the app config.
	 *
	 * Fails closed: '' means "unconfigured", and every caller refuses the
	 * OpenRegister call on it. An empty register must never be handed to
	 * OpenRegister — ObjectService skips setRegister() for an empty value, so
	 * the query silently inherits whatever register context an earlier call in
	 * the same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @return string Register slug (typically `pipelinq`); '' when unconfigured.
	 *
	 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
	 */
	private function getRegister(): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return $registerId;
	}//end getRegister()

	/**
	 * Normalise an OpenRegister entity (object or array) to a plain array.
	 *
	 * @param mixed $object The object or array returned by ObjectService.
	 *
	 * @return array<string, mixed> Plain associative array.
	 *
	 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true) {
			return (array)$object;
		}

		return [];
	}//end toArray()

	/**
	 * Find all objects of a given schema, swallowing OR outages so a partial
	 * workspace still renders (REQ-KWP-010 - the page must remain usable).
	 *
	 * @param string $schemaKey App config key (e.g. `queue_schema`).
	 *
	 * @return array<int, array<string, mixed>> Plain object arrays.
	 *
	 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
	 */
	private function findAllSafe(string $schemaKey): array {
		$register = $this->getRegister();
		$schema = $this->getSchema(schemaKey: $schemaKey);
		if ($register === '' || $schema === '') {
			$this->logger->info(
				message: '[KccWerkplekService] register or schema not configured',
				context: ['schemaKey' => $schemaKey]
			);
			return [];
		}

		try {
			$results = $this->getObjectService()->findAll(
				config: ['filters' => ['register' => $register, 'schema' => $schema]]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[KccWerkplekService] findAll failed',
				context: ['schemaKey' => $schemaKey, 'error' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach ($results as $result) {
			$out[] = $this->toArray(object: $result);
		}

		return $out;
	}//end findAllSafe()

	/**
	 * Find objects of a schema matching the given equality / IN filters, pushing
	 * the filter down into OpenRegister (REQ-KWP-020 — the inbox is the user's
	 * assigned-and-open subset, not the whole register).
	 *
	 * The register + schema are always injected; the caller's `$filters` are the
	 * field criteria (a scalar value is `eq`, an array value is `IN`). Swallows
	 * OR outages so a partial workspace still renders, mirroring findAllSafe().
	 *
	 * @param string $schemaKey App config key (e.g. `task_schema`).
	 * @param array<string, mixed> $filters Field criteria (eq / IN).
	 *
	 * @return array<int, array<string, mixed>> Plain object arrays.
	 *
	 * @spec openspec/changes/pipelinq-query-pushdown-batch-3/tasks.md#task-2.2
	 */
	private function findFilteredSafe(string $schemaKey, array $filters): array {
		$register = $this->getRegister();
		$schema = $this->getSchema(schemaKey: $schemaKey);
		if ($register === '' || $schema === '') {
			$this->logger->info(
				message: '[KccWerkplekService] register or schema not configured',
				context: ['schemaKey' => $schemaKey]
			);
			return [];
		}

		try {
			$results = $this->getObjectService()->findAll(
				config: ['filters' => array_merge(['register' => $register, 'schema' => $schema], $filters)]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[KccWerkplekService] filtered findAll failed',
				context: ['schemaKey' => $schemaKey, 'error' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach ($results as $result) {
			$out[] = $this->toArray(object: $result);
		}

		return $out;
	}//end findFilteredSafe()

	/**
	 * Find tickets of one subtype matching the given equality / IN filters.
	 *
	 * The `request` subtype of the unified `ticket` schema replaces the retired
	 * `request` schema (unify-ticket-supertype); resolution goes through
	 * {@see TicketService} so register/schema/discriminator stay in one place.
	 * TicketService::findByType already degrades to `[]` on an unprovisioned
	 * install or an OR outage, mirroring findFilteredSafe()'s fail-soft contract
	 * so a partial workspace still renders.
	 *
	 * @param string $ticketType One of the TicketService::TYPE_* constants.
	 * @param array<string, mixed> $filters Field criteria (eq / IN).
	 *
	 * @return array<int, array<string, mixed>> Plain object arrays.
	 *
	 * @spec openspec/changes/pipelinq-query-pushdown-batch-3/tasks.md#task-2.2
	 */
	private function findTicketsSafe(string $ticketType, array $filters): array {
		if ($this->ticketService->isConfigured() === false) {
			$this->logger->info(
				message: '[KccWerkplekService] register or ticket schema not configured',
				context: ['ticketType' => $ticketType]
			);
			return [];
		}

		$out = [];
		foreach ($this->ticketService->findByType($ticketType, $filters) as $result) {
			$out[] = $this->toArray(object: $result);
		}

		return $out;
	}//end findTicketsSafe()

	/**
	 * Count open requests grouped by the stored `queue` field, pushing the
	 * grouped COUNT down into OpenRegister rather than hydrating every request.
	 *
	 * Returns a raw map of `<stored queue ref> => <open count>`. The stored ref
	 * may be a queue slug OR a queue id (a request stores either), so the caller
	 * MUST re-map each key to a queue slug before folding — the bucket key stays
	 * computed in PHP. Degrades to an empty map when OR is unavailable.
	 *
	 * @return array<string, int> Map of raw queue ref to open-request count.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AggregationQuery::create is OR's public query factory; no DI alternative.
	 *
	 * @spec openspec/changes/pipelinq-query-pushdown-batch-3/tasks.md#task-2.4
	 */
	private function openRequestCountsByQueue(): array {
		$register = $this->ticketService->getRegisterId();
		$schema = $this->ticketService->getSchemaId();
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			// The grouped COUNT now runs over the unified `ticket` schema, so the
			// `ticketType` discriminator narrows it back to requests only.
			$query = AggregationQuery::create(
				metric: 'count',
				filter: [
					'ticketType' => TicketService::TYPE_REQUEST,
					'status' => ['in' => self::OPEN_REQUEST_STATUSES],
				],
				groupBy: ['field' => 'queue'],
			);
			$result = $this->getAggregationRunner()->runAdhocByRef(
				registerRef: $register,
				schemaRef: $schema,
				query: $query
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[KccWerkplekService] queue-count aggregation failed',
				context: ['error' => $e->getMessage()]
			);
			return [];
		}//end try

		$counts = [];
		foreach ((array)($result['groups'] ?? []) as $group) {
			$key = ($group['key'] ?? null);
			// A null/empty group key (requests with no queue) matches no queue
			// downstream; skip it here to mirror the prior `$queueRef === ''` skip.
			if ($key === null || $key === '') {
				continue;
			}

			$counts[(string)$key] = (int)($group['value'] ?? 0);
		}

		return $counts;
	}//end openRequestCountsByQueue()

	/**
	 * Build the aggregated workspace state payload for one agent.
	 *
	 * Returns the agent profile (with sensible defaults if none exists),
	 * the assigned-and-open requests, the open tasks assigned to the user
	 * and the queue counts (open requests grouped by queue UUID).
	 *
	 * @param string $userId Nextcloud user UID of the agent.
	 *
	 * @return array{agentProfile: array<string, mixed>, assignedRequests: array<int, array<string, mixed>>,
	 *               openTasks: array<int, array<string, mixed>>, queueCounts: array<string, int>,
	 *               queues: array<int, array<string, mixed>>} Workspace state.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential guard clauses assembling one payload; extraction adds no clarity.
	 *
	 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
	 */
	public function getWorkspaceState(string $userId): array {
		// Push the assigned-and-open inbox subset down into OpenRegister instead
		// of hydrating every request/task. The prior PHP path compared
		// `(string)($row[assignee] ?? '') === $userId` — and `$userId` is the
		// authenticated UID (never empty), so a missing assignee never matched;
		// an `eq` filter on `assignee` reproduces this exactly. The matching
		// subset keeps OpenRegister's natural order (the prior loop applied no
		// sort), so the lists are unchanged.
		$assignedRequests = $this->findTicketsSafe(
			ticketType: TicketService::TYPE_REQUEST,
			filters: ['assignee' => $userId, 'status' => self::OPEN_REQUEST_STATUSES]
		);

		$openTasks = $this->findFilteredSafe(
			schemaKey: 'task_schema',
			filters: ['assigneeUserId' => $userId, 'status' => self::OPEN_TASK_STATUSES]
		);

		$allAgents = $this->findAllSafe(schemaKey: 'agentProfile_schema');
		$allQueues = $this->findAllSafe(schemaKey: 'queue_schema');

		// Resolve the agent profile for this user (fallback: sensible defaults).
		$agentProfile = [
			'userId' => $userId,
			'isAvailable' => false,
			'maxConcurrent' => 0,
			'skills' => [],
		];
		foreach ($allAgents as $candidate) {
			if ((string)($candidate['userId'] ?? '') === $userId) {
				$agentProfile = [
					'id' => (string)($candidate['id'] ?? ($candidate['@self']['id'] ?? '')),
					'userId' => $userId,
					'isAvailable' => (bool)($candidate['isAvailable'] ?? false),
					'maxConcurrent' => (int)($candidate['maxConcurrent'] ?? 0),
					'skills' => (array)($candidate['skills'] ?? []),
				];
				break;
			}
		}

		// Compute the queue counts across all queues (open requests per queue UUID).
		// The grouped COUNT is pushed down into OpenRegister; the raw group key
		// (the stored `queue` ref, which a request may store as either the queue
		// slug or its id) is re-mapped to the queue slug HERE — the bucket key
		// stays computed in PHP because OpenRegister groups on the raw column.
		$queueCounts = [];
		foreach ($allQueues as $queue) {
			$slug = (string)($queue['@self']['slug'] ?? $queue['slug'] ?? '');
			if ($slug !== '') {
				$queueCounts[$slug] = 0;
			}
		}

		foreach ($this->openRequestCountsByQueue() as $queueRef => $count) {
			// Match queue by id or slug (the request may store either). A ref
			// that matches no queue is dropped, mirroring the prior loop's
			// no-match path.
			foreach ($allQueues as $queue) {
				$qSlug = (string)($queue['@self']['slug'] ?? $queue['slug'] ?? '');
				$qId = (string)($queue['@self']['id'] ?? $queue['id'] ?? '');
				if ((string)$queueRef === $qSlug || (string)$queueRef === $qId) {
					$queueCounts[$qSlug] = ($queueCounts[$qSlug] ?? 0) + $count;
					break;
				}
			}
		}

		// Strip private fields and order queues by sortOrder for the menu.
		$queues = [];
		foreach ($allQueues as $queue) {
			if ((bool)($queue['isActive'] ?? true) === false) {
				continue;
			}

			$queues[] = [
				'id' => (string)($queue['@self']['id'] ?? $queue['id'] ?? ''),
				'slug' => (string)($queue['@self']['slug'] ?? $queue['slug'] ?? ''),
				'title' => (string)($queue['title'] ?? ''),
				'sortOrder' => (int)($queue['sortOrder'] ?? 0),
				'maxCapacity' => $queue['maxCapacity'] ?? null,
			];
		}

		usort(
			$queues,
			static fn (array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']
		);

		return [
			'agentProfile' => $agentProfile,
			'assignedRequests' => $assignedRequests,
			'openTasks' => $openTasks,
			'queueCounts' => $queueCounts,
			'queues' => $queues,
		];
	}//end getWorkspaceState()

	/**
	 * Set the availability flag on the calling agent's profile.
	 *
	 * Idempotent: if no profile exists for `$userId` one is created with
	 * `userId` and `isAvailable` set. The caller's user ID is the only
	 * trusted identity — never accept a user ID from the request body.
	 *
	 * @param string $userId Nextcloud user UID of the agent.
	 * @param bool $available Desired availability flag.
	 *
	 * @return array{userId: string, isAvailable: bool} The updated profile shape.
	 *
	 * @throws RuntimeException When OR is unavailable or the save fails.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Idempotent find-merge-save with defensive guards; extraction adds no clarity.
	 *
	 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
	 */
	public function setAvailability(string $userId, bool $available): array {
		$register = $this->getRegister();
		$schema = $this->getSchema(schemaKey: 'agentProfile_schema');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('agentProfile schema is not configured');
		}

		$objectService = $this->getObjectService();

		// Try to find an existing profile for this user.
		$existingId = '';
		$existingData = [];
		try {
			$results = $objectService->findAll(
				config: ['filters' => ['register' => $register, 'schema' => $schema]]
			);
			foreach ($results as $result) {
				$arr = $this->toArray(object: $result);
				if ((string)($arr['userId'] ?? '') === $userId) {
					$existingData = $arr;
					$existingId = (string)($arr['@self']['id'] ?? $arr['id'] ?? '');
					break;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[KccWerkplekService] setAvailability lookup failed',
				context: ['error' => $e->getMessage()]
			);
		}

		// Merge the existing data with the new flag — preserves skills, maxConcurrent etc.
		$payload = array_filter(
			$existingData,
			static fn (string $k): bool => $k !== '@self' && $k !== 'id',
			ARRAY_FILTER_USE_KEY
		);
		$payload['userId'] = $userId;
		$payload['isAvailable'] = $available;
		if (isset($payload['maxConcurrent']) === false) {
			$payload['maxConcurrent'] = 1;
		}

		try {
			$saveId = null;
			if ($existingId !== '') {
				$saveId = $existingId;
			}

			$objectService->saveObject(
				$payload,
				[],
				$register,
				$schema,
				$saveId
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[KccWerkplekService] saveObject failed',
				context: ['error' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to update agent availability', 0, $e);
		}//end try

		return [
			'userId' => $userId,
			'isAvailable' => $available,
		];
	}//end setAvailability()
}//end class
