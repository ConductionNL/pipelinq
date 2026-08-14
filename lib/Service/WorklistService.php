<?php

/**
 * Pipelinq WorklistService.
 *
 * Server-side "my work" union: the canonical merge of the current user's
 * leads and requests that was previously duplicated client-side between
 * the MyWorkWidget dashboard widget and the MyWork page. Ports those
 * union / exclusion / overdue / sort rules verbatim:
 *
 *  - Leads sitting in a closed pipeline stage are excluded; the closed
 *    stage set is derived at runtime from the pipelines schema
 *    (src/services/dashboardData.js getClosedStageNames).
 *  - Requests in a terminal status (completed / rejected / converted)
 *    are excluded (src/views/dashboard/widgets/MyWorkWidget.vue:90).
 *  - Lead overdue = expectedCloseDate strictly before now
 *    (MyWorkWidget.vue:85); request overdue = the request's own timestamp
 *    older than 30 days (MyWorkWidget.vue:99) — `requestedAt` on the legacy
 *    request schema, `occurredAt` on the unified ticket schema.
 *
 * Requests are a subtype of the unified `ticket` schema and are read through
 * {@see TicketService} with a `ticketType` discriminator (unify-ticket-supertype).
 *  - Rows sort overdue-first, then priority (urgent > high > normal >
 *    low), then due date ascending with date-less rows last
 *    (MyWorkWidget.vue:103-112).
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
 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\ObjectService;

/**
 * "My work" worklist aggregation service.
 *
 * Stateless. Reads the current user's leads and pipeline definitions via the
 * OpenRegister ObjectService and the user's request tickets via
 * {@see TicketService} (assignee scoping is pushed into the OR query in both
 * cases), normalizes both sources into one row shape and sorts them with the
 * exact comparator the dashboard widget used client-side.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Verbatim port of the client
 * union/overdue/sort rules; the complexity is the mirrored spec, not accidental.
 */
class WorklistService {
	/**
	 * Priority sort ranks — lower sorts first (MyWorkWidget.vue:43).
	 *
	 * @var array<string, int>
	 */
	public const PRIORITY_ORDER = [
		'urgent' => 0,
		'high' => 1,
		'normal' => 2,
		'low' => 3,
	];

	/**
	 * Rank used for unknown priorities — treated as normal
	 * (MyWorkWidget.vue:105-106, the `?? 2` fallback).
	 *
	 * @var int
	 */
	private const DEFAULT_PRIORITY_RANK = 2;

	/**
	 * Request statuses that mean "done" — excluded from the worklist
	 * (MyWorkWidget.vue:90, MyWork.vue:242).
	 *
	 * @var array<int, string>
	 */
	public const TERMINAL_REQUEST_STATUSES = ['completed', 'rejected', 'converted'];

	/**
	 * A request counts as overdue when its occurredAt (the ticket-schema name
	 * of the request's requestedAt) is older than this (30 days;
	 * MyWorkWidget.vue:99).
	 *
	 * @var int
	 */
	private const REQUEST_OVERDUE_AGE_SECONDS = 2592000;

	/**
	 * Fetch caps mirroring the client fetches: leads/requests used
	 * `_limit: 200` (dashboardData.js:160-175, MyWork.vue:387-394) and
	 * pipelines `_limit: 100` (dashboardData.js:126).
	 *
	 * @var int
	 */
	private const LEAD_FETCH_LIMIT = 200;

	/**
	 * Fetch cap for the current user's requests (see LEAD_FETCH_LIMIT).
	 *
	 * @var int
	 */
	private const REQUEST_FETCH_LIMIT = 200;

	/**
	 * Fetch cap for pipeline definitions (see LEAD_FETCH_LIMIT).
	 *
	 * @var int
	 */
	private const PIPELINE_FETCH_LIMIT = 100;

