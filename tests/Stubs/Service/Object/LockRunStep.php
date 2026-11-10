<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\LockRunStep.
 *
 * Mirrors OpenRegister's value object from openregister #9095: the flow run and node that take a run-scoped lock.
 * Same constructor and defaults.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

if (class_exists(LockRunStep::class) === false) {
	/**
	 * The flow run and node behind a lock.
	 */
	final class LockRunStep {
		/**
		 * Constructor.
		 *
		 * @param string|null $runUuid The flow run taking the lock.
		 * @param string|null $nodeId  The flow node that took it.
		 */
		public function __construct(
			public readonly ?string $runUuid,
			public readonly ?string $nodeId,
		) {
		}//end __construct()
	}//end class
}//end if
