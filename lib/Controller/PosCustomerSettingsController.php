<?php

/**
 * Pipelinq PosCustomerSettingsController.
 *
 * Admin-only settings for the POS customer-link surface — search fields,
 * history depth, sync toggle, on-account customer requirement. Settings
 * are persisted to IAppConfig so they take effect immediately without a
 * restart (REQ-PCL-006).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin settings controller for POS customer-link configuration.
 *
 * The `#[AuthorizedAdminSetting(Application::APP_ID)]` attribute restricts
 * both endpoints to admins authorised to manage the pipelinq app's
 * settings (Nextcloud middleware enforces this before the action runs).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates the standard
 *  Nextcloud controller collaborators (request, config, logger).
 *
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-006
 */
class PosCustomerSettingsController extends Controller {
	/**
	 * Configurable setting keys and their defaults.
	 *
	 * @var array<string, string>
	 */
	private const DEFAULTS = [
		'customerSearchFields' => 'name,email,phone',
		'customerHistoryDepth' => '10',
		'enablePipelinqSync' => 'true',
		'requireCustomerForOnAccount' => 'true',
	];

	/**
	 * Allowed values for customerSearchFields (defensive whitelist).
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_FIELDS = ['name', 'email', 'phone'];

	/**
	 * Allowed values for customerHistoryDepth.
	 *
	 * @var array<int, int>
	 */
	private const ALLOWED_DEPTHS = [10, 20, 50];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/admin/pos-customer-settings — return the current settings.
	 *
	 * @return JSONResponse The settings.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-006
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function index(): JSONResponse {
		return new JSONResponse(['settings' => $this->readAll()]);
	}//end index()

	/**
	 * POST /api/admin/pos-customer-settings — update settings.
	 *
	 * Body shape (any subset):
	 *   {
	 *     "customerSearchFields": ["name","email","phone"],
	 *     "customerHistoryDepth": 20,
	 *     "enablePipelinqSync": true,
	 *     "requireCustomerForOnAccount": true
	 *   }
	 *
	 * @return JSONResponse The updated settings, or 422 on validation error.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-006
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function update(): JSONResponse {
		try {
			$this->applyFieldsParam();
			$this->applyDepthParam();
			$this->applyBoolParam(key: 'enablePipelinqSync');
			$this->applyBoolParam(key: 'requireCustomerForOnAccount');
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('PosCustomerSettingsController::update failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => 'Onverwachte fout bij opslaan van instellingen.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(['settings' => $this->readAll()]);
	}//end update()

	/**
	 * Read every setting (with defaults) as a structured array.
	 *
	 * @return array<string, mixed> The settings.
	 */
	private function readAll(): array {
		$fields = $this->appConfig->getValueString(
			Application::APP_ID,
			'customerSearchFields',
			self::DEFAULTS['customerSearchFields']
		);
		$fieldList = array_values(
			array_intersect(
				array_filter(array_map('trim', explode(',', $fields))),
				self::ALLOWED_FIELDS
			)
		);
		if (count($fieldList) === 0) {
			$fieldList = self::ALLOWED_FIELDS;
		}

		return [
			'customerSearchFields' => $fieldList,
			'customerHistoryDepth' => (int)$this->appConfig->getValueString(
				Application::APP_ID,
				'customerHistoryDepth',
				self::DEFAULTS['customerHistoryDepth']
			),
			'enablePipelinqSync' => $this->readBool(key: 'enablePipelinqSync'),
			'requireCustomerForOnAccount' => $this->readBool(key: 'requireCustomerForOnAccount'),
		];
	}//end readAll()

	/**
	 * Read a boolean setting (stored as 'true' / 'false' string).
	 *
	 * @param string $key The setting key.
	 *
	 * @return bool The setting value.
	 */
	private function readBool(string $key): bool {
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, self::DEFAULTS[$key]);
		return strtolower($value) !== 'false';
	}//end readBool()

	/**
	 * Apply the customerSearchFields request param to app config.
	 *
	 * Accepts either an array `["name","email"]` or a comma-separated
	 * string `"name,email"`. Rejects unknown field names.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On invalid input.
	 */
	private function applyFieldsParam(): void {
		$raw = $this->request->getParam('customerSearchFields', null);
		if ($raw === null) {
			return;
		}

		$fields = array_filter(array_map('trim', explode(',', (string)$raw)));
		if (is_array($raw) === true) {
			$fields = array_values(array_filter(array_map('strval', $raw)));
		}

		foreach ($fields as $field) {
			if (in_array($field, self::ALLOWED_FIELDS, true) === false) {
				throw new InvalidArgumentException(
					"Onbekend zoekveld: '" . $field . "'. Toegestaan: name, email, phone."
				);
			}
		}

		if (count($fields) === 0) {
			throw new InvalidArgumentException('Ten minste één zoekveld is verplicht.');
		}

		$this->appConfig->setValueString(Application::APP_ID, 'customerSearchFields', implode(',', $fields));
	}//end applyFieldsParam()

	/**
	 * Apply the customerHistoryDepth request param.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On invalid input.
	 */
	private function applyDepthParam(): void {
		$raw = $this->request->getParam('customerHistoryDepth', null);
		if ($raw === null) {
			return;
		}

		$depth = (int)$raw;
		if (in_array($depth, self::ALLOWED_DEPTHS, true) === false) {
			throw new InvalidArgumentException('Geschiedenisdiepte moet 10, 20 of 50 zijn.');
		}

		$this->appConfig->setValueString(Application::APP_ID, 'customerHistoryDepth', (string)$depth);
	}//end applyDepthParam()

	/**
	 * Apply a boolean request param.
	 *
	 * @param string $key The setting key.
	 *
	 * @return void
	 */
	private function applyBoolParam(string $key): void {
		$raw = $this->request->getParam($key, null);
		if ($raw === null) {
			return;
		}

		$truthy = ((int)$raw) === 1;
		if (is_bool($raw) === true) {
			$truthy = $raw;
		}

		if (is_string($raw) === true) {
			$truthy = in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
		}

		$value = 'false';
		if ($truthy === true) {
			$value = 'true';
		}

		$this->appConfig->setValueString(Application::APP_ID, $key, $value);
	}//end applyBoolParam()
}//end class
