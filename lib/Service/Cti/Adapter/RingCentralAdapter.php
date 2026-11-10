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
	 * Normalised event type per party status code (lower-cased).
	 *
	 * @var array<string,string>
	 */
	private const EVENT_TYPES = [
		'setup' => 'ringing',
		'proceeding' => 'ringing',
		'answered' => 'answered',
		'disconnected' => 'ended',
		'voicemail' => 'abandoned',
		'parked' => 'transferred',
	];

	/**
	 * Normalised direction per party direction (lower-cased).
	 *
	 * @var array<string,string>
	 */
	private const DIRECTIONS = [
		'inbound' => 'inbound',
		'outbound' => 'outbound',
	];

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
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.3
	 */
	public function handleInboundWebhook(array $payload): CtiWebhookResult {
		$body = (array)($payload['body'] ?? $payload);
		$party = $this->firstPartyOf(body: $body);
		return new CtiWebhookResult(
			eventType: $this->eventTypeOf(party: $party),
			externalCallId: (string)($body['telephonySessionId'] ?? ($body['sessionId'] ?? '')),
			direction: self::DIRECTIONS[strtolower((string)($party['direction'] ?? ''))] ?? null,
			fromNumber: WebhookField::text(source: WebhookField::part(source: $party, key: 'from'), key: 'phoneNumber'),
			toNumber: WebhookField::text(source: WebhookField::part(source: $party, key: 'to'), key: 'phoneNumber'),
			extension: WebhookField::text(source: $party, key: 'extensionId'),
			userId: WebhookField::text(source: $party, key: 'accountId'),
			durationSeconds: WebhookField::number(source: $body, key: 'duration'),
			recordingUrl: WebhookField::text(source: WebhookField::part(source: $body, key: 'recording'), key: 'contentUri'),
			recordingExpiresAt: WebhookField::text(source: WebhookField::part(source: $body, key: 'recording'), key: 'expirationTime'),
			presenceState: WebhookField::text(source: $body, key: 'presenceStatus'),
			queueName: WebhookField::text(source: $body, key: 'queue'),
			raw: $payload,
		);
	}//end handleInboundWebhook()

	/**
	 * The first call party of a telephony-session body, or an empty party.
	 *
	 * @param array<array-key,mixed> $body The session body.
	 *
	 * @return array<array-key,mixed> The first party.
	 */
	private function firstPartyOf(array $body): array {
		$party = ((array)($body['parties'] ?? []))[0] ?? [];
		return (array)$party;
	}//end firstPartyOf()

	/**
	 * The normalised event type of a party's status code.
	 *
	 * @param array<array-key,mixed> $party The call party.
	 *
	 * @return string ringing|answered|ended|abandoned|transferred, the lower-cased code, or unknown.
	 */
	private function eventTypeOf(array $party): string {
		$statusCode = strtolower((string)(($party['status']['code'] ?? '')));
		if ($statusCode === '') {
			return 'unknown';
		}

		return (self::EVENT_TYPES[$statusCode] ?? $statusCode);
	}//end eventTypeOf()

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
			return CtiCallResult::refused(error: 'RingCentral API base URL not configured.', platform: $this->getPlatform());
		}

		$ringOut = [
			'from' => ['phoneNumber' => $callerId],
			'to' => ['phoneNumber' => $targetNumber],
			'caller' => ['phoneNumber' => $callerId],
			'playPrompt' => false,
		];
		$endpoint = new OriginateEndpoint(url: rtrim($baseUrl, '/') . '/restapi/v1.0/account/~/extension/~/ring-out', callIdKeys: ['id']);
		return $this->originate(request: new OriginateRequest(endpoint: $endpoint, options: BearerJsonPost::options(token: $authToken, body: $ringOut)));
	}//end originateCall()

	/**
	 * Place the call and report the outcome under this platform's name.
	 *
	 * @param OriginateRequest $request The originate request.
	 *
	 * @return CtiCallResult The outcome.
	 */
	private function originate(OriginateRequest $request): CtiCallResult {
		$originator = new CallOriginator(clientService: $this->clientService, logger: $this->logger);
		return $originator->place(request: $request, vendor: new CtiVendor(platform: $this->getPlatform(), label: 'RingCentral'));
	}//end originate()

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
			$filter = [
				'eventFilters' => [
					'/restapi/v1.0/account/~/extension/' . $extension . '/presence',
				],
				'deliveryMode' => ['transportType' => 'WebHook'],
			];
			$client->post(rtrim($baseUrl, '/') . '/restapi/v1.0/subscription', BearerJsonPost::options(token: $authToken, body: $filter));
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
