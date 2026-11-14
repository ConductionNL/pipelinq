<?php

/**
 * Pipelinq ReportingService.
 *
 * Service for contact moment reporting and KPI calculations.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-sla-configuration
 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-export-and-bi-integration
 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-kpi-dashboard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Pipelinq\Kernel\Contracts\ReportingServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for reporting and KPI calculations.
 *
 * Provides methods for calculating KPIs, SLA compliance, channel distribution,
 * and agent performance metrics from contactmoment data.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Aggregates the KPI, SLA,
 *  channel-distribution and agent-performance calculations as many small,
 *  single-purpose methods over one contactmoment reporting concern; splitting
 *  it would scatter one cohesive reporting surface across several classes.
 *
 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
 */
class ReportingService implements ReportingServiceInterface {
	/**
	 * Default SLA targets.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const DEFAULT_SLA_TARGETS = [
		'telefoon' => ['wait_seconds' => 30, 'target_percent' => 90, 'handle_minutes' => 5],
		'email' => ['response_hours' => 8, 'target_percent' => 90, 'resolution_hours' => 24],
		'balie' => ['wait_minutes' => 5, 'target_percent' => 90, 'handle_minutes' => 10],
		'chat' => ['response_seconds' => 30, 'target_percent' => 90, 'handle_minutes' => 10],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 * @param TicketService $ticketService The unified ticket resolver (unify-ticket-supertype).
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private TicketService $ticketService,
	) {
	}//end __construct()

	/**
	 * Get KPI data for the given date range.
	 *
	 * Reads contactmoment objects via ObjectService where available; returns
	 * zero-value KPIs when no data is present rather than throwing.
	 *
	 * @param string $from ISO 8601 start date (inclusive).
	 * @param string $to ISO 8601 end date (inclusive).
	 *
	 * @return array{total: int, fcrRate: float, avgHandlingTime: string, slaCompliance: float} KPI data.
	 *
	 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getKpis(string $from, string $to): array {
		try {
			return $this->kpisOf(contactmomenten: $this->fetchContactmomenten(from: $from, to: $to));
		} catch (\Throwable $e) {
			$this->logger->error('ReportingService::getKpis failed', ['exception' => $e]);
			return [
				'total' => 0,
				'fcrRate' => 0.0,
				'avgHandlingTime' => '0:00',
				'slaCompliance' => 0.0,
			];
		}
	}//end getKpis()

	/**
	 * The KPIs of a set of contactmomenten.
	 *
	 * @param array<array<string, mixed>> $contactmomenten The contactmoment data.
	 *
	 * @return array{total: int, fcrRate: float, avgHandlingTime: string, slaCompliance: float}
	 */
	private function kpisOf(array $contactmomenten): array {
		return [
			'total' => count($contactmomenten),
			'fcrRate' => $this->calculateFcr(contactmomenten: $contactmomenten),
			'avgHandlingTime' => $this->calculateAverageHandlingTime(durations: self::durationsOf(moments: $contactmomenten)),
			// Compute SLA compliance across all channels.
			'slaCompliance' => $this->overallSlaCompliance(contactmomenten: $contactmomenten),
		];
	}//end kpisOf()

	/**
	 * The share of contactmomenten handled within their channel's SLA, as a
	 * percentage; 0.0 for none.
	 *
	 * @param array<array<string, mixed>> $contactmomenten The contactmoment data.
	 *
	 * @return float
	 */
	private function overallSlaCompliance(array $contactmomenten): float {
		$total = count($contactmomenten);
		if ($total === 0) {
			return 0.0;
		}

		// Group by channel preserving full moment arrays for SLA threshold evaluation.
		$totalWithin = 0;
		foreach (self::groupedBy(moments: $contactmomenten, field: 'channel') as $channel => $items) {
			$totalWithin += $this->countWithinSla(channel: $channel, moments: $items);
		}

		return round(($totalWithin / $total) * 100, 1);
	}//end overallSlaCompliance()

	/**
	 * The non-empty handling durations of a set of contactmomenten, in order.
	 *
	 * @param array<array<string, mixed>> $moments The contactmoment data.
	 *
	 * @return array<int, mixed>
	 */
	private static function durationsOf(array $moments): array {
		return array_values(
			array_filter(
				array_column($moments, 'duration'),
				static fn ($v) => $v !== null && $v !== '',
			)
		);
	}//end durationsOf()

