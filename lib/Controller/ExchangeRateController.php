<?php

/**
 * Pipelinq ExchangeRateController.
 *
 * Converts a deal value into the reporting currency for LeadDetail and says
 * at which rate, from where and of which day.
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
 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-a-stale-rate-is-shown-as-stale-req-dxr-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ExchangeRateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Read-only conversion of an amount at the resolved rate.
 *
 * It touches no record: the caller passes the amount and currency it already
 * shows, so any signed-in user may ask. The override reason stays on the
 * admin settings page.
 *
 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-a-stale-rate-is-shown-as-stale-req-dxr-002
 */
class ExchangeRateController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ExchangeRateService $exchangeRate The rate resolution.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-a-stale-rate-is-shown-as-stale-req-dxr-002
	 */
	public function __construct(
		IRequest $request,
		private ExchangeRateService $exchangeRate,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Convert an amount: the converted amount, the rate, its source and date.
	 *
	 * @param string $currency The ISO 4217 source currency.
	 * @param string $amount The amount in that currency.
	 *
	 * @return JSONResponse The conversion, or 400 for a malformed currency or amount.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-a-stale-rate-is-shown-as-stale-req-dxr-002
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
	 */
	#[NoAdminRequired]
	public function convert(string $currency = '', string $amount = '0'): JSONResponse {
		$code = strtoupper(trim($currency));
		if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
			return new JSONResponse(['error' => 'currency must be a three-letter currency code.'], 400);
		}

		if (is_numeric($amount) === false) {
			return new JSONResponse(['error' => 'amount must be a number.'], 400);
		}

		$converted = $this->exchangeRate->convert(amount: (float)$amount, currency: $code);
		unset($converted['reason']);
		$converted['maxRateAgeDays'] = $this->exchangeRate->getMaxRateAgeDays();

		return new JSONResponse($converted);
	}//end convert()
}//end class
