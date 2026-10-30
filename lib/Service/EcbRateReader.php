<?php

/**
 * Pipelinq EcbRateReader.
 *
 * Reads the ECB daily reference rates that integriq keeps in OpenRegister.
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
 * EcbRateReader: the ECB rate between two currencies, as integriq stores it.
 *
 * The integriq source `ecb-eurofxref-daily` writes one `exchange_rate` object
 * per currency and day in register `integriq`: `rate` is units of `currency`
 * per one euro. The ECB publishes no rates on weekends and TARGET holidays, so the
 * rate of a day is the newest `rateDate` on or before it. Without integriq or
 * its rates the reader answers null and the next source is tried.
 *
 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
 */
class EcbRateReader {

	/**
	 * integriq's register slug.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The rate schema slug integriq declares.
	 *
	 * @var string
	 */
	public const SCHEMA = 'exchange_rate';

	/**
	 * The currency every ECB rate is quoted against.
	 *
	 * @var string
	 */
	public const BASE_CURRENCY = 'EUR';

	/**
	 * The `source` value integriq writes on ECB rates.
	 *
	 * @var string
	 */
	public const SOURCE = 'ecb';

	/**
	 * Rows read for one currency, newest first.
	 *
	 * @var int
	 */
	private const LIMIT = 10;

	/**
	 * Lookups already made in this request, keyed `CURRENCY@DAY`.
	 *
	 * @var array<string, array{rate: float, date: string}|null>
	 */
	private array $perEuro = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the lazy, duck-typed object service.
	 * @param LoggerInterface    $logger    Logger.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The ECB rate from one currency to another on a day.
	 *
	 * A pair without the euro is a cross rate through the euro and carries
	 * the older of its two dates.
	 *
	 * @param string $from The ISO 4217 source currency.
	 * @param string $to   The ISO 4217 target currency.
	 * @param string $day  The day asked for, `YYYY-MM-DD`.
	 *
	 * @return array{rate: float, date: string}|null Units of `$to` per 1 `$from`, or null.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
	 */
	public function rateFor(string $from, string $to, string $day): ?array {
		$from = strtoupper(trim($from));
		$to = strtoupper(trim($to));
		if ($from === '' || $to === '' || $from === $to) {
			return null;
		}

		$fromPerEuro = $this->perEuro(currency: $from, day: $day);
		$toPerEuro = $this->perEuro(currency: $to, day: $day);
		if ($fromPerEuro === null || $toPerEuro === null) {
			return null;
		}

		return [
			'rate' => round($toPerEuro['rate'] / $fromPerEuro['rate'], 6),
			'date' => min($fromPerEuro['date'], $toPerEuro['date']),
		];
	}//end rateFor()

	/**
	 * Units of a currency per one euro on a day; the euro itself is 1.
	 *
	 * @param string $currency The upper-cased currency.
	 * @param string $day      The day asked for.
	 *
	 * @return array{rate: float, date: string}|null The rate and its date, or null.
	 */
	private function perEuro(string $currency, string $day): ?array {
		if ($currency === self::BASE_CURRENCY) {
			return ['rate' => 1.0, 'date' => $day];
		}

		$key = $currency.'@'.$day;
		if (array_key_exists($key, $this->perEuro) === true) {
			return $this->perEuro[$key];
		}

		$best = null;
		foreach ($this->rows(currency: $currency) as $row) {
			$candidate = $this->normalise(row: $row, currency: $currency, day: $day);
			if ($candidate !== null && ($best === null || $candidate['date'] > $best['date'])) {
				$best = $candidate;
			}
		}

		$this->perEuro[$key] = $best;
		return $best;
	}//end perEuro()

	/**
	 * Reduce a row to rate and date, or null when it is not a usable ECB rate
	 * for the currency on or before the day.
	 *
	 * The currency, base and source are checked again because OpenRegister's
	 * filter DSL ignores a key it does not recognise.
	 *
	 * @param array<string, mixed> $row      The exchange_rate object.
	 * @param string               $currency The currency asked for.
	 * @param string               $day      The day asked for.
	 *
	 * @return array{rate: float, date: string}|null The rate, or null.
	 */
	private function normalise(array $row, string $currency, string $day): ?array {
		if (strtoupper((string)($row['currency'] ?? '')) !== $currency
			|| strtoupper((string)($row['baseCurrency'] ?? '')) !== self::BASE_CURRENCY
			|| strtolower((string)($row['source'] ?? '')) !== self::SOURCE
		) {
			return null;
		}

		$rate = ($row['rate'] ?? null);
		$date = substr((string)($row['rateDate'] ?? ''), 0, 10);
		if (is_numeric($rate) === false || (float)$rate <= 0
			|| preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
			|| $date > $day
		) {
			return null;
		}

		return ['rate' => (float)$rate, 'date' => $date];
	}//end normalise()

	/**
	 * Read the newest rows of one currency.
	 *
	 * A missing register or schema (integriq absent) and a failing read both
	 * end here as no rows.
	 *
	 * @param string $currency The currency.
	 *
	 * @return array<int, array<string, mixed>> Plain rows.
	 */
	private function rows(string $currency): array {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$page = $objectService->findAll(
				config: [
					'filters' => [
						'register' => self::REGISTER,
						'schema' => self::SCHEMA,
						'currency' => $currency,
						'baseCurrency' => self::BASE_CURRENCY,
						'source' => self::SOURCE,
					],
					'sort' => ['rateDate' => 'desc'],
					'limit' => self::LIMIT,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'EcbRateReader: no ECB rates read',
				['currency' => $currency, 'exception' => $e->getMessage()]
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