	/**
	 * Contactmomenten grouped by one of their fields ('unknown' when absent), in first-seen order.
	 *
	 * @param array<array<string, mixed>> $moments The contactmoment data.
	 * @param string                      $field   `channel` or `agent`.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function groupedBy(array $moments, string $field): array {
		$groups = [];
		foreach ($moments as $moment) {
			$groups[$moment[$field] ?? 'unknown'][] = $moment;
		}

		return $groups;
	}//end groupedBy()

	/**
	 * Get channel distribution for the given date range.
	 *
	 * @param string $from ISO 8601 start date.
	 * @param string $to ISO 8601 end date.
	 *
	 * @return array<string, int> Contact counts keyed by channel.
	 *
	 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getChannelDistribution(string $from, string $to): array {
		try {
			$contactmomenten = $this->fetchContactmomenten(from: $from, to: $to);
			return $this->groupByChannel(contactmomenten: $contactmomenten);
		} catch (\Throwable $e) {
			$this->logger->error('ReportingService::getChannelDistribution failed', ['exception' => $e]);
			return [];
		}//end try
	}//end getChannelDistribution()

	/**
	 * Get channel trend data for the given date range.
	 *
	 * Returns daily or weekly contact counts per channel as time-series data.
	 *
	 * @param string $from ISO 8601 start date.
	 * @param string $to ISO 8601 end date.
	 * @param string $granularity 'daily' or 'weekly'.
	 *
	 * @return array<string, array<string, int>> Time-series data: channel => [date => count].
	 *
	 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getChannelTrend(string $from, string $to, string $granularity = 'daily'): array {
		try {
			$contactmomenten = $this->fetchContactmomenten(from: $from, to: $to);
			$trend = [];

			foreach ($contactmomenten as $moment) {
				$channel = $moment['channel'] ?? 'unknown';
				$contactedAt = $moment['contactedAt'] ?? null;
				if ($contactedAt === null) {
					continue;
				}

				try {
					$dateTime = new DateTimeImmutable($contactedAt);
					$key = $dateTime->format('Y-m-d');
					if ($granularity === 'weekly') {
						$key = $dateTime->format('o-W');
					}
				} catch (\Exception) {
					continue;
				}

				$trend[$channel][$key] = ($trend[$channel][$key] ?? 0) + 1;
			}

			return $trend;
		} catch (\Throwable $e) {
			$this->logger->error('ReportingService::getChannelTrend failed', ['exception' => $e]);
			return [];
		}//end try
	}//end getChannelTrend()

	/**
	 * Get per-agent performance metrics for the given date range.
	 *
	 * @param string $from ISO 8601 start date.
	 * @param string $to ISO 8601 end date.
	 *
	 * @return array<string, array{count: int, fcrRate: float, avgHandlingTime: string}> Per-agent metrics.
	 *
	 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getAgentPerformance(string $from, string $to): array {
		try {
			$result = array_map(
				fn (array $moments): array => $this->agentMetrics(moments: $moments),
				self::groupedBy(moments: $this->fetchContactmomenten(from: $from, to: $to), field: 'agent')
			);

			// Sort by contact count descending.
			uasort($result, static fn ($a, $b) => $b['count'] <=> $a['count']);

			return $result;
		} catch (\Throwable $e) {
			$this->logger->error('ReportingService::getAgentPerformance failed', ['exception' => $e]);
			return [];
		}
	}//end getAgentPerformance()

	/**
	 * One agent's count, first-contact resolution and average handling time.
	 *
	 * @param array<array<string, mixed>> $moments The agent's contactmomenten.
	 *
	 * @return array{count: int, fcrRate: float, avgHandlingTime: string}
	 */
	private function agentMetrics(array $moments): array {
		return [
			'count' => count($moments),
			'fcrRate' => $this->calculateFcr(contactmomenten: $moments),
			'avgHandlingTime' => $this->calculateAverageHandlingTime(durations: self::durationsOf(moments: $moments)),
		];
	}//end agentMetrics()

