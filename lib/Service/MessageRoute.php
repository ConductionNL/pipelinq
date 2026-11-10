<?php

/**
 * Pipelinq MessageRoute.
 *
 * The provider vendor and channel a message went out on.
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
 * The provider vendor key and the channel (whatsapp / sms) of a message.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
final class MessageRoute {
	/**
	 * Constructor.
	 *
	 * @param string $vendor  Provider vendor key.
	 * @param string $channel Channel (whatsapp / sms).
	 */
	public function __construct(
		public readonly string $vendor,
		public readonly string $channel,
	) {
	}//end __construct()
}//end class
