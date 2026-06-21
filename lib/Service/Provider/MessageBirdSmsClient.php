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
use Throwable;

/**
 * MessageBirdSmsClient — MessageBird SMS via openconnector.
 *
 * Vendor key: `messagebird`. Webhook signatures use MessageBird's
 * static-secret HMAC scheme (the `messagebird-signature` header is
 * an HMAC-SHA256 of the body using the channelProvider.webhookSecret).
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.2
 */
class MessageBirdSmsClient implements SmsProviderClientInterface
{
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
     */
    public function send(string $toNumber, string $body): array
    {
        $payload = [
            'originator' => $this->fromNumber,
            'recipients' => [$toNumber],
            'body'       => $body,
        ];

        $result = $this->dispatchViaOpenConnector(action: 'send-sms', payload: $payload);
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

    /**
     * Dispatch via openconnector.
     *
     * @param string               $action  Action name.
     * @param array<string, mixed> $payload Payload.
     *
     * @return array<string, mixed> Result.
     *
     * @throws TransientSmsProviderException On transient.
     * @throws PermanentSmsProviderException On permanent.
     */
    private function dispatchViaOpenConnector(string $action, array $payload): array
    {
        if ($this->sourceId === null || $this->sourceId === '') {
            throw new PermanentSmsProviderException('MessageBird source not configured');
        }

        try {
            $sourceService = $this->container->get('OCA\\OpenConnector\\Service\\SourceService');
        } catch (Throwable $e) {
            throw new TransientSmsProviderException('openconnector unavailable: '.$e->getMessage());
        }

        if (method_exists($sourceService, 'executeAction') === false) {
            throw new PermanentSmsProviderException('openconnector SourceService lacks executeAction');
        }

        try {
            $result = $sourceService->executeAction($this->sourceId, $action, $payload);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $this->logger->warning(
                'MessageBirdSmsClient.dispatchViaOpenConnector: failed',
                ['code' => $code, 'message' => $e->getMessage()]
            );
            if ($code === 0 || ($code >= 500 && $code < 600)) {
                throw new TransientSmsProviderException($e->getMessage(), $code, $e);
            }

            throw new PermanentSmsProviderException($e->getMessage(), $code, $e);
        }

        if (is_array($result) === true) {
            return $result;
        }

        return [];
    }//end dispatchViaOpenConnector()
}//end class
