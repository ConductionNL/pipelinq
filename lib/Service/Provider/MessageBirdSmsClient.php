<?php

/**
 * Pipelinq MessageBirdSmsClient.
 *
 * MessageBird SMS provider implementation (now Bird.com). Delegates
 * actual HTTP transport to OpenRegister's `MessageDispatchProvider` leaf
 * (via {@see MessageDispatchTrait}), which routes through openconnector's
 * `messagebird-sms` source. `SourceService` no longer exists in OpenConnector.
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
 * MessageBirdSmsClient — MessageBird SMS via the OpenRegister dispatch leaf.
 *
 * Vendor key: `messagebird`. Webhook signatures use MessageBird's
 * static-secret HMAC scheme (the `messagebird-signature` header is
 * an HMAC-SHA256 of the body using the channelProvider.webhookSecret).
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class MessageBirdSmsClient extends LeafSmsClient {
	/**
	 * MessageBird messages send path, relative to the source base URL.
	 *
	 * @var string
	 */
	protected const SEND_PATH = 'messages';

	/**
	 * The key of the message id in the dispatch answer.
	 *
	 * @var string
	 */
	protected const MESSAGE_ID_KEY = 'id';

	/**
	 * The MessageBird send payload for one message.
	 *
	 * @param string $toNumber Recipient E.164.
	 * @param string $body Plain-text body.
	 *
	 * @return array<string, mixed>
	 */
	protected function payloadFor(string $toNumber, string $body): array {
		return [
			'originator' => $this->fromNumber,
			'recipients' => [$toNumber],
			'body' => $body,
		];
	}//end payloadFor()

	/**
	 * Vendor key.
	 *
	 * @return string `messagebird`.
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function getVendor(): string {
		return 'messagebird';
	}//end getVendor()
}//end class
