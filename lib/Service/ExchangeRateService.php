<?php

/**
 * Pipelinq ExchangeRateService.
 *
 * Converts deal amounts into the org reporting currency for forecast roll-ups.
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
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004-05
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use InvalidArgumentException;
use OCP\IAppConfig;

/**
 * Currency normalization for forecast roll-ups (ISO 4217).
 *
 * The reporting currency and the per-currency rate table are org-configurable
 * via app config. Rates express "units of reporting currency per 1 unit of the
 * source currency". An unknown source currency falls back to a 1:1 rate so a
 * roll-up never silently drops a deal's value.
 *
 * @spec exclude infrastructure utility with no feature requirement of its own; it is
 *   exercised through the features that call it
 */
class ExchangeRateService {
	/**
	 * App-config key for the org reporting currency.
	 *
	 * The one the setup wizard stores and manifest dashboards format with
	 * (`@config.currency`). The forecast used to keep a second setting for the
	 * same thing (pipelinq#2040); that key is still read as a fallback.
	 *
	 * @var string
	 */
	public const REPORTING_CURRENCY_KEY = 'currency';

	/**
	 * The forecast's former reporting-currency key, read only when the setup
	 * wizard's key is unset so an instance that set it keeps its value.
	 *
	 * @var string
	 */
	public const LEGACY_REPORTING_CURRENCY_KEY = 'forecast_reporting_currency';

	/**
	 * Default reporting currency when none is configured.
	 *
	 * @var string
	 */
	public const REPORTING_CURRENCY_DEFAULT = 'EUR';

	/**
	 * App-config key for the JSON rate table (source currency => rate).
	 *
	 * @var string
	 */
	public const RATES_KEY = 'forecast_exchange_rates';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 */
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Resolve the org reporting currency (ISO 4217).
	 *
	 * @return string The reporting currency code.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004-05
	 */
	public function getReportingCurrency(): string {
		foreach ([self::REPORTING_CURRENCY_KEY, self::LEGACY_REPORTING_CURRENCY_KEY] as $key) {
			$currency = strtoupper(trim($this->appConfig->getValueString(Application::APP_ID, $key, '')));
			if ($currency !== '') {
				return $currency;
			}
		}

		return self::REPORTING_CURRENCY_DEFAULT;
	}//end getReportingCurrency()

	/**
	 * Convert an amount from a source currency into the reporting currency.
	 *
	 * @param float $amount The amount in the source currency.
	 * @param string|null $currency The ISO 4217 source currency (null/empty = reporting currency).
	 *
	 * @return float The amount in the reporting currency, rounded to 2 decimals.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004-05
	 */
	public function toReportingCurrency(float $amount, ?string $currency): float {
		$source = strtoupper(trim((string)$currency));
		$reporting = $this->getReportingCurrency();
		if ($source === '' || $source === $reporting) {
			return round($amount, 2);
		}

		$rate = $this->rateFor(currency: $source);
		return round($amount * $rate, 2);
	}//end toReportingCurrency()

	/**
	 * The rate table: units of reporting currency per 1 unit of each currency.
	 *
	 * @return array<string, float> Currency code => rate, sorted by code.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004-05
	 */
	public function getRates(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::RATES_KEY, '');
		$table = json_decode($raw, true);
		if (is_array($table) === false) {
			return [];
		}

		$rates = [];
		foreach ($table as $code => $rate) {
			if (is_numeric($rate) === true && (float)$rate > 0) {
				$rates[strtoupper(trim((string)$code))] = (float)$rate;
			}
		}

		ksort($rates);
		return $rates;
	}//end getRates()

	/**
	 * Validate a rate table an administrator submits.
	 *
	 * @param array<mixed, mixed> $input Currency code => rate.
	 *
	 * @return array<string, float> The table, codes upper-cased and sorted.
	 *
	 * @throws InvalidArgumentException When a code is not ISO 4217 shaped or a rate is not positive.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004-05
	 */
	public function normaliseRates(array $input): array {
		$rates = [];
		foreach ($input as $code => $rate) {
			$currency = strtoupper(trim((string)$code));
			if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
				throw new InvalidArgumentException("'{$code}' is not a three-letter currency code.");
			}

			if (is_numeric($rate) === false || (float)$rate <= 0) {
				throw new InvalidArgumentException("The rate for {$currency} must be a number above zero.");
			}

			$rates[$currency] = (float)$rate;
		}

		ksort($rates);
		return $rates;
	}//end normaliseRates()

	/**
	 * Resolve the conversion rate for a source currency.
	 *
	 * @param string $currency The ISO 4217 source currency code (upper-cased).
	 *
	 * @return float The rate in reporting currency per 1 source unit (1.0 when unknown).
	 */
	private function rateFor(string $currency): float {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::RATES_KEY, '');
		if ($raw === '') {
			return 1.0;
		}

		$table = json_decode($raw, true);
		if (is_array($table) === false) {
			return 1.0;
		}

		$rate = $table[$currency] ?? null;
		if (is_numeric($rate) === false || (float)$rate <= 0) {
			return 1.0;
		}

		return (float)$rate;
	}//end rateFor()
}//end class
