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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.4
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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.4
 */
class AsteriskAdapter implements CtiAdapterInterface
{
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
     *
     * @return string Platform identifier.
     */
    public function getPlatform(): string
    {
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
     */
    public function handleInboundWebhook(array $payload): CtiWebhookResult
    {
        $event     = strtolower((string) ($payload['Event'] ?? ($payload['event'] ?? 'unknown')));
        $eventType = match ($event) {
            'newchannel', 'dialbegin' => 'ringing',
            'bridgeenter', 'answer'   => 'answered',
            'hangup', 'dialend'       => 'ended',
            'channelhangup'           => 'ended',
            'devicestatechange'       => 'presence',
            default                   => $event,
        };

        $extension = null;
        $channel   = (string) ($payload['Channel'] ?? ($payload['channel'] ?? ''));
        if ($channel !== '' && preg_match('#/(\d+)#', $channel, $m) === 1) {
            $extension = $m[1];
        }

        $duration = null;
        $raw      = ($payload['Duration'] ?? ($payload['duration'] ?? null));
        if (is_string($raw) === true && preg_match('/(\d+):(\d+):(\d+)/', $raw, $m) === 1) {
            $duration = (((int) $m[1]) * 3600) + (((int) $m[2]) * 60) + ((int) $m[3]);
        } else if (is_numeric($raw) === true) {
            $duration = (int) $raw;
        }

        $presenceMap = [
            'NOT_INUSE'   => 'available',
            'INUSE'       => 'on-call',
            'BUSY'        => 'on-call',
            'RINGING'     => 'on-call',
            'ONHOLD'      => 'wrap-up',
            'UNAVAILABLE' => 'offline',
        ];
        $presenceRaw = (string) ($payload['State'] ?? ($payload['DeviceState'] ?? ''));
        $presence    = ($presenceMap[$presenceRaw] ?? null);

        $direction = null;
        if (isset($payload['direction']) === true) {
            $direction = (string) $payload['direction'];
        }

        $fromNumber = null;
        if (isset($payload['CallerIDNum']) === true) {
            $fromNumber = (string) $payload['CallerIDNum'];
        }

        $toNumber = null;
        if (isset($payload['ConnectedLineNum']) === true) {
            $toNumber = (string) $payload['ConnectedLineNum'];
        }

        $userId = null;
        if (isset($payload['userId']) === true) {
            $userId = (string) $payload['userId'];
        }

        $recordingUrl = null;
        if (isset($payload['RecordingURL']) === true) {
            $recordingUrl = (string) $payload['RecordingURL'];
        }

        $recordingExpiresAt = null;
        if (isset($payload['RecordingExpiresAt']) === true) {
            $recordingExpiresAt = (string) $payload['RecordingExpiresAt'];
        }

        $queueName = null;
        if (isset($payload['Queue']) === true) {
            $queueName = (string) $payload['Queue'];
        }

        return new CtiWebhookResult(
            eventType: $eventType,
            externalCallId: (string) ($payload['Uniqueid'] ?? ($payload['uniqueid'] ?? '')),
            direction: $direction,
            fromNumber: $fromNumber,
            toNumber: $toNumber,
            extension: $extension,
            userId: $userId,
            durationSeconds: $duration,
            recordingUrl: $recordingUrl,
            recordingExpiresAt: $recordingExpiresAt,
            presenceState: $presence,
            queueName: $queueName,
            raw: $payload,
        );
    }//end handleInboundWebhook()

    /**
     * {@inheritDoc}
     *
     * Posts to the configured ARI Originate endpoint with HTTP-basic auth.
     *
     * @param string $extension    The agent's telephony extension that should ring.
     * @param string $targetNumber Destination phone number (E.164 preferred).
     * @param string $callerId     Caller-ID to present on the outbound call.
     *
     * @return CtiCallResult Outcome including the new external call id.
     */
    public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult
    {
        $baseUrl = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_api_base_url', '');
        $user    = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_ari_user', '');
        $pass    = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_ari_pass', '');
        $context = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_context', 'from-internal');
        if ($baseUrl === '') {
            return new CtiCallResult(
                success: false,
                error: 'Asterisk API base URL not configured.',
                platform: $this->getPlatform(),
            );
        }

        $auth = null;
        if ($user !== '') {
            $auth = [$user, $pass];
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                rtrim($baseUrl, '/').'/ari/channels',
                [
                    'auth'    => $auth,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => json_encode(
                        [
                            'endpoint'  => 'SIP/'.$extension,
                            'extension' => $targetNumber,
                            'context'   => $context,
                            'callerId'  => $callerId,
                        ]
                    ),
                    'timeout' => 10,
                ]
            );

            $bodyContents = (string) $response->getBody();
            $body         = json_decode($bodyContents, true);
            $callId       = null;
            if (is_array($body) === true) {
                $callId = ($body['id'] ?? ($body['uniqueid'] ?? null));
            }

            $externalCallId = null;
            if ($callId !== null) {
                $externalCallId = (string) $callId;
            }

            return new CtiCallResult(
                success: true,
                externalCallId: $externalCallId,
                platform: $this->getPlatform(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Asterisk originate failed',
                ['exception' => $e->getMessage()]
            );
            return new CtiCallResult(
                success: false,
                error: 'Asterisk originate failed: '.$e->getMessage(),
                platform: $this->getPlatform(),
            );
        }//end try
    }//end originateCall()

    /**
     * {@inheritDoc}
     *
     * Asterisk Stasis bridge pushes channel-state events to the configured
     * webhook URL automatically once the application is registered; nothing to
     * do here.
     *
     * @param string $userId    NC user UID.
     * @param string $extension Agent extension.
     *
     * @return void
     */
    public function subscribeToPresence(string $userId, string $extension): void
    {
        // No-op: Stasis device-state events are delivered via the existing webhook stream.
    }//end subscribeToPresence()

    /**
     * {@inheritDoc}
     *
     * Validates a shared-secret query parameter against the configured secret.
     *
     * @param string $payload   Raw request body as received.
     * @param string $signature Signature/token to validate.
     *
     * @return bool True when the signature is valid.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expected = $this->appConfig->getValueString(Application::APP_ID, 'cti_asterisk_webhook_secret', '');
        if ($expected === '' || $signature === '') {
            return false;
        }

        return hash_equals($expected, $signature);
    }//end verifyWebhookSignature()
}//end class
