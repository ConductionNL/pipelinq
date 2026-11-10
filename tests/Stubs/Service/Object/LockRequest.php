<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\LockRequest.
 *
 * Mirrors OpenRegister's value object from openregister #9095: what lockObject() takes.
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

if (class_exists(LockRequest::class) === false) {
	/**
	 * A lock on one object.
	 */
	final class LockRequest {
		/**
		 * The flow run taking the lock, from the run step.
		 *
		 * @var string|null
		 */
		public readonly ?string $runUuid;

		/**
		 * The flow node that took the lock, from the run step.
		 *
		 * @var string|null
		 */
		public readonly ?string $nodeId;

		/**
		 * Constructor.
		 *
		 * @param string       $identifier The object id or uuid.
		 * @param string|null  $process    A label for the process holding the lock.
		 * @param integer|null $duration   Lock duration in seconds.
		 * @param LockRunStep  $runStep    The flow run and node, for a run-scoped lock.
		 */
		public function __construct(
			public readonly string $identifier,
			public readonly ?string $process = null,
			public readonly ?int $duration = null,
			LockRunStep $runStep = new LockRunStep(runUuid: null, nodeId: null),
		) {
			$this->runUuid = $runStep->runUuid;
			$this->nodeId  = $runStep->nodeId;
		}//end __construct()
	}//end class
}//end if
