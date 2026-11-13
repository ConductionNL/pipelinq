<?php

/**
 * Pipelinq RapportageService.
 *
 * Server-side analytics aggregation for the lead-management Rapportage
 * dashboard. Computes pipeline funnel, source performance, lead aging
 * and win/loss summaries from OpenRegister `lead` objects.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/lead-management/spec.md
 * @spec openspec/specs/lead-management/spec.md
 * @spec openspec/specs/lead-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Pipeline analytics aggregation service.
 *
 * Read-only — every public method returns a JSON-serialisable array. The
 * controller layer is responsible for HTTP concerns (auth, validation,
 * error mapping). Static error strings keep the response surface stable
 * for the frontend (no $e->getMessage() leakage into JSON).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Aggregation reads several schemas.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) four independent read-only
 *  analytics endpoints (stage values / source performance / aging / win-loss)
 *  each broken into small single-purpose methods; the sum is high but each
 *  method is individually under threshold.
 *
 * @spec openspec/specs/lead-management/spec.md
 */
class RapportageService {
	use PayloadToArray;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config (register/schema slugs).
	 * @param LoggerInterface $logger Logger for fallback paths.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Pipeline value per stage (count, total value, weighted by win chance).
	 *
	 * @param string|null $pipelineId Optional pipeline filter (matches lead.pipeline).
	 *
	 * @return array<int, array{stage: string, count: int, totalValue: float, weightedValue: float}>
	 *
	 * @spec openspec/specs/lead-management/spec.md
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	public function getStageValues(?string $pipelineId = null): array {
		$leads = $this->fetchLeads();
		$buckets = [];

		foreach ($leads as $lead) {
			if ($pipelineId !== null && $pipelineId !== '' && (string)($lead['pipeline'] ?? '') !== $pipelineId) {
				continue;
			}

			$stage = (string)($lead['stage'] ?? '');
			if ($stage === '') {
				continue;
			}

			if (isset($buckets[$stage]) === false) {
				$buckets[$stage] = ['stage' => $stage, 'count' => 0, 'totalValue' => 0.0, 'weightedValue' => 0.0];
			}

			$buckets[$stage]['count']++;
			$buckets[$stage]['totalValue'] += (float)($lead['value'] ?? 0);
			$buckets[$stage]['weightedValue'] += $this->winChanceWeightedValue(lead: $lead);
		}

		return array_values($buckets);
	}//end getStageValues()

	/**
	 * A lead's value weighted by its win chance.
	 *
	 * The win chance is the lead's qualification score read as a percentage,
	 * clamped to 0-100; a lead without a score counts as 0 (decided by Ruben,
	 * 2026-10-06). The `probability` field is hidden on the lead form and
	 * empty on real installs, so it never feeds a forecast. AnalyticsService
	 * applies the same rule; keep the two in step.
	 *
	 * @param array<string, mixed> $lead The lead row.
	 *
	 * @return float The weighted value.
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	private function winChanceWeightedValue(array $lead): float {
		$score = ($lead['qualificationScore'] ?? null);
		if (is_numeric($score) === false) {
			return 0.0;
		}

		return ((float)($lead['value'] ?? 0) * (max(0.0, min(100.0, (float)$score)) / 100.0));
	}//end winChanceWeightedValue()

	/**
	 * Source performance: total / won / conversion / avg-won-value per source.
	 *
	 * @param string|null $dateFrom Optional ISO 8601 lower bound (lead created date).
	 * @param string|null $dateTo Optional ISO 8601 upper bound (lead created date).
	 *
	 * @return array<int, array{source: string, total: int, won: int, conversionRate: float, avgWonValue: float}>
	 *
	 * @spec openspec/specs/lead-management/spec.md
	 */
	public function getSourcePerformance(?string $dateFrom = null, ?string $dateTo = null): array {
		$leads = $this->fetchLeads();
		$byCreated = $this->filterByCreated(leads: $leads, from: $dateFrom, to: $dateTo);

		$buckets = [];
		foreach ($byCreated as $lead) {
			$source = self::sourceOf(lead: $lead);
			$buckets[$source] = self::addToSource(
				bucket: ($buckets[$source] ?? ['source' => $source, 'total' => 0, 'won' => 0, 'wonValueSum' => 0.0]),
				lead: $lead
			);
		}

		return array_map(static fn (array $row): array => self::sourceRow(row: $row), array_values($buckets));
	}//end getSourcePerformance()

