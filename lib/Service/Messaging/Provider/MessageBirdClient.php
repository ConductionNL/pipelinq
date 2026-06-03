<?php

/**
 * Pipelinq MessageBirdClient.
 *
 * MessageBird (Bird) SMS client with EU data residency.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-3.2
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
 * MessageBird SMS client.
 *
 * Inbound and status webhooks are authenticated with a shared signing key over
 * the raw body via HMAC-SHA256, supplied in the `messagebird-signature` header
 * (ADR-005). Outbound sends post to the REST `/messages` endpoint with an
 * `AccessKey` authorization header.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — translates a vendor payload into DTOs
 * @SuppressWarnings(PHPMD.StaticAccess)           — SendResult exposes only named factories
 * @spec                                           openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-3.2
 */
class MessageBirdClient implements ChannelProviderInterface
{
    use MessagingPayloadTrait;

    /**
     * The MessageBird REST API base URL.
     *
     * @var string
     */
    private const API_BASE = 'https://rest.messagebird.com';

    /**
     * The resolved access key (never persisted).
     *
     * @var string
     */
    private string $accessKey = '';

    /**
     * The originator (sender) shown to the recipient.
     *
     * @var string
     */
    private string $originator = '';

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
        return 'messagebird';
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
        $this->accessKey  = (string) ($secrets['accessKey'] ?? '');
        $this->originator = (string) ($config['phoneNumber'] ?? '');
    }//end configure()

    /**
     * Verify the MessageBird HMAC-SHA256 signature over the raw body (ADR-005).
     *
     * @param string                $rawBody The raw request body.
     * @param array<string, string> $headers Lower-cased headers.
     * @param array<string, string> $query   Query parameters.
     * @param string                $secret  The webhook signing key.
     *
     * @return bool True when the signature matches.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, array $query, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $provided = ($headers['messagebird-signature'] ?? '');
        if ($provided === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($expected, $provided);
    }//end verifyWebhookSignature()

    /**
     * Parse a MessageBird inbound MO webhook into a normalised message.
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return InboundMessage[] The normalised inbound messages (0 or 1).
     */
    public function parseInboundMessages(array $payload): array
    {
        $from = (string) ($payload['originator'] ?? ($payload['msisdn'] ?? ''));
        if ($from === '') {
            return [];
        }

        return [
            new InboundMessage(
                channel: 'sms',
                fromNumber: $from,
                toNumber: (string) ($payload['recipient'] ?? ''),
                body: (string) ($payload['payload'] ?? ($payload['body'] ?? '')),
                externalMessageId: $this->stringOrNull(value: ($payload['id'] ?? null)),
            ),
        ];
    }//end parseInboundMessages()

    /**
     * Parse a MessageBird status webhook into a normalised delivery update.
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return DeliveryUpdate[] The normalised delivery updates (0 or 1).
     */
    public function parseDeliveryUpdates(array $payload): array
    {
        $id = $this->stringOrNull(value: ($payload['id'] ?? null));
        if ($id === null) {
            return [];
        }

        $price    = null;
        $currency = null;
        if (is_array(($payload['price'] ?? null)) === true) {
            $price    = $this->floatOrNull(value: ($payload['price']['amount'] ?? null));
            $currency = $this->stringOrNull(value: ($payload['price']['currency'] ?? null));
        }

        return [
            new DeliveryUpdate(
                externalMessageId: $id,
                status: $this->mapStatus(status: (string) ($payload['status'] ?? '')),
                costAmount: $price,
                costCurrency: $currency,
            ),
        ];
    }//end parseDeliveryUpdates()

    /**
     * Send a template as a plain SMS body (SMS has no HSM concept).
     *
     * @param string             $toNumber     Recipient E.164 number.
     * @param string             $templateName The resolved body text.
     * @param string             $language     BCP-47 language tag (unused).
     * @param array<int, string> $parameters   Positional placeholder values.
     *
     * @return SendResult The send outcome.
     */
    public function sendTemplate(string $toNumber, string $templateName, string $language, array $parameters): SendResult
    {
        $body = trim($templateName.' '.implode(' ', $parameters));

        return $this->sendFreeForm(toNumber: $toNumber, body: $body);
    }//end sendTemplate()

    /**
     * Send a free-form SMS via the MessageBird REST API.
     *
     * @param string             $toNumber Recipient E.164 number.
     * @param string             $body     The text body.
     * @param array<int, string> $mediaIds Ignored (SMS has no media).
     *
     * @return SendResult The send outcome.
     */
    public function sendFreeForm(string $toNumber, string $body, array $mediaIds=[]): SendResult
    {
        if ($this->accessKey === '' || $this->originator === '') {
            return SendResult::permanent(errorCode: 'provider_not_configured');
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                self::API_BASE.'/messages',
                [
                    'headers' => ['Authorization' => 'AccessKey '.$this->accessKey],
                    'json'    => [
                        'originator' => $this->originator,
                        'recipients' => [$toNumber],
                        'body'       => $body,
                    ],
                    'timeout' => 15,
                ]
            );

            $decoded = json_decode((string) $response->getBody(), true);
            $id      = null;
            if (is_array($decoded) === true) {
                $id = $this->stringOrNull(value: ($decoded['id'] ?? null));
            }

            return SendResult::ok(externalMessageId: $id);
        } catch (Throwable $e) {
            $this->logger->warning('MessageBird send failed', ['exception' => $e->getMessage()]);
            $code = (int) $e->getCode();
            if ($code === 0 || $this->isTransientStatus(statusCode: $code) === true) {
                return SendResult::transient(errorCode: 'provider_transient');
            }

            return SendResult::permanent(errorCode: 'provider_rejected');
        }//end try
    }//end sendFreeForm()

    /**
     * Map a MessageBird status string to the internal delivery-status enum.
     *
     * @param string $status The MessageBird status.
     *
     * @return string The normalised status.
     */
    private function mapStatus(string $status): string
    {
        $map = [
            'scheduled'       => 'queued',
            'buffered'        => 'queued',
            'sent'            => 'sent',
            'delivered'       => 'delivered',
            'delivery_failed' => 'failed',
            'expired'         => 'expired',
        ];

        return ($map[strtolower($status)] ?? 'queued');
    }//end mapStatus()
}//end class
