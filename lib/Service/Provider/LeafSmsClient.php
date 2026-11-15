<?php

/**
 * Pipelinq LeafSmsClient.
 *
 * What every SMS vendor client shares: the constructor, the send through
 * OpenRegister's `MessageDispatchProvider` leaf (via {@see MessageDispatchTrait})
 * and the shared-secret HMAC-SHA256 check of a webhook body. A vendor client
 * names its send path, the answer key of its message id, its payload and its
 * vendor key.
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
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Provider;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An SMS vendor client that sends through the OpenRegister dispatch leaf.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
abstract class LeafSmsClient implements SmsProviderClientInterface {
	use MessageDispatchTrait;

	/**
	 * Messages send path, relative to the source base URL.
	 *
	 * @var string
	 */
	protected const SEND_PATH = '';

	/**
	 * The key of the vendor's message id in the dispatch answer.
	 *
	 * @var string
	 */
	protected const MESSAGE_ID_KEY = '';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (for MessageDispatchProvider).
	 * @param LoggerInterface $logger Logger.
	 * @param array<string, mixed> $credentials Decoded credentials.
	 * @param string $fromNumber Sender E.164 / account id.
	 * @param string $webhookSecret Shared HMAC secret.
	 * @param string|null $sourceId openconnector source id.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function __construct(
		protected ContainerInterface $container,
		protected LoggerInterface $logger,
		protected array $credentials,
		protected string $fromNumber,
		protected string $webhookSecret,
		protected ?string $sourceId = null,
	) {
	}//end __construct()

	/**
	 * Send a single SMS through the vendor's openconnector source.
	 *
	 * @param string $toNumber Recipient E.164.
	 * @param string $body Plain-text body.
	 *
	 * @return array{externalMessageId: string, vendor: string} Provider id.
	 *
	 * @throws TransientSmsProviderException On 5xx / network.
	 * @throws PermanentSmsProviderException On 4xx / config.
	 *
	 * @spec openspec/changes/archive/2026-06-21-pipelinq-messaging-via-or-leaf/tasks.md#1.1
	 */
	public function send(string $toNumber, string $body): array {
		$result = $this->dispatchViaLeaf(
			source: (string)($this->sourceId ?? ''),
			body: $this->payloadFor(toNumber: $toNumber, body: $body),
			path: static::SEND_PATH,
			headers: $this->sendHeaders(),
		);
		$id = (string)($result[static::MESSAGE_ID_KEY] ?? ($result['externalMessageId'] ?? ''));

		return [
			'externalMessageId' => $id,
			'vendor' => $this->getVendor(),
		];
	}//end send()

	/**
	 * Verify a shared-secret HMAC-SHA256 (hex) signature over the raw body.
	 *
	 * @param string $rawBody Raw body.
	 * @param string $signature Header.
	 *
	 * @return bool True when authentic.
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function verifySignature(string $rawBody, string $signature): bool {
		if ($this->webhookSecret === '' || $signature === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);
		return hash_equals($expected, $signature);
	}//end verifySignature()

	/**
	 * The vendor's send payload for one message.
	 *
	 * @param string $toNumber Recipient E.164.
	 * @param string $body Plain-text body.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function payloadFor(string $toNumber, string $body): array;

	/**
	 * Extra headers on the send request (none by default).
	 *
	 * @return array<string, string>
	 */
	protected function sendHeaders(): array {
		return [];
	}//end sendHeaders()
}//end class
