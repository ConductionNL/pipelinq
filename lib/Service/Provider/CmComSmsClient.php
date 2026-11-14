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
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Provider;

/**
 * CmComSmsClient — CM.com SMS via the OpenRegister dispatch leaf.
 *
 * Vendor key: `cm-com`. CM.com's status callbacks use a shared-secret
 * HMAC over the raw body.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class CmComSmsClient extends LeafSmsClient {
	/**
	 * CM.com messages send path, relative to the source base URL.
	 *
	 * @var string
	 */
	protected const SEND_PATH = 'messages';

	/**
	 * The key of the message id in the dispatch answer.
	 *
	 * @var string
	 */
	protected const MESSAGE_ID_KEY = 'messageId';

	/**
	 * The CM.com send payload for one message.
	 *
	 * @param string $toNumber Recipient E.164.
	 * @param string $body Plain-text body.
	 *
	 * @return array<string, mixed>
	 */
	protected function payloadFor(string $toNumber, string $body): array {
		return [
			'from' => $this->fromNumber,
			'to' => [$toNumber],
			'body' => $body,
		];
	}//end payloadFor()

	/**
	 * Vendor key.
	 *
	 * @return string `cm-com`.
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function getVendor(): string {
		return 'cm-com';
	}//end getVendor()
}//end class