	/**
	 * Request status labels — English source strings identical to the
	 * client STATUS_LABELS map (src/services/requestStatus.js:15-21) so
	 * both sides resolve to the same pipelinq translations.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_LABELS = [
		'new' => 'New',
		'in_progress' => 'In progress',
		'completed' => 'Completed',
		'rejected' => 'Rejected',
		'converted' => 'Converted to case',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (OpenRegister lookup).
	 * @param IAppConfig $appConfig App configuration (register/schema IDs).
	 * @param IL10N $l10n Localisation (request status labels).
	 * @param LoggerInterface $logger The logger.
	 * @param TicketService $ticketService Resolver for the unified ticket schema.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private readonly TicketService $ticketService,
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The current user's worklist: union of assigned leads + requests.
	 *
	 * Returns:
	 *   - items:        normalized rows (capped at $limit when given), each
	 *                    {entityType, id, title, stageOrStatus, priority,
	 *                    dueDate, isOverdue, routeName}
	 *   - total:        size of the full union (before the limit cap)
	 *   - leadCount:    leads in the full union
	 *   - requestCount: requests in the full union
	 *
	 * @param string $userId The Nextcloud UID whose work to list (assignee scoping).
	 * @param int|null $limit Optional cap on returned rows (widget uses 5); null = all.
	 *
	 * @return array{
	 *   items: array<int, array<string, mixed>>,
	 *   total: int,
	 *   leadCount: int,
	 *   requestCount: int
	 * }
	 *
	 * @throws RuntimeException When OpenRegister is unreachable.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
	 */
	public function getMine(string $userId, ?int $limit = null): array {
		$leads = $this->findObjects(
			schemaKey: 'lead_schema',
			filters: ['assignee' => $userId],
			limit: self::LEAD_FETCH_LIMIT
		);
		$requests = $this->findTickets(
			ticketType: TicketService::TYPE_REQUEST,
			filters: ['assignee' => $userId],
			limit: self::REQUEST_FETCH_LIMIT
		);
		$pipelines = $this->findObjects(
			schemaKey: 'pipeline_schema',
			filters: [],
			limit: self::PIPELINE_FETCH_LIMIT
		);

		$closedStages = $this->getClosedStageNames(pipelines: $pipelines);
		$nowTs = (new DateTimeImmutable())->getTimestamp();

		$items = array_merge(
			$this->buildLeadRows(leads: $leads, closedStages: $closedStages, nowTs: $nowTs),
			$this->buildRequestRows(requests: $requests, nowTs: $nowTs)
		);

		// Stable sort (PHP >= 8.0) mirroring the stable Array.prototype.sort
		// comparator in MyWorkWidget.vue:103-112.
		usort($items, $this->compareRows(...));

		$total = count($items);
		$leadCount = 0;
		$requestCount = 0;
		foreach ($items as $item) {
			if ($item['entityType'] === 'lead') {
				$leadCount++;
				continue;
			}

			$requestCount++;
		}

		if ($limit !== null) {
			$items = array_slice($items, 0, $limit);
		}

		$rows = [];
		foreach ($items as $item) {
			// Internal sort key — not part of the response contract.
			unset($item['dueTimestamp']);
			$rows[] = $item;
		}

		return [
			'items' => $rows,
			'total' => $total,
			'leadCount' => $leadCount,
			'requestCount' => $requestCount,
		];
	}//end getMine()

