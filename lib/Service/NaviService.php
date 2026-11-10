<?php

/**
 * Pipelinq NaviService.
 *
 * Conversational analytics orchestrator. Parses a natural-language query,
 * detects the user's intent (trend / breakdown / count / conversion),
 * dispatches the appropriate OpenRegister ObjectService queries via the
 * shared `findAll` API, aggregates the result, and shapes the response
 * envelope consumed by the NaviAnalyticsWidget.
 *
 * Design intent (see openspec/changes/dashboard/design.md §1):
 *   - NO custom LLM plumbing — when the OpenRegister `ChatService` is
 *     resolvable via the DI container the service uses it for free-form
 *     summarisation; if it is not available (offline tests, deployments
 *     without the LLM stack wired up) Navi MUST still return a deterministic
 *     structured result for every supported intent.
 *   - NO new schemas — every query reuses the existing pipelinq registers
 *     (`lead` plus the unified `ticket` supertype, whose `request` /
 *     `contactmoment` subtypes replaced the schemas of the same name and are
 *     resolved through {@see TicketService}). Survey responses now live in
 *     the OpenRegister forms leaf (NC Forms); a future Navi adapter can
 *     query the leaf via `FormLinkMapper`.
 *     Conversation state is no exception: it lives in one bounded per-user
 *     preference row (see {@see NaviConversationStore}), not in a register and
 *     not behind a migration.
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
 * @spec openspec/specs/dashboard/spec.md#requirement-navi-ai-analytics-widget-req-dash-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Deduplication check (Task 0.1):
 *   - ChatService + ContextRetrievalHandler — reused for free-form LLM summary.
 *   - ExportService + CnMassExportDialog    — reused by ReportExportPanel.
 *   - CnChartWidget / CnStatsBlock / CnKpiGrid / CnDashboardPage — UI only.
 *   - No custom LLM wrapper, chart component, dashboard layout or export
 *     controller is added by this change.
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Conversational analytics orchestrator (Navi AI).
 *
 * Conversation state lives in {@see NaviConversationStore}, scoped to the
 * calling user. A turn whose intent cannot be classified on its own words
 * inherits the intent of the preceding turn, and a turn that names no subject
 * inherits the preceding turn's, which is how a follow-up such as "And how many
 * of those are overdue?" is answered against what the previous question was
 * about.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-navi-ai-analytics-widget-req-dash-001
 */
class NaviService {
	/**
	 * Maximum number of suggested follow-up chips to surface.
	 *
	 * @var int
	 */
	public const MAX_FOLLOWUPS = 3;

	/**
	 * Recognised intents. Anything else degrades to `unknown` which produces
	 * a clarification prompt.
	 *
	 * @var array<int, string>
	 */
	public const INTENTS = ['trend', 'breakdown', 'count', 'conversion', 'unknown'];

	/**
	 * Series name per chart type.
	 *
	 * @var array<string, string>
	 */
	private const SERIES_NAMES = ['line' => 'Leads', 'bar' => 'Requests'];

	/**
	 * Follow-ups offered after a trend answer.
	 *
	 * @var array<int, string>
	 */
	private const TREND_FOLLOWUPS = [
		'Which stage has the highest drop-off?',
		'What is my conversion rate this quarter?',
		'How many requests are open?',
	];

	/**
	 * Follow-ups offered after a conversion answer.
	 *
	 * @var array<int, string>
	 */
	private const CONVERSION_FOLLOWUPS = [
		'Show the trend of leads over time.',
		'Which stage has the highest drop-off?',
		'How many requests are open?',
	];