	/**
	 * Get SLA compliance percentage for a given channel and date range.
	 *
	 * Returns 100.0 when there are no contacts (vacuously compliant).
	 *
	 * @param string $channel The channel type.
	 * @param string $from ISO 8601 start date.
	 * @param string $to ISO 8601 end date.
	 *
	 * @return float Compliance as a percentage (0-100).
	 *
	 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getSlaCompliance(string $channel, string $from, string $to): float {
		try {
			$contactmomenten = $this->fetchContactmomenten(from: $from, to: $to);
			$channelItems = array_values(
				array_filter(
					$contactmomenten,
					static fn ($moment) => ($moment['channel'] ?? '') === $channel,
				)
			);

			$total = count($channelItems);
			if ($total === 0) {
				return 100.0;
			}

			$within = $this->countWithinSla(channel: $channel, moments: $channelItems);
			return round(($within / $total) * 100, 1);
		} catch (\Throwable $e) {
			$this->logger->error('ReportingService::getSlaCompliance failed', ['exception' => $e]);
			return 0.0;
		}//end try
	}//end getSlaCompliance()

	/**
	 * Calculate first-call resolution rate from an array of contactmoment objects.
	 *
	 * FCR = count(outcome == 'resolved') / count(total) * 100.
	 * Returns 0.0 for an empty dataset.
	 *
	 * @param array<array<string, mixed>> $contactmomenten Array of contactmoment data arrays.
	 *
	 * @return float FCR as a percentage (0-100).
	 *
	 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
	 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-kpi-dashboard
	 */
	public function calculateFcr(array $contactmomenten): float {
		$total = count($contactmomenten);
		if ($total === 0) {
			return 0.0;
		}

		$resolved = count(
			array_filter(
				$contactmomenten,
				static fn ($moment) => ($moment['outcome'] ?? '') === 'resolved',
			)
		);

		return round(($resolved / $total) * 100, 1);
	}//end calculateFcr()

	/**
	 * Get SLA targets from IAppConfig.
	 *
	 * @return array<string, array<string, mixed>> SLA targets per channel.
	 *
	 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getSlaTargets(): array {
		return $this->getAllSlaTargets();
	}//end getSlaTargets()

	/**
	 * Calculate SLA compliance for a channel.
	 *
	 * @param string $channel The channel type.
	 * @param int $totalContacts Total contacts for the channel.
	 * @param int $withinSla Contacts handled within SLA target.
	 *
	 * @return array{compliance: float, target: float, status: string} SLA data.
	 *
	 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-sla-configuration
	 */
	public function calculateSlaCompliance(
		string $channel,
		int $totalContacts,
		int $withinSla,
	): array {
		$target = $this->getSlaTarget(channel: $channel);
		$compliance = 0.0;
		if ($totalContacts > 0) {
			$compliance = round(($withinSla / $totalContacts) * 100, 1);
		}

		$status = 'green';
		if ($compliance < $target - 5) {
			$status = 'red';
		} elseif ($compliance < $target) {
			$status = 'orange';
		}

		return [
			'compliance' => $compliance,
			'target' => $target,
			'status' => $status,
		];
	}//end calculateSlaCompliance()

	/**
	 * Get SLA target percentage for a channel.
	 *
	 * @param string $channel The channel type.
	 *
	 * @return float The target percentage.
	 *
	 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-sla-configuration
	 */
	public function getSlaTarget(string $channel): float {
		$key = 'sla_' . $channel . '_target_percent';
		$default = self::DEFAULT_SLA_TARGETS[$channel]['target_percent'] ?? 90;

		return (float)$this->appConfig->getValueString(
			'pipelinq',
			$key,
			(string)$default,
		);
	}//end getSlaTarget()

	/**
	 * Get all SLA configuration.
	 *
	 * @return array<string, array<string, mixed>> SLA targets per channel.
	 *
	 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-sla-configuration
	 */
	public function getAllSlaTargets(): array {
		$targets = [];

		foreach (self::DEFAULT_SLA_TARGETS as $channel => $defaults) {
			$targets[$channel] = [];
			foreach ($defaults as $metric => $default) {
				$key = 'sla_' . $channel . '_' . $metric;
				$targets[$channel][$metric] = $this->appConfig->getValueString(
					'pipelinq',
					$key,
					(string)$default,
				);
			}
		}

		return $targets;
	}//end getAllSlaTargets()

	/**
	 * Update SLA target for a channel.
	 *
	 * Validates that channel and metric are in the DEFAULT_SLA_TARGETS allowlist
	 * before constructing the appconfig key, preventing arbitrary key injection
	 * into oc_appconfig (issue #606).
	 *
	 * @param string $channel The channel type.
	 * @param string $metric The metric name.
	 * @param string $value The target value.
	 *
	 * @return bool False when channel or metric is not in the allowlist.
	 *
	 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-sla-configuration
	 */
	public function setSlaTarget(string $channel, string $metric, string $value): bool {
		// Validate channel against the allowlist.
		if (isset(self::DEFAULT_SLA_TARGETS[$channel]) === false) {
			return false;
		}

		// Validate metric against the allowed metrics for this channel.
		if (array_key_exists($metric, self::DEFAULT_SLA_TARGETS[$channel]) === false) {
			return false;
		}

		$key = 'sla_' . $channel . '_' . $metric;
		$this->appConfig->setValueString('pipelinq', $key, $value);
		return true;
	}//end setSlaTarget()

