<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\FlowNodeCall.
 *
 * Mirrors OpenRegister's value object: what IFlowNode::execute() receives
 * since openregister #9090. Same constructor and defaults.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

if (class_exists(FlowNodeCall::class) === false) {
	/**
	 * One flow node invocation: its items, configuration and run context.
	 */
	final class FlowNodeCall {
		/**
		 * Constructor.
		 *
		 * @param array<int, mixed>    $items   The input items.
		 * @param array<string, mixed> $config  The step configuration.
		 * @param array<string, mixed> $context Run-level metadata.
		 */
		public function __construct(
			public readonly array $items = [],
			public readonly array $config = [],
			public readonly array $context = [],
		) {
		}//end __construct()
	}//end class
}//end if
