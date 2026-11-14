<?php

/**
 * Pipelinq TwilioSmsClient.
 *
 * Twilio SMS provider implementation. Delegates actual HTTP transport to
 * OpenRegister's `MessageDispatchProvider` leaf (via {@see MessageDispatchTrait}),
 * which routes through openconnector's `twilio-sms` source (per ADR-005 —
 * pipelinq never touches a provider SDK directly; `SourceService` no longer
 * exists in OpenConnector) and surfaces the result as a vendor-neutral
 * SmsProviderClientInterface response.
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

/**
 * TwilioSmsClient — Twilio Messaging API client (via OpenRegister dispatch leaf).
 *
 * Vendor key: `twilio`. Webhook signatures use `X-Twilio-Signature`
 * (HMAC-SHA1 of the URL + form-encoded body, base64-encoded). For
 * shared-secret deployments behind a reverse proxy the secret is
 * the channelProvider.webhookSecret on the provider row.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class TwilioSmsClient extends LeafSmsClient {
	/**
	 * Twilio Messages send path, relative to the source base URL.
	 *
	 * @var string
	 */
	protected const SEND_PATH = 'Messages.json';

	/**
	 * The key of the message id in the dispatch answer.
	 *
	 * @var string
	 */
	protected const MESSAGE_ID_KEY = 'sid';

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
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function verifySignature(string $rawBody, string $signature): bool {
		if ($this->webhookSecret === '' || $signature === '') {
			return false;
		}

		$expected = base64_encode(hash_hmac('sha256', $rawBody, $this->webhookSecret, true));
		if (hash_equals($expected, $signature) === true) {
			return true;
		}

		return parent::verifySignature(rawBody: $rawBody, signature: $signature);
	}//end verifySignature()

	/**
	 * Twilio's Messages API takes form fields; without this header the
	 * OpenRegister leaf sends the array body as JSON.
	 *
	 * @return array<string, string>
	 */
	protected function sendHeaders(): array {
		return ['Content-Type' => 'application/x-www-form-urlencoded'];
	}//end sendHeaders()

	/**
	 * The Twilio send payload for one message.
	 *
	 * @param string $toNumber Recipient E.164.
	 * @param string $body Plain-text body.
	 *
	 * @return array<string, mixed>
	 */
	protected function payloadFor(string $toNumber, string $body): array {
		return [
			'From' => $this->fromNumber,
			'To' => $toNumber,
			'Body' => $body,
		];
	}//end payloadFor()

	/**
	 * Vendor key.
	 *
	 * @return string `twilio`.
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function getVendor(): string {
		return 'twilio';
	}//end getVendor()
}//end class
