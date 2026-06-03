<?php

/**
 * Pipelinq TwilioClient.
 *
 * Twilio Programmable Messaging client (SMS + WhatsApp BSP fallback).
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
 * Twilio messaging client.
 *
 * Inbound webhooks are authenticated with Twilio's `X-Twilio-Signature`: a
 * base64 HMAC-SHA1 over the full request URL concatenated with the
 * alphabetically-sorted POST parameters (ADR-005). Twilio's status webhook
 * exposes `Price` / `PriceUnit`, captured into the delivery update for EUR
 * conversion (REQ-007).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — translates a rich vendor payload into DTOs
 * @SuppressWarnings(PHPMD.StaticAccess)           — SendResult exposes only named factories
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)   — defensive payload coercion per Twilio field
 * @spec                                           openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-3.2
 */
class TwilioClient implements ChannelProviderInterface
{
    use MessagingPayloadTrait;

    /**
     * The Twilio API base URL.
     *
     * @var string
     */
    private const API_BASE = 'https://api.twilio.com/2010-04-01';

    /**
     * The resolved account SID.
     *
     * @var string
     */
    private string $accountSid = '';

    /**
     * The resolved auth token (never persisted).
     *
     * @var string
     */
    private string $authToken = '';

    /**
     * The sender number / WhatsApp address.
     *
     * @var string
     */
    private string $from = '';

    /**
     * The configured webhook URL (used in signature validation).
     *
     * @var string
     */
    private string $webhookUrl = '';