	/**
	 * The lead's source, 'unknown' when it has none.
	 *
	 * @param array<string, mixed> $lead The lead.
	 *
	 * @return string
	 */
	private static function sourceOf(array $lead): string {
		$source = (string)($lead['source'] ?? 'unknown');
		if ($source === '') {
			return 'unknown';
		}

		return $source;
	}//end sourceOf()

	/**
	 * Count one lead in its source bucket, with its value when it was won.
	 *
	 * @param array<string, mixed> $bucket The source bucket so far.
	 * @param array<string, mixed> $lead   The lead.
	 *
	 * @return array<string, mixed>
	 */
	private static function addToSource(array $bucket, array $lead): array {
		$bucket['total']++;
		$status = (string)($lead['status'] ?? 'open');
		if ($status === 'won') {
			$bucket['won']++;
			$bucket['wonValueSum'] += (float)($lead['value'] ?? 0);
		}

		return $bucket;
	}//end addToSource()

	/**
	 * One source's row: totals, conversion rate and average won value.
	 *
	 * @param array<string, mixed> $row The source bucket.
	 *
	 * @return array{source: string, total: int, won: int, conversionRate: float, avgWonValue: float}
	 */
	private static function sourceRow(array $row): array {
		$conversion = round(($row['won'] / $row['total']) * 100.0, 1);
		$avgWon = 0.0;

		if ($row['won'] > 0) {
			$avgWon = round($row['wonValueSum'] / $row['won'], 2);
		}

		return [
			'source' => $row['source'],
			'total' => $row['total'],
			'won' => $row['won'],
			'conversionRate' => $conversion,
			'avgWonValue' => $avgWon,
		];
	}//end sourceRow()

	/**
	 * Aging buckets — distributes open leads across the 4 fixed buckets.
	 *
	 * Buckets: 0-7d / 8-14d / 15-30d / >30d, keyed by `_dateModified`.
	 *
	 * @return array<int, array{bucket: string, count: int, totalValue: float}>
	 *
	 * @spec openspec/specs/lead-management/spec.md
	 */
	public function getAgingBuckets(): array {
		$leads = $this->fetchLeads();
		$now = time();

		$buckets = [
			'0-7d' => ['bucket' => '0-7d',   'count' => 0, 'totalValue' => 0.0],
			'8-14d' => ['bucket' => '8-14d',  'count' => 0, 'totalValue' => 0.0],
			'15-30d' => ['bucket' => '15-30d', 'count' => 0, 'totalValue' => 0.0],
			'30d+' => ['bucket' => '30d+',   'count' => 0, 'totalValue' => 0.0],
		];

		foreach ($leads as $lead) {
			$key = $this->agingBucketOf(lead: $lead, now: $now);
			if ($key !== null) {
				$buckets[$key]['count']++;
				$buckets[$key]['totalValue'] += (float)($lead['value'] ?? 0);
			}
		}

		return array_values($buckets);
	}//end getAgingBuckets()

	/**
	 * The aging bucket of an open lead by its last modification; null for a
	 * closed lead or one without a modification date.
	 *
	 * @param array<string, mixed> $lead The lead.
	 * @param int                  $now  The current Unix time.
	 *
	 * @return string|null
	 */
	private function agingBucketOf(array $lead, int $now): ?string {
		$status = (string)($lead['status'] ?? 'open');
		if ($status === 'won' || $status === 'lost') {
			return null;
		}

		$modified = $this->extractTimestamp(lead: $lead, key: '_dateModified');
		if ($modified === null) {
			return null;
		}

		return self::bucketForDays(days: max(0, (int)floor(($now - $modified) / 86400)));
	}//end agingBucketOf()

	/**
	 * The aging bucket of an age in days.
	 *
	 * @param int $days The age in whole days.
	 *
	 * @return string
	 */
	private static function bucketForDays(int $days): string {
		if ($days > 30) {
			return '30d+';
		}

		if ($days > 14) {
			return '15-30d';
		}

		if ($days > 7) {
			return '8-14d';
		}

		return '0-7d';
	}//end bucketForDays()

