<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\UnlockRequest.
 *
 * Mirrors OpenRegister's value object from openregister #9095: what unlockObject() takes.
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

if (class_exists(UnlockRequest::class) === false) {
	/**
	 * The release of a lock on one object.
	 */
	final class UnlockRequest {
		/**
		 * Constructor.
		 *
		 * @param string      $identifier The object id or uuid.
		 * @param boolean     $advisory   Whether the lock is advisory.
		 * @param string|null $runUuid    The flow run releasing a run-scoped lock.
		 * @param boolean     $break      Whether to break another holder's lock.
		 */
		public function __construct(
			public readonly string $identifier,
			public readonly bool $advisory = false,
			public readonly ?string $runUuid = null,
			public readonly bool $break = false,
		) {
		}//end __construct()
	}//end class
}//end if
