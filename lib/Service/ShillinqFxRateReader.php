<?php

/**
 * Pipelinq ShillinqFxRateReader.
 *
 * Reads exchange rates out of shillinq's `FxRate` records so a foreign
 * currency deal converts at the rate the bookkeeping already holds.
 *
 * Read only: shillinq owns its rates. The read is duck-typed through
 * OpenRegister with `_rbac` and `_multitenancy` off, the same shape
 * `ShillinqInvoiceReader` uses, and fails soft: shillinq absent or
 * OpenRegister failing means no rate, never an error.
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
 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ShillinqFxRateReader: the newest usable shillinq rate for a currency pair.
 *
 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
 */
class ShillinqFxRateReader {

	/**
	 * Shillinq's register slug.
	 *
	 * @var string
	 */
	public const SHILLINQ_REGISTER = 'shillinq';

	/**
	 * The rate schema slug shillinq declares.
	 *
	 * @var string
	 */
	public const FX_RATE_SCHEMA = 'FxRate';

	/**
	 * A class that exists exactly when shillinq is installed.
	 *
	 * @var string
	 */
	public const SHILLINQ_PROBE_CLASS = 'OCA\\Shillinq\\AppInfo\\Application';

	/**
	 * The currency every cross rate goes through (ECB quotes per euro).
	 *
	 * @var string
	 */
	public const CROSS_CURRENCY = 'EUR';

	/**
	 * Shillinq rate sources, most trusted first.
	 *
	 * @var array<int, string>
	 */
	public const SOURCE_PREFERENCE = ['ecb', 'bank-feed', 'manual'];

	/**
	 * Rows read for one currency pair.
	 *
	 * @var int
	 */
	private const LIMIT = 100;

	/**
	 * Pair lookups already made in this request, keyed `FROM>TO`.
	 *
	 * @var array<string, array{rate: float, date: string, origin: string}|null>
	 */
	private array $pairs = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the lazy, duck-typed object service.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The rate from one currency to another, with its date and shillinq source.
	 *
	 * Tries the direct pair, then the inverse pair, then a cross rate through
	 * the euro. A cross rate carries the older of its two dates.
	 *
	 * @param string $from The ISO 4217 source currency.
	 * @param string $to The ISO 4217 target currency.
	 *
	 * @return array{rate: float, date: string, origin: string}|null Units of `$to` per 1 `$from`, or null.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
	 */
	public function rateFor(string $from, string $to): ?array {
		$from = strtoupper(trim($from));
		$to = strtoupper(trim($to));
		if ($from === '' || $to === '' || $from === $to || $this->probe() === false) {
			return null;
		}

		$direct = $this->eitherWay(from: $from, to: $to);
		if ($direct !== null || in_array(self::CROSS_CURRENCY, [$from, $to], true) === true) {
			return $direct;
		}

		$fromEur = $this->eitherWay(from: $from, to: self::CROSS_CURRENCY);
		$toEur = $this->eitherWay(from: $to, to: self::CROSS_CURRENCY);
		if ($fromEur === null || $toEur === null) {
			return null;
		}

		return [
			'rate' => round($fromEur['rate'] / $toEur['rate'], 6),
			'date' => min($fromEur['date'], $toEur['date']),
			'origin' => $fromEur['origin'],
		];
	}//end rateFor()

	/**
	 * Whether shillinq's application class is loadable. Protected so a test
	 * can substitute the answer without shillinq present.
	 *
	 * @return bool True when shillinq is installed.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
	 */
	protected function probe(): bool {
		return class_exists(self::SHILLINQ_PROBE_CLASS);
	}//end probe()

	/**
	 * The direct pair, or the inverse of the reverse pair.
	 *
	 * @param string $from The source currency.
	 * @param string $to The target currency.
	 *
	 * @return array{rate: float, date: string, origin: string}|null The rate, or null.
	 */
	private function eitherWay(string $from, string $to): ?array {
		$direct = $this->pair(from: $from, to: $to);
		if ($direct !== null) {
			return $direct;
		}

		$reverse = $this->pair(from: $to, to: $from);
		if ($reverse === null) {
			return null;
		}

		return [
			'rate' => round(1 / $reverse['rate'], 6),
			'date' => $reverse['date'],
			'origin' => $reverse['origin'],
		];
	}//end eitherWay()

