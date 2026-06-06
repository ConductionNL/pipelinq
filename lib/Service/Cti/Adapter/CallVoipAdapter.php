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
class CallVoipAdapter implements CtiAdapterInterface
{
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
     * @param IAppConfig      $appConfig     The app config.
     * @param IClientService  $clientService HTTP client factory.
     * @param LoggerInterface $logger        The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     */
    public function getPlatform(): string
    {
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
     */
    public function handleInboundWebhook(array $payload): CtiWebhookResult
    {
        $eventType = (string) ($payload['event'] ?? 'unknown');
        $callId    = (string) ($payload['callId'] ?? '');

        $direction = null;
        if (isset($payload['direction']) === true) {
            $direction = (string) $payload['direction'];
        } else if ($eventType === 'answered' && isset($payload['extension']) === true) {
            // Default: an extension-targeted answer is inbound.
            $direction = 'inbound';
        }

        $recording          = (array) ($payload['recording'] ?? []);
        $recordingUrl       = ($recording['url'] ?? null);
        $recordingExpiresAt = ($recording['expiresAt'] ?? null);

        return new CtiWebhookResult(
            eventType: $eventType,
            externalCallId: $callId,
            direction: $direction,
            fromNumber: isset($payload['from']) === true ? (string) $payload['from'] : null,
            toNumber: isset($payload['to']) === true ? (string) $payload['to'] : null,
            extension: isset($payload['extension']) === true ? (string) $payload['extension'] : null,
            userId: isset($payload['userId']) === true ? (string) $payload['userId'] : null,
            durationSeconds: isset($payload['duration']) === true ? (int) $payload['duration'] : null,
            recordingUrl: $recordingUrl !== null ? (string) $recordingUrl : null,
            recordingExpiresAt: $recordingExpiresAt !== null ? (string) $recordingExpiresAt : null,
            presenceState: isset($payload['presence']) === true ? (string) $payload['presence'] : null,
            queueName: isset($payload['queue']) === true ? (string) $payload['queue'] : null,
            agentSkill: isset($payload['skill']) === true ? (string) $payload['skill'] : null,
            raw: $payload,
        );
    }//end handleInboundWebhook()

    /**
     * {@inheritDoc}
     */
    public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult
    {
        if ($this->rateLimit() === false) {
            return new CtiCallResult(
                success: false,
                error: 'CallVoip rate limit exceeded (100/sec).',
                platform: $this->getPlatform(),
            );
        }

        $baseUrl = $this->appConfig->getValueString(Application::APP_ID, 'cti_callvoip_api_base_url', '');
        $apiKey  = $this->appConfig->getValueString(Application::APP_ID, 'cti_callvoip_api_key', '');
        if ($baseUrl === '') {
            return new CtiCallResult(
                success: false,
                error: 'CallVoip API base URL not configured.',
                platform: $this->getPlatform(),
            );
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                rtrim($baseUrl, '/').'/calls',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Content-Type'  => 'application/json',
                    ],
                    'body'    => json_encode(
                        [
                            'extension'    => $extension,
                            'target'       => $targetNumber,
                            'callerId'     => $callerId,
                        ]
                    ),
                    'timeout' => 10,
                ]
            );

            $bodyContents = (string) $response->getBody();
            $body         = json_decode($bodyContents, true);
            $callId       = is_array($body) === true ? ($body['callId'] ?? null) : null;

            return new CtiCallResult(
                success: true,
                externalCallId: $callId !== null ? (string) $callId : null,
                platform: $this->getPlatform(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CallVoip originate failed',
                ['exception' => $e->getMessage()]
            );
            return new CtiCallResult(
                success: false,
                error: 'CallVoip originate failed: '.$e->getMessage(),
                platform: $this->getPlatform(),
            );
        }//end try
    }//end originateCall()

    /**
     * {@inheritDoc}
     *
     * CallVoip pushes presence via webhook (event: "presence"), so there is no
     * client-side subscribe to do. Implementation is intentionally a no-op.
     */
    public function subscribeToPresence(string $userId, string $extension): void
    {
        // No-op: CallVoip presence is delivered via inbound webhook events.
    }//end subscribeToPresence()

    /**
     * {@inheritDoc}
     *
     * Validates HMAC-SHA256(raw body, webhook_secret) using a constant-time compare.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
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
    private function rateLimit(): bool
    {
        $now    = microtime(true);
        $cutoff = ($now - 1.0);
        $this->callTimestamps = array_values(
            array_filter(
                $this->callTimestamps,
                static fn(float $ts): bool => ($ts >= $cutoff)
            )
        );

        if (count($this->callTimestamps) >= self::RATE_LIMIT_PER_SEC) {
            return false;
        }

        $this->callTimestamps[] = $now;
        return true;
    }//end rateLimit()
}//end class