	/**
	 * Win/loss summary for closed leads within the optional date range.
	 *
	 * @param string|null $dateFrom Optional ISO 8601 lower bound.
	 * @param string|null $dateTo Optional ISO 8601 upper bound.
	 *
	 * @return array{wonCount:int, lostCount:int, winRate:float, avgWonValue:float, avgLostValue:float, avgDaysToClose:float}
	 *
	 * @spec openspec/specs/lead-management/spec.md
	 */
	public function getWinLossAnalysis(?string $dateFrom = null, ?string $dateTo = null): array {
		$leads = $this->fetchLeads();
		$closed = [];
		foreach ($leads as $lead) {
			$status = (string)($lead['status'] ?? '');
			if ($status === 'won' || $status === 'lost') {
				$closed[] = $lead;
			}
		}

		$closed = $this->filterByCreated(leads: $closed, from: $dateFrom, to: $dateTo);
		$totals = $this->accumulateWinLossTotals(leads: $closed);

		return $this->buildWinLossSummary(totals: $totals);
	}//end getWinLossAnalysis()

	/**
	 * Accumulate won/lost counts, value sums, and close-duration stats for closed leads.
	 *
	 * @param array<int, array<string, mixed>> $leads The closed (won/lost) leads.
	 *
	 * @return array{wonCount:int, lostCount:int, wonValueSum:float, lostValueSum:float, daysSum:float, daysCount:int}
	 */
	private function accumulateWinLossTotals(array $leads): array {
		$totals = ['wonCount' => 0, 'lostCount' => 0, 'wonValueSum' => 0.0, 'lostValueSum' => 0.0, 'daysSum' => 0.0, 'daysCount' => 0];
		foreach ($leads as $lead) {
			$totals = self::addOutcome(totals: $totals, lead: $lead);

			$days = $this->daysToClose(lead: $lead);
			if ($days !== null) {
				$totals['daysSum'] += $days;
				$totals['daysCount']++;
			}
		}

		return $totals;
	}//end accumulateWinLossTotals()

	/**
	 * Count a closed lead as won or lost, with its value.
	 *
	 * @param array<string, mixed> $totals The totals so far.
	 * @param array<string, mixed> $lead   The closed lead.
	 *
	 * @return array<string, mixed>
	 */
	private static function addOutcome(array $totals, array $lead): array {
		$value = (float)($lead['value'] ?? 0);
		if ((string)($lead['status'] ?? '') === 'won') {
			$totals['wonCount']++;
			$totals['wonValueSum'] += $value;
			return $totals;
		}

		$totals['lostCount']++;
		$totals['lostValueSum'] += $value;
		return $totals;
	}//end addOutcome()

	/**
	 * Days from creation to the last modification; null when either is missing or out of order.
	 *
	 * @param array<string, mixed> $lead The lead.
	 *
	 * @return float|null
	 */
	private function daysToClose(array $lead): ?float {
		$created = $this->extractTimestamp(lead: $lead, key: '_dateCreated');
		$modified = $this->extractTimestamp(lead: $lead, key: '_dateModified');
		if ($created === null || $modified === null || $modified < $created) {
			return null;
		}

		return (($modified - $created) / 86400);
	}//end daysToClose()

	/**
	 * Turn accumulated win/loss totals into the public summary shape.
	 *
	 * @param array{wonCount:int, lostCount:int, wonValueSum:float, lostValueSum:float, daysSum:float, daysCount:int} $totals The accumulated totals.
	 *
	 * @return array{wonCount:int, lostCount:int, winRate:float, avgWonValue:float, avgLostValue:float, avgDaysToClose:float}
	 */
	private function buildWinLossSummary(array $totals): array {
		$wonCount = $totals['wonCount'];
		$lostCount = $totals['lostCount'];
		$total = ($wonCount + $lostCount);

		$winRate = 0.0;
		if ($total > 0) {
			$winRate = round(($wonCount / $total) * 100.0, 1);
		}

		$avgWon = 0.0;
		if ($wonCount > 0) {
			$avgWon = round($totals['wonValueSum'] / $wonCount, 2);
		}

		$avgLost = 0.0;
		if ($lostCount > 0) {
			$avgLost = round($totals['lostValueSum'] / $lostCount, 2);
		}

		$avgDays = 0.0;
		if ($totals['daysCount'] > 0) {
			$avgDays = round($totals['daysSum'] / $totals['daysCount'], 1);
		}

		return [
			'wonCount' => $wonCount,
			'lostCount' => $lostCount,
			'winRate' => $winRate,
			'avgWonValue' => $avgWon,
			'avgLostValue' => $avgLost,
			'avgDaysToClose' => $avgDays,
		];
	}//end buildWinLossSummary()

