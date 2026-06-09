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
class RingCentralAdapter implements CtiAdapterInterface
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
     */
    public function getPlatform(): string
    {
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
     */
    public function handleInboundWebhook(array $payload): CtiWebhookResult
    {
        $body  = (array) ($payload['body'] ?? $payload);
        $party = ((array) ($body['parties'] ?? []))[0] ?? [];
        $party = (array) $party;

        $statusCode = strtolower((string) (($party['status']['code'] ?? '')));
        $eventType  = match ($statusCode) {
            'setup', 'proceeding' => 'ringing',
            'answered'            => 'answered',
            'disconnected'        => 'ended',
            'voicemail'           => 'abandoned',
            'parked'              => 'transferred',
            default               => ($statusCode !== '' ? $statusCode : 'unknown'),
        };

        $directionRaw = strtolower((string) ($party['direction'] ?? ''));
        $direction    = match ($directionRaw) {
            'inbound'  => 'inbound',
            'outbound' => 'outbound',
            default    => null,
        };

        return new CtiWebhookResult(
            eventType: $eventType,
            externalCallId: (string) ($body['telephonySessionId'] ?? ($body['sessionId'] ?? '')),
            direction: $direction,
            fromNumber: isset($party['from']['phoneNumber']) === true ? (string) $party['from']['phoneNumber'] : null,
            toNumber: isset($party['to']['phoneNumber']) === true ? (string) $party['to']['phoneNumber'] : null,
            extension: isset($party['extensionId']) === true ? (string) $party['extensionId'] : null,
            userId: isset($party['accountId']) === true ? (string) $party['accountId'] : null,
            durationSeconds: isset($body['duration']) === true ? (int) $body['duration'] : null,
            recordingUrl: isset($body['recording']['contentUri']) === true ? (string) $body['recording']['contentUri'] : null,
            recordingExpiresAt: isset($body['recording']['expirationTime']) === true ? (string) $body['recording']['expirationTime'] : null,
            presenceState: isset($body['presenceStatus']) === true ? (string) $body['presenceStatus'] : null,
            queueName: isset($body['queue']) === true ? (string) $body['queue'] : null,
            raw: $payload,
        );
    }//end handleInboundWebhook()

    /**
     * {@inheritDoc}
     *
     * Posts to the RingCentral "ring-out" endpoint with the OAuth bearer token.
     */
    public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult
    {
        $baseUrl    = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_api_base_url', '');
        $authToken  = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_access_token', '');
        if ($baseUrl === '') {
            return new CtiCallResult(
                success: false,
                error: 'RingCentral API base URL not configured.',
                platform: $this->getPlatform(),
            );
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                rtrim($baseUrl, '/').'/restapi/v1.0/account/~/extension/~/ring-out',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$authToken,
                        'Content-Type'  => 'application/json',
                    ],
                    'body'    => json_encode(
                        [
                            'from'    => ['phoneNumber' => $callerId],
                            'to'      => ['phoneNumber' => $targetNumber],
                            'caller'  => ['phoneNumber' => $callerId],
                            'playPrompt' => false,
                        ]
                    ),
                    'timeout' => 10,
                ]
            );

            $bodyContents = (string) $response->getBody();
            $body         = json_decode($bodyContents, true);
            $callId       = is_array($body) === true ? ($body['id'] ?? null) : null;

            return new CtiCallResult(
                success: true,
                externalCallId: $callId !== null ? (string) $callId : null,
                platform: $this->getPlatform(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'RingCentral originate failed',
                ['exception' => $e->getMessage()]
            );
            return new CtiCallResult(
                success: false,
                error: 'RingCentral originate failed: '.$e->getMessage(),
                platform: $this->getPlatform(),
            );
        }//end try
    }//end originateCall()

    /**
     * {@inheritDoc}
     *
     * RingCentral pushes presence events via the subscription stream; this method
     * issues the subscribe-extension event filter.
     */
    public function subscribeToPresence(string $userId, string $extension): void
    {
        $baseUrl   = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_api_base_url', '');
        $authToken = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_access_token', '');
        if ($baseUrl === '' || $authToken === '') {
            return;
        }

        try {
            $client = $this->clientService->newClient();
            $client->post(
                rtrim($baseUrl, '/').'/restapi/v1.0/subscription',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$authToken,
                        'Content-Type'  => 'application/json',
                    ],
                    'body'    => json_encode(
                        [
                            'eventFilters' => [
                                '/restapi/v1.0/account/~/extension/'.$extension.'/presence',
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
                    'userId'    => $userId,
                ]
            );
        }
    }//end subscribeToPresence()

    /**
     * {@inheritDoc}
     *
     * RingCentral webhook validation: the `Validation-Token` header must match
     * the configured OAuth access token (constant-time compare).
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expected = $this->appConfig->getValueString(Application::APP_ID, 'cti_ringcentral_webhook_token', '');
        if ($expected === '' || $signature === '') {
            return false;
        }

        return hash_equals($expected, $signature);
    }//end verifyWebhookSignature()
}//end class
