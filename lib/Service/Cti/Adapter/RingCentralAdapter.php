<?php

/**
 * Pipelinq RingCentralAdapter.
 *
 * CTI adapter for the RingCentral platform.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti\Adapter
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Adapter;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Cti\CtiAdapterInterface;
use OCA\Pipelinq\Service\Cti\PresenceSubscribingInterface;
use OCA\Pipelinq\Service\Cti\Result\CtiCallResult;
use OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * RingCentral CTI adapter.
 *
 * Authentication: OAuth 2.0 bearer token. Webhook validation: the platform
 * delivers a `Validation-Token` / bearer token header; the adapter checks it
 * against the configured OAuth access token (or its hash).
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.3
 */
class RingCentralAdapter implements CtiAdapterInterface, PresenceSubscribingInterface {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param IClientService $clientService HTTP client factory.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The platform identifier.
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function getPlatform(): string {
		return 'ringcentral';
	}//end getPlatform()

	/**
	 * {@inheritDoc}
	 *
	 * RingCentral payload shape (excerpt):
	 *   { "event": "/restapi/v1.0/account/~/telephony/sessions/...",
	 *     "body": { "telephonySessionId": "...", "parties": [{
	 *       "id": "...", "direction": "Inbound",
	 *       "from": { "phoneNumber": "+31..." },
	 *       "to":   { "phoneNumber": "+31..." },
	 *       "status": { "code": "Answered" } }] } }
	 *
	 * @param array $payload The raw webhook payload.
	 *
	 * @return CtiWebhookResult The normalised webhook result.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Flat field-by-field payload normalisation; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Flat field-by-field payload normalisation; extraction adds no clarity.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.3
	 */
	public function handleInboundWebhook(array $payload): CtiWebhookResult {
		$body = (array)($payload['body'] ?? $payload);
		$party = ((array)($body['parties'] ?? []))[0] ?? [];
		$party = (array)$party;

		$statusCode = strtolower((string)(($party['status']['code'] ?? '')));
		$defaultEventType = 'unknown';
		if ($statusCode !== '') {
			$defaultEventType = $statusCode;
		}

		$eventType = match ($statusCode) {
			'setup', 'proceeding' => 'ringing',
			'answered' => 'answered',
			'disconnected' => 'ended',
			'voicemail' => 'abandoned',
			'parked' => 'transferred',
			default => $defaultEventType,
		};

		$directionRaw = strtolower((string)($party['direction'] ?? ''));
		$direction = match ($directionRaw) {
			'inbound' => 'inbound',
			'outbound' => 'outbound',
			default => null,
		};

		$fromNumber = null;
		if (isset($party['from']['phoneNumber']) === true) {
			$fromNumber = (string)$party['from']['phoneNumber'];
		}

		$toNumber = null;
		if (isset($party['to']['phoneNumber']) === true) {
			$toNumber = (string)$party['to']['phoneNumber'];
		}

		$extension = null;
		if (isset($party['extensionId']) === true) {
			$extension = (string)$party['extensionId'];
		}

		$userId = null;
		if (isset($party['accountId']) === true) {
			$userId = (string)$party['accountId'];
		}

		$durationSeconds = null;
		if (isset($body['duration']) === true) {
			$durationSeconds = (int)$body['duration'];
		}

		$recordingUrl = null;
		if (isset($body['recording']['contentUri']) === true) {
			$recordingUrl = (string)$body['recording']['contentUri'];
		}

		$recordingExpiresAt = null;
		if (isset($body['recording']['expirationTime']) === true) {
			$recordingExpiresAt = (string)$body['recording']['expirationTime'];
		}

		$presenceState = null;
		if (isset($body['presenceStatus']) === true) {
			$presenceState = (string)$body['presenceStatus'];
		}

		$queueName = null;
		if (isset($body['queue']) === true) {
			$queueName = (string)$body['queue'];
		}

		return new CtiWebhookResult(
			eventType: $eventType,
			externalCallId: (string)($body['telephonySessionId'] ?? ($body['sessionId'] ?? '')),
			direction: $direction,
			fromNumber: $fromNumber,
			toNumber: $toNumber,
			extension: $extension,
			userId: $userId,
			durationSeconds: $durationSeconds,
			recordingUrl: $recordingUrl,
			recordingExpiresAt: $recordingExpiresAt,
			presenceState: $presenceState,
			queueName: $queueName,
			raw: $payload,
		);
	}//end handleInboundWebhook()