	/**
	 * Filter leads by `_dateCreated` within the optional bounds.
	 *
	 * @param array<int, array<string, mixed>> $leads The leads.
	 * @param string|null $from ISO 8601 lower bound (inclusive).
	 * @param string|null $to ISO 8601 upper bound (inclusive).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function filterByCreated(array $leads, ?string $from, ?string $to): array {
		if (($from === null || $from === '') && ($to === null || $to === '')) {
			return $leads;
		}

		$fromTs = $this->parseDateBound(value: $from);
		$toTs = $this->parseDateBound(value: $to);

		$result = [];
		foreach ($leads as $lead) {
			if ($this->isWithinCreatedBounds(lead: $lead, fromTs: $fromTs, toTs: $toTs) === true) {
				$result[] = $lead;
			}
		}

		return $result;
	}//end filterByCreated()

	/**
	 * Parse an optional ISO-8601 date bound into a unix timestamp.
	 *
	 * @param ?string $value The date string, or null/empty for "no bound".
	 *
	 * @return int|null The parsed timestamp, or null when absent/unparseable.
	 */
	private function parseDateBound(?string $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		$parsed = strtotime($value);
		if ($parsed === false) {
			return null;
		}

		return $parsed;
	}//end parseDateBound()

	/**
	 * Whether a lead's `_dateCreated` falls within the optional [from, to] bounds.
	 *
	 * @param array<string, mixed> $lead The lead row.
	 * @param int|null $fromTs Lower bound (inclusive), or null.
	 * @param int|null $toTs Upper bound (inclusive), or null.
	 *
	 * @return bool
	 */
	private function isWithinCreatedBounds(array $lead, ?int $fromTs, ?int $toTs): bool {
		$created = $this->extractTimestamp(lead: $lead, key: '_dateCreated');
		if ($created === null) {
			return false;
		}

		if ($fromTs !== null && $created < $fromTs) {
			return false;
		}

		if ($toTs !== null && $created > $toTs) {
			return false;
		}

		return true;
	}//end isWithinCreatedBounds()

	/**
	 * Extract a unix timestamp from `_dateModified` / `_dateCreated` /
	 * the OpenRegister `@self.updated` mirror.
	 *
	 * @param array<string, mixed> $lead The lead row.
	 * @param string $key The preferred key.
	 *
	 * @return int|null
	 */
	private function extractTimestamp(array $lead, string $key): ?int {
		$candidates = [$lead[$key] ?? null];

		$self = $lead['@self'] ?? null;
		if (is_array($self) === true) {
			if ($key === '_dateModified') {
				$candidates[] = $self['updated'] ?? null;
			} elseif ($key === '_dateCreated') {
				$candidates[] = $self['created'] ?? null;
			}
		}

		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				$timestamp = strtotime($candidate);
				if ($timestamp !== false) {
					return $timestamp;
				}
			} elseif (is_int($candidate) === true) {
				return $candidate;
			}
		}

		return null;
	}//end extractTimestamp()

	/**
	 * Fetch all lead objects via OpenRegister ObjectService. Returns an
	 * empty list when OpenRegister is unavailable (no exception leakage).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchLeads(): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'lead_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$service = $this->getObjectService();
			$results = $service->findAll(config: ['filters' => ['register' => $register, 'schema' => $schema]]);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: rapportage lead fetch failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$leads = [];
		foreach (($results ?? []) as $result) {
			$leads[] = $this->toArray(value: $result);
		}

		return $leads;
	}//end fetchLeads()

	/**
	 * Resolve the OpenRegister ObjectService from the container.
	 *
	 * @return object
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;

	}//end getObjectService()
}//end class