	/**
	 * Normalize the user's leads into worklist rows.
	 *
	 * Port of the lead branch in MyWorkWidget.vue:75-87: skip leads in a
	 * closed stage, default stage to `-` and priority to `normal`, due
	 * date = expectedCloseDate, overdue when strictly before now.
	 *
	 * @param array<int, array<string, mixed>> $leads Lead rows from OpenRegister.
	 * @param array<int, string> $closedStages Closed stage names.
	 * @param int $nowTs Current Unix timestamp.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildLeadRows(array $leads, array $closedStages, int $nowTs): array {
		$rows = [];
		foreach ($leads as $lead) {
			$stage = (string)($lead['stage'] ?? '');
			if (in_array($stage, $closedStages, true) === true) {
				// Leads in a closed pipeline stage are not open work (MyWorkWidget.vue:76).
				continue;
			}

			$dueDate = $this->normalizeDate(value: ($lead['expectedCloseDate'] ?? null));
			$dueTs = $this->toTimestamp(value: $dueDate);

			$stageOrStatus = '-';
			if ($stage !== '') {
				$stageOrStatus = $stage;
			}

			$priority = (string)($lead['priority'] ?? '');
			if ($priority === '') {
				$priority = 'normal';
			}

			$isOverdue = false;
			if ($dueTs !== null && $dueTs < $nowTs) {
				// Lead overdue = expectedCloseDate strictly before now (MyWorkWidget.vue:85).
				$isOverdue = true;
			}

			$rows[] = [
				'entityType' => 'lead',
				'id' => (string)($lead['id'] ?? ''),
				'title' => (string)($lead['title'] ?? ''),
				'stageOrStatus' => $stageOrStatus,
				'priority' => $priority,
				'dueDate' => $dueDate,
				'isOverdue' => $isOverdue,
				'routeName' => 'LeadDetail',
				'dueTimestamp' => $dueTs,
			];
		}//end foreach

		return $rows;
	}//end buildLeadRows()

	/**
	 * Normalize the user's requests into worklist rows.
	 *
	 * Port of the request branch in MyWorkWidget.vue:89-101: skip
	 * terminal statuses, label the status like the client
	 * getStatusLabel, due date = the request's occurredAt (the ticket-schema
	 * name of requestedAt), overdue when that timestamp is older than 30 days.
	 *
	 * @param array<int, array<string, mixed>> $requests Request-ticket rows from OpenRegister.
	 * @param int $nowTs Current Unix timestamp.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildRequestRows(array $requests, int $nowTs): array {
		$rows = [];
		foreach ($requests as $request) {
			$status = (string)($request['status'] ?? '');
			if (in_array($status, self::TERMINAL_REQUEST_STATUSES, true) === true) {
				// Terminal requests are done — not work (MyWorkWidget.vue:90).
				continue;
			}

			$dueDate = $this->normalizeDate(value: ($request['occurredAt'] ?? null));
			$dueTs = $this->toTimestamp(value: $dueDate);

			$priority = (string)($request['priority'] ?? '');
			if ($priority === '') {
				$priority = 'normal';
			}

			$isOverdue = false;
			if ($dueTs !== null && ($nowTs - $dueTs) > self::REQUEST_OVERDUE_AGE_SECONDS) {
				// Request overdue = occurredAt older than 30 days (MyWorkWidget.vue:99).
				$isOverdue = true;
			}

			$rows[] = [
				'entityType' => 'request',
				'id' => (string)($request['id'] ?? ''),
				'title' => (string)($request['title'] ?? ''),
				'stageOrStatus' => $this->statusLabel(status: $status),
				'priority' => $priority,
				'dueDate' => $dueDate,
				'isOverdue' => $isOverdue,
				// Unify-ticket-supertype: request-type tickets render on the one
				// unified detail page; the retired RequestDetail page is gone.
				'routeName' => 'TicketDetail',
				'dueTimestamp' => $dueTs,
			];
		}//end foreach

		return $rows;
	}//end buildRequestRows()

	/**
	 * Worklist row comparator — exact port of MyWorkWidget.vue:103-112.
	 *
	 * Overdue rows first; then priority rank (urgent > high > normal >
	 * low, unknown = normal); then due date ascending with rows that
	 * have a due date before rows that do not.
	 *
	 * @param array<string, mixed> $a Left row.
	 * @param array<string, mixed> $b Right row.
	 *
	 * @return int Comparator result.
	 */
	private function compareRows(array $a, array $b): int {
		// Overdue items first (MyWorkWidget.vue:104).
		if ($a['isOverdue'] !== $b['isOverdue']) {
			if ($a['isOverdue'] === true) {
				return -1;
			}

			return 1;
		}

		// Priority rank (MyWorkWidget.vue:105-107).
		$rankA = (self::PRIORITY_ORDER[$a['priority']] ?? self::DEFAULT_PRIORITY_RANK);
		$rankB = (self::PRIORITY_ORDER[$b['priority']] ?? self::DEFAULT_PRIORITY_RANK);
		if ($rankA !== $rankB) {
			return ($rankA <=> $rankB);
		}

		// Due date ascending; rows with a date before rows without
		// (MyWorkWidget.vue:108-111).
		return $this->compareDueDates(a: $a, b: $b);
	}//end compareRows()

	/**
	 * Due-date leg of the worklist comparator (MyWorkWidget.vue:108-111):
	 * ascending by date when both rows have one, rows with a due date
	 * before rows without, equal otherwise.
	 *
	 * @param array<string, mixed> $a Left row.
	 * @param array<string, mixed> $b Right row.
	 *
	 * @return int Comparator result.
	 */
	private function compareDueDates(array $a, array $b): int {
		$hasDueA = $this->hasDueDate(row: $a);
		$hasDueB = $this->hasDueDate(row: $b);
		if ($hasDueA === true && $hasDueB === true) {
			if ($a['dueTimestamp'] === null || $b['dueTimestamp'] === null) {
				// Unparseable date string: the client comparator yields NaN,
				// which Array.prototype.sort treats as 0 (keep order).
				return 0;
			}

			return ($a['dueTimestamp'] <=> $b['dueTimestamp']);
		}

		if ($hasDueA === true) {
			return -1;
		}

		if ($hasDueB === true) {
			return 1;
		}

		return 0;
	}//end compareDueDates()

	/**
	 * Whether a row carries a due date — mirrors the client's JS
	 * truthiness test on the raw dueDate value (MyWorkWidget.vue:108-110).
	 *
	 * @param array<string, mixed> $row The worklist row.
	 *
	 * @return bool
	 */
	private function hasDueDate(array $row): bool {
		return ($row['dueDate'] ?? null) !== null && $row['dueDate'] !== '';
	}//end hasDueDate()

