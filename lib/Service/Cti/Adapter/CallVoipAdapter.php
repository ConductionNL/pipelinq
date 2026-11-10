<?php

/**
 * Pipelinq CallVoipAdapter.
 *
 * CTI adapter for the CallVoip platform (Dutch SME market leader).
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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Adapter;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Cti\CtiAdapterInterface;
use OCA\Pipelinq\Service\Cti\Result\CtiCallResult;
use OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * CallVoip CTI adapter.
 *
 * Webhook signature: HMAC-SHA256 hex of the raw body using `webhook_secret`.
 * Originate endpoint: POST {api_base_url}/calls.
 * Rate limit: 100 requests/sec per platform — enforced via {@see $callTimestamps}.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.2
 */
class CallVoipAdapter implements CtiAdapterInterface {

	/**
	 * Sliding-window timestamps of recent originateCall invocations (microsecond precision).
	 *
	 * Keeps adapter rate limiting in-process, which is adequate for a single
	 * pipelinq instance; multi-host deployments should additionally throttle at
	 * the platform side.
	 *
	 * @var array<int,float>
	 */
	private array $callTimestamps = [];

	/**
	 * Maximum requests per second permitted against the platform.
	 *
	 * @var int
	 */
	private const RATE_LIMIT_PER_SEC = 100;

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
		return 'callvoip';
	}//end getPlatform()

	/**
	 * {@inheritDoc}
	 *
	 * CallVoip payload shape (excerpt):
	 *   { "event": "answered", "callId": "uuid", "extension": "101",
	 *     "from": "+31612345678", "to": "+31303033000",
	 *     "timestamp": "2026-05-22T09:16:45Z", "duration": 327,
	 *     "recording": { "url": "...", "expiresAt": "..." } }
	 *
	 * @param array $payload The inbound webhook payload.
	 *
	 * @return CtiWebhookResult The parsed webhook result.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.2
	 */
	public function handleInboundWebhook(array $payload): CtiWebhookResult {
		$recording = WebhookField::part(source: $payload, key: 'recording');
		return new CtiWebhookResult(
			eventType: (string)($payload['event'] ?? 'unknown'),
			externalCallId: (string)($payload['callId'] ?? ''),
			direction: $this->directionOf(payload: $payload),
			fromNumber: WebhookField::text(source: $payload, key: 'from'),
			toNumber: WebhookField::text(source: $payload, key: 'to'),
			extension: WebhookField::text(source: $payload, key: 'extension'),
			userId: WebhookField::text(source: $payload, key: 'userId'),
			durationSeconds: WebhookField::number(source: $payload, key: 'duration'),
			recordingUrl: WebhookField::text(source: $recording, key: 'url'),
			recordingExpiresAt: WebhookField::text(source: $recording, key: 'expiresAt'),
			presenceState: WebhookField::text(source: $payload, key: 'presence'),
			queueName: WebhookField::text(source: $payload, key: 'queue'),
			agentSkill: WebhookField::text(source: $payload, key: 'skill'),
			raw: $payload,
		);
	}//end handleInboundWebhook()

	/**
	 * The call direction; an extension-targeted answer without one is inbound.
	 *
	 * @param array<string,mixed> $payload The inbound webhook payload.
	 *
	 * @return string|null inbound|outbound as supplied, or null when unknown.
	 */
	private function directionOf(array $payload): ?string {
		if (isset($payload['direction']) === true) {
			return (string)$payload['direction'];
		}

		if ((string)($payload['event'] ?? 'unknown') === 'answered' && isset($payload['extension']) === true) {
			return 'inbound';
		}

		return null;
	}//end directionOf()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $extension The originating extension.
	 * @param string $targetNumber The number to dial.
	 * @param string $callerId The caller ID to present.
	 *
	 * @return CtiCallResult The result of the originate request.
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult {
		$call = [
			'extension' => $extension,
			'target' => $targetNumber,
			'callerId' => $callerId,
		];
		if ($this->rateLimit() === false) {
			return CtiCallResult::refused(error: 'CallVoip rate limit exceeded (100/sec).', platform: $this->getPlatform());
		}

		return $this->dial(call: $call);
	}//end originateCall()

	/**
	 * Post the call to the configured CallVoip API, when one is configured.
	 *
	 * @param array<string,string> $call The extension, target and caller id.
	 *
	 * @return CtiCallResult The outcome.
	 */
	private function dial(array $call): CtiCallResult {
		$baseUrl = $this->appConfig->getValueString(Application::APP_ID, 'cti_callvoip_api_base_url', '');
		$apiKey = $this->appConfig->getValueString(Application::APP_ID, 'cti_callvoip_api_key', '');
		if ($baseUrl === '') {
			return CtiCallResult::refused(error: 'CallVoip API base URL not configured.', platform: $this->getPlatform());
		}

		$endpoint = new OriginateEndpoint(url: rtrim($baseUrl, '/') . '/calls', callIdKeys: ['callId']);
		return $this->originate(request: new OriginateRequest(endpoint: $endpoint, options: BearerJsonPost::options(token: $apiKey, body: $call)));
	}//end dial()

	/**
	 * Place the call and report the outcome under this platform's name.
	 *
	 * @param OriginateRequest $request The originate request.
	 *
	 * @return CtiCallResult The outcome.
	 */
	private function originate(OriginateRequest $request): CtiCallResult {
		$originator = new CallOriginator(clientService: $this->clientService, logger: $this->logger);
		return $originator->place(request: $request, vendor: new CtiVendor(platform: $this->getPlatform(), label: 'CallVoip'));
	}//end originate()

	/**
	 * {@inheritDoc}
	 *
	 * Validates HMAC-SHA256(raw body, webhook_secret) using a constant-time compare.
	 *
	 * @param string $payload The raw webhook body.
	 * @param string $signature The signature to verify.
	 *
	 * @return bool True when the signature is valid.
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function verifyWebhookSignature(string $payload, string $signature): bool {
		$secret = $this->appConfig->getValueString(Application::APP_ID, 'cti_callvoip_webhook_secret', '');
		if ($secret === '' || $signature === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $payload, $secret);
		return hash_equals($expected, $signature);
	}//end verifyWebhookSignature()

	/**
	 * Token-bucket-ish sliding window: drop timestamps older than 1 second,
	 * accept the call iff there are < {@see self::RATE_LIMIT_PER_SEC} remaining.
	 *
	 * @return bool True when the request is within the rate limit.
	 */
	private function rateLimit(): bool {
		$now = microtime(true);
		$cutoff = ($now - 1.0);
		$this->callTimestamps = array_values(
			array_filter(
				$this->callTimestamps,
				static fn (float $timestamp): bool => ($timestamp >= $cutoff)
			)
		);

		if (count($this->callTimestamps) >= self::RATE_LIMIT_PER_SEC) {
			return false;
		}

		$this->callTimestamps[] = $now;
		return true;
	}//end rateLimit()
}//end class
