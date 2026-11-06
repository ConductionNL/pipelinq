<?php

/**
 * Pipelinq MessageContent.
 *
 * What an outbound message says and on which channel.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Messaging
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Messaging;

/**
 * The channel, the text, and the approved template with its parameters.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
 */
class MessageContent {
	/**
	 * Constructor.
	 *
	 * @param string             $channel    `sms` or `whatsapp`.
	 * @param string             $body       Free-text body (sms / in-window whatsapp).
	 * @param string|null        $templateId Approved template UUID (whatsapp) or null.
	 * @param array<int, string> $parameters Positional template parameters.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $channel,
		public readonly string $body,
		public readonly ?string $templateId = null,
		public readonly array $parameters = [],
	) {
	}//end __construct()
}//end class
