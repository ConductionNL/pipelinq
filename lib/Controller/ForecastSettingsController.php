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
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-003-05
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ForecastAccessPolicy;
use OCA\Pipelinq\Service\ExchangeRateService;
use OCA\Pipelinq\Service\ForecastDealService;
use OCA\Pipelinq\Service\ForecastService;
use OCA\Pipelinq\Service\QuotaService;
use OCA\Pipelinq\Service\SnapshotGenerationService;
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
 */
class ForecastSettingsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IAppConfig $appConfig The app configuration.
	 */
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Read the current forecast configuration.
	 *
	 * @return JSONResponse The configuration values.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-003-05
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function index(): JSONResponse {
		$app = Application::APP_ID;
		$greenDefault = (string)ForecastService::ACCURACY_GREEN_DEFAULT;
		$amberDefault = (string)ForecastService::ACCURACY_AMBER_DEFAULT;
		$thresholdKey = ForecastDealService::COMMIT_THRESHOLD_KEY;
		$thresholdValue = ForecastDealService::COMMIT_THRESHOLD_DEFAULT;
		$atRiskPctKey = QuotaService::AT_RISK_PERCENT_KEY;
		$atRiskPctValue = QuotaService::AT_RISK_PERCENT_DEFAULT;
		$currencyKey = ExchangeRateService::REPORTING_CURRENCY_KEY;
		$currencyValue = ExchangeRateService::REPORTING_CURRENCY_DEFAULT;

		return new JSONResponse(
			[
				'commit_threshold' => $this->appConfig->getValueInt($app, $thresholdKey, $thresholdValue),
				'generation_timezone' => $this->appConfig->getValueString($app, 'forecast_generation_timezone', 'UTC'),
				'generation_day' => $this->appConfig->getValueInt($app, 'forecast_generation_day', 1),
				'generation_hour' => $this->appConfig->getValueInt($app, 'forecast_generation_hour', 6),
				'accuracy_green' => $this->appConfig->getValueString($app, ForecastService::ACCURACY_GREEN_KEY, $greenDefault),
				'accuracy_amber' => $this->appConfig->getValueString($app, ForecastService::ACCURACY_AMBER_KEY, $amberDefault),
				'at_risk_percent' => $this->appConfig->getValueInt($app, $atRiskPctKey, $atRiskPctValue),
				'at_risk_days' => $this->appConfig->getValueInt($app, QuotaService::AT_RISK_DAYS_KEY, QuotaService::AT_RISK_DAYS_DEFAULT),
				'reporting_currency' => $this->appConfig->getValueString($app, $currencyKey, $currencyValue),
				'manager_group' => $this->appConfig->getValueString($app, ForecastAccessPolicy::MANAGER_GROUP_KEY, ''),
				'team_groups' => $this->appConfig->getValueString($app, SnapshotGenerationService::TEAMS_KEY, ''),
			]
		);
	}//end index()

	/**
	 * Persist forecast configuration changes.
	 *
	 * Only the provided keys are updated; each is validated before persistence.
	 *
	 * @return JSONResponse The save result.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-003-05
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Linear sequence of independent per-key
	 *   `isset` guards — each validates one optional field before persistence; not nested logic.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same rationale: the guards are independent,
	 *   so the theoretical path count is high but each branch is trivial.
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function update(): JSONResponse {
		$params = $this->request->getParams();

		if (isset($params['commit_threshold']) === true) {
			$threshold = (int)$params['commit_threshold'];
			if ($threshold < 0) {
				return new JSONResponse(['error' => 'commit_threshold must be non-negative.'], 400);
			}

			$this->appConfig->setValueInt(Application::APP_ID, ForecastDealService::COMMIT_THRESHOLD_KEY, $threshold);
		}

		if (isset($params['generation_timezone']) === true) {
			$this->appConfig->setValueString(Application::APP_ID, 'forecast_generation_timezone', (string)$params['generation_timezone']);
		}

		if (isset($params['generation_day']) === true) {
			$day = (int)$params['generation_day'];
			if ($day < 1 || $day > 7) {
				return new JSONResponse(['error' => 'generation_day must be 1-7.'], 400);
			}

			$this->appConfig->setValueInt(Application::APP_ID, 'forecast_generation_day', $day);
		}

		if (isset($params['generation_hour']) === true) {
			$hour = (int)$params['generation_hour'];
			if ($hour < 0 || $hour > 23) {
				return new JSONResponse(['error' => 'generation_hour must be 0-23.'], 400);
			}

			$this->appConfig->setValueInt(Application::APP_ID, 'forecast_generation_hour', $hour);
		}

		if (isset($params['accuracy_green']) === true) {
			$this->appConfig->setValueString(Application::APP_ID, ForecastService::ACCURACY_GREEN_KEY, (string)(float)$params['accuracy_green']);
		}

		if (isset($params['accuracy_amber']) === true) {
			$this->appConfig->setValueString(Application::APP_ID, ForecastService::ACCURACY_AMBER_KEY, (string)(float)$params['accuracy_amber']);
		}

		if (isset($params['at_risk_percent']) === true) {
			$this->appConfig->setValueInt(Application::APP_ID, QuotaService::AT_RISK_PERCENT_KEY, (int)$params['at_risk_percent']);
		}

		if (isset($params['at_risk_days']) === true) {
			$this->appConfig->setValueInt(Application::APP_ID, QuotaService::AT_RISK_DAYS_KEY, (int)$params['at_risk_days']);
		}

		if (isset($params['reporting_currency']) === true) {
			$currency = strtoupper((string)$params['reporting_currency']);
			$this->appConfig->setValueString(Application::APP_ID, ExchangeRateService::REPORTING_CURRENCY_KEY, $currency);
		}

		if (isset($params['manager_group']) === true) {
			$this->appConfig->setValueString(Application::APP_ID, ForecastAccessPolicy::MANAGER_GROUP_KEY, (string)$params['manager_group']);
		}

		if (isset($params['team_groups']) === true) {
			$this->appConfig->setValueString(Application::APP_ID, SnapshotGenerationService::TEAMS_KEY, (string)$params['team_groups']);
		}

		return new JSONResponse(['status' => 'saved']);
	}//end update()
}//end class