	/**
	 * Generate CSV content from data.
	 *
	 * Uses semicolon separators and UTF-8 BOM for Excel compatibility.
	 *
	 * @param array<string> $headers The CSV header row.
	 * @param array<array<string>> $rows The data rows.
	 *
	 * @return string The CSV content.
	 *
	 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-export-and-bi-integration
	 */
	public function generateCsv(array $headers, array $rows): string {
		$bom = "\xEF\xBB\xBF";
		$output = $bom . implode(';', array_map($this->neutralizeCsvCell(...), $headers)) . "\n";

		foreach ($rows as $row) {
			$output .= implode(
				';',
				array_map($this->neutralizeCsvCell(...), $row)
			) . "\n";
		}

		return $output;
	}//end generateCsv()

	/**
	 * Calculate average handling time from durations.
	 *
	 * @param array<string> $durations ISO 8601 duration strings.
	 *
	 * @return string Formatted average duration (MM:SS).
	 *
	 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-kpi-dashboard
	 */
	public function calculateAverageHandlingTime(array $durations): string {
		if (count($durations) === 0) {
			return '0:00';
		}

		$totalSeconds = 0;
		$counted = 0;
		foreach ($durations as $duration) {
			try {
				$interval = new DateInterval($duration);
				$totalSeconds += ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
				$counted++;
			} catch (\Exception) {
				// Skip invalid durations.
				continue;
			}
		}

		if ($counted === 0) {
			return '0:00';
		}

		$avgSeconds = (int)($totalSeconds / $counted);
		$minutes = (int)($avgSeconds / 60);
		$seconds = $avgSeconds % 60;

		return $minutes . ':' . str_pad((string)$seconds, 2, '0', STR_PAD_LEFT);
	}//end calculateAverageHandlingTime()

	/**
	 * Fetch the contactmoment tickets that fall inside the given date range.
	 *
	 * Reads the unified `ticket` schema narrowed to `ticketType=contactmoment`
	 * (unify-ticket-supertype) and normalises the two renamed fields back to the
	 * vocabulary the KPI/channel/agent calculators below already speak
	 * (`occurredAt` -> `contactedAt`, `assignee` -> `agent`). Keeping that
	 * translation at this single boundary means the calculators — and their unit
	 * tests, which feed them plain arrays — stay untouched.
	 *
	 * Degrades to an empty array (never throws) when the ticket schema is
	 * unprovisioned or OpenRegister is unavailable, so the reporting endpoints
	 * return zero-value KPIs rather than a 500.
	 *
	 * @param string $from Start date (inclusive), `YYYY-MM-DD`.
	 * @param string $to End date (inclusive), `YYYY-MM-DD`.
	 *
	 * @return array<array<string, mixed>> Normalised contactmoment data arrays.
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#scenario-contactmomenten-reporting-reads-tickets
	 */
	private function fetchContactmomenten(string $from, string $to): array {
		$rows = $this->ticketService->findByType(ticketType: TicketService::TYPE_CONTACTMOMENT);
		if ($rows === []) {
			return [];
		}

		$moments = [];
		foreach ($rows as $row) {
			$data = self::ticketData(row: $row);
			if ($data === null) {
				continue;
			}

			$occurredAt = (string)($data['occurredAt'] ?? '');
			if ($this->withinRange(occurredAt: $occurredAt, from: $from, to: $to) === true) {
				$moments[] = self::momentOf(data: $data, occurredAt: $occurredAt);
			}
		}

		return $moments;
	}//end fetchContactmomenten()

