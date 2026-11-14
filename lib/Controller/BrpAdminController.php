<?php

/**
 * Pipelinq BrpAdminController.
 *
 * Admin-only endpoints for HaalCentraal / BRP integration configuration. Secrets are
 * encrypted via ICrypto before being persisted. File-path settings reference paths on
 * the Nextcloud host filesystem (admin uploads PEM bundles via the standard server
 * filesystem; no in-app upload to limit attack surface).
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
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin-only configuration for the HaalCentraal client.
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
		'brp.base_url' => 'baseUrl',
		'brp.oauth_endpoint' => 'oauthEndpoint',
		'brp.client_id' => 'clientId',
		'brp.cert_path' => 'certPath',
		'brp.key_path' => 'keyPath',
		'brp.ca_bundle' => 'caBundle',
		'brp.cache_ttl_hours' => 'cacheTtlHours',
		'brp.retention_days' => 'retentionDays',
		'brp.health_timezone' => 'healthTimezone',
		'brp.allowed_groups' => 'allowedGroups',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param IAppConfig $appConfig App config.
	 * @param ICrypto $crypto Crypto for secret encryption.
	 * @param ISecureRandom $secureRandom Secure random for webhook secret rotation.
	 * @param IL10N $l10n i18n.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private ICrypto $crypto,
		private ISecureRandom $secureRandom,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/brp/settings — return masked configuration for the admin UI.
	 *
	 * Secrets are returned as either `'***SET***'` (already configured) or empty string.
	 *
	 * @return JSONResponse
	 * @spec openspec/specs/brp-lookup/spec.md
	 */
	#[AuthorizedAdminSetting(settings: \OCA\Pipelinq\Settings\AdminSettings::class)]
	public function get(): JSONResponse {
		$hasSecret = $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.client_secret_encrypted', '') !== '';
		$hasWebhookSecret = $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.webhook_secret', '') !== '';
		return new JSONResponse(
			[
				'baseUrl' => $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.base_url', ''),
				'oauthEndpoint' => $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.oauth_endpoint', ''),
				'clientId' => $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.client_id', ''),
				'clientSecretSet' => $hasSecret,
				'certPath' => $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.cert_path', ''),
				'keyPath' => $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.key_path', ''),
				'caBundle' => $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.ca_bundle', ''),
				'cacheTtlHours' => (int)$this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.cache_ttl_hours', '24'),
				'retentionDays' => (int)$this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.retention_days', '7'),
				'healthTimezone' => $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.health_timezone', 'UTC'),
				'allowedGroups' => $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.allowed_groups', ''),
				'webhookSecretSet' => $hasWebhookSecret,
			],
			Http::STATUS_OK
		);
	}//end get()

	/**
	 * POST /api/brp/settings — save the configuration.
	 *
	 * Body parameters mirror the GET shape. Empty `clientSecret` keeps the existing value;
	 * a non-empty string is encrypted via ICrypto before storage.
	 *
	 * @return JSONResponse
	 * @spec openspec/specs/brp-lookup/spec.md
	 */
	#[AuthorizedAdminSetting(settings: \OCA\Pipelinq\Settings\AdminSettings::class)]
	public function save(): JSONResponse {
		$this->storeGivenSettings();

		// Client secret — encrypt on save.
		$refusal = $this->storeNewClientSecret();
		if ($refusal !== null) {
			return $refusal;
		}

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
	 * Store a new client secret encrypted; an empty one keeps the stored secret.
	 *
	 * @return JSONResponse|null The 500 when it cannot be encrypted, else null.
	 */
	private function storeNewClientSecret(): ?JSONResponse {
		$clientSecret = $this->request->getParam('clientSecret');
		if (is_string($clientSecret) === false || $clientSecret === '') {
			return null;
		}

		try {
			$encrypted = $this->crypto->encrypt($clientSecret);
			$this->appConfig->setValueString(AppIdentity::APP_ID, 'brp.client_secret_encrypted', $encrypted);
		} catch (Throwable $e) {
			$this->logger->error('Failed to encrypt BRP client secret', ['error' => $e->getMessage()]);
			return new JSONResponse(
				[
					'errorCode' => 'crypto-failed',
					'errorMessage' => $this->l10n->t('Kon BRP client-secret niet versleutelen.'),
				],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return null;
	}//end storeNewClientSecret()

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
