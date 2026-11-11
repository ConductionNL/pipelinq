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
		$body = new WebhookFields(source: (array)($payload['body'] ?? $payload));
		$party = $body->first(key: 'parties');
		return new CtiWebhookResult(
			eventType: $this->eventTypeOf(party: $party),
			externalCallId: (string)($body->text(key: 'telephonySessionId') ?? $body->text(key: 'sessionId')),
			direction: (self::DIRECTIONS[strtolower((string)$party->text(key: 'direction'))] ?? null),
			fromNumber: $party->part(key: 'from')->text(key: 'phoneNumber'),
			toNumber: $party->part(key: 'to')->text(key: 'phoneNumber'),
			extension: $party->text(key: 'extensionId'),
			userId: $party->text(key: 'accountId'),
			durationSeconds: $body->number(key: 'duration'),
			recordingUrl: $body->part(key: 'recording')->text(key: 'contentUri'),
			recordingExpiresAt: $body->part(key: 'recording')->text(key: 'expirationTime'),
			presenceState: $body->text(key: 'presenceStatus'),
			queueName: $body->text(key: 'queue'),
			raw: $payload,
		);
	}//end handleInboundWebhook()

	/**
	 * The normalised event type of a party's status code.
	 *
	 * @param WebhookFields $party The call party.
	 *
	 * @return string ringing|answered|ended|abandoned|transferred, the lower-cased code, or unknown.
	 */
	private function eventTypeOf(WebhookFields $party): string {
		$statusCode = strtolower((string)$party->part(key: 'status')->text(key: 'code'));
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
			return $this->platform()->refused(error: 'RingCentral API base URL not configured.');
		}

		$ringOut = [
			'from' => ['phoneNumber' => $callerId],
			'to' => ['phoneNumber' => $targetNumber],
			'caller' => ['phoneNumber' => $callerId],
			'playPrompt' => false,
		];
		$options = (new BearerJsonPost(token: $authToken))->options(body: $ringOut);
		return $this->originate(endpoint: $this->ringOutEndpoint(baseUrl: $baseUrl), options: $options);
	}//end originateCall()

	/**
	 * The ring-out endpoint of the caller's own extension; its reply names the call by `id`.
	 *
	 * @param string $baseUrl The configured API base URL.
	 *
	 * @return OriginateEndpoint The endpoint.
	 */
	private function ringOutEndpoint(string $baseUrl): OriginateEndpoint {
		return new OriginateEndpoint(url: rtrim($baseUrl, '/') . '/restapi/v1.0/account/~/extension/~/ring-out', callIdKeys: ['id']);
	}//end ringOutEndpoint()

	/**
	 * Place the call and report the outcome under this platform's name.
	 *
	 * @param OriginateEndpoint   $endpoint Where the call is placed.
	 * @param array<string,mixed> $options  The HTTP client options.
	 *
	 * @return CtiCallResult The outcome.
	 */
	private function originate(OriginateEndpoint $endpoint, array $options): CtiCallResult {
		$originator = new CallOriginator(clientService: $this->clientService, logger: $this->logger);
		return $originator->place(request: $endpoint->request(options: $options), platform: $this->platform());
	}//end originate()

	/**
	 * This adapter's platform, for its call results and log lines.
	 *
	 * @return CtiPlatform The platform.
	 */
	private function platform(): CtiPlatform {
		return new CtiPlatform(platform: $this->getPlatform(), label: 'RingCentral');
	}//end platform()

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
			$options = (new BearerJsonPost(token: $authToken))->options(body: $this->presenceFilter(extension: $extension));
			$client->post(rtrim($baseUrl, '/') . '/restapi/v1.0/subscription', $options);
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
	 * The webhook subscription to one extension's presence events.
	 *
	 * @param string $extension The extension to subscribe.
	 *
	 * @return array<string,mixed> The subscription request body.
	 */
	private function presenceFilter(string $extension): array {
		return [
			'eventFilters' => [
				'/restapi/v1.0/account/~/extension/' . $extension . '/presence',
			],
			'deliveryMode' => ['transportType' => 'WebHook'],
		];
	}//end presenceFilter()

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
