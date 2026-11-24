<?php

/**
 * Test stub for OCA\OpenRegister\Service\Integration\Providers\OutboundMessage.
 *
 * Mirrors OpenRegister's value object from openregister #9094: what
 * MessageDispatchProvider::dispatch() takes besides the source. Same
 * constructor and defaults.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

if (class_exists(OutboundMessage::class) === false) {
	/**
	 * One outbound message: where it goes, what it carries, which headers.
	 */
	final class OutboundMessage {
		/**
		 * Constructor.
		 *
		 * @param string                $path    The send path.
		 * @param array<string, mixed>  $body    The vendor-shaped body.
		 * @param array<string, string> $headers Extra headers.
		 */
		public function __construct(
			public readonly string $path,
			public readonly array $body,
			public readonly array $headers = [],
		) {
		}//end __construct()
	}//end class
}//end if
