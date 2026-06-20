<?php

/**
 * Pipelinq CmComSmsClient.
 *
 * CM.com SMS provider implementation. Delegates HTTP transport to
 * openconnector's SourceService.
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
 * CmComSmsClient — CM.com SMS via openconnector.
 *
 * Vendor key: `cm-com`. CM.com's status callbacks use a shared-secret
 * HMAC over the raw body.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.2
 */
class CmComSmsClient implements SmsProviderClientInterface
{
    /**
     * Constructor.
     *
     * @param ContainerInterface   $container     DI container.
     * @param LoggerInterface      $logger        Logger.
     * @param array<string, mixed> $credentials   Decoded credentials.
     * @param string               $fromNumber    Sender E.164 / account id.
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
     * Send a single SMS via CM.com.
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
            'from' => $this->fromNumber,
            'to'   => [$toNumber],
            'body' => $body,
        ];

        $result = $this->dispatchViaOpenConnector(action: 'send-sms', payload: $payload);
        $id     = (string) ($result['messageId'] ?? ($result['externalMessageId'] ?? ''));

        return [
            'externalMessageId' => $id,
            'vendor'            => $this->getVendor(),
        ];
    }//end send()

    /**
     * Verify the CM.com signature header.
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
     * @return string `cm-com`.
     */
    public function getVendor(): string
    {
        return 'cm-com';
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
            throw new PermanentSmsProviderException('CM.com source not configured');
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
                'CmComSmsClient.dispatchViaOpenConnector: failed',
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