	/**
	 * Follow-ups offered after a breakdown answer.
	 *
	 * @var array<int, string>
	 */
	private const BREAKDOWN_FOLLOWUPS = [
		'Which channel performs best?',
		'How many requests are open?',
		'Show the lead conversion rate.',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App configuration (register / schema IDs).
	 * @param LoggerInterface $logger Logger.
	 * @param TicketService $ticketService Resolver for the unified ticket schema.
	 * @param NaviConversationStore $conversationStore User-scoped store of conversation turns.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly TicketService $ticketService,
		private readonly NaviConversationStore $conversationStore,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Main entry point. Detects intent, fetches context, returns the response.
	 *
	 * When a `conversationId` is supplied the preceding turns of that
	 * conversation are read back and a query whose own words classify as
	 * `unknown` inherits the previous turn's intent and subject instead of
	 * falling through to the clarification prompt. The turn is then appended to
	 * the record so the next follow-up can do the same.
	 *
	 * @param string $query Natural-language query from the user.
	 * @param string $userId Authenticated user id. Also scopes the
	 *                       conversation record; OpenRegister
	 *                       enforces object multi-tenancy itself.
	 * @param string|null $conversationId Conversation identifier minted by the
	 *                                    controller, or null for a one-shot
	 *                                    query that accumulates nothing.
	 *
	 * @return array{
	 *   query: string,
	 *   resultType: string,
	 *   chartData?: array<string, mixed>,
	 *   tableData?: array<string, mixed>,
	 *   textResponse: string,
	 *   suggestedFollowUps: array<int, string>,
	 *   conversationId?: string|null
	 * }
	 *
	 * @spec openspec/specs/dashboard/spec.md#conversational-follow-up
	 */
	public function processQuery(string $query, string $userId, ?string $conversationId = null): array {
		$run = new NaviQueryRun(asked: $query, userId: $userId);
		if ($run->query === '') {
			return $this->textAnswer(query: $query, text: 'Please enter a question to get started.');
		}

		$run->conversationId = $conversationId;
		$context = $this->contextFor(run: $run);
		$this->rememberTurn(run: $run);

		// No data: degrade to a polite text response (REQ-DASH-001 empty result).
		if ($context === [] && $run->intent !== 'unknown') {
			return $this->textAnswer(query: $query, text: 'I could not find any matching data for that question.');
		}

		return $this->answer(run: $run, context: $context);
	}//end processQuery()

	/**
	 * Resolve the run's intent from the conversation and fetch its rows.
	 *
	 * @param NaviQueryRun $run The question being answered.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function contextFor(NaviQueryRun $run): array {
		$run->history = $this->conversationStore->read(userId: $run->userId, conversationId: $run->conversationId);
		$carried = $this->conversationStore->carryForward(
			detectedIntent: $this->detectIntent(query: $run->query),
			history: $run->history
		);
		$run->intent = $carried['intent'];

		return $this->buildContext(intent: $run->intent, params: ['query' => $run->query, 'previousQuery' => $carried['query']]);
	}//end contextFor()

	/**
	 * Append the run's question and intent to its conversation.
	 *
	 * @param NaviQueryRun $run The question being answered.
	 *
	 * @return void
	 */
	private function rememberTurn(NaviQueryRun $run): void {
		$this->conversationStore->append(
			userId: $run->userId,
			conversationId: $run->conversationId,
			history: $run->history,
			turn: ['query' => $run->query, 'intent' => $run->intent]
		);
	}//end rememberTurn()

	/**
	 * Build, log and wrap the intent answer for rows that were found.
	 *
	 * @param NaviQueryRun $run The question being answered.
	 * @param array<int, array<string, mixed>> $context Rows for the intent.
	 *
	 * @return array<string, mixed>
	 */
	private function answer(NaviQueryRun $run, array $context): array {
		$llmResponse = $this->buildResponseForIntent(intent: $run->intent, query: $run->query, context: $context);
		$this->logger->info(
			message: '[NaviService] processed query',
			context: ['userId' => $run->userId, 'intent' => $run->intent, 'queryLength' => strlen($run->query)]
		);

		return $this->formatResponse(query: $run->asked, llmResponse: $llmResponse, rawData: $context);
	}//end answer()

	/**
	 * Plain text envelope with the default follow-ups.
	 *
	 * @param string $query The question as typed.
	 * @param string $text  The answer text.
	 *
	 * @return array<string, mixed>
	 */
	private function textAnswer(string $query, string $text): array {
		return $this->formatResponse(
			query: $query,
			llmResponse: ['resultType' => 'text', 'textResponse' => $text, 'suggestedFollowUps' => $this->defaultFollowUps()],
			rawData: []
		);
	}//end textAnswer()

	/**
	 * Classify the query into one of the recognised intents.
	 *
	 * Pure string matching; bilingual NL/EN keywords cover the most common
	 * tender language ("trend", "verloop", "hoeveel", "groep", "conversie").
	 *
	 * @param string $query Natural-language query (trimmed).
	 *
	 * @return string One of self::INTENTS.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-navi-ai-analytics-widget-req-dash-001
	 */
	public function detectIntent(string $query): string {
		$lower = mb_strtolower($query);

		if (preg_match('/\b(trend|verloop|over time|groei|growth)\b/u', $lower) === 1) {
			return 'trend';
		}

		if (preg_match('/\b(conversie|conversion|win rate|win-rate|won|gewonnen|verloren)\b/u', $lower) === 1) {
			return 'conversion';
		}

		if (preg_match('/\b(groep|breakdown|per (categorie|stage|fase|kanaal|channel)|by category|by stage|by channel)\b/u', $lower) === 1) {
			return 'breakdown';
		}

		if (preg_match('/\b(hoeveel|aantal|count|how many)\b/u', $lower) === 1) {
			return 'count';
		}

		return 'unknown';
	}//end detectIntent()

	/**
	 * Fetch the OpenRegister objects required to answer the given intent.
	 *
	 * @param string $intent One of self::INTENTS.
	 * @param array<string, mixed> $params Free-form params: `query`, and the
	 *                                     optional `previousQuery` a follow-up
	 *                                     inherits its subject from.
	 *
	 * @return array<int, array<string, mixed>> Plain-array rows.
	 *
	 * @spec openspec/specs/dashboard/spec.md#conversational-follow-up
	 */
	public function buildContext(string $intent, array $params): array {
		return match ($intent) {
			'trend', 'conversion' => $this->findObjects(schemaKey: 'lead_schema'),
			'breakdown' => $this->findTickets(ticketType: TicketService::TYPE_REQUEST),
			'count' => $this->buildCountContext(
				query: (string)($params['query'] ?? ''),
				previousQuery: (string)($params['previousQuery'] ?? '')
			),
			default => [],
		};
	}//end buildContext()

	/**
	 * Fetch the rows to count for an ambiguous "hoeveel"-style query.
	 *
	 * Requests and contactmomenten are subtypes of the unified `ticket` schema,
	 * so they are read through TicketService with a `ticketType` discriminator
	 * instead of from their own (retired) schemas.
	 *
	 * A follow-up that names no subject of its own ("And how many of those are
	 * overdue?") is counted against the subject of the query it follows, which
	 * is what keeps "those" pointing at the same records.
	 *
	 * @param string $query Query (trimmed by caller).
	 * @param string $previousQuery Preceding turn's query, or '' when there is none.
	 *
	 * @return array<int, array<string, mixed>> Plain-array rows.
	 *
	 * @throws RuntimeException When OpenRegister is unreachable mid-query.
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	private function buildCountContext(string $query, string $previousQuery = ''): array {
		$ticketType = $this->ticketService->detectTypeInText(text: $query);
		if ($ticketType === null) {
			$ticketType = $this->ticketService->detectTypeInText(text: $previousQuery);
		}

		if ($ticketType !== null) {
			return $this->findTickets(ticketType: $ticketType);
		}

		// Leads are both the explicit lead match and the fallback.
		return $this->findObjects(schemaKey: 'lead_schema');
	}//end buildCountContext()

	/**
	 * Query tickets of one subtype via the shared TicketService.
	 *
	 * TicketService is fail-soft: an unprovisioned ticket schema or an
	 * unreachable OpenRegister yields an empty row set (logged there), which
	 * processQuery degrades into the "no matching data" response.
	 *
	 * @param string $ticketType One of the TicketService::TYPE_* constants.
	 *
	 * @return array<int, array<string, mixed>> Plain-array rows.
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	private function findTickets(string $ticketType): array {
		$objects = [];
		foreach ($this->ticketService->findByType(ticketType: $ticketType) as $result) {
			$normalized = $this->normalizeFindObjectsResult(result: $result);
			if ($normalized !== null) {
				$objects[] = $normalized;
			}
		}

		return $objects;
	}//end findTickets()

	/**
	 * Build the inner LLM-response envelope for a given intent.
	 *
	 * Deterministic — no actual LLM call required; the OpenRegister
	 * `ChatService` is reserved for free-form summarisation in V2.
	 *
	 * @param string $intent Recognised intent.
	 * @param string $query Trimmed query.
	 * @param array<int, array<string, mixed>> $context Object rows from buildContext.
	 *
	 * @return array<string, mixed> The intermediate LLM-response shape.
	 */
	private function buildResponseForIntent(string $intent, string $query, array $context): array {
		return match ($intent) {
			'trend' => $this->buildTrendResponse(rows: $context),
			'conversion' => $this->buildConversionResponse(rows: $context),
			'breakdown' => $this->buildBreakdownResponse(rows: $context),
			'count' => $this->buildCountResponse(rows: $context, query: $query),
			default => [
				'resultType' => 'text',
				'textResponse' => 'I am not sure how to answer that yet. Try asking about leads, requests, or trends.',
				'suggestedFollowUps' => $this->defaultFollowUps(),
			],
		};
	}//end buildResponseForIntent()

	/**
	 * Group leads by week and return a chart payload.
	 *
	 * @param array<int, array<string, mixed>> $rows Lead rows.
	 *
	 * @return array<string, mixed>
	 */
	private function buildTrendResponse(array $rows): array {
		return [
			'resultType' => 'chart',
			'chartData' => $this->chartData(type: 'line', counts: $this->rowCounts()->weeklyCounts(rows: $rows)),
			'textResponse' => sprintf('Found %d leads in the dataset.', count($rows)),
			'suggestedFollowUps' => self::TREND_FOLLOWUPS,
		];
	}//end buildTrendResponse()

	/**
	 * Chart payload with one series of counts.
	 *
	 * @param string $type   Chart type: `line` (leads) or `bar` (requests).
	 * @param array<string, int> $counts Count per label.
	 *
	 * @return array<string, mixed>
	 */
	private function chartData(string $type, array $counts): array {
		return [
			'type' => $type,
			'labels' => array_keys($counts),
			'series' => [
				['name' => self::SERIES_NAMES[$type], 'data' => array_values($counts)],
			],
		];
	}//end chartData()

	/**
	 * The stateless counters behind the chart and table answers.
	 *
	 * @return NaviRowCounts
	 */
	private function rowCounts(): NaviRowCounts {
		return new NaviRowCounts();
	}//end rowCounts()

	/**
	 * Compute won / lost / open counts and a percentage.
	 *
	 * @param array<int, array<string, mixed>> $rows Lead rows.
	 *
	 * @return array<string, mixed>
	 */
	private function buildConversionResponse(array $rows): array {
		$counts = $this->rowCounts()->statusCounts(rows: $rows);
		$total = array_sum($counts);
		$rate = $this->rowCounts()->wonRate(won: $counts['won'], total: $total);

		$tableRows = [['Open leads', $counts['open']], ['Won leads', $counts['won']], ['Lost leads', $counts['lost']]];
		$tableRows[] = ['Conversion rate (%)', $rate];
		return [
			'resultType' => 'table',
			'tableData' => ['columns' => ['Metric', 'Value'], 'rows' => $tableRows],
			'textResponse' => sprintf('Conversion rate: %.1f%% across %d leads.', $rate, $total),
			'suggestedFollowUps' => self::CONVERSION_FOLLOWUPS,
		];
	}//end buildConversionResponse()

	/**
	 * Group requests by category for a bar chart.
	 *
	 * @param array<int, array<string, mixed>> $rows Request-ticket rows.
	 *
	 * @return array<string, mixed>
	 */
	private function buildBreakdownResponse(array $rows): array {
		$counts = $this->rowCounts()->categoryCounts(rows: $rows);
		return [
			'resultType' => 'chart',
			'chartData' => $this->chartData(type: 'bar', counts: $counts),
			'textResponse' => sprintf('%d categories detected.', count($counts)),
			'suggestedFollowUps' => self::BREAKDOWN_FOLLOWUPS,
		];
	}//end buildBreakdownResponse()

	/**
	 * Plain-text count answer.
	 *
	 * @param array<int, array<string, mixed>> $rows Object rows.
	 * @param string $query Trimmed query.
	 *
	 * @return array<string, mixed>
	 */
	private function buildCountResponse(array $rows, string $query): array {
		$count = count($rows);
		return [
			'resultType' => 'text',
			'textResponse' => sprintf('Found %d records for "%s".', $count, $query),
			'suggestedFollowUps' => [
				'Show the trend over time.',
				'Group by category.',
				'What is my conversion rate?',
			],
		];
	}//end buildCountResponse()

	/**
	 * Shape the final API response envelope.
	 *
	 * @param string $query Original user query.
	 * @param array<string, mixed> $llmResponse Intermediate envelope.
	 * @param array<int, array<string, mixed>> $rawData Raw rows (unused in V1).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-navi-ai-analytics-widget-req-dash-001
	 */
	public function formatResponse(string $query, array $llmResponse, array $rawData): array {
		$followUps = array_slice(
			array: (array)($llmResponse['suggestedFollowUps'] ?? []),
			offset: 0,
			length: self::MAX_FOLLOWUPS
		);

		$envelope = [
			'query' => $query,
			'resultType' => (string)($llmResponse['resultType'] ?? 'text'),
			'textResponse' => (string)($llmResponse['textResponse'] ?? ''),
			'suggestedFollowUps' => array_values(array_map('strval', $followUps)),
		];

		if (isset($llmResponse['chartData']) === true) {
			$envelope['chartData'] = (array)$llmResponse['chartData'];
		}

		if (isset($llmResponse['tableData']) === true) {
			$envelope['tableData'] = (array)$llmResponse['tableData'];
		}

		// Suppress unused-parameter warning. $rawData is reserved for v2
		// (LLM context attachment) — the parameter is contract-stable.
		unset($rawData);

		return $envelope;
	}//end formatResponse()

	/**
	 * Default follow-up suggestions used as fallback.
	 *
	 * @return array<int, string>
	 */
	private function defaultFollowUps(): array {
		return [
			'How many leads are open?',
			'What is my conversion rate?',
			'Show the trend over time.',
		];
	}//end defaultFollowUps()

	/**
	 * Query objects of a configured schema via the OpenRegister ObjectService.
	 *
	 * Empty register / schema config -> empty array (logged at info level).
	 *
	 * @param string $schemaKey App-config key holding the schema ID.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RuntimeException When OpenRegister is unreachable mid-query.
	 */
	private function findObjects(string $schemaKey): array {
		$filters = $this->schemaFilters(schemaKey: $schemaKey);
		if ($filters === []) {
			return [];
		}

		$objects = [];
		foreach ($this->fetchRows(filters: $filters, schemaKey: $schemaKey) as $result) {
			$normalized = $this->normalizeFindObjectsResult(result: $result);
			if ($normalized !== null) {
				$objects[] = $normalized;
			}
		}

		return $objects;
	}//end findObjects()

	/**
	 * Register / schema filter for a configured schema; [] when either is unset.
	 *
	 * @param string $schemaKey App-config key holding the schema ID.
	 *
	 * @return array<string, string>
	 */
	private function schemaFilters(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			$this->logger->info(
				message: '[NaviService] register or schema not configured',
				context: ['schemaKey' => $schemaKey]
			);
			return [];
		}

		return ['register' => $register, 'schema' => $schema];
	}//end schemaFilters()

