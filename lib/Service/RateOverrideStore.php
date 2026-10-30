<?php

/**
 * Pipelinq RateOverrideStore.
 *
 * Keeps the administrator's fixed exchange rates and the date each hand-kept
 * rate was typed, in app config next to the forecast rate table.
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
 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-an-administrator-can-override-a-rate-on-purpose-req-dxr-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Fixed rates with their reason and date, and the typed dates of the rate table.
 *
 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-an-administrator-can-override-a-rate-on-purpose-req-dxr-003
 */
class RateOverrideStore {
	/**
	 * App-config key for administrator overrides (currency => rate, reason, date).
	 *
	 * @var string
	 */
	public const OVERRIDES_KEY = 'forecast_rate_overrides';

	/**
	 * App-config key for the date each hand-kept rate was typed (currency => date).
	 *
	 * @var string
	 */
	public const RATE_DATES_KEY = 'forecast_exchange_rate_dates';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param ITimeFactory|null $timeFactory The clock that dates a saved rate.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-an-administrator-can-override-a-rate-on-purpose-req-dxr-003
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ?ITimeFactory $timeFactory = null,
	) {
	}//end __construct()

	/**
	 * The administrator overrides.
	 *
	 * @return array<string, array{rate: float, reason: string, date: string|null}> Currency => override, sorted by code.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-an-administrator-can-override-a-rate-on-purpose-req-dxr-003
	 */
	public function getOverrides(): array {
		$table = json_decode($this->appConfig->getValueString(Application::APP_ID, self::OVERRIDES_KEY, ''), true);
		if (is_array($table) === false) {
			return [];
		}

		$overrides = [];
		foreach ($table as $code => $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$rate = ($entry['rate'] ?? null);
			if (is_numeric($rate) === true && (float)$rate > 0) {
				$overrides[strtoupper(trim((string)$code))] = [
					'rate' => (float)$rate,
					'reason' => (string)($entry['reason'] ?? ''),
					'date' => $this->dateOrNull(value: $entry['date'] ?? null),
				];
			}
		}

		ksort($overrides);
		return $overrides;
	}//end getOverrides()

	/**
	 * Validate the overrides an administrator submits and stamp them with today.
	 *
	 * An override that keeps its rate and reason keeps its original date.
	 *
	 * @param array<mixed, mixed> $input Currency => {rate, reason}.
	 *
	 * @return array<string, array{rate: float, reason: string, date: string}> The overrides, sorted by code.
	 *
	 * @throws InvalidArgumentException When a code, rate or reason is not valid.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-an-administrator-can-override-a-rate-on-purpose-req-dxr-003
	 */
	public function normaliseOverrides(array $input): array {
		$current = $this->getOverrides();
		$overrides = [];
		foreach ($input as $code => $entry) {
			$currency = strtoupper(trim((string)$code));
			$override = $this->validOverride(code: (string)$code, entry: (array)$entry);
			$override['date'] = $this->today()->format('Y-m-d');

			$previous = ($current[$currency] ?? null);
			if ($previous !== null && $previous['date'] !== null
				&& $previous['rate'] === $override['rate'] && $previous['reason'] === $override['reason']
			) {
				$override['date'] = $previous['date'];
			}

			$overrides[$currency] = $override;
		}

		ksort($overrides);
		return $overrides;
	}//end normaliseOverrides()

	/**
	 * The date each hand-kept rate was typed, updated for a newly saved table.
	 *
	 * A rate that did not change keeps its date; a new or changed rate gets
	 * today; a removed rate loses its date.
	 *
	 * @param array<string, float> $rates The normalised table about to be saved.
	 * @param array<string, float> $previousRates The table saved before.
	 *
	 * @return array<string, string> Currency => date.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
	 */
	public function rateDatesFor(array $rates, array $previousRates): array {
		$previousDates = $this->getRateDates();
		$today = $this->today()->format('Y-m-d');

		$dates = [];
		foreach ($rates as $code => $rate) {
			$dates[$code] = $today;
			if (isset($previousRates[$code], $previousDates[$code]) === true && $previousRates[$code] === $rate) {
				$dates[$code] = $previousDates[$code];
			}
		}

		return $dates;
	}//end rateDatesFor()

	/**
	 * The typed dates of the hand-kept rates.
	 *
	 * @return array<string, string> Currency => date.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
	 */
	public function getRateDates(): array {
		$table = json_decode($this->appConfig->getValueString(Application::APP_ID, self::RATE_DATES_KEY, ''), true);
		if (is_array($table) === false) {
			return [];
		}

		$dates = [];
		foreach ($table as $code => $date) {
			$date = $this->dateOrNull(value: $date);
			if ($date !== null) {
				$dates[strtoupper(trim((string)$code))] = $date;
			}
		}

		return $dates;
	}//end getRateDates()

	/**
	 * A `YYYY-MM-DD` date, or null for anything else.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The date.
	 */
	private function dateOrNull(mixed $value): ?string {
		$date = substr((string)$value, 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			return null;
		}

		return $date;
	}//end dateOrNull()

	/**
	 * Today at midnight, from the clock when one is injected.
	 *
	 * @return DateTimeImmutable Today.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-a-stale-rate-is-shown-as-stale-req-dxr-002
	 */
	public function today(): DateTimeImmutable {
		$now = new DateTimeImmutable();
		if ($this->timeFactory !== null) {
			$now = $this->timeFactory->now();
		}

		return $now->setTime(0, 0);
	}//end today()

	/**
	 * Check one submitted override.
	 *
	 * @param string $code The currency code as submitted.
	 * @param array<mixed, mixed> $entry The submitted {rate, reason}.
	 *
	 * @return array{rate: float, reason: string} The override.
	 *
	 * @throws InvalidArgumentException When the code, rate or reason is not valid.
	 */
	private function validOverride(string $code, array $entry): array {
		$currency = strtoupper(trim($code));
		if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
			throw new InvalidArgumentException("'{$code}' is not a three-letter currency code.");
		}

		$rate = ($entry['rate'] ?? null);
		if (is_numeric($rate) === false || (float)$rate <= 0) {
			throw new InvalidArgumentException("The override for {$currency} must be a number above zero.");
		}

		$reason = trim((string)($entry['reason'] ?? ''));
		if ($reason === '') {
			throw new InvalidArgumentException("The override for {$currency} needs a reason.");
		}

		return ['rate' => (float)$rate, 'reason' => $reason];
	}//end validOverride()
}//end class
