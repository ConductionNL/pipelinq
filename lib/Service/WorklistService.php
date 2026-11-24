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
use InvalidArgumentException;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\WorklistServiceInterface;
use OCA\Pipelinq\Service\Worklist\WeekStats;
use OCA\Pipelinq\Service\Worklist\WorkBuckets;
use OCA\Pipelinq\Service\Worklist\WorkFilters;
use OCA\Pipelinq\Service\Worklist\WorkScope;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
class WorklistService implements WorklistServiceInterface {
	use PayloadToArray;

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
	 * Counts one to twenty as words, through the translations.
	 *
	 * @var array<int, string>
	 */
	private const NUMBER_WORDS = [
		1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven',
		8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven', 12 => 'twelve', 13 => 'thirteen',
		14 => 'fourteen', 15 => 'fifteen', 16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen',
		19 => 'nineteen', 20 => 'twenty',
	];

	/**
	 * Request statuses that mean "done" — excluded from the worklist
	 * (MyWorkWidget.vue:90, MyWork.vue:242).
	 *
	 * @var array<int, string>
	 */
	public const TERMINAL_REQUEST_STATUSES = ['completed', 'rejected', 'converted'];

	/**
	 * Statuses that close a ticket of any type: the closed half of the
	 * unified lifecycle, the same list the Queue page excludes.
	 *
	 * @var array<int, string>
	 */
	public const TERMINAL_TICKET_STATUSES = ['resolved', 'completed', 'rejected', 'converted', 'closed'];

	/**
	 * The ticket types that can be assigned and so belong on a worklist.
	 *
	 * @var array<int, string>
	 */
	private const WORK_TICKET_TYPES = [
		TicketService::TYPE_REQUEST,
		TicketService::TYPE_COMPLAINT,
		TicketService::TYPE_CONTACTMOMENT,
	];

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
	 * @param IAppConfig $appConfig App configuration (register/schema IDs).
	 * @param IL10N $l10n Localisation (request status labels).
	 * @param LoggerInterface $logger The logger.
	 * @param TicketService $ticketService Resolver for the unified ticket schema.
	 * @param WorkScope $workScope Whose work a scope lists.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private readonly TicketService $ticketService,
		private readonly WorkScope $workScope,
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
	 * The scope widens whose work is listed (REQ-MW-180, {@see WorkScope}):
	 * `mine` (default) the user's own, `team` every member of the teams the
	 * user manages (or one `member` of them), `organisation` everyone's. Every
	 * scope still reads under the user's own OpenRegister permissions.
	 *
	 * @param string   $userId The Nextcloud UID asking (assignee scoping).
	 * @param int|null $limit  Optional cap on returned rows (widget uses 5); null = all.
	 * @param string   $scope  `mine`, `team` or `organisation`.
	 * @param string   $member With `team`: one member of the team; '' for the whole team.
	 *
	 * @return array{
	 *   items: array<int, array<string, mixed>>,
	 *   total: int,
	 *   leadCount: int,
	 *   requestCount: int
	 * }
	 *
	 * @throws RuntimeException When OpenRegister is unreachable.
	 * @throws InvalidArgumentException For a scope the user may not choose, or a member outside their team.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
	 * @spec openspec/specs/my-work/spec.md
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 */
	public function getMine(string $userId, ?int $limit=null, string $scope=WorkScope::MINE, string $member=''): array {
		$leads    = [];
		$requests = [];
		foreach ($this->workScope->assigneeFilters(userId: $userId, scope: $scope, member: $member) as $filter) {
			$leads    = array_merge(
				$leads,
				$this->findObjects(schemaKey: 'lead_schema', query: ['filters' => $filter, 'limit' => self::LEAD_FETCH_LIMIT])
			);
			$requests = array_merge($requests, $this->assignedTickets(filters: $filter));
		}

		$pipelines = $this->findObjects(
			schemaKey: 'pipeline_schema',
			query: ['filters' => [], 'limit' => self::PIPELINE_FETCH_LIMIT]
		);

		$openLeads = $this->openLeads(leads: $leads, closedStages: $this->getClosedStageNames(pipelines: $pipelines));
		$nowTs = (new DateTimeImmutable())->getTimestamp();
		$items = array_merge(
			array_values(array_map(fn (array $lead): array => $this->leadRow(lead: $lead, nowTs: $nowTs), $openLeads)),
			$this->buildRequestRows(requests: $requests, nowTs: $nowTs)
		);

		// Stable sort (PHP >= 8.0) mirroring the stable Array.prototype.sort
		// comparator in MyWorkWidget.vue:103-112.
		usort($items, $this->compareRows(...));
		return $this->mineResponse(items: $items, limit: $limit);
	}//end getMine()

