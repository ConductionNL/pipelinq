<?php

/**
 * Pipelinq CmComSmsClient.
 *
 * CM.com SMS provider implementation. Delegates HTTP transport to
 * OpenRegister's `MessageDispatchProvider` leaf (via {@see MessageDispatchTrait}),
 * which routes through openconnector's `cmcom-sms` source. `SourceService`
 * no longer exists in OpenConnector.
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
 * CmComSmsClient — CM.com SMS via the OpenRegister dispatch leaf.
 *
 * Vendor key: `cm-com`. CM.com's status callbacks use a shared-secret
 * HMAC over the raw body.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.2
 */
class CmComSmsClient implements SmsProviderClientInterface {
	use MessageDispatchTrait;

	/**
	 * CM.com messages send path, relative to the source base URL.
	 *
	 * @var string
	 */
	private const SEND_PATH = 'messages';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param LoggerInterface $logger Logger.
	 * @param array<string, mixed> $credentials Decoded credentials.
	 * @param string $fromNumber Sender E.164 / account id.
	 * @param string $webhookSecret Shared HMAC secret.
	 * @param string|null $sourceId openconnector source id.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.2
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private array $credentials,
		private string $fromNumber,
		private string $webhookSecret,
		private ?string $sourceId = null,
	) {
	}//end __construct()

	/**
	 * Send a single SMS via CM.com.
	 *
	 * @param string $toNumber Recipient E.164.
	 * @param string $body Plain-text body.
	 *
	 * @return array{externalMessageId: string, vendor: string} Provider id.
	 *
	 * @throws TransientSmsProviderException On 5xx / network.
	 * @throws PermanentSmsProviderException On 4xx / config.
	 *
	 * @spec openspec/changes/archive/2026-06-21-pipelinq-messaging-via-or-leaf/tasks.md#1.3
	 */
	public function send(string $toNumber, string $body): array {
		$payload = [
			'from' => $this->fromNumber,
			'to' => [$toNumber],
			'body' => $body,
		];

		$result = $this->dispatchViaLeaf(
			source: (string)($this->sourceId ?? ''),
			body: $payload,
			path: self::SEND_PATH,
		);
		$id = (string)($result['messageId'] ?? ($result['externalMessageId'] ?? ''));

		return [
			'externalMessageId' => $id,
			'vendor' => $this->getVendor(),
		];
	}//end send()

	/**
	 * Verify the CM.com signature header.
	 *
	 * @param string $rawBody Raw body.
	 * @param string $signature Header.
	 *
	 * @return bool True when authentic.
	 */
	public function verifySignature(string $rawBody, string $signature): bool {
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
	public function getVendor(): string {
		return 'cm-com';
	}//end getVendor()
}//end class
