<?php

/**
 * Pipelinq CmComClient.
 *
 * CM.com Business Messaging SMS client (Dutch provider).
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
 * CM.com SMS client.
 *
 * Inbound and status webhooks are authenticated with a shared HMAC-SHA256 token
 * over the raw body in the `x-cm-signature` header (ADR-005). Outbound sends
 * post to the CM messaging gateway with the product-token in the body.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — translates a vendor payload into DTOs
 * @SuppressWarnings(PHPMD.StaticAccess)           — SendResult exposes only named factories
 * @spec                                           openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-3.2
 */
class CmComClient implements ChannelProviderInterface
{
    use MessagingPayloadTrait;

    /**
     * The CM.com gateway base URL.
     *
     * @var string
     */
    private const API_BASE = 'https://gw.cmtelecom.com';

    /**
     * The resolved product token (never persisted).
     *
     * @var string
     */
    private string $productToken = '';

    /**
     * The sender shown to the recipient.
     *
     * @var string
     */
    private string $from = '';

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
        return 'cm-com';
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
        $this->productToken = (string) ($secrets['apiKey'] ?? ($secrets['productToken'] ?? ''));
        $this->from         = (string) ($config['phoneNumber'] ?? 'Pipelinq');
    }//end configure()

    /**
     * Verify the CM.com HMAC-SHA256 signature over the raw body (ADR-005).
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

        $provided = ($headers['x-cm-signature'] ?? '');
        if ($provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, strtolower($provided));
    }//end verifyWebhookSignature()

    /**
     * Parse a CM.com inbound MO webhook into a normalised message.
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return InboundMessage[] The normalised inbound messages (0 or 1).
     */
    public function parseInboundMessages(array $payload): array
    {
        $from = (string) ($payload['from'] ?? '');
        if ($from === '') {
            return [];
        }

        return [
            new InboundMessage(
                channel: 'sms',
                fromNumber: $from,
                toNumber: (string) ($payload['to'] ?? ''),
                body: (string) ($payload['message'] ?? ($payload['body'] ?? '')),
                externalMessageId: $this->stringOrNull(value: ($payload['messageId'] ?? ($payload['reference'] ?? null))),
            ),
        ];
    }//end parseInboundMessages()

    /**
     * Parse a CM.com status webhook into a normalised delivery update.
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return DeliveryUpdate[] The normalised delivery updates (0 or 1).
     */
    public function parseDeliveryUpdates(array $payload): array
    {
        $id = $this->stringOrNull(value: ($payload['reference'] ?? ($payload['messageId'] ?? null)));
        if ($id === null) {
            return [];
        }

        return [
            new DeliveryUpdate(
                externalMessageId: $id,
                status: $this->mapStatus(status: (string) ($payload['status'] ?? '')),
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
     * Send a free-form SMS via the CM.com gateway.
     *
     * @param string             $toNumber Recipient E.164 number.
     * @param string             $body     The text body.
     * @param array<int, string> $mediaIds Ignored (SMS has no media).
     *
     * @return SendResult The send outcome.
     */
    public function sendFreeForm(string $toNumber, string $body, array $mediaIds=[]): SendResult
    {
        if ($this->productToken === '') {
            return SendResult::permanent(errorCode: 'provider_not_configured');
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                self::API_BASE.'/v1.0/message',
                [
                    'json'    => [
                        'messages' => [
                            'authentication' => ['producttoken' => $this->productToken],
                            'msg'            => [
                                [
                                    'from' => $this->from,
                                    'to'   => [['number' => $toNumber]],
                                    'body' => ['content' => $body],
                                ],
                            ],
                        ],
                    ],
                    'timeout' => 15,
                ]
            );

            $decoded   = json_decode((string) $response->getBody(), true);
            $reference = null;
            if (is_array($decoded) === true && is_array(($decoded['messages'][0] ?? null)) === true) {
                $reference = $this->stringOrNull(value: ($decoded['messages'][0]['reference'] ?? null));
            }

            return SendResult::ok(externalMessageId: $reference);
        } catch (Throwable $e) {
            $this->logger->warning('CM.com send failed', ['exception' => $e->getMessage()]);
            $code = (int) $e->getCode();
            if ($code === 0 || $this->isTransientStatus(statusCode: $code) === true) {
                return SendResult::transient(errorCode: 'provider_transient');
            }

            return SendResult::permanent(errorCode: 'provider_rejected');
        }//end try
    }//end sendFreeForm()

    /**
     * Map a CM.com status string to the internal delivery-status enum.
     *
     * @param string $status The CM.com status.
     *
     * @return string The normalised status.
     */
    private function mapStatus(string $status): string
    {
        $map = [
            'accepted'  => 'sent',
            'sent'      => 'sent',
            'delivered' => 'delivered',
            'failed'    => 'failed',
            'expired'   => 'expired',
        ];

        return ($map[strtolower($status)] ?? 'queued');
    }//end mapStatus()
}//end class