	/**
	 * Every ticket matching an assignee filter, of every work ticket type.
	 *
	 * Every ticket type assigned to the user, not only requests: an
	 * assigned complaint or contact moment is work too (pipelinq review G1).
	 *
	 * @param array<string, string> $filters The assignee filter; empty for every assignee.
	 *
	 * @return array<int, array<string, mixed>> The tickets.
	 */
	private function assignedTickets(array $filters): array {
		$requests = [];
		foreach (self::WORK_TICKET_TYPES as $ticketType) {
			$requests = array_merge(
				$requests,
				$this->findTickets(ticketType: $ticketType, filters: $filters)
			);
		}

		return $requests;
	}//end assignedTickets()

	/**
	 * The worklist answer: the (capped) rows without their sort key, and the counts of the full union.
	 *
	 * @param array<int, array<string, mixed>> $items The sorted rows.
	 * @param int|null                         $limit Optional cap on returned rows.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, leadCount: int, requestCount: int}
	 */
	private function mineResponse(array $items, ?int $limit): array {
		$total = count($items);
		$leadCount = count(array_filter($items, static fn (array $item): bool => $item['entityType'] === 'lead'));
		if ($limit !== null) {
			$items = array_slice($items, 0, $limit);
		}

		return [
			'items' => array_map(
				static function (array $item): array {
					// Internal sort key — not part of the response contract.
					unset($item['dueTimestamp']);
					return $item;
				},
				$items
			),
			'total' => $total,
			'leadCount' => $leadCount,
			'requestCount' => ($total - $leadCount),
		];
	}//end mineResponse()

	/**
	 * The leads that are open work: not in a closed pipeline stage (MyWorkWidget.vue:76).
	 *
	 * @param array<int, array<string, mixed>> $leads        Lead rows from OpenRegister.
	 * @param array<int, string>               $closedStages Closed stage names.
	 *
	 * @return array<int, array<string, mixed>> The open leads.
	 */
	private function openLeads(array $leads, array $closedStages): array {
		return array_filter(
			$leads,
			static fn (array $lead): bool => in_array((string)($lead['stage'] ?? ''), $closedStages, true) === false
		);
	}//end openLeads()

	/**
	 * One open lead as a worklist row.
	 *
	 * @param array<string, mixed> $lead  The lead.
	 * @param int                  $nowTs Current Unix timestamp.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function leadRow(array $lead, int $nowTs): array {
		$stage = (string)($lead['stage'] ?? '');
		$dueDate = $this->normalizeDate(value: ($lead['expectedCloseDate'] ?? null));
		$dueTs = $this->toTimestamp(value: $dueDate);

		return [
			'entityType' => 'lead',
			'id' => (string)($lead['id'] ?? ''),
			'title' => (string)($lead['title'] ?? ''),
			'stageOrStatus' => $this->orDefault(value: $stage, default: '-'),
			'priority' => $this->orDefault(value: (string)($lead['priority'] ?? ''), default: 'normal'),
			'dueDate' => $dueDate,
			// Lead overdue = expectedCloseDate strictly before now (MyWorkWidget.vue:85).
			'isOverdue' => ($dueTs !== null && $dueTs < $nowTs),
			'routeName' => 'LeadDetail',
			'dueTimestamp' => $dueTs,
		];
	}//end leadRow()

	/**
	 * The value, or the default when it is empty.
	 *
	 * @param string $value   The value.
	 * @param string $default The default.
	 *
	 * @return string The value or the default.
	 */
	private function orDefault(string $value, string $default): string {
		if ($value === '') {
			return $default;
		}

		return $value;
	}//end orDefault()

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
			if (in_array($status, self::TERMINAL_TICKET_STATUSES, true) === true) {
				// Terminal requests are done — not work (MyWorkWidget.vue:90).
				continue;
			}

