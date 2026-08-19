<?php

/**
 * Pipelinq MessageBirdSmsClient.
 *
 * MessageBird SMS provider implementation (now Bird.com). Delegates
 * actual HTTP transport to openconnector's SourceService.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Provider
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Provider;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * MessageBirdSmsClient — MessageBird SMS via the OpenRegister dispatch leaf.
 *
 * Vendor key: `messagebird`. Webhook signatures use MessageBird's
 * static-secret HMAC scheme (the `messagebird-signature` header is
 * an HMAC-SHA256 of the body using the channelProvider.webhookSecret).
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.2
 */
class MessageBirdSmsClient implements SmsProviderClientInterface
{
    use MessageDispatchTrait;

    /**
     * MessageBird messages send path, relative to the source base URL.
     *
     * @var string
     */
    private const SEND_PATH = 'messages';

    /**
     * Constructor.
     *
     * @param ContainerInterface   $container     DI container.
     * @param LoggerInterface      $logger        Logger.
     * @param array<string, mixed> $credentials   Decoded credentials.
     * @param string               $fromNumber    Sender E.164.
     * @param string               $webhookSecret Shared HMAC secret.
     * @param string|null          $sourceId      openconnector source id.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.2
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private array $credentials,
        private string $fromNumber,
        private string $webhookSecret,
        private ?string $sourceId=null,
    ) {
    }//end __construct()

    /**
     * Send a single SMS via MessageBird.
     *
     * @param string $toNumber Recipient E.164.
     * @param string $body     Plain-text body.
     *
     * @return array{externalMessageId: string, vendor: string} Provider id.
     *
     * @throws TransientSmsProviderException On 5xx / network.
     * @throws PermanentSmsProviderException On 4xx / config.
     *
     * @spec openspec/changes/archive/2026-06-21-pipelinq-messaging-via-or-leaf/tasks.md#1.2
     */
    public function send(string $toNumber, string $body): array
    {
        $payload = [
            'originator' => $this->fromNumber,
            'recipients' => [$toNumber],
            'body'       => $body,
        ];

        $result = $this->dispatchViaLeaf(
            source: (string) ($this->sourceId ?? ''),
            body: $payload,
            path: self::SEND_PATH,
        );
        $id     = (string) ($result['id'] ?? ($result['externalMessageId'] ?? ''));

        return [
            'externalMessageId' => $id,
            'vendor'            => $this->getVendor(),
        ];
    }//end send()

    /**
     * Verify the messagebird-signature header against the raw body.
     *
     * @param string $rawBody   Raw body.
     * @param string $signature Header.
     *
     * @return bool True when authentic.
     */
    public function verifySignature(string $rawBody, string $signature): bool
    {
        if ($this->webhookSecret === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);
        return hash_equals($expected, $signature);
    }//end verifySignature()

    /**
     * Vendor key.
     *
     * @return string `messagebird`.
     */
    public function getVendor(): string
    {
        return 'messagebird';
    }//end getVendor()
}//end class