	/**
	 * {@inheritDoc}
	 *
	 * Posts to the RingCentral "ring-out" endpoint with the OAuth bearer token.
	 *
	 * @param string $extension The originating extension.
	 * @param string $targetNumber The number to dial.
	 * @param string $callerId The caller ID to present.
	 *
	 * @return CtiCallResult The call origination result.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.3
	 */
	public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult {
		$baseUrl = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_api_base_url', '');
		$authToken = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_access_token', '');
		if ($baseUrl === '') {
			return new CtiCallResult(
				success: false,
				error: 'RingCentral API base URL not configured.',
				platform: $this->getPlatform(),
			);
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->post(
				rtrim($baseUrl, '/') . '/restapi/v1.0/account/~/extension/~/ring-out',
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $authToken,
						'Content-Type' => 'application/json',
					],
					'body' => json_encode(
						[
							'from' => ['phoneNumber' => $callerId],
							'to' => ['phoneNumber' => $targetNumber],
							'caller' => ['phoneNumber' => $callerId],
							'playPrompt' => false,
						]
					),
					'timeout' => 10,
				]
			);

			$bodyContents = (string)$response->getBody();
			$body = json_decode($bodyContents, true);
			$callId = null;
			if (is_array($body) === true) {
				$callId = ($body['id'] ?? null);
			}

			$externalCallId = null;
			if ($callId !== null) {
				$externalCallId = (string)$callId;
			}

			return new CtiCallResult(
				success: true,
				externalCallId: $externalCallId,
				platform: $this->getPlatform(),
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RingCentral originate failed',
				['exception' => $e->getMessage()]
			);
			return new CtiCallResult(
				success: false,
				error: 'RingCentral originate failed: ' . $e->getMessage(),
				platform: $this->getPlatform(),
			);
		}//end try
	}//end originateCall()

	/**
	 * {@inheritDoc}
	 *
	 * RingCentral pushes presence events via the subscription stream; this method
	 * issues the subscribe-extension event filter.
	 *
	 * @param string $userId The user identifier.
	 * @param string $extension The extension to subscribe.
	 *
	 * @return void
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function subscribeToPresence(string $userId, string $extension): void {
		$baseUrl = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_api_base_url', '');
		$authToken = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_access_token', '');
		if ($baseUrl === '' || $authToken === '') {
			return;
		}

		try {
			$client = $this->clientService->newClient();
			$client->post(
				rtrim($baseUrl, '/') . '/restapi/v1.0/subscription',
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $authToken,
						'Content-Type' => 'application/json',
					],
					'body' => json_encode(
						[
							'eventFilters' => [
								'/restapi/v1.0/account/~/extension/' . $extension . '/presence',
							],
							'deliveryMode' => ['transportType' => 'WebHook'],
						]
					),
					'timeout' => 10,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RingCentral presence subscribe failed',
				[
					'exception' => $e->getMessage(),
					'userId' => $userId,
				]
			);
		}//end try
	}//end subscribeToPresence()

	/**
	 * {@inheritDoc}
	 *
	 * RingCentral webhook validation: the `Validation-Token` header must match
	 * the configured OAuth access token (constant-time compare).
	 *
	 * @param string $payload The raw request body.
	 * @param string $signature The signature/validation token to verify.
	 *
	 * @return bool True when the signature is valid.
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function verifyWebhookSignature(string $payload, string $signature): bool {
		$expected = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_webhook_token', '');
		if ($expected === '' || $signature === '') {
			return false;
		}

		return hash_equals($expected, $signature);
	}//end verifyWebhookSignature()
}//end class
