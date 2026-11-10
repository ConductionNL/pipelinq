<?php

/**
 * Pipelinq DeliveryCostRequest.
 *
 * A provider delivery webhook event and the message whose cost it reports.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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

namespace OCA\Pipelinq\Service;

/**
 * The delivery event to price: its payload, and the vendor, channel,
 * template category and destination country of the delivered message.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
final class DeliveryCostRequest {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $payload  Webhook event payload.
	 * @param string               $vendor   Provider vendor key.
	 * @param string               $channel  Channel (whatsapp / sms).
	 * @param string               $category Template category.
	 * @param string               $country  ISO 3166-1 alpha-2.
	 */
	public function __construct(
		public readonly array $payload,
		public readonly string $vendor,
		public readonly string $channel,
		public readonly string $category='utility',
		public readonly string $country='NL',
	) {
	}//end __construct()
}//end class
