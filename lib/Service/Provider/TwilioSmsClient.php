<?php

/**
 * Pipelinq TwilioSmsClient.
 *
 * Twilio SMS provider implementation. Delegates actual HTTP transport
 * to openconnector's SourceService (per ADR-005 — pipelinq never
 * touches a provider SDK directly) and surfaces the result as a
 * vendor-neutral SmsProviderClientInterface response.
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
 * TwilioSmsClient — Twilio Messaging API client (via OpenRegister dispatch leaf).
 *
 * Vendor key: `twilio`. Webhook signatures use `X-Twilio-Signature`
 * (HMAC-SHA1 of the URL + form-encoded body, base64-encoded). For
 * shared-secret deployments behind a reverse proxy the secret is
 * the channelProvider.webhookSecret on the provider row.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.2
 */
class TwilioSmsClient implements SmsProviderClientInterface {
	use MessageDispatchTrait;

	/**
	 * Twilio Messages send path, relative to the source base URL.
	 *
	 * @var string
	 */
	private const SEND_PATH = 'Messages.json';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (for SourceService).
	 * @param LoggerInterface $logger Logger.
	 * @param array<string, mixed> $credentials Decoded credentials bag.
	 * @param string $fromNumber Sender phone number (E.164).
	 * @param string $webhookSecret Shared HMAC secret for signature checks.
	 * @param string|null $sourceId openconnector source id (or null in tests).
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
	 * Send a single SMS via Twilio.
	 *
	 * @param string $toNumber Recipient phone number in E.164.
	 * @param string $body Plain-text body.
	 *
	 * @return array{externalMessageId: string, vendor: string} Provider id.
	 *
	 * @throws TransientSmsProviderException On 5xx / network failure.
	 * @throws PermanentSmsProviderException On 4xx / config failure.
	 *
	 * @spec openspec/changes/archive/2026-06-21-pipelinq-messaging-via-or-leaf/tasks.md#1.1
	 */
	public function send(string $toNumber, string $body): array {
		$payload = [
			'From' => $this->fromNumber,
			'To' => $toNumber,
			'Body' => $body,
		];

		$result = $this->dispatchViaLeaf(
			source: (string)($this->sourceId ?? ''),
			body: $payload,
			path: self::SEND_PATH,
		);
		$sid = (string)($result['sid'] ?? ($result['externalMessageId'] ?? ''));

		return [
			'externalMessageId' => $sid,
			'vendor' => $this->getVendor(),
		];
	}//end send()

	/**
	 * Verify the X-Twilio-Signature header against the raw body.
	 *
	 * Falls back to a static-secret HMAC-SHA256 check when the host
	 * proxy uses a shared-secret model (Pipelinq on-prem default).
	 *
	 * @param string $rawBody Raw request body.
	 * @param string $signature Header value.
	 *
	 * @return bool True when authentic.
	 */
	public function verifySignature(string $rawBody, string $signature): bool {
		if ($this->webhookSecret === '' || $signature === '') {
			return false;
		}

		$expected = base64_encode(hash_hmac('sha256', $rawBody, $this->webhookSecret, true));
		if (hash_equals($expected, $signature) === true) {
			return true;
		}

		$expectedHex = hash_hmac('sha256', $rawBody, $this->webhookSecret);
		return hash_equals($expectedHex, $signature);
	}//end verifySignature()

	/**
	 * Vendor key.
	 *
	 * @return string `twilio`.
	 */
	public function getVendor(): string {
		return 'twilio';
	}//end getVendor()
}//end class
