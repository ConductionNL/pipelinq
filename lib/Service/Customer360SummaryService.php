<?php

/**
 * Pipelinq Customer360SummaryService.
 *
 * Consolidated 360 summary for a single client — the one piece of the
 * customer-360 MVP the declarative layer cannot express (klantbeeld-360-activation).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/klantbeeld-360-activation/design.md#decisions
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use Exception;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Aggregates a client's open tickets (all `ticketType`s), SLA/queue status,
 * open leads, and last activity into one summary payload.
 *
 * ADR-031 exception (2): this is a legitimate service concern, not a
 * declarative `summaryAggregates`/`stats-block` chip, because it spans
 * ticket types and statuses (an OR over `ticketType`, an OR over open
 * statuses) and does a per-row time comparison (SLA deadline vs now) then a
 * distinct-set reduce (queues) — none of which is a single-object
 * calculation or a single-equality aggregation. See
 * `openspec/changes/klantbeeld-360-activation/design.md` for the full
 * declarative-vs-imperative table. The service reads RBAC-visible objects
 * and reduces; it persists nothing.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates across ticket/lead/queue/activity reads.
 *
 * @spec openspec/specs/customer-360/spec.md#requirement-consolidated-customer-360-summary
 */
class Customer360SummaryService {
	/**
	 * Ticket statuses considered "open" (mirrors KccWerkplekService::OPEN_REQUEST_STATUSES
	 * and the ticket schema's `x-openregister-lifecycle` non-final states).
	 *
	 * @var array<int, string>
	 */
	private const OPEN_TICKET_STATUSES = ['new', 'in_progress'];

