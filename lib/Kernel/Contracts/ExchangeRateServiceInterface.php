<?php

/**
 * Contract of ExchangeRateService
 *
 * The public surface of ExchangeRateService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use InvalidArgumentException;
use OCA\Pipelinq\Service\RateOverrideStore;

/**
 * Contract of ExchangeRateService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExchangeRateServiceInterface {

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
	 * App-config key for the JSON rate table (source currency => rate).
	 *
	 * @var string
	 */
	public const RATES_KEY = 'forecast_exchange_rates';

	/**
	 * App-config key for administrator overrides.
	 *
	 * @var string
	 */
	public const OVERRIDES_KEY = RateOverrideStore::OVERRIDES_KEY;

	/**
	 * App-config key for the typed dates of the rate table.
	 *
	 * @var string
	 */
	public const RATE_DATES_KEY = RateOverrideStore::RATE_DATES_KEY;

	/**
	 * App-config key for the age in days past which a rate is stale.
	 *
	 * @var string
	 */
	public const MAX_RATE_AGE_KEY = 'forecast_max_rate_age_days';

	/**
	 * Convert an amount and say at which rate, from where and of which day.
	 *
	 * @param float $amount The amount in the source currency.
	 * @param string|null $currency The ISO 4217 source currency (null/empty = reporting currency).
	 *
	 * @return array{amount: float, currency: string, reportingCurrency: string, rate: float, source: string,
	 *     origin: string|null, rateDate: string|null, reason: string|null, stale: bool}
	 *         The converted amount (2 decimals) and the rate's metadata.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-a-stale-rate-is-shown-as-stale-req-dxr-002
	 */
	public function convert(float $amount, ?string $currency): array;

	/**
	 * The resolved rate of every currency the forecast knows a rate for.
	 *
	 * @param array<int, string> $extra Further currencies to include (for example the deals' currencies).
	 *
	 * @return array<string, array<string, mixed>> Currency => resolved rate, sorted by code,
	 *         the reporting currency left out.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-a-stale-rate-is-shown-as-stale-req-dxr-002
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-an-administrator-can-override-a-rate-on-purpose-req-dxr-003
	 */
	public function describeRates(array $extra = []): array;

	/**
	 * The stale limit in days (default 7 when unset or not positive).
	 *
	 * @return int Days.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-a-stale-rate-is-shown-as-stale-req-dxr-002
	 */
	public function getMaxRateAgeDays(): int;

	/**
	 * The rate table: units of reporting currency per 1 unit of each currency.
	 *
	 * @return array<string, float> Currency code => rate, sorted by code.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-004-05
	 */
	public function getRates(): array;

	/**
	 * Resolve the org reporting currency (ISO 4217).
	 *
	 * @return string The reporting currency code.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-004-05
	 */
	public function getReportingCurrency(): string;

	/**
	 * Validate a rate table an administrator submits.
	 *
	 * @param array<mixed, mixed> $input Currency code => rate.
	 *
	 * @return array<string, float> The table, codes upper-cased and sorted.
	 *
	 * @throws InvalidArgumentException When a code is not ISO 4217 shaped or a rate is not positive.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-004-05
	 */
	public function normaliseRates(array $input): array;

	/**
	 * The fixed rates and the typed dates of the rate table.
	 *
	 * @return RateOverrideStore The store.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-an-administrator-can-override-a-rate-on-purpose-req-dxr-003
	 */
	public function overrideStore(): RateOverrideStore;
}//end interface
