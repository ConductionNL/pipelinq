<?php

/**
 * Pipelinq ForecastSettingsController.
 *
 * Admin-only configuration for the forecast feature: commit threshold,
 * generation schedule/timezone, accuracy bands, at-risk thresholds and the
 * reporting currency / manager-team groups.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-003-05
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ExchangeRateServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ForecastDealServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ForecastServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\QuotaServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SnapshotGenerationServiceInterface;
use OCA\Pipelinq\Lifecycle\ForecastAccessPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Admin settings controller for the forecast feature.
 *
 * Every action is gated with #[AuthorizedAdminSetting]; values persist via
 * IAppConfig and take effect immediately for subsequent operations.
 *
 * @spec exclude forecast has no owning requirement. pipeline-insights defers its config
 *   scenario to admin-settings, which never grew one
 */
class ForecastSettingsController extends Controller {
	/**
	 * The plain forecast fields update() saves first, in save order: the
	 * config key, the cast and, for integers, the accepted bounds and the
	 * error for a value outside them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const FORECAST_FIELDS = [
		'commit_threshold' => [
			'type' => 'int',
			'key' => ForecastDealServiceInterface::COMMIT_THRESHOLD_KEY,
			'min' => 0,
			'max' => PHP_INT_MAX,
			'error' => 'commit_threshold must be non-negative.',
		],
		'pipeline_target' => [
			'type' => 'int',
			'key' => ForecastServiceInterface::PIPELINE_TARGET_KEY,
			'min' => 0,
			'max' => PHP_INT_MAX,
			'error' => 'pipeline_target must be non-negative.',
		],
		'generation_timezone' => ['type' => 'string', 'key' => 'forecast_generation_timezone'],
		'generation_day' => [
			'type' => 'int',
			'key' => 'forecast_generation_day',
			'min' => 1,
			'max' => 7,
			'error' => 'generation_day must be 1-7.',
		],
		'generation_hour' => [
			'type' => 'int',
			'key' => 'forecast_generation_hour',
			'min' => 0,
			'max' => 23,
			'error' => 'generation_hour must be 0-23.',
		],
		'accuracy_green' => ['type' => 'float', 'key' => ForecastServiceInterface::ACCURACY_GREEN_KEY],
		'accuracy_amber' => ['type' => 'float', 'key' => ForecastServiceInterface::ACCURACY_AMBER_KEY],
		'at_risk_percent' => [
			'type' => 'int',
			'key' => QuotaServiceInterface::AT_RISK_PERCENT_KEY,
			'min' => PHP_INT_MIN,
			'max' => PHP_INT_MAX,
			'error' => '',
		],
		'at_risk_days' => [
			'type' => 'int',
			'key' => QuotaServiceInterface::AT_RISK_DAYS_KEY,
			'min' => PHP_INT_MIN,
			'max' => PHP_INT_MAX,
			'error' => '',
		],
	];

	/**
	 * The access-group fields update() saves last, in save order.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const GROUP_FIELDS = [
		'manager_group' => ['type' => 'string', 'key' => ForecastAccessPolicy::MANAGER_GROUP_KEY],
		'team_groups' => ['type' => 'string', 'key' => SnapshotGenerationServiceInterface::TEAMS_KEY],
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IAppConfig $appConfig The app configuration.
	 * @param ExchangeRateServiceInterface $exchangeRate The reporting currency and rate table.
	 */
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private ExchangeRateServiceInterface $exchangeRate,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Read the current forecast configuration.
	 *
	 * @return JSONResponse The configuration values.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-003-05
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-an-administrator-can-override-a-rate-on-purpose-req-dxr-003
	 */
	#[AuthorizedAdminSetting(AppIdentity::APP_ID)]
	public function index(): JSONResponse {
		$app = AppIdentity::APP_ID;
		$greenDefault = (string)ForecastServiceInterface::ACCURACY_GREEN_DEFAULT;
		$amberDefault = (string)ForecastServiceInterface::ACCURACY_AMBER_DEFAULT;
		$thresholdKey = ForecastDealServiceInterface::COMMIT_THRESHOLD_KEY;
		$thresholdValue = ForecastDealServiceInterface::COMMIT_THRESHOLD_DEFAULT;
		$atRiskPctKey = QuotaServiceInterface::AT_RISK_PERCENT_KEY;
		$atRiskPctValue = QuotaServiceInterface::AT_RISK_PERCENT_DEFAULT;

		return new JSONResponse(
			[
				'commit_threshold' => $this->appConfig->getValueInt($app, $thresholdKey, $thresholdValue),
				'pipeline_target' => $this->appConfig->getValueInt($app, ForecastServiceInterface::PIPELINE_TARGET_KEY, 0),
				'generation_timezone' => $this->appConfig->getValueString($app, 'forecast_generation_timezone', 'UTC'),
				'generation_day' => $this->appConfig->getValueInt($app, 'forecast_generation_day', 1),
				'generation_hour' => $this->appConfig->getValueInt($app, 'forecast_generation_hour', 6),
				'accuracy_green' => $this->appConfig->getValueString($app, ForecastServiceInterface::ACCURACY_GREEN_KEY, $greenDefault),
				'accuracy_amber' => $this->appConfig->getValueString($app, ForecastServiceInterface::ACCURACY_AMBER_KEY, $amberDefault),
				'at_risk_percent' => $this->appConfig->getValueInt($app, $atRiskPctKey, $atRiskPctValue),
				'at_risk_days' => $this->appConfig->getValueInt($app, QuotaServiceInterface::AT_RISK_DAYS_KEY, QuotaServiceInterface::AT_RISK_DAYS_DEFAULT),
				'reporting_currency' => $this->exchangeRate->getReportingCurrency(),
				'exchange_rates' => (object)$this->exchangeRate->getRates(),
				'rate_overrides' => (object)$this->exchangeRate->overrideStore()->getOverrides(),
				'rate_sources' => (object)$this->exchangeRate->describeRates(),
				'max_rate_age_days' => $this->exchangeRate->getMaxRateAgeDays(),
				'manager_group' => $this->appConfig->getValueString($app, ForecastAccessPolicy::MANAGER_GROUP_KEY, ''),
				'team_groups' => $this->appConfig->getValueString($app, SnapshotGenerationServiceInterface::TEAMS_KEY, ''),
			]
		);
	}//end index()

