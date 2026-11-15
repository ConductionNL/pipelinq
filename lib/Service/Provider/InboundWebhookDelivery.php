<?php

/**
 * Pipelinq InboundWebhookDelivery.
 *
 * One delivery to the public SMS or WhatsApp inbound webhook.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Provider
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
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Provider;

/**
 * The raw body, the signature and the provider of one inbound webhook
 * delivery, before the signature is checked.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
final class InboundWebhookDelivery {
	/**
	 * Constructor.
	 *
	 * @param string $rawBody    The raw request body.
	 * @param string $signature  The signature header.
	 * @param string $providerId The channelProvider UUID from the route.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $rawBody,
		public readonly string $signature,
		public readonly string $providerId,
	) {
	}//end __construct()
}//end class