	/**
	 * SLA "at-risk" lookahead window in hours.
	 *
	 * The ticket schema carries only a flat `slaDeadline` instant — it has no
	 * `startedAt` / policy pair, so `SlaEngineService::evaluateTargets()`'s
	 * consumed-percentage definition (>=80% of elapsed business time) cannot be
	 * evaluated here without re-deriving a policy resolution this service does
	 * not own. Per design.md's provisional resolution ("reuse the SLA engine's
	 * definition when present, else 24h"), this service uses a documented fixed
	 * window: an open ticket whose `slaDeadline` falls within this many hours of
	 * now (and has not already passed) is "at-risk"; a `slaDeadline` already in
	 * the past is "breached" — the same on-track -> at-risk -> breached
	 * vocabulary `SlaEngineService::STATUS_AT_RISK` / `STATUS_BREACHED` use.
	 *
	 * @var int
	 */
	private const AT_RISK_WINDOW_HOURS = 24;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (OpenRegister ObjectService).
	 * @param RegisterResolverService $registerResolver Resolves the pipelinq register id.
	 * @param IAppConfig $appConfig App config (schema slugs).
	 * @param TicketService $ticketService Unified ticket resolver (unify-ticket-supertype).
	 * @param ActivityTimelineService $activityTimeline Merged activity timeline (last-activity lookup).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly RegisterResolverService $registerResolver,
		private readonly IAppConfig $appConfig,
		private readonly TicketService $ticketService,
		private readonly ActivityTimelineService $activityTimeline,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Build the consolidated customer 360 summary for one client.
	 *
	 * All reads go through OpenRegister's `ObjectService` (via {@see TicketService}
	 * for tickets, directly for leads/queues), which applies the caller's RBAC —
	 * an object the caller may not read never contributes to any count or total.
	 *
	 * @param string $clientId The client UUID.
	 *
	 * @return array<string, mixed> The summary payload (see class docblock).
	 *
	 * @spec openspec/specs/customer-360/spec.md#requirement-consolidated-customer-360-summary
	 */
	public function getSummary(string $clientId): array {
		$now = new DateTimeImmutable('now');

		[$openTicketsByType, $slaBreached, $slaAtRisk, $queueIds] = $this->collectOpenTickets(clientId: $clientId, now: $now);

		$openLeads = $this->findLeadsSafe(filters: ['client' => $clientId, 'status' => 'open']);
		$openLeadValue = 0.0;
		foreach ($openLeads as $lead) {
			$openLeadValue += (float)($lead['value'] ?? 0);
		}

		return [
			'clientId' => $clientId,
			'openTicketCount' => array_sum($openTicketsByType),
			'openTicketsByType' => $openTicketsByType,
			'sla' => [
				'breached' => $slaBreached,
				'atRisk' => $slaAtRisk,
			],
			'queues' => array_values($this->resolveQueueNames(queueIds: array_keys($queueIds))),
			'queueCount' => count($queueIds),
			'openLeadCount' => count($openLeads),
			'openLeadValue' => $openLeadValue,
			'lastActivityAt' => $this->resolveLastActivity(clientId: $clientId),
		];
	}//end getSummary()

	/**
	 * Read the client's open tickets across all `ticketType`s and reduce them
	 * into a per-type count, SLA breached/at-risk counts, and the distinct set
	 * of queue ids in one pass.
	 *
	 * @param string $clientId The client UUID.
	 * @param DateTimeImmutable $now Evaluation instant (SLA comparison).
	 *
	 * @return array{0: array<string,int>, 1: int, 2: int, 3: array<string,bool>}
	 *                                                                            [openTicketsByType, slaBreached, slaAtRisk, queueIds (id => true)].
	 */
	private function collectOpenTickets(string $clientId, DateTimeImmutable $now): array {
		$atRiskThreshold = $now->modify('+' . self::AT_RISK_WINDOW_HOURS . ' hours');

		$openTicketsByType = [];
		$slaBreached = 0;
		$slaAtRisk = 0;
		$queueIds = [];

		foreach (TicketService::TYPES as $ticketType) {
			$tickets = $this->ticketService->findByType(
				$ticketType,
				['client' => $clientId, 'status' => self::OPEN_TICKET_STATUSES]
			);
			$openTicketsByType[$ticketType] = count($tickets);

			foreach ($tickets as $rawTicket) {
				$ticket = $this->toArray(object: $rawTicket);

				$deadline = $this->parseDeadline(raw: (string)($ticket['slaDeadline'] ?? ''));
				if ($deadline !== null) {
					if ($deadline < $now) {
						$slaBreached++;
					} elseif ($deadline <= $atRiskThreshold) {
						$slaAtRisk++;
					}
				}

				$queueId = (string)($ticket['queue'] ?? '');
				if ($queueId !== '') {
					$queueIds[$queueId] = true;
				}
			}
		}//end foreach

		return [$openTicketsByType, $slaBreached, $slaAtRisk, $queueIds];
	}//end collectOpenTickets()

	/**
	 * Parse a ticket's `slaDeadline` into an instant, or null when absent/unparsable.
	 *
	 * @param string $raw The raw `slaDeadline` field value.
	 *
	 * @return DateTimeImmutable|null The parsed instant, or null.
	 */
	private function parseDeadline(string $raw): ?DateTimeImmutable {
		if ($raw === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($raw);
		} catch (Exception $e) {
			return null;
		}
	}//end parseDeadline()

	/**
	 * Resolve the client's most recent activity timestamp via the merged
	 * activity timeline (reuses {@see ActivityTimelineService} rather than
	 * re-deriving a max-over-schemas scan).
	 *
	 * @param string $clientId The client UUID.
	 *
	 * @return string|null ISO-8601 timestamp of the most recent activity, or null.
	 */
	private function resolveLastActivity(string $clientId): ?string {
		try {
			$timeline = $this->activityTimeline->getTimeline(
				entityType: 'client',
				entityId: $clientId,
				params: ['_page' => 1, '_limit' => 1]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Customer360SummaryService: last-activity lookup failed',
				['clientId' => $clientId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		$date = ($timeline['items'][0]['date'] ?? null);
		if (is_string($date) === true && $date !== '') {
			return $date;
		}

		return null;
	}//end resolveLastActivity()

	/**
	 * Resolve queue ids to `{id, name}` pairs. Unknown ids (a queue not found —
	 * deleted, or RBAC-hidden) fall back to the raw id as the name.
	 *
	 * @param array<int, string> $queueIds Distinct queue ids from open tickets.
	 *
	 * @return array<int, array{id: string, name: string}> The resolved queues.
	 */
	private function resolveQueueNames(array $queueIds): array {
		if (empty($queueIds) === true) {
			return [];
		}

		$allQueues = $this->findAllSafe(schemaKey: 'queue_schema');
		$nameById = [];
		foreach ($allQueues as $queue) {
			$id = (string)($queue['@self']['id'] ?? $queue['id'] ?? '');
			if ($id !== '') {
				// Queue's display field is `title` (not `name`) — see the
				// `queue` schema in lib/Settings/pipelinq_register.json.
				$nameById[$id] = (string)($queue['title'] ?? $id);
			}
		}

		$resolved = [];
		foreach ($queueIds as $queueId) {
			$resolved[] = [
				'id' => $queueId,
				'name' => ($nameById[$queueId] ?? $queueId),
			];
		}

		return $resolved;
	}//end resolveQueueNames()

	/**
	 * Resolve the OpenRegister ObjectService lazily.
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface The OpenRegister object service.
	 *
	 * @throws RuntimeException If OpenRegister is not available.
	 */
	private function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		try {
			return $this->objectService;
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister ObjectService is not available', 0, $e);
		}
	}//end getObjectService()

	/**
	 * Read the pipelinq register id.
	 *
	 * @return string Register id, or empty string when unconfigured.
	 */
	private function getRegister(): string {
		return $this->registerResolver->resolve('customer-360');
	}//end getRegister()

	/**
	 * Read a schema slug from the app config.
	 *
	 * @param string $schemaKey App config key (e.g. `queue_schema`).
	 *
	 * @return string Resolved schema slug, or empty string when missing.
	 */
	private function getSchema(string $schemaKey): string {
		return $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
	}//end getSchema()

	/**
	 * Normalise an OpenRegister entity (object or array) to a plain array.
	 *
	 * @param mixed $object The object or array returned by ObjectService.
	 *
	 * @return array<string, mixed> Plain associative array.
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
	 * Find all objects of a schema, swallowing OR outages so a partial summary
	 * still renders (mirrors KccWerkplekService::findAllSafe()'s fail-soft contract).
	 *
	 * @param string $schemaKey App config key (e.g. `queue_schema`).
	 *
	 * @return array<int, array<string, mixed>> Plain object arrays.
	 */
	private function findAllSafe(string $schemaKey): array {
		$register = $this->getRegister();
		$schema = $this->getSchema(schemaKey: $schemaKey);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$results = $this->getObjectService()->findAll(
				['filters' => ['register' => $register, 'schema' => $schema]]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Customer360SummaryService: findAll failed',
				['schemaKey' => $schemaKey, 'error' => $e->getMessage()]
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
	 * Find leads matching the given equality filters, scoped to the pipelinq
	 * register + lead schema. Swallows OR outages (fail-soft, see findAllSafe()).
	 *
	 * @param array<string, mixed> $filters Field criteria (eq).
	 *
	 * @return array<int, array<string, mixed>> Plain object arrays.
	 */
	private function findLeadsSafe(array $filters): array {
		$register = $this->getRegister();
		$schema = $this->getSchema(schemaKey: 'lead_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$results = $this->getObjectService()->findAll(
				['filters' => array_merge(['register' => $register, 'schema' => $schema], $filters)]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Customer360SummaryService: lead findAll failed',
				['error' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach ($results as $result) {
			$out[] = $this->toArray(object: $result);
		}

		return $out;
	}//end findLeadsSafe()
}//end class