	/**
	 * Stage names flagged isClosed across all pipelines.
	 *
	 * Exact port of getClosedStageNames (src/services/dashboardData.js:266-275).
	 *
	 * @param array<int, array<string, mixed>> $pipelines Pipeline rows.
	 *
	 * @return array<int, string> Unique closed stage names.
	 */
	private function getClosedStageNames(array $pipelines): array {
		$names = [];
		foreach ($pipelines as $pipeline) {
			$stages = ($pipeline['stages'] ?? null);
			if (is_array($stages) === false) {
				continue;
			}

			foreach ($stages as $stage) {
				if (is_array($stage) === false) {
					continue;
				}

				if (empty($stage['isClosed']) === true) {
					// Mirrors the client's truthiness check on s.isClosed.
					continue;
				}

				$names[] = (string)($stage['name'] ?? '');
			}
		}

		return array_values(array_unique($names));
	}//end getClosedStageNames()

	/**
	 * Translated label for a request status — port of getStatusLabel
	 * (src/services/requestStatus.js:89-91): mapped label or the raw
	 * status when unknown.
	 *
	 * @param string $status Raw request status.
	 *
	 * @return string
	 */
	private function statusLabel(string $status): string {
		if (isset(self::STATUS_LABELS[$status]) === true) {
			return $this->l10n->t(self::STATUS_LABELS[$status]);
		}

		return $status;
	}//end statusLabel()

	/**
	 * Normalize a raw due-date value to a non-empty string or null.
	 *
	 * @param mixed $value Raw field value from OpenRegister.
	 *
	 * @return string|null
	 */
	private function normalizeDate(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end normalizeDate()

	/**
	 * Parse a date string to a Unix timestamp; null when unparseable.
	 *
	 * @param string|null $value Date string (ISO-8601 or date-only).
	 *
	 * @return int|null
	 */
	private function toTimestamp(?string $value): ?int {
		if ($value === null) {
			return null;
		}

		$timestamp = strtotime($value);
		if ($timestamp === false) {
			return null;
		}

		return $timestamp;
	}//end toTimestamp()

	/**
	 * Query tickets of one subtype via the shared TicketService.
	 *
	 * The legacy `request` / `complaint` / `contactmoment` schemas were merged
	 * into the unified `ticket` schema; a subtype is selected with the
	 * `ticketType` discriminator rather than with a separate schema id. The
	 * assignee filter and the fetch cap are still pushed into the OpenRegister
	 * query. TicketService is fail-soft: an unprovisioned ticket schema or an
	 * unreachable OpenRegister yields an empty row set (logged there), which
	 * degrades the worklist to its lead rows only.
	 *
	 * @param string $ticketType One of the TicketService::TYPE_* constants.
	 * @param array<string, mixed> $filters Additional equality filters.
	 * @param int $limit Row limit pushed to OpenRegister.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	private function findTickets(string $ticketType, array $filters, int $limit): array {
		$results = $this->ticketService->findByType(
			ticketType: $ticketType,
			extraFilters: $filters,
			limit: $limit
		);

		$objects = [];
		foreach ($results as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}//end findTickets()

	/**
	 * Query objects of a configured schema via OpenRegister ObjectService.
	 *
	 * Same access pattern as AnalyticsService::findObjects, extended
	 * with equality filters (assignee scoping) and a row limit pushed
	 * into the OR query. Empty register/schema config is treated as
	 * "no data" (logged); OR failures surface as RuntimeException so
	 * the controller can map them to a 500.
	 *
	 * @param string $schemaKey The app-config key holding the schema ID.
	 * @param array<string, mixed> $filters Additional equality filters.
	 * @param int $limit Row limit pushed to OpenRegister.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RuntimeException When OpenRegister is unreachable mid-query.
	 */
	private function findObjects(string $schemaKey, array $filters, int $limit): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			$this->logger->info(
				message: '[WorklistService] register or schema not configured',
				context: ['schemaKey' => $schemaKey]
			);
			return [];
		}

		$allFilters = array_merge(['register' => $register, 'schema' => $schema], $filters);

		try {
			$objectService = $this->getObjectService();
			$results = $objectService->findAll(
				config: [
					'filters' => $allFilters,
					'limit' => $limit,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[WorklistService] findAll failed',
				context: ['schemaKey' => $schemaKey, 'error' => $e->getMessage()]
			);
			throw new RuntimeException(message: 'Worklist query failed', code: 0, previous: $e);
		}

		$objects = [];
		foreach (($results ?? []) as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}//end findObjects()

	/**
	 * Normalize an OpenRegister entity (or array) to a plain array.
	 *
	 * @param mixed $object Entity / array / unknown payload.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end toArray()

	/**
	 * Resolve the OpenRegister ObjectService via DI container.
	 *
	 * @return object The ObjectService instance.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function getObjectService(): object {
		try {
			return $this->objectService;
		} catch (\Throwable $e) {
			throw new RuntimeException(message: 'OpenRegister ObjectService is unavailable.', code: 0, previous: $e);
		}
	}//end getObjectService()
}//end class
