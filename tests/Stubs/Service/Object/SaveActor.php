<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\SaveActor.
 *
 * Mirrors OpenRegister's value object from openregister #9123: who saves and who owns the result.
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

use OCP\IUser;

if (class_exists(SaveActor::class) === false) {
	/**
	 * The acting user and the owner of a save.
	 */
	final class SaveActor {
		/**
		 * Constructor.
		 *
		 * @param IUser|null  $currentUser The acting user.
		 * @param string|null $owner       Explicit owner user id.
		 * @param boolean     $unowned     Whether to store the object without an owner.
		 */
		public function __construct(
			public readonly ?IUser $currentUser = null,
			public readonly ?string $owner = null,
			public readonly bool $unowned = false,
		) {
		}//end __construct()
	}//end class
}//end if
