<?php

/**
 * Pipelinq BrpAdminController.
 *
 * Admin-only endpoints for what pipelinq keeps of the BRP: the cache, the
 * retention, who may look up, the mutation webhook secret and the pseudonym
 * secret. The connection itself (base URL, OAuth2 credentials, certificates)
 * is managed in integriq, not here.
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
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-brp-person-lookup-goes-through-integriq-only
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Security\ISecureRandom;

/**
 * Admin-only configuration of what pipelinq keeps of the BRP; the connection is managed in integriq.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003
 */
class BrpAdminController extends Controller {
	/**
	 * App-config key => request parameter of every plain BRP setting.
	 *
	 * @var array<string, string>
	 */
	private const SETTING_PARAMS = [
		'brp.cache_ttl_hours' => 'cacheTtlHours',
		'brp.retention_days' => 'retentionDays',
		'brp.allowed_groups' => 'allowedGroups',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest      $request      Request.
	 * @param IAppConfig    $appConfig    App config.
	 * @param ISecureRandom $secureRandom Secure random for webhook and pseudonym secret rotation.
	 */
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private ISecureRandom $secureRandom,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/brp/settings — return masked configuration for the admin UI.
	 *
	 * The webhook secret is returned only as whether it is set. The answer
	 * names integriq as the place the connection is managed.
	 *
	 * @return JSONResponse
	 * @spec openspec/specs/brp-lookup/spec.md
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-pipelinq-does-not-monitor-the-brp-connection
	 */
	#[AuthorizedAdminSetting(settings: \OCA\Pipelinq\Settings\AdminSettings::class)]
	public function get(): JSONResponse {
		$hasWebhookSecret = $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.webhook_secret', '') !== '';
		return new JSONResponse(
			[
				'connectionManagedBy' => 'integriq',
				'cacheTtlHours' => (int)$this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.cache_ttl_hours', '24'),
				'retentionDays' => (int)$this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.retention_days', '7'),
				'allowedGroups' => $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.allowed_groups', ''),
				'webhookSecretSet' => $hasWebhookSecret,
			],
			Http::STATUS_OK
		);
	}//end get()

	/**
	 * POST /api/brp/settings — save the configuration.
	 *
	 * Body parameters mirror the GET shape. Connection settings are not taken:
	 * they live in integriq.
	 *
	 * @return JSONResponse
	 * @spec openspec/specs/brp-lookup/spec.md
	 */
	#[AuthorizedAdminSetting(settings: \OCA\Pipelinq\Settings\AdminSettings::class)]
	public function save(): JSONResponse {
		$this->storeGivenSettings();

		// Pseudonym secret (for RTBF) — rotated only on explicit request.
		$this->rotatePseudonymSecretWhenAsked();
		return new JSONResponse(['result' => 'ok'], Http::STATUS_OK);
	}//end save()

	/**
	 * Store every setting the request carries, as a string; absent ones keep their value.
	 *
	 * @return void
	 */
	private function storeGivenSettings(): void {
		foreach (self::SETTING_PARAMS as $configKey => $paramName) {
			$value = $this->request->getParam($paramName);
			if ($value === null) {
				continue;
			}

			$this->appConfig->setValueString(AppIdentity::APP_ID, $configKey, (string)$value);
		}
	}//end storeGivenSettings()

	/**
	 * Rotate the pseudonym secret when the request explicitly asks for it.
	 *
	 * @return void
	 */
	private function rotatePseudonymSecretWhenAsked(): void {
		$rotatePseudonym = $this->request->getParam('rotatePseudonymSecret');
		if ($rotatePseudonym === true || $rotatePseudonym === 'true') {
			$this->appConfig->setValueString(
				AppIdentity::APP_ID,
				'brp.pseudonym_secret',
				$this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC)
			);
		}
	}//end rotatePseudonymSecretWhenAsked()

	/**
	 * POST /api/brp/settings/webhook-secret — rotate the inbound mutation webhook secret.
	 *
	 * Returns the new secret once so the admin can paste it into HaalCentraal's
	 * configuration; subsequent reads only return `webhookSecretSet`.
	 *
	 * @return JSONResponse
	 * @spec openspec/specs/brp-lookup/spec.md
	 */
	#[AuthorizedAdminSetting(settings: \OCA\Pipelinq\Settings\AdminSettings::class)]
	public function rotateWebhookSecret(): JSONResponse {
		$secret = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->appConfig->setValueString(AppIdentity::APP_ID, 'brp.webhook_secret', $secret);
		return new JSONResponse(['secret' => $secret], Http::STATUS_OK);
	}//end rotateWebhookSecret()
}//end class
