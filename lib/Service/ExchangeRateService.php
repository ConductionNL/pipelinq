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
use OCP\IAppConfig;

/**
 * Currency normalization for forecast roll-ups (ISO 4217).
 *
 * The reporting currency and the per-currency rate table are org-configurable
 * via app config. Rates express "units of reporting currency per 1 unit of the
 * source currency". An unknown source currency falls back to a 1:1 rate so a
 * roll-up never silently drops a deal's value.
 */
class ExchangeRateService
{
    /**
     * App-config key for the org reporting currency.
     *
     * @var string
     */
    public const REPORTING_CURRENCY_KEY = 'forecast_reporting_currency';

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
    public function getReportingCurrency(): string
    {
        $currency = strtoupper(
                trim(
                $this->appConfig->getValueString(
            Application::APP_ID,
            self::REPORTING_CURRENCY_KEY,
            self::REPORTING_CURRENCY_DEFAULT
                )
                )
                );

        if ($currency === '') {
            return self::REPORTING_CURRENCY_DEFAULT;
        }

        return $currency;
    }//end getReportingCurrency()

    /**
     * Convert an amount from a source currency into the reporting currency.
     *
     * @param float       $amount   The amount in the source currency.
     * @param string|null $currency The ISO 4217 source currency (null/empty = reporting currency).
     *
     * @return float The amount in the reporting currency, rounded to 2 decimals.
     *
     * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004-05
     */
    public function toReportingCurrency(float $amount, ?string $currency): float
    {
        $source    = strtoupper(trim((string) $currency));
        $reporting = $this->getReportingCurrency();
        if ($source === '' || $source === $reporting) {
            return round($amount, 2);
        }

        $rate = $this->rateFor(currency: $source);
        return round($amount * $rate, 2);
    }//end toReportingCurrency()

    /**
     * Resolve the conversion rate for a source currency.
     *
     * @param string $currency The ISO 4217 source currency code (upper-cased).
     *
     * @return float The rate in reporting currency per 1 source unit (1.0 when unknown).
     */
    private function rateFor(string $currency): float
    {
        $raw = $this->appConfig->getValueString(Application::APP_ID, self::RATES_KEY, '');
        if ($raw === '') {
            return 1.0;
        }

        $table = json_decode($raw, true);
        if (is_array($table) === false) {
            return 1.0;
        }

        $rate = $table[$currency] ?? null;
        if (is_numeric($rate) === false || (float) $rate <= 0) {
            return 1.0;
        }

        return (float) $rate;
    }//end rateFor()
}//end class