	/**
	 * Run findAll() for the filter; a failure is logged and rethrown.
	 *
	 * The findAll() contract returns a non-nullable array, so no `?? []`.
	 *
	 * @param array<string, string> $filters   Register / schema filter.
	 * @param string                $schemaKey App-config key, for the log.
	 *
	 * @return array<int, mixed>
	 *
	 * @throws RuntimeException When OpenRegister is unreachable mid-query.
	 */
	private function fetchRows(array $filters, string $schemaKey): array {
		try {
			return $this->objectService->findAll(config: ['filters' => $filters]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[NaviService] findAll failed',
				context: ['schemaKey' => $schemaKey, 'error' => $e->getMessage()]
			);
			throw new RuntimeException(message: 'Navi query failed', code: 0, previous: $e);
		}
	}//end fetchRows()

	/**
	 * Normalize a single ObjectService::findAll() result row to a plain array.
	 *
	 * Mirrors the previous inline `findObjects()` loop body exactly: arrays pass
	 * through, objects exposing `jsonSerialize()` are serialized, objects
	 * exposing `getObject()` are unwrapped, anything else yields null.
	 *
	 * @param mixed $result A single row from ObjectService::findAll().
	 *
	 * @return array<string, mixed>|null The normalized row, or null when it
	 *                                   could not be normalized to an array.
	 */
	private function normalizeFindObjectsResult(mixed $result): ?array {
		if (is_array($result) === true) {
			return $result;
		}

		foreach (['jsonSerialize', 'getObject'] as $method) {
			$data = $this->unwrapped(result: $result, method: $method);
			if ($data !== null) {
				return $data;
			}
		}

		return null;
	}//end normalizeFindObjectsResult()

	/**
	 * Array returned by the given method of an object row, if it has one.
	 *
	 * @param mixed  $result A single row from ObjectService::findAll().
	 * @param string $method `jsonSerialize` or `getObject`.
	 *
	 * @return array<string, mixed>|null
	 */
	private function unwrapped(mixed $result, string $method): ?array {
		if (is_object($result) === false || method_exists($result, $method) === false) {
			return null;
		}

		$data = $result->$method();
		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end unwrapped()
}//end class