	/**
	 * The best shillinq rate recorded for one pair: newest date, then the
	 * most trusted source.
	 *
	 * @param string $from The transaction currency.
	 * @param string $to The base currency.
	 *
	 * @return array{rate: float, date: string, origin: string}|null The rate, or null.
	 */
	private function pair(string $from, string $to): ?array {
		$key = $from.'>'.$to;
		if (array_key_exists($key, $this->pairs) === true) {
			return $this->pairs[$key];
		}

		$best = null;
		foreach ($this->rows(from: $from, to: $to) as $row) {
			$candidate = $this->normalise(row: $row, from: $from, to: $to);
			if ($candidate !== null && ($best === null || $this->beats(candidate: $candidate, best: $best) === true)) {
				$best = $candidate;
			}
		}

		$this->pairs[$key] = $best;
		return $best;
	}//end pair()

	/**
	 * Whether a candidate rate is better than the best so far.
	 *
	 * @param array{rate: float, date: string, origin: string} $candidate The candidate.
	 * @param array{rate: float, date: string, origin: string} $best The best so far.
	 *
	 * @return bool True when the candidate wins.
	 */
	private function beats(array $candidate, array $best): bool {
		if ($candidate['date'] !== $best['date']) {
			return $candidate['date'] > $best['date'];
		}

		return $this->rank(origin: $candidate['origin']) < $this->rank(origin: $best['origin']);
	}//end beats()

	/**
	 * The preference rank of a shillinq source; unknown sources rank last.
	 *
	 * @param string $origin The shillinq source.
	 *
	 * @return int Lower is more trusted.
	 */
	private function rank(string $origin): int {
		$rank = array_search($origin, self::SOURCE_PREFERENCE, true);
		if ($rank === false) {
			return count(self::SOURCE_PREFERENCE);
		}

		return (int)$rank;
	}//end rank()

	/**
	 * Reduce a shillinq row to rate, date and source.
	 *
	 * The pair is checked again here because OpenRegister's filter DSL
	 * ignores a key it does not recognise, which would return rows of every
	 * pair while looking like a correct result.
	 *
	 * @param array<string, mixed> $row The shillinq FxRate.
	 * @param string $from The transaction currency asked for.
	 * @param string $to The base currency asked for.
	 *
	 * @return array{rate: float, date: string, origin: string}|null The rate, or null when unusable.
	 */
	private function normalise(array $row, string $from, string $to): ?array {
		if (strtoupper((string)($row['transactionCurrency'] ?? '')) !== $from
			|| strtoupper((string)($row['baseCurrency'] ?? '')) !== $to
		) {
			return null;
		}

		$rate = $row['rate'] ?? null;
		$date = substr((string)($row['date'] ?? ''), 0, 10);
		if (is_numeric($rate) === false || (float)$rate <= 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			return null;
		}

		return [
			'rate' => (float)$rate,
			'date' => $date,
			'origin' => (string)($row['source'] ?? ''),
		];
	}//end normalise()

	/**
	 * Read the FxRate rows of one pair.
	 *
	 * @param string $from The transaction currency.
	 * @param string $to The base currency.
	 *
	 * @return array<int, array<string, mixed>> Plain rows.
	 */
	private function rows(string $from, string $to): array {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$page = $objectService->findAll(
				config: [
					'filters' => [
						'register' => self::SHILLINQ_REGISTER,
						'schema' => self::FX_RATE_SCHEMA,
						'transactionCurrency' => $from,
						'baseCurrency' => $to,
					],
					'limit' => self::LIMIT,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ShillinqFxRateReader: reading exchange rates failed',
				['pair' => $from.'>'.$to, 'exception' => $e->getMessage()]
			);
			return [];
		}

		if (is_iterable($page) === false) {
			return [];
		}

		$rows = [];
		foreach ($page as $row) {
			$rows[] = $this->toArray(value: $row);
		}

		return $rows;
	}//end rows()

	/**
	 * Normalise an OpenRegister entity, or an array, to a plain array.
	 *
	 * @param mixed $value Entity object or array.
	 *
	 * @return array<string, mixed> Plain payload.
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end toArray()
}//end class
