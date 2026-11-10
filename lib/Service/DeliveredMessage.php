<?php

/**
 * Pipelinq DeliveredMessage.
 *
 * The message a provider delivery webhook reports on.
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
 * The provider route and the tariff of a delivered message.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
final class DeliveredMessage {
	/**
	 * Constructor.
	 *
	 * @param MessageRoute  $route  The vendor and channel it went out on.
	 * @param MessageTariff $tariff Its template category and destination country.
	 */
	public function __construct(
		public readonly MessageRoute $route,
		public readonly MessageTariff $tariff=new MessageTariff(),
	) {
	}//end __construct()
}//end class
