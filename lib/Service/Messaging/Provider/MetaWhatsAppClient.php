<?php

/**
 * Pipelinq MetaWhatsAppClient.
 *
 * WhatsApp provider client speaking the Meta Cloud API directly.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Messaging\Provider
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Messaging\Provider;

use OCA\Pipelinq\Service\Messaging\ChannelProviderInterface;
use OCA\Pipelinq\Service\Messaging\DeliveryUpdate;
use OCA\Pipelinq\Service\Messaging\InboundMessage;
use OCA\Pipelinq\Service\Messaging\MessagingPayloadTrait;
use OCA\Pipelinq\Service\Messaging\SendResult;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Meta WhatsApp Cloud API client.
 *
 * Inbound webhooks are authenticated with an `X-Hub-Signature-256` HMAC over
 * the raw request body (ADR-005). Outbound sends post to the Graph API
 * `{phoneNumberId}/messages` endpoint. Meta does not expose per-message cost in
 * its webhooks, so {@see parseDeliveryUpdates()} never sets a cost; the
 * orchestrator estimates it from the price table (REQ-007).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   — translates a rich vendor payload into DTOs
 * @SuppressWarnings(PHPMD.StaticAccess)             — SendResult exposes only named factories
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) — vendor payload mapping is inherently branchy
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)     — defensive payload coercion per Meta field
 * @spec                                             openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.5
 */
class MetaWhatsAppClient implements ChannelProviderInterface
{
    use MessagingPayloadTrait;

    /**
     * The Graph API base URL.
     *
     * @var string
     */
    private const GRAPH_BASE = 'https://graph.facebook.com/v19.0';

    /**
     * The resolved phone-number id used in the send URL.
     *
     * @var string
     */
    private string $phoneNumberId = '';

    /**
     * The resolved WhatsApp Business Account id (for template listing).
     *
     * @var string
     */
    private string $wabaId = '';

    /**
     * The resolved system-user bearer token (never persisted).
     *
     * @var string
     */
    private string $accessToken = '';

    /**
     * Constructor.
     *
     * @param IClientService  $clientService The HTTP client service.
     * @param LoggerInterface $logger        The logger.
     */
    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * The vendor slug this client handles.
     *
     * @return string The vendor slug.
     */
    public function getVendor(): string
    {
        return 'meta';
    }//end getVendor()

    /**
     * Configure the client with the resolved provider config + secrets.
     *
     * @param array<string, mixed>  $config  The channelProvider object (no secrets).
     * @param array<string, string> $secrets The resolved secret material.
     *
     * @return void
     */
    public function configure(array $config, array $secrets): void
    {
        $this->phoneNumberId = (string) ($secrets['phoneNumberId'] ?? '');
        $this->wabaId        = (string) ($secrets['wabaId'] ?? '');
        $this->accessToken   = (string) ($secrets['systemUserToken'] ?? '');
    }//end configure()

    /**
     * Fetch the current approval status of each template from the Graph API.
     *
     * Returns a map of template name => provider status (e.g. APPROVED,
     * REJECTED, DISABLED). An empty map is returned when the client is not
     * configured or the call fails, so the caller treats the sync as a no-op.
     *
     * @return array<string, string> The template-status map.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.8
     */
    public function fetchTemplateStatuses(): array
    {
        if ($this->wabaId === '' || $this->accessToken === '') {
            return [];
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                self::GRAPH_BASE.'/'.rawurlencode($this->wabaId).'/message_templates',
                [
                    'headers' => ['Authorization' => 'Bearer '.$this->accessToken],
                    'query'   => ['fields' => 'name,status', 'limit' => 200],
                    'timeout' => 20,
                ]
            );

            $decoded = json_decode((string) $response->getBody(), true);
        } catch (Throwable $e) {
            $this->logger->warning('Meta template status fetch failed', ['exception' => $e->getMessage()]);
            return [];
        }

        if (is_array($decoded) === false || is_array(($decoded['data'] ?? null)) === false) {
            return [];
        }

        $statuses = [];
        foreach ($decoded['data'] as $template) {
            if (is_array($template) === false) {
                continue;
            }

            $name   = (string) ($template['name'] ?? '');
            $status = (string) ($template['status'] ?? '');
            if ($name !== '' && $status !== '') {
                $statuses[$name] = $status;
            }
        }