			$rows[] = $this->requestRow(request: $request, nowTs: $nowTs);
		}

		return $rows;
	}//end buildRequestRows()

	/**
	 * One open request ticket as a worklist row.
	 *
	 * @param array<string, mixed> $request The request ticket.
	 * @param int                  $nowTs   Current Unix timestamp.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function requestRow(array $request, int $nowTs): array {
		$dueDate = $this->normalizeDate(value: ($request['occurredAt'] ?? null));
		$dueTs = $this->toTimestamp(value: $dueDate);

		return [
			'entityType' => 'request',
			'ticketType' => (string)($request['ticketType'] ?? TicketService::TYPE_REQUEST),
			'id' => (string)($request['id'] ?? ''),
			'title' => (string)($request['title'] ?? ''),
			'stageOrStatus' => $this->statusLabel(status: (string)($request['status'] ?? '')),
			'priority' => $this->orDefault(value: (string)($request['priority'] ?? ''), default: 'normal'),
			'dueDate' => $dueDate,
			// Request overdue = occurredAt older than 30 days (MyWorkWidget.vue:99).
			'isOverdue' => ($dueTs !== null && ($nowTs - $dueTs) > self::REQUEST_OVERDUE_AGE_SECONDS),
			// Unify-ticket-supertype: request-type tickets render on the one
			// unified detail page; the retired RequestDetail page is gone.
			'routeName' => 'TicketDetail',
			'dueTimestamp' => $dueTs,
		];
	}//end requestRow()

	/**
	 * Every open item of the user's work, tickets, leads and follow-up tasks,
	 * in one shape, sorted into My work's lists (`bucket`) and the phone's
	 * due groups (`dueGroup`), the most urgent first.
	 *
	 * Each item: `{ kind, id, title, priority, routeName, record, dueAt,
	 * bucket, dueGroup }`; `kind` is the ticket type, `lead` or `task`, and
	 * `record` the object as stored, for the list's templates.
	 *
	 * The view, every key optional:
	 *   - bucket: keep one list (`answer-today`, `waiting-on-me`, `waiting-on-customer`); '' for all.
	 *   - lens:   keep one kind of work (`tickets`, `leads`, `tasks`); '' or `all` for all.
	 *   - sort:   `deadline` (default), `waiting` (oldest first), `newest` or `priority`.
	 *   - done:   true to list finished work too (the page's "Toon afgerond").
	 *   - scope:  whose work, `mine` (default), `team` or `organisation` ({@see WorkScope}, REQ-MW-180).
	 *   - member: with `team`, one member of the team; '' for the whole team.
	 *   - priority: comma-separated priorities to keep (`urgent,high`); '' for all ({@see WorkFilters}, REQ-MW-200).
	 *   - pipeline: keep only the leads of this pipeline id; tickets and follow-ups stay; '' for every pipeline.
	 *
	 * @param string $userId The Nextcloud UID asking.
	 * @param array<string, mixed> $view The view (the keys above).
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 *
	 * @throws RuntimeException When OpenRegister is unreachable.
	 * @throws InvalidArgumentException For a scope the user may not choose, a member outside their team, or an unknown priority.
	 *
	 * @spec openspec/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-priority-and-pipeline-filters-req-mw-200
	 */
	public function items(string $userId, array $view=[]): array {
		$list = [
			'now'    => new DateTimeImmutable(),
			'bucket' => (string)($view['bucket'] ?? ''),
			'lens'   => (string)($view['lens'] ?? ''),
			'sort'   => (string)($view['sort'] ?? 'deadline'),
		];
		$filters = $this->workScope->assigneeFilters(
			userId: $userId,
			scope: (string)($view['scope'] ?? WorkScope::MINE),
			member: (string)($view['member'] ?? '')
		);
		$narrow = new WorkFilters(
			priorities: (string)($view['priority'] ?? ''),
			pipeline: (string)($view['pipeline'] ?? '')
		);
		$rows   = $narrow->keep(
			rows: $this->openWorkRows(assigneeFilters: $filters, done: ($view['done'] ?? false) === true)
		);

		$items = (new WorkBuckets())->listed(rows: $rows, list: $list);

		return ['items' => $this->withClientNames(items: $items), 'total' => count($items)];
	}//end items()

	/**
	 * The open leads (not in a closed stage), tickets (not closed) and
	 * follow-up tasks of every assignee filter, as work items before they are
	 * sorted into a list.
	 *
	 * @param array<int, array<string, string>> $assigneeFilters One `assignee` filter per user; an empty one for everyone.
	 * @param bool                              $done            Keep finished work too.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 *
	 * @throws RuntimeException When OpenRegister is unreachable.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the page's "Toon afgerond" switch.
	 */
	private function openWorkRows(array $assigneeFilters, bool $done): array {
		$closedStages = $this->getClosedStageNames(
			pipelines: $this->findObjects(schemaKey: 'pipeline_schema', query: ['filters' => [], 'limit' => self::PIPELINE_FETCH_LIMIT])
		);

		$rows = [];
		foreach ($assigneeFilters as $filter) {
			$leads = $this->findObjects(schemaKey: 'lead_schema', query: ['filters' => $filter, 'limit' => self::LEAD_FETCH_LIMIT]);
			foreach ($leads as $lead) {
				if ($done === true || in_array((string)($lead['stage'] ?? ''), $closedStages, true) === false) {
					$rows[] = $this->itemRow(record: $lead, shape: ['lead', 'LeadDetail']);
				}
			}

			foreach ($this->workTickets(filters: $filter, done: $done) as $ticket) {
				$rows[] = $this->itemRow(record: $ticket['record'], shape: [$ticket['kind'], 'TicketDetail']);
			}

			$rows = array_merge($rows, $this->taskRows(filter: $filter, done: $done));
		}

		return $rows;
	}//end openWorkRows()

	/**
	 * The tickets of every work type matching a filter, open ones only unless $done.
	 *
	 * @param array<string, string> $filters The assignee filter; empty for every assignee.
	 * @param bool                  $done    Keep closed tickets too.
	 *
	 * @return array<int, array{kind: string, record: array<string, mixed>}> The tickets with their kind.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the page's "Toon afgerond" switch.
	 */
	private function workTickets(array $filters, bool $done): array {
		$found = [];
		foreach ([...self::WORK_TICKET_TYPES, TicketService::TYPE_MELDING] as $ticketType) {
			foreach ($this->findTickets(ticketType: $ticketType, filters: $filters) as $ticket) {
				if ($done === true || in_array((string)($ticket['status'] ?? ''), self::TERMINAL_TICKET_STATUSES, true) === false) {
					$found[] = ['kind' => (string)($ticket['ticketType'] ?? $ticketType), 'record' => $ticket];
				}
			}
		}

		return $found;
	}//end workTickets()

	/**
	 * The follow-up tasks of an assignee filter as work items: the open ones, or all with $done.
	 *
	 * @param array<string, string> $filter The `assignee` filter (a task names it `assigneeUserId`); empty for everyone.
	 * @param bool                  $done   Keep finished tasks too.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 *
	 * @throws RuntimeException When OpenRegister is unreachable.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the page's "Toon afgerond" switch.
	 */
	private function taskRows(array $filter, bool $done): array {
		$filters = [];
		if (isset($filter['assignee']) === true) {
			$filters['assigneeUserId'] = $filter['assignee'];
		}

		if ($done === false) {
			$filters['status'] = 'open';
		}

		$rows = [];
		foreach ($this->findObjects(schemaKey: 'task_schema', query: ['filters' => $filters, 'limit' => self::REQUEST_FETCH_LIMIT]) as $task) {
			$rows[] = $this->itemRow(record: $task, shape: ['task', 'TaskDetail']);
		}

		return $rows;
	}//end taskRows()

	/**
	 * The figures of My work's "Your week so far" tiles over the current
	 * calendar week (Monday to now): answered (and answered today), the share
	 * answered within the deadline, the average answer time in days and the
	 * tickets converted to a case.
	 *
	 * The scope says whose tickets count ({@see WorkScope}, REQ-MW-180): the
	 * user's own by default, the team's for a manager, everyone's for an
	 * administrator. A scope the user may not choose is refused rather than
	 * silently answered with the user's own figures.
	 *
	 * @param string $userId The Nextcloud UID.
	 * @param string $scope  `mine`, `team` or `organisation`.
	 *
	 * @return array<string, mixed> The figures (see WeekStats::of()) and the scope.
	 *
	 * @throws InvalidArgumentException For a scope the user may not choose.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-kpi-summary-tiles-on-my-work-req-mw-090
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 */
	public function getWeekStats(string $userId, string $scope='mine'): array {
		$tickets = [];
		foreach ($this->workScope->assigneeFilters(userId: $userId, scope: $scope) as $filter) {
			foreach ([...self::WORK_TICKET_TYPES, TicketService::TYPE_MELDING] as $ticketType) {
				$tickets = array_merge($tickets, $this->findTickets(ticketType: $ticketType, filters: $filter));
			}
		}

		return (new WeekStats())->of(tickets: $tickets, now: new DateTimeImmutable()) + ['scope' => $scope];
	}//end getWeekStats()

	/**
	 * One sentence on the user's open work, for My work's header: how many
	 * items, by kind, and how many are due today ("9 items are with you: 7
	 * tickets, 1 lead and 1 follow-up; 2 must be answered today").
	 *
	 * @param string $userId The Nextcloud UID.
	 *
	 * @return string The sentence.
	 *
	 * @throws RuntimeException When OpenRegister is unreachable.
	 *
	 * @spec openspec/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
	 */
	public function summary(string $userId): string {
		$items = $this->items(userId: $userId)['items'];
		$counts = (new WorkBuckets())->countsByLens(items: $items);
		$dueNow = count(array_filter($items, static fn (array $item): bool => $item['bucket'] === 'answer-today'));

		if ($items === []) {
			return $this->l10n->t('Nothing is with you right now');
		}

		$kinds = $this->kindsPhrase(counts: $counts);
		$sentence = $this->counted(singular: '%n item is with you', plural: '%n items are with you', count: count($items)) . ': ' . $kinds;
		if ($dueNow > 0) {
			$sentence .= '; ' . $this->counted(singular: '%n must be answered today', plural: '%n must be answered today', count: $dueNow);
		}

		return $sentence;
	}//end summary()

	/**
	 * The kinds of work as a list: "two tickets and one lead", "one ticket, one lead and two follow-ups".
	 *
	 * @param array{tickets: int, leads: int, tasks: int} $counts The counts by lens.
	 *
	 * @return string The phrase.
	 */
	private function kindsPhrase(array $counts): string {
		$phrases = [
			'tickets' => $this->counted(singular: '%n ticket', plural: '%n tickets', count: $counts['tickets']),
			'leads' => $this->counted(singular: '%n lead', plural: '%n leads', count: $counts['leads']),
			'tasks' => $this->counted(singular: '%n follow-up', plural: '%n follow-ups', count: $counts['tasks']),
		];
		$parts = array_values(
			array_filter($phrases, static fn (string $key): bool => ($counts[$key] > 0), ARRAY_FILTER_USE_KEY)
		);
		$last = array_pop($parts);
		if ($parts === []) {
			return $last;
		}

		return $this->l10n->t('%1$s and %2$s', [implode(', ', $parts), $last]);
	}//end kindsPhrase()

	/**
	 * A counted phrase with the count written as a word from one to twenty
	 * ("negen items", board PqMijnWerk), as digits above.
	 *
	 * @param string $singular The phrase for one, with `%n`.
	 * @param string $plural   The phrase for more, with `%n`.
	 * @param int    $count    The count.
	 *
	 * @return string The phrase.
	 *
	 * @spec openspec/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
	 */
	private function counted(string $singular, string $plural, int $count): string {
		return $this->withNumberWord(phrase: $this->l10n->n($singular, $plural, $count), count: $count);
	}//end counted()

	/**
	 * The phrase with its count written as a word from one to twenty, as digits above.
	 *
	 * @param string $phrase The counted phrase, with the count as digits.
	 * @param int    $count  The count.
	 *
	 * @return string The phrase.
	 */
	private function withNumberWord(string $phrase, int $count): string {
		$word   = (self::NUMBER_WORDS[$count] ?? '');
		if ($word === '') {
			return $phrase;
		}

		$digits = (string)$count;
		$offset = strpos($phrase, $digits);
		if ($offset === false) {
			return $phrase;
		}

		return substr($phrase, 0, $offset) . $this->l10n->t($word) . substr($phrase, ($offset + strlen($digits)));
	}//end withNumberWord()

	/**
	 * Each item with the name of its client (`clientName`), read once per
	 * client; '' when the item has none or the client cannot be read.
	 *
	 * @param array<int, array<string, mixed>> $items The items.
	 *
	 * @return array<int, array<string, mixed>> The items with names.
	 */
	private function withClientNames(array $items): array {
		$names = [];
		foreach ($items as $index => $item) {
			$record   = $item['record'];
			$clientId = (string)($record['client'] ?? ($record['clientId'] ?? ''));
			if ($clientId !== '' && array_key_exists($clientId, $names) === false) {
				$names[$clientId] = $this->clientName(clientId: $clientId);
			}

			$items[$index]['clientName'] = ($names[$clientId] ?? '');
		}

		return $items;
	}//end withClientNames()

	/**
	 * A client's name, or '' when it cannot be read.
	 *
	 * @param string $clientId The client's id.
	 *
	 * @return string The name.
	 */
	private function clientName(string $clientId): string {
		try {
			$client = $this->toArray(value: $this->ticketService->getObjectService()->find($clientId));
		} catch (\Throwable $e) {
			return '';
		}

		return (string)($client['name'] ?? ($client['title'] ?? ''));
	}//end clientName()

	/**
	 * One work item before it is sorted into a list.
	 *
	 * @param array<string, mixed>        $record The object as stored.
	 * @param array{0: string, 1: string} $shape  The kind (ticket type, `lead` or `task`) and the detail page.
	 *
	 * @return array<string, mixed> The item.
	 */
	private function itemRow(array $record, array $shape): array {
		[$kind, $routeName] = $shape;
		$priority = (string)($record['priority'] ?? '');
		if ($priority === '') {
			$priority = 'normal';
		}

		return [
			'kind'      => $kind,
			'id'        => (string)($record['id'] ?? ''),
			'title'     => (string)($record['title'] ?? ($record['subject'] ?? '')),
			'priority'  => $priority,
			'channel'   => (string)($record['channel'] ?? ''),
			'routeName' => $routeName,
			'record'    => $record,
		];
	}//end itemRow()

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
	 *
	 * @return array<int, array<string, mixed>> At most REQUEST_FETCH_LIMIT tickets.
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	private function findTickets(string $ticketType, array $filters): array {
		$results = $this->ticketService->findByType(
			ticketType: $ticketType,
			extraFilters: $filters,
			limit: self::REQUEST_FETCH_LIMIT
		);

		$objects = [];
		foreach ($results as $result) {
			$objects[] = $this->toArray(value: $result);
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
	 * @param string                                          $schemaKey The app-config key holding the schema ID.
	 * @param array{filters: array<string, mixed>, limit: int} $query     Additional equality filters and the row limit pushed to OpenRegister.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RuntimeException When OpenRegister is unreachable mid-query.
	 */
	private function findObjects(string $schemaKey, array $query): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			$this->logger->info(
				message: '[WorklistService] register or schema not configured',
				context: ['schemaKey' => $schemaKey]
			);
			return [];
		}

		$config = [
			'filters' => array_merge(['register' => $register, 'schema' => $schema], $query['filters']),
			'limit' => $query['limit'],
		];
		return array_map(fn ($result): array => $this->toArray(value: $result), $this->runFindAll(config: $config, schemaKey: $schemaKey));
	}//end findObjects()

	/**
	 * Run the OpenRegister query; a failure is logged and rethrown as a RuntimeException.
	 *
	 * @param array<string, mixed> $config    The findAll configuration.
	 * @param string               $schemaKey The schema key, for the log line.
	 *
	 * @return array<int, mixed> The results.
	 *
	 * @throws RuntimeException When OpenRegister is unreachable mid-query.
	 */
	private function runFindAll(array $config, string $schemaKey): array {
		try {
			$objectService = $this->ticketService->getObjectService();
			$results = $objectService->findAll(config: $config);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[WorklistService] findAll failed',
				context: ['schemaKey' => $schemaKey, 'error' => $e->getMessage()]
			);
			throw new RuntimeException(message: 'Worklist query failed', code: 0, previous: $e);
		}

		return array_values($results);
	}//end runFindAll()
}//end class