	/**
	 * A ticket row as an array: the row itself, or what it serialises to; null otherwise.
	 *
	 * @param mixed $row The ticket row or entity.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function ticketData($row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		$data = null;
		if ($row instanceof \JsonSerializable) {
			$data = $row->jsonSerialize();
		}

		if (is_array($data) === true) {
			return $data;
		}

		return null;
	}//end ticketData()

	/**
	 * The contactmoment the calculators read, from a ticket's data.
	 *
	 * @param array<string, mixed> $data       The ticket data.
	 * @param string               $occurredAt The ticket's `occurredAt`.
	 *
	 * @return array<string, mixed>
	 */
	private static function momentOf(array $data, string $occurredAt): array {
		return [
			// Renamed on the ticket schema — translate back for the calculators.
			'contactedAt' => $occurredAt,
			'agent' => ($data['assignee'] ?? 'unknown'),
			// Unchanged between contactmoment and ticket.
			'channel' => ($data['channel'] ?? 'unknown'),
			'outcome' => ($data['outcome'] ?? ''),
			'duration' => ($data['duration'] ?? null),
			'channelMetadata' => ($data['channelMetadata'] ?? []),
		];
	}//end momentOf()

	/**
	 * Whether an occurrence timestamp falls inside an inclusive date window.
	 *
	 * Compares on the date part only, so a `YYYY-MM-DD` bound matches any time
	 * of day on that date. An empty bound is treated as unbounded; an
	 * unparseable/absent timestamp is excluded (conservative).
	 *
	 * @param string $occurredAt The ticket's `occurredAt` (ISO-8601 or empty).
	 * @param string $from Start date (inclusive), `YYYY-MM-DD`, or ''.
	 * @param string $to End date (inclusive), `YYYY-MM-DD`, or ''.
	 *
	 * @return bool True when the timestamp is inside the window.
	 */
	private function withinRange(string $occurredAt, string $from, string $to): bool {
		if ($occurredAt === '') {
			return false;
		}

		$date = substr($occurredAt, 0, 10);
		if ($from !== '' && $date < substr($from, 0, 10)) {
			return false;
		}

		if ($to !== '' && $date > substr($to, 0, 10)) {
			return false;
		}

		return true;
	}//end withinRange()

	/**
	 * Group contactmomenten by channel.
	 *
	 * @param array<array<string, mixed>> $contactmomenten The contactmoment data.
	 *
	 * @return array<string, int> Counts keyed by channel.
	 */
	private function groupByChannel(array $contactmomenten): array {
		$groups = [];
		foreach ($contactmomenten as $moment) {
			$channel = $moment['channel'] ?? 'unknown';
			$groups[$channel] = ($groups[$channel] ?? 0) + 1;
		}

		return $groups;
	}//end groupByChannel()

	/**
	 * Count contacts within SLA for a channel.
	 *
	 * Uses channelMetadata.waitTime (seconds) for telefoon/balie/chat, or
	 * channelMetadata.responseTime (hours) for email. Falls back to 0 when
	 * the metadata is absent (conservative: not within SLA).
	 *
	 * @param string $channel The channel.
	 * @param array<array<string, mixed>> $moments The contactmoment data for this channel.
	 *
	 * @return int Count of contacts within SLA target.
	 */
	private function countWithinSla(string $channel, array $moments): int {
		$within = 0;
		$targets = self::DEFAULT_SLA_TARGETS[$channel] ?? [];
		$threshold = match ($channel) {
			'telefoon' => (int)($targets['wait_seconds'] ?? 30),
			'balie' => (int)($targets['wait_minutes'] ?? 5) * 60,
			'chat' => (int)($targets['response_seconds'] ?? 30),
			'email' => (int)($targets['response_hours'] ?? 8) * 3600,
			default => 0,
		};

		if ($threshold === 0) {
			return count($moments);
		}

		foreach ($moments as $moment) {
			$metadata = $moment['channelMetadata'] ?? [];
			$measured = match ($channel) {
				'email' => (int)(($metadata['responseTimeHours'] ?? 0) * 3600),
				default => (int)($metadata['waitTime'] ?? $threshold + 1),
			};

			if ($measured <= $threshold) {
				$within++;
			}
		}

		return $within;
	}//end countWithinSla()

	/**
	 * Neutralize a CSV cell value to prevent formula injection.
	 *
	 * Prefixes cells starting with =, +, -, @, tab, or CR with a single
	 * quote so spreadsheet applications treat them as plain text.
	 *
	 * @param mixed $value The raw cell value.
	 *
	 * @return string The quoted and injection-safe cell string.
	 */
	private function neutralizeCsvCell(mixed $value): string {
		$str = (string)$value;
		if (preg_match('/^[=+\-@\t\r]/', $str) === 1) {
			$str = "'" . $str;
		}

		return '"' . str_replace('"', '""', $str) . '"';
	}//end neutralizeCsvCell()
}//end class