    /**
     * Whether this provider instance is acting as a WhatsApp BSP.
     *
     * @var boolean
     */
    private bool $isWhatsApp = false;

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
        return 'twilio';
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
        $this->accountSid = (string) ($secrets['accountSid'] ?? '');
        $this->authToken  = (string) ($secrets['authToken'] ?? '');
        $this->webhookUrl = (string) ($secrets['webhookUrl'] ?? '');
        $this->from       = (string) ($config['phoneNumber'] ?? '');
        $this->isWhatsApp = (((string) ($config['kind'] ?? '')) === 'whatsapp-bsp');
    }//end configure()

    /**
     * Verify Twilio's `X-Twilio-Signature` over URL + sorted params (ADR-005).
     *
     * @param string                $rawBody The raw request body (unused; Twilio signs params).
     * @param array<string, string> $headers Lower-cased headers.
     * @param array<string, string> $query   The POST/query parameters.
     * @param string                $secret  The auth token Twilio signs with.
     *
     * @return bool True when the signature matches.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, array $query, string $secret): bool
    {
        if ($secret === '' || $this->webhookUrl === '') {
            return false;
        }

        $provided = ($headers['x-twilio-signature'] ?? '');
        if ($provided === '') {
            return false;
        }

        ksort($query);
        $data = $this->webhookUrl;
        foreach ($query as $key => $value) {
            $data .= $key.$value;
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $secret, true));

        return hash_equals($expected, $provided);
    }//end verifyWebhookSignature()

    /**
     * Parse a Twilio inbound SMS/WhatsApp webhook into a normalised message.
     *
     * @param array<string, mixed> $payload The decoded webhook body (form params).
     *
     * @return InboundMessage[] The normalised inbound messages (0 or 1).
     */
    public function parseInboundMessages(array $payload): array
    {
        $from = (string) ($payload['From'] ?? '');
        if ($from === '') {
            return [];
        }

        $media = [];
        $count = (int) ($payload['NumMedia'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $url = (string) ($payload['MediaUrl'.$i] ?? '');
            if ($url !== '') {
                $media[] = [
                    'url'      => $url,
                    'mimeType' => (string) ($payload['MediaContentType'.$i] ?? ''),
                    'filename' => 'twilio-media-'.$i,
                ];
            }
        }

        $channel = 'sms';
        if ($this->isWhatsApp === true) {
            $channel = 'whatsapp';
        }

        return [
            new InboundMessage(
                channel: $channel,
                fromNumber: $this->stripWhatsAppPrefix(value: $from),
                toNumber: $this->stripWhatsAppPrefix(value: (string) ($payload['To'] ?? '')),
                body: (string) ($payload['Body'] ?? ''),
                externalMessageId: $this->stringOrNull(value: ($payload['MessageSid'] ?? null)),
                media: $media,
            ),
        ];
    }//end parseInboundMessages()

    /**
     * Parse a Twilio status webhook into a normalised delivery update.
     *
     * @param array<string, mixed> $payload The decoded webhook body (form params).
     *
     * @return DeliveryUpdate[] The normalised delivery updates (0 or 1).
     */
    public function parseDeliveryUpdates(array $payload): array
    {
        $sid = $this->stringOrNull(value: ($payload['MessageSid'] ?? ($payload['SmsSid'] ?? null)));
        if ($sid === null) {
            return [];
        }

        $price    = $this->floatOrNull(value: ($payload['Price'] ?? null));
        $currency = $this->stringOrNull(value: ($payload['PriceUnit'] ?? null));
        // Twilio reports Price as a negative amount (a debit); store its magnitude.
        if ($price !== null) {
            $price = abs($price);
        }

        return [
            new DeliveryUpdate(
                externalMessageId: $sid,
                status: $this->mapStatus(status: (string) ($payload['MessageStatus'] ?? ($payload['SmsStatus'] ?? ''))),
                costAmount: $price,
                costCurrency: $currency,
            ),
        ];
    }//end parseDeliveryUpdates()

    /**
     * Send a template message. Twilio sends templates as body text with the
     * approved content; placeholders are substituted positionally.
     *
     * @param string             $toNumber     Recipient E.164 number.
     * @param string             $templateName The template identifier (unused by Twilio body send).
     * @param string             $language     BCP-47 language tag (unused).
     * @param array<int, string> $parameters   Positional placeholder values.
     *
     * @return SendResult The send outcome.
     */
    public function sendTemplate(string $toNumber, string $templateName, string $language, array $parameters): SendResult
    {
        $body  = $templateName;
        $index = 1;
        foreach ($parameters as $parameter) {
            $body .= ' '.$parameter;
            $index++;
        }

        return $this->sendFreeForm(toNumber: $toNumber, body: trim($body));
    }//end sendTemplate()

    /**
     * Send a free-form message via the Twilio Messages API.
     *
     * @param string             $toNumber Recipient E.164 number.
     * @param string             $body     The text body.
     * @param array<int, string> $mediaIds Optional media URLs to attach.
     *
     * @return SendResult The send outcome.
     */
    public function sendFreeForm(string $toNumber, string $body, array $mediaIds=[]): SendResult
    {
        if ($this->accountSid === '' || $this->authToken === '' || $this->from === '') {
            return SendResult::permanent(errorCode: 'provider_not_configured');
        }

        $prefix = '';
        if ($this->isWhatsApp === true) {
            $prefix = 'whatsapp:';
        }

        $form = [
            'To'   => $prefix.$toNumber,
            'From' => $prefix.$this->from,
            'Body' => $body,
        ];
        if ($mediaIds !== []) {
            $form['MediaUrl'] = (string) $mediaIds[0];
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                self::API_BASE.'/Accounts/'.rawurlencode($this->accountSid).'/Messages.json',
                [
                    'auth'    => [$this->accountSid, $this->authToken],
                    'body'    => $form,
                    'timeout' => 15,
                ]
            );

            $decoded = json_decode((string) $response->getBody(), true);
            $sid     = null;
            if (is_array($decoded) === true) {
                $sid = $this->stringOrNull(value: ($decoded['sid'] ?? null));
            }

            return SendResult::ok(externalMessageId: $sid);
        } catch (Throwable $e) {
            $this->logger->warning('Twilio send failed', ['exception' => $e->getMessage()]);
            $code = (int) $e->getCode();
            if ($code === 0 || $this->isTransientStatus(statusCode: $code) === true) {
                return SendResult::transient(errorCode: 'provider_transient');
            }

            return SendResult::permanent(errorCode: 'provider_rejected');
        }//end try
    }//end sendFreeForm()

    /**
     * Strip Twilio's `whatsapp:` address prefix when present.
     *
     * @param string $value The raw address.
     *
     * @return string The bare number.
     */
    private function stripWhatsAppPrefix(string $value): string
    {
        if (str_starts_with($value, 'whatsapp:') === true) {
            return substr($value, strlen('whatsapp:'));
        }

        return $value;
    }//end stripWhatsAppPrefix()

    /**
     * Map a Twilio status string to the internal delivery-status enum.
     *
     * @param string $status The Twilio status.
     *
     * @return string The normalised status.
     */
    private function mapStatus(string $status): string
    {
        $map = [
            'queued'      => 'queued',
            'sending'     => 'queued',
            'sent'        => 'sent',
            'delivered'   => 'delivered',
            'read'        => 'read',
            'undelivered' => 'failed',
            'failed'      => 'failed',
        ];

        return ($map[strtolower($status)] ?? 'queued');
    }//end mapStatus()
}//end class
