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

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

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
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy ObjectService lookup).
	 * @param IAppConfig $appConfig The app config (register/schema slugs).
	 * @param LoggerInterface $logger Logger for fallback paths.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Pipeline value per stage (count, total value, probability-weighted).
	 *
	 * @param string|null $pipelineId Optional pipeline filter (matches lead.pipeline).
	 *
	 * @return array<int, array{stage: string, count: int, totalValue: float, weightedValue: float}>
	 *
	 * @spec openspec/specs/lead-management/spec.md
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

			$value = (float)($lead['value'] ?? 0);
			$probability = (float)($lead['probability'] ?? 0);
			$buckets[$stage]['count']++;
			$buckets[$stage]['totalValue'] += $value;
			$buckets[$stage]['weightedValue'] += ($value * $probability / 100.0);
		}

		return array_values($buckets);
	}//end getStageValues()

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
			$source = (string)($lead['source'] ?? 'unknown');
			if ($source === '') {
				$source = 'unknown';
			}

			if (isset($buckets[$source]) === false) {
				$buckets[$source] = ['source' => $source, 'total' => 0, 'won' => 0, 'wonValueSum' => 0.0];
			}

			$buckets[$source]['total']++;
			$status = (string)($lead['status'] ?? 'open');
			if ($status === 'won') {
				$buckets[$source]['won']++;
				$buckets[$source]['wonValueSum'] += (float)($lead['value'] ?? 0);
			}
		}

		$result = [];
		foreach ($buckets as $row) {
			$conversion = round(($row['won'] / $row['total']) * 100.0, 1);
			$avgWon = 0.0;

			if ($row['won'] > 0) {
				$avgWon = round($row['wonValueSum'] / $row['won'], 2);
			}

			$result[] = [
				'source' => $row['source'],
				'total' => $row['total'],
				'won' => $row['won'],
				'conversionRate' => $conversion,
				'avgWonValue' => $avgWon,
			];
		}

		return $result;
	}//end getSourcePerformance()

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
			$status = (string)($lead['status'] ?? 'open');
			if ($status === 'won' || $status === 'lost') {
				continue;
			}

			$modified = $this->extractTimestamp(lead: $lead, key: '_dateModified');
			if ($modified === null) {
				continue;
			}

			$days = (int)floor(($now - $modified) / 86400);
			if ($days < 0) {
				$days = 0;
			}

			$key = '0-7d';
			if ($days > 30) {
				$key = '30d+';
			} elseif ($days > 14) {
				$key = '15-30d';
			} elseif ($days > 7) {
				$key = '8-14d';
			}

			$buckets[$key]['count']++;
			$buckets[$key]['totalValue'] += (float)($lead['value'] ?? 0);
		}//end foreach

		return array_values($buckets);
	}//end getAgingBuckets()

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
		$wonCount = 0;
		$lostCount = 0;
		$wonValueSum = 0.0;
		$lostValueSum = 0.0;
		$daysSum = 0.0;
		$daysCount = 0;

		foreach ($leads as $lead) {
			$status = (string)($lead['status'] ?? '');
			$value = (float)($lead['value'] ?? 0);

			if ($status === 'won') {
				$wonCount++;
				$wonValueSum += $value;
			}

			if ($status !== 'won') {
				$lostCount++;
				$lostValueSum += $value;
			}

			$created = $this->extractTimestamp(lead: $lead, key: '_dateCreated');
			$modified = $this->extractTimestamp(lead: $lead, key: '_dateModified');
			if ($created !== null && $modified !== null && $modified >= $created) {
				$daysSum += (($modified - $created) / 86400);
				$daysCount++;
			}
		}//end foreach

		return [
			'wonCount' => $wonCount,
			'lostCount' => $lostCount,
			'wonValueSum' => $wonValueSum,
			'lostValueSum' => $lostValueSum,
			'daysSum' => $daysSum,
			'daysCount' => $daysCount,
		];
	}//end accumulateWinLossTotals()

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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
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
			$leads[] = $this->toArray(object: $result);
		}

		return $leads;
	}//end fetchLeads()

	/**
	 * Resolve the OpenRegister ObjectService from the container.
	 *
	 * @return object
	 * @throws RuntimeException When OpenRegister is unavailable.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;

	}//end getObjectService()

	/**
	 * Normalise an OpenRegister entity (or array) to a plain array.
	 *
	 * @param mixed $object The entity or array.
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
}//end class
