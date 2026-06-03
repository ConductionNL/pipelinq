<?php

/**
 * Pipelinq RingCentralAdapter.
 *
 * CTI adapter for the RingCentral telephony platform (international).
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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.3
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
 * RingCentral adapter.
 *
 * Inbound webhooks carry a verification token: RingCentral echoes the
 * subscription's `Validation-Token`, which is compared in constant time
 * against the configured secret (REQ-CTI-007). Outbound origination uses the
 * RingCentral RingOut ("click-to-call") endpoint with a bearer token.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.3
 */
class RingCentralAdapter implements CtiAdapterInterface
{
    use CtiPayloadTrait;

    /**
     * Constructor.
     *
     * @param IClientService  $clientService The HTTP client service.
     * @param LoggerInterface $logger        The logger.
     * @param string          $apiBaseUrl    The configured platform API base URL.
     * @param string          $accessToken   The OAuth bearer token for outbound calls.
     */
    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
        private string $apiBaseUrl='',
        private string $accessToken='',
    ) {
    }//end __construct()

    /**
     * The platform slug this adapter handles.
     *
     * @return string The platform slug.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.3
     */
    public function getPlatform(): string
    {
        return 'ringcentral';
    }//end getPlatform()

    /**
     * Set the API base URL.
     *
     * @param string $apiBaseUrl The base URL.
     *
     * @return void
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.3
     */
    public function setApiBaseUrl(string $apiBaseUrl): void
    {
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');
    }//end setApiBaseUrl()

    /**
     * Set the OAuth bearer token used for outbound API calls.
     *
     * @param string $accessToken The bearer token.
     *
     * @return void
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.3
     */
    public function setAccessToken(string $accessToken): void
    {
        $this->accessToken = $accessToken;
    }//end setAccessToken()

    /**
     * Verify the RingCentral validation token (REQ-CTI-007).
     *
     * @param string                $rawBody The raw request body (unused).
     * @param array<string, string> $headers Lower-cased headers.
     * @param array<string, string> $query   Query parameters (unused).
     * @param string                $secret  The configured verification token.
     *
     * @return bool True when the validation token matches.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.3
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, array $query, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $provided = ($headers['validation-token'] ?? ($headers['authorization'] ?? ''));
        if ($provided === '') {
            return false;
        }

        // Accept either a raw token or a "Bearer <token>" Authorization header.
        $provided = (string) preg_replace('/^Bearer\s+/i', '', $provided);

        return hash_equals($secret, $provided);
    }//end verifyWebhookSignature()

    /**
     * Normalise a RingCentral telephony webhook payload.
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return CtiWebhookResult The normalised event.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.3
     */
    public function handleInboundWebhook(array $payload): CtiWebhookResult
    {
        // RingCentral nests telephony status under body; accept a flattened
        // shape too for resilience.
        $body = $payload;
        if (is_array(($payload['body'] ?? null)) === true) {
            $body = $payload['body'];
        }

        $status = (string) ($body['telephonyStatus'] ?? ($payload['event'] ?? ''));

        $recording = [];
        if (is_array(($body['recording'] ?? null)) === true) {
            $recording = $body['recording'];
        }

        $eventType = $this->normaliseEventType(status: $status);

        $duration = null;
        if (isset($body['duration']) === true) {
            $duration = (int) $body['duration'];
        }

        return new CtiWebhookResult(
            eventType: $eventType,
            externalCallId: $this->stringOrNull(value: ($body['sessionId'] ?? ($body['callId'] ?? null))),
            fromNumber: $this->stringOrNull(value: ($body['from'] ?? null)),
            toNumber: $this->stringOrNull(value: ($body['to'] ?? null)),
            extension: $this->stringOrNull(value: ($body['extensionId'] ?? ($body['extension'] ?? null))),
            durationSeconds: $duration,
            recordingUrl: $this->stringOrNull(value: ($recording['contentUri'] ?? ($recording['url'] ?? null))),
            recordingExpiresAt: $this->stringOrNull(value: ($recording['expiresAt'] ?? null)),
            presenceState: $this->stringOrNull(value: ($body['presenceState'] ?? null)),
            userId: $this->stringOrNull(value: ($body['userId'] ?? null)),
        );
    }//end handleInboundWebhook()

    /**
     * Map a raw RingCentral telephony status to a normalised event type.
     *
     * @param string $status The raw telephony status.
     *
     * @return string The normalised event type.
     */
    private function normaliseEventType(string $status): string
    {
        $lowered = strtolower($status);

        if ($lowered === 'callconnected') {
            return 'answered';
        }

        if ($lowered === 'nocall') {
            return 'ended';
        }

        $known = ['ringing', 'answered', 'ended', 'abandoned', 'recording_ready', 'presence_changed'];
        if (in_array($lowered, $known, true) === true) {
            return $lowered;
        }

        if ($lowered === '') {
            return 'unknown';
        }

        return $lowered;
    }//end normaliseEventType()

    /**
     * Originate via the RingCentral RingOut endpoint.
     *
     * @param string $extension    The agent extension.
     * @param string $targetNumber The number to dial.
     * @param string $callerId     The caller ID to present.
     *
     * @return CtiCallResult The origination outcome.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.3
     */
    public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult
    {
        if ($this->apiBaseUrl === '' || $this->accessToken === '') {
            return new CtiCallResult(success: false, message: 'RingCentral API URL or access token is not configured.');
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                $this->apiBaseUrl.'/restapi/v1.0/account/~/extension/'.$extension.'/ring-out',
                [
                    'headers' => ['Authorization' => 'Bearer '.$this->accessToken],
                    'json'    => [
                        'from'       => ['phoneNumber' => $callerId],
                        'to'         => ['phoneNumber' => $targetNumber],
                        'playPrompt' => false,
                    ],
                    'timeout' => 10,
                ]
            );

            $decoded = json_decode((string) $response->getBody(), true);
            $callId  = null;
            if (is_array($decoded) === true) {
                $callId = $this->stringOrNull(value: ($decoded['id'] ?? null));
            }

            return new CtiCallResult(success: true, externalCallId: $callId, message: 'Call originated.');
        } catch (Throwable $e) {
            $this->logger->warning('RingCentral originate failed', ['exception' => $e->getMessage()]);
            return new CtiCallResult(success: false, message: 'Origination failed.');
        }//end try
    }//end originateCall()
}//end class