	/**
	 * Persist forecast configuration changes.
	 *
	 * Only the provided keys are updated; each is validated before persistence.
	 * The fields are saved in a fixed order and the first refused value stops
	 * the save, leaving the fields before it stored.
	 *
	 * @return JSONResponse The save result.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-003-05
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-an-administrator-can-override-a-rate-on-purpose-req-dxr-003
	 */
	#[AuthorizedAdminSetting(AppIdentity::APP_ID)]
	public function update(): JSONResponse {
		$params = $this->request->getParams();

		foreach ($this->saveSteps() as $saveStep) {
			$error = $saveStep($params);
			if ($error !== null) {
				return new JSONResponse(['error' => $error], 400);
			}
		}

		return new JSONResponse(['status' => 'saved']);
	}//end update()

	/**
	 * The save steps of update(), in the order the fields are persisted.
	 *
	 * Each step takes the request parameters and returns its validation
	 * error, or null when it saved or its fields are absent.
	 *
	 * @return list<callable(array<string, mixed>): ?string> The steps.
	 */
	private function saveSteps(): array {
		return [
			fn (array $params): ?string => $this->saveFields(params: $params, fields: self::FORECAST_FIELDS),
			$this->saveReportingCurrency(...),
			$this->saveExchangeRates(...),
			$this->saveMaxRateAge(...),
			$this->saveRateOverrides(...),
			fn (array $params): ?string => $this->saveFields(params: $params, fields: self::GROUP_FIELDS),
		];
	}//end saveSteps()

	/**
	 * Persist the submitted plain fields of one field table, in table order.
	 *
	 * @param array<string, mixed>                $params The request parameters.
	 * @param array<string, array<string, mixed>> $fields The field table.
	 *
	 * @return string|null The first validation error, or null when all saved.
	 */
	private function saveFields(array $params, array $fields): ?string {
		foreach ($fields as $name => $field) {
			if (isset($params[$name]) === false) {
				continue;
			}

			$error = $this->saveField(field: $field, value: $params[$name]);
			if ($error !== null) {
				return $error;
			}
		}

		return null;
	}//end saveFields()

