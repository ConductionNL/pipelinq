<?php

/**
 * Pipelinq AsteriskAdapter.
 *
 * CTI adapter for self-hosted Asterisk PBX (AMI bridge / HTTP callback).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti\Adapter
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
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

use OCA\Pipelinq\Service\Cti\CtiAdapterInterface;
use OCA\Pipelinq\Service\Cti\CtiCallResult;
use OCA\Pipelinq\Service\Cti\CtiWebhookResult;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asterisk adapter.
 *
 * Legacy AMI bridges authenticate inbound webhooks with a shared secret passed
 * as the `secret` query parameter, compared in constant time (REQ-CTI-007).
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.4
 */
class AsteriskAdapter implements CtiAdapterInterface
{
    use CtiPayloadTrait;

    /**
     * Constructor.
     *
     * @param IClientService  $clientService The HTTP client service.
     * @param LoggerInterface $logger        The logger.
     * @param string          $apiBaseUrl    The configured platform API base URL.
     */
    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
        private string $apiBaseUrl='',
    ) {
    }//end __construct()

    /**
     * The platform slug this adapter handles.
     *
     * @return string The platform slug.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.4
     */
    public function getPlatform(): string
    {
        return 'asterisk';
    }//end getPlatform()

    /**
     * Set the API base URL.
     *
     * @param string $apiBaseUrl The base URL.
     *
     * @return void
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.4
     */
    public function setApiBaseUrl(string $apiBaseUrl): void
    {
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');
    }//end setApiBaseUrl()

    /**
     * Verify the shared-secret query parameter (REQ-CTI-007).
     *
     * @param string                $rawBody The raw request body (unused).
     * @param array<string, string> $headers Lower-cased headers (unused).
     * @param array<string, string> $query   Query parameters.
     * @param string                $secret  The shared secret.
     *
     * @return bool True when the query secret matches.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.4
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, array $query, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $provided = ($query['secret'] ?? '');
        if ($provided === '') {
            return false;
        }

        return hash_equals($secret, $provided);
    }//end verifyWebhookSignature()

    /**
     * Normalise an Asterisk JSON webhook payload.
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return CtiWebhookResult The normalised event.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.4
     */
    public function handleInboundWebhook(array $payload): CtiWebhookResult
    {
        $event = (string) ($payload['event'] ?? ($payload['Event'] ?? ''));

        $recording = [];
        if (is_array(($payload['recording'] ?? null)) === true) {
            $recording = $payload['recording'];
        }

        $eventType = $this->normaliseEventType(event: $event);

        $duration = null;
        if (isset($payload['duration']) === true) {
            $duration = (int) $payload['duration'];
        }

        return new CtiWebhookResult(
            eventType: $eventType,
            externalCallId: $this->stringOrNull(value: ($payload['callId'] ?? ($payload['Uniqueid'] ?? null))),
            fromNumber: $this->stringOrNull(value: ($payload['from'] ?? ($payload['CallerIDNum'] ?? null))),
            toNumber: $this->stringOrNull(value: ($payload['to'] ?? null)),
            extension: $this->stringOrNull(value: ($payload['extension'] ?? ($payload['Exten'] ?? null))),
            durationSeconds: $duration,
            recordingUrl: $this->stringOrNull(value: ($recording['url'] ?? null)),
            recordingExpiresAt: $this->stringOrNull(value: ($recording['expiresAt'] ?? null)),
            presenceState: $this->stringOrNull(value: ($payload['presenceState'] ?? null)),
            userId: $this->stringOrNull(value: ($payload['userId'] ?? null)),
        );
    }//end handleInboundWebhook()

    /**
     * Map a raw Asterisk event name to a normalised event type.
     *
     * @param string $event The raw event name.
     *
     * @return string The normalised event type.
     */
    private function normaliseEventType(string $event): string
    {
        $lowered = strtolower($event);
        if ($lowered === 'hangup') {
            return 'ended';
        }

        $known = ['ringing', 'answered', 'ended', 'abandoned', 'transferred', 'recording_ready', 'presence_changed'];
        if (in_array($lowered, $known, true) === true) {
            return $lowered;
        }

        if ($lowered === '') {
            return 'unknown';
        }

        return $lowered;
    }//end normaliseEventType()

    /**
     * Originate via the Asterisk ARI/AMI bridge REST endpoint.
     *
     * @param string $extension    The agent extension.
     * @param string $targetNumber The number to dial.
     * @param string $callerId     The caller ID to present.
     *
     * @return CtiCallResult The origination outcome.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.4
     */
    public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult
    {
        if ($this->apiBaseUrl === '') {
            return new CtiCallResult(success: false, message: 'Asterisk API base URL is not configured.');
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                $this->apiBaseUrl.'/originate',
                [
                    'json'    => [
                        'endpoint'  => 'PJSIP/'.$extension,
                        'extension' => $targetNumber,
                        'callerId'  => $callerId,
                    ],
                    'timeout' => 10,
                ]
            );

            $body   = json_decode((string) $response->getBody(), true);
            $callId = null;
            if (is_array($body) === true) {
                $callId = $this->stringOrNull(value: ($body['id'] ?? null));
            }

            return new CtiCallResult(success: true, externalCallId: $callId, message: 'Call originated.');
        } catch (Throwable $e) {
            $this->logger->warning('Asterisk originate failed', ['exception' => $e->getMessage()]);
            return new CtiCallResult(success: false, message: 'Origination failed.');
        }//end try
    }//end originateCall()
}//end class