        return $statuses;
    }//end fetchTemplateStatuses()

    /**
     * Verify Meta's `X-Hub-Signature-256` HMAC over the raw body (ADR-005).
     *
     * @param string                $rawBody The raw request body.
     * @param array<string, string> $headers Lower-cased headers.
     * @param array<string, string> $query   Query parameters.
     * @param string                $secret  The app secret used by Meta to sign.
     *
     * @return bool True when the signature matches.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, array $query, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $provided = ($headers['x-hub-signature-256'] ?? '');
        if (str_starts_with($provided, 'sha256=') === false) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $provided);
    }//end verifyWebhookSignature()

    /**
     * Parse Meta `messages` webhook entries into normalised inbound messages.
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return InboundMessage[] The normalised inbound messages.
     */
    public function parseInboundMessages(array $payload): array
    {
        $messages = [];
        foreach ($this->valueObjects(payload: $payload) as $value) {
            $contact = '';
            if (is_array(($value['contacts'][0] ?? null)) === true) {
                $contact = (string) ($value['contacts'][0]['wa_id'] ?? '');
            }

            $metadata = [];
            if (is_array(($value['metadata'] ?? null)) === true) {
                $metadata = $value['metadata'];
            }

            $to = (string) ($metadata['display_phone_number'] ?? '');

            $rawMessages = ($value['messages'] ?? null);
            if (is_array($rawMessages) === false) {
                continue;
            }

            foreach ($rawMessages as $rawMessage) {
                if (is_array($rawMessage) === false) {
                    continue;
                }

                $messages[] = $this->mapInbound(rawMessage: $rawMessage, contact: $contact, to: $to);
            }
        }//end foreach

        return $messages;
    }//end parseInboundMessages()

    /**
     * Parse Meta `statuses` webhook entries into normalised delivery updates.
     *
     * Meta does not expose per-message cost, so cost is never set here.
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return DeliveryUpdate[] The normalised delivery updates.
     */
    public function parseDeliveryUpdates(array $payload): array
    {
        $updates = [];
        foreach ($this->valueObjects(payload: $payload) as $value) {
            $statuses = ($value['statuses'] ?? null);
            if (is_array($statuses) === false) {
                continue;
            }

            foreach ($statuses as $status) {
                if (is_array($status) === false) {
                    continue;
                }

                $id = $this->stringOrNull(value: ($status['id'] ?? null));
                if ($id === null) {
                    continue;
                }

                $updates[] = new DeliveryUpdate(
                    externalMessageId: $id,
                    status: $this->mapStatus(status: (string) ($status['status'] ?? '')),
                );
            }
        }//end foreach

        return $updates;
    }//end parseDeliveryUpdates()

    /**
     * Send an approved template message via the Graph API.
     *
     * @param string             $toNumber     Recipient E.164 number.
     * @param string             $templateName The Meta template name.
     * @param string             $language     BCP-47 language tag.
     * @param array<int, string> $parameters   Positional placeholder values.
     *
     * @return SendResult The send outcome.
     */
    public function sendTemplate(string $toNumber, string $templateName, string $language, array $parameters): SendResult
    {
        $components = [];
        if ($parameters !== []) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    static fn(string $param): array => ['type' => 'text', 'text' => $param],
                    array_values($parameters)
                ),
            ];
        }

        return $this->post(
            body: [
                'messaging_product' => 'whatsapp',
                'to'                => $toNumber,
                'type'              => 'template',
                'template'          => [
                    'name'       => $templateName,
                    'language'   => ['code' => $language],
                    'components' => $components,
                ],
            ]
        );
    }//end sendTemplate()

    /**
     * Send a free-form (session) text message, optionally with media.
     *
     * @param string             $toNumber Recipient E.164 number.
     * @param string             $body     The text body.
     * @param array<int, string> $mediaIds Optional Meta media ids to attach.
     *
     * @return SendResult The send outcome.
     */
    public function sendFreeForm(string $toNumber, string $body, array $mediaIds=[]): SendResult
    {
        if ($mediaIds !== []) {
            return $this->post(
                body: [
                    'messaging_product' => 'whatsapp',
                    'to'                => $toNumber,
                    'type'              => 'document',
                    'document'          => ['id' => (string) $mediaIds[0], 'caption' => $body],
                ]
            );
        }

        return $this->post(
            body: [
                'messaging_product' => 'whatsapp',
                'to'                => $toNumber,
                'type'              => 'text',
                'text'              => ['body' => $body],
            ]
        );
    }//end sendFreeForm()

    /**
     * Download an inbound media item's bytes from Meta within the expiry window.
     *
     * Two-step: resolve the media URL from the media id, then GET the bytes with
     * the bearer token (REQ-008).
     *
     * @param string $mediaId The Meta media id.
     *
     * @return string|null The raw bytes, or null on failure.
     */
    public function downloadMedia(string $mediaId): ?string
    {
        if ($this->accessToken === '' || $mediaId === '') {
            return null;
        }

        try {
            $client   = $this->clientService->newClient();
            $metaResp = $client->get(
                self::GRAPH_BASE.'/'.rawurlencode($mediaId),
                ['headers' => ['Authorization' => 'Bearer '.$this->accessToken], 'timeout' => 30]
            );
            $meta     = json_decode((string) $metaResp->getBody(), true);
            $url      = '';
            if (is_array($meta) === true) {
                $url = (string) ($meta['url'] ?? '');
            }

            if ($url === '') {
                return null;
            }

            $binResp = $client->get(
                $url,
                ['headers' => ['Authorization' => 'Bearer '.$this->accessToken], 'timeout' => 60]
            );

            return (string) $binResp->getBody();
        } catch (Throwable $e) {
            $this->logger->warning('Meta media download failed', ['exception' => $e->getMessage()]);
            return null;
        }//end try
    }//end downloadMedia()

    /**
     * Extract the `changes[].value` objects from a Meta webhook payload.
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return array<int, array<string, mixed>> The value objects.
     */
    private function valueObjects(array $payload): array
    {
        $values  = [];
        $entries = ($payload['entry'] ?? null);
        if (is_array($entries) === false) {
            return [];
        }

        foreach ($entries as $entry) {
            $changes = ($entry['changes'] ?? null);
            if (is_array($changes) === false) {
                continue;
            }

            foreach ($changes as $change) {
                if (is_array(($change['value'] ?? null)) === true) {
                    $values[] = $change['value'];
                }
            }
        }

        return $values;
    }//end valueObjects()

    /**
     * Map a single raw Meta message into a normalised inbound message.
     *
     * @param array<string, mixed> $rawMessage The raw message object.
     * @param string               $contact    The sender wa_id.
     * @param string               $to         The tenant display number.
     *
     * @return InboundMessage The normalised inbound message.
     */
    private function mapInbound(array $rawMessage, string $contact, string $to): InboundMessage
    {
        $type = (string) ($rawMessage['type'] ?? 'text');
        $body = '';
        if (is_array(($rawMessage['text'] ?? null)) === true) {
            $body = (string) ($rawMessage['text']['body'] ?? '');
        }

        $media = [];
        foreach (['image', 'document', 'audio', 'video'] as $mediaType) {
            if (is_array(($rawMessage[$mediaType] ?? null)) === true) {
                $media[] = [
                    'id'       => (string) ($rawMessage[$mediaType]['id'] ?? ''),
                    'mimeType' => (string) ($rawMessage[$mediaType]['mime_type'] ?? ''),
                    'filename' => (string) ($rawMessage[$mediaType]['filename'] ?? ($mediaType.'-'.$type)),
                ];
            }
        }

        $from = (string) ($rawMessage['from'] ?? $contact);

        return new InboundMessage(
            channel: 'whatsapp',
            fromNumber: $from,
            toNumber: $to,
            body: $body,
            externalMessageId: $this->stringOrNull(value: ($rawMessage['id'] ?? null)),
            media: $media,
            timestamp: $this->stringOrNull(value: ($rawMessage['timestamp'] ?? null)),
        );
    }//end mapInbound()

    /**
     * Map a Meta status string to the internal delivery-status enum.
     *
     * @param string $status The Meta status.
     *
     * @return string The normalised status.
     */
    private function mapStatus(string $status): string
    {
        $map = [
            'sent'      => 'sent',
            'delivered' => 'delivered',
            'read'      => 'read',
            'failed'    => 'failed',
        ];

        return ($map[$status] ?? 'queued');
    }//end mapStatus()

    /**
     * POST a message body to the Graph API and classify the outcome.
     *
     * @param array<string, mixed> $body The send body.
     *
     * @return SendResult The send outcome.
     */
    private function post(array $body): SendResult
    {
        if ($this->phoneNumberId === '' || $this->accessToken === '') {
            return SendResult::permanent(errorCode: 'provider_not_configured');
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                self::GRAPH_BASE.'/'.rawurlencode($this->phoneNumberId).'/messages',
                [
                    'headers' => ['Authorization' => 'Bearer '.$this->accessToken],
                    'json'    => $body,
                    'timeout' => 15,
                ]
            );

            $decoded = json_decode((string) $response->getBody(), true);
            $id      = null;
            if (is_array($decoded) === true && is_array(($decoded['messages'][0] ?? null)) === true) {
                $id = $this->stringOrNull(value: ($decoded['messages'][0]['id'] ?? null));
            }

            return SendResult::ok(externalMessageId: $id);
        } catch (Throwable $e) {
            return $this->classifyException(exception: $e);
        }//end try
    }//end post()

    /**
     * Classify a thrown HTTP exception into a transient or permanent failure.
     *
     * @param Throwable $exception The thrown exception.
     *
     * @return SendResult The classified failure result.
     */
    private function classifyException(Throwable $exception): SendResult
    {
        $this->logger->warning('Meta send failed', ['exception' => $exception->getMessage()]);
        $code = (int) $exception->getCode();
        if ($code === 0 || $this->isTransientStatus(statusCode: $code) === true) {
            return SendResult::transient(errorCode: 'provider_transient');
        }

        return SendResult::permanent(errorCode: 'provider_rejected');
    }//end classifyException()
}//end class