	/**
	 * Persist one plain field with the cast its table entry names.
	 *
	 * @param array<string, mixed> $field The field's table entry.
	 * @param mixed                $value The submitted value.
	 *
	 * @return string|null The validation error, or null when saved.
	 */
	private function saveField(array $field, mixed $value): ?string {
		if ($field['type'] === 'int') {
			return $this->saveBoundedInt(field: $field, value: (int)$value);
		}

		if ($field['type'] === 'float') {
			$value = (float)$value;
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, $field['key'], (string)$value);
		return null;
	}//end saveField()

	/**
	 * Persist an integer field when it lies within its bounds.
	 *
	 * @param array<string, mixed> $field The field's table entry.
	 * @param int                  $value The submitted value.
	 *
	 * @return string|null The field's error when out of bounds, or null when saved.
	 */
	private function saveBoundedInt(array $field, int $value): ?string {
		if ($value < $field['min'] || $value > $field['max']) {
			return $field['error'];
		}

		$this->appConfig->setValueInt(AppIdentity::APP_ID, $field['key'], $value);
		return null;
	}//end saveBoundedInt()

	/**
	 * Persist the reporting currency when it is submitted.
	 *
	 * One reporting currency for the whole app: the setup wizard's setting,
	 * which dashboards format with (pipelinq#2040).
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return string|null The validation error, or null when saved or absent.
	 */
	private function saveReportingCurrency(array $params): ?string {
		if (isset($params['reporting_currency']) === false) {
			return null;
		}

		$currency = strtoupper(trim((string)$params['reporting_currency']));
		if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
			return 'reporting_currency must be a three-letter currency code.';
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, ExchangeRateServiceInterface::REPORTING_CURRENCY_KEY, $currency);
		return null;
	}//end saveReportingCurrency()

	/**
	 * Persist the rate table and its rate dates when it is submitted.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return string|null The validation error, or null when saved or absent.
	 */
	private function saveExchangeRates(array $params): ?string {
		if (isset($params['exchange_rates']) === false) {
			return null;
		}

		try {
			$rates = $this->exchangeRate->normaliseRates(input: (array)$params['exchange_rates']);
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage();
		}

		$dates = $this->exchangeRate->overrideStore()->rateDatesFor(rates: $rates, previousRates: $this->exchangeRate->getRates());
		$this->appConfig->setValueString(AppIdentity::APP_ID, ExchangeRateServiceInterface::RATES_KEY, (string)json_encode($rates));
		$this->appConfig->setValueString(AppIdentity::APP_ID, ExchangeRateServiceInterface::RATE_DATES_KEY, (string)json_encode($dates));
		return null;
	}//end saveExchangeRates()

	/**
	 * Persist the stale limit for exchange rates when it is submitted.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return string|null The validation error, or null when saved or absent.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-an-administrator-can-override-a-rate-on-purpose-req-dxr-003
	 */
	private function saveMaxRateAge(array $params): ?string {
		if (isset($params['max_rate_age_days']) === false) {
			return null;
		}

		$days = (int)$params['max_rate_age_days'];
		if ($days < 1) {
			return 'max_rate_age_days must be at least 1.';
		}

		$this->appConfig->setValueInt(AppIdentity::APP_ID, ExchangeRateServiceInterface::MAX_RATE_AGE_KEY, $days);
		return null;
	}//end saveMaxRateAge()

	/**
	 * Persist the rate overrides when they are submitted.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return string|null The validation error, or null when saved or absent.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-an-administrator-can-override-a-rate-on-purpose-req-dxr-003
	 */
	private function saveRateOverrides(array $params): ?string {
		if (isset($params['rate_overrides']) === false) {
			return null;
		}

		try {
			$overrides = $this->exchangeRate->overrideStore()->normaliseOverrides(input: (array)$params['rate_overrides']);
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage();
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, ExchangeRateServiceInterface::OVERRIDES_KEY, (string)json_encode($overrides));
		return null;
	}//end saveRateOverrides()
}//end class
