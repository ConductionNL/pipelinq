<?php

/**
 * Pipelinq AsteriskAdapter.
 *
 * CTI adapter for the Asterisk platform (self-hosted PBX).
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
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.4
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
 * Asterisk CTI adapter.
 *
 * Authentication: shared-secret query parameter (legacy AMI bridge style).
 * Originate: POST to the configured ARI Originate endpoint, or fall through to
 * an AMI HTTP relay (e.g. asterisk-mgr).
 *
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.4
 */
class AsteriskAdapter implements CtiAdapterInterface {
	/**
	 * Presence value per Asterisk device state.
	 *
	 * @var array<string,string>
	 */
	private const PRESENCE_STATES = [
		'NOT_INUSE' => 'available',
		'INUSE' => 'on-call',
		'BUSY' => 'on-call',
		'RINGING' => 'on-call',
		'ONHOLD' => 'wrap-up',
		'UNAVAILABLE' => 'offline',
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
	 * @return string Platform identifier.
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function getPlatform(): string {
		return 'asterisk';
	}//end getPlatform()

	/**
	 * {@inheritDoc}
	 *
	 * Asterisk JSON payload (AMI/Stasis bridge):
	 *   { "Event": "Newchannel"|"DialBegin"|"DialEnd"|"Hangup"|"Bridge",
	 *     "Uniqueid": "...", "Channel": "SIP/101-00001234",
	 *     "CallerIDNum": "...", "ConnectedLineNum": "...",
	 *     "Duration": "00:05:27" }
	 *
	 * @param array<string,mixed> $payload Raw decoded webhook body.
	 *
	 * @return CtiWebhookResult Normalised event.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.4
	 */
	public function handleInboundWebhook(array $payload): CtiWebhookResult {
		$fields = new WebhookFields(source: $payload);
		return new CtiWebhookResult(
			eventType: $this->eventTypeOf(payload: $payload),
			externalCallId: (string)($payload['Uniqueid'] ?? ($payload['uniqueid'] ?? '')),
			direction: $fields->text(key: 'direction'),
			fromNumber: $fields->text(key: 'CallerIDNum'),
			toNumber: $fields->text(key: 'ConnectedLineNum'),
			extension: $this->extensionOf(payload: $payload),
			userId: $fields->text(key: 'userId'),
			durationSeconds: $this->durationOf(payload: $payload),
			recordingUrl: $fields->text(key: 'RecordingURL'),
			recordingExpiresAt: $fields->text(key: 'RecordingExpiresAt'),
			presenceState: (self::PRESENCE_STATES[(string)($payload['State'] ?? ($payload['DeviceState'] ?? ''))] ?? null),
			queueName: $fields->text(key: 'Queue'),
			raw: $payload,
		);
	}//end handleInboundWebhook()

	/**
	 * The normalised event type of an AMI/Stasis event name.
	 *
	 * @param array<string,mixed> $payload Raw decoded webhook body.
	 *
	 * @return string ringing|answered|ended|presence, or the lower-cased event name.
	 */
	private function eventTypeOf(array $payload): string {
		$event = strtolower((string)($payload['Event'] ?? ($payload['event'] ?? 'unknown')));
		return match ($event) {
			'newchannel', 'dialbegin' => 'ringing',
			'bridgeenter', 'answer' => 'answered',
			'hangup', 'dialend' => 'ended',
			'channelhangup' => 'ended',
			'devicestatechange' => 'presence',
			default => $event,
		};
	}//end eventTypeOf()

	/**
	 * The agent extension in the channel name (`SIP/101-00001234` gives `101`).
	 *
	 * @param array<string,mixed> $payload Raw decoded webhook body.
	 *
	 * @return string|null The extension, or null when the channel names none.
	 */
	private function extensionOf(array $payload): ?string {
		$channel = (string)($payload['Channel'] ?? ($payload['channel'] ?? ''));
		if ($channel !== '' && preg_match('#/(\d+)#', $channel, $matches) === 1) {
			return $matches[1];
		}

		return null;
	}//end extensionOf()

	/**
	 * The talk duration in seconds, from `HH:MM:SS` or a plain number.
	 *
	 * @param array<string,mixed> $payload Raw decoded webhook body.
	 *
	 * @return int|null The duration, or null when absent or unreadable.
	 */
	private function durationOf(array $payload): ?int {
		$raw = ($payload['Duration'] ?? ($payload['duration'] ?? null));
		if (is_string($raw) === true && preg_match('/(\d+):(\d+):(\d+)/', $raw, $matches) === 1) {
			return (((int)$matches[1]) * 3600) + (((int)$matches[2]) * 60) + ((int)$matches[3]);
		}

		if (is_numeric($raw) === true) {
			return (int)$raw;
		}

		return null;
	}//end durationOf()

	/**
	 * {@inheritDoc}
	 *
	 * Posts to the configured ARI Originate endpoint with HTTP-basic auth.
	 *
	 * @param string $extension The agent's telephony extension that should ring.
	 * @param string $targetNumber Destination phone number (E.164 preferred).
	 * @param string $callerId Caller-ID to present on the outbound call.
	 *
	 * @return CtiCallResult Outcome including the new external call id.
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult {
		$baseUrl = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_api_base_url', '');
		$auth = $this->ariAuth();
		$context = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_context', 'from-internal');
		if ($baseUrl === '') {
			return $this->platform()->refused(error: 'Asterisk API base URL not configured.');
		}

		$channel = [
			'endpoint' => 'SIP/' . $extension,
			'extension' => $targetNumber,
			'context' => $context,
			'callerId' => $callerId,
		];
		return $this->originate(endpoint: $this->ariEndpoint(baseUrl: $baseUrl), options: $this->ariOptions(auth: $auth, channel: $channel));
	}//end originateCall()

	/**
	 * The ARI channel-originate endpoint; its reply names the channel by `id` or `uniqueid`.
	 *
	 * @param string $baseUrl The configured API base URL.
	 *
	 * @return OriginateEndpoint The endpoint.
	 */
	private function ariEndpoint(string $baseUrl): OriginateEndpoint {
		return new OriginateEndpoint(url: rtrim($baseUrl, '/') . '/ari/channels', callIdKeys: ['id', 'uniqueid']);
	}//end ariEndpoint()

	/**
	 * The ARI basic-auth pair, or null when no ARI user is configured.
	 *
	 * @return array{0:string,1:string}|null The user and password.
	 */
	private function ariAuth(): ?array {
		$user = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_ari_user', '');
		$pass = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_ari_pass', '');
		if ($user === '') {
			return null;
		}

		return [$user, $pass];
	}//end ariAuth()

	/**
	 * The HTTP options of an ARI channel-originate request.
	 *
	 * @param array{0:string,1:string}|null $auth    The basic-auth pair, or null.
	 * @param array<string,string>          $channel The channel to originate.
	 *
	 * @return array<string,mixed> The client options.
	 */
	private function ariOptions(?array $auth, array $channel): array {
		return [
			'auth' => $auth,
			'headers' => ['Content-Type' => 'application/json'],
			'body' => json_encode($channel),
			'timeout' => 10,
		];
	}//end ariOptions()

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
		return new CtiPlatform(platform: $this->getPlatform(), label: 'Asterisk');
	}//end platform()

	/**
	 * {@inheritDoc}
	 *
	 * Validates a shared-secret query parameter against the configured secret.
	 *
	 * @param string $payload Raw request body as received.
	 * @param string $signature Signature/token to validate.
	 *
	 * @return bool True when the signature is valid.
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function verifyWebhookSignature(string $payload, string $signature): bool {
		$expected = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_webhook_secret', '');
		if ($expected === '' || $signature === '') {
			return false;
		}

		return hash_equals($expected, $signature);
	}//end verifyWebhookSignature()
}//end class
