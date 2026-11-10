<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\ObjectDeletion.
 *
 * Mirrors OpenRegister's value object from openregister #9099: what deleteObject() takes.
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

if (class_exists(ObjectDeletion::class) === false) {
	/**
	 * One object to delete, where to find it and who deletes it.
	 */
	final class ObjectDeletion {
		/**
		 * Constructor.
		 *
		 * @param string           $uuid        The object uuid.
		 * @param ObjectFindTarget $target      The register and schema to find it in.
		 * @param IUser|null       $currentUser The acting user.
		 * @param boolean          $permanent   Whether to delete permanently.
		 */
		public function __construct(
			public readonly string $uuid,
			public readonly ObjectFindTarget $target = new ObjectFindTarget(),
			public readonly ?IUser $currentUser = null,
			public readonly bool $permanent = false,
		) {
		}//end __construct()

		/**
		 * Whether both register and schema are named.
		 *
		 * @return boolean
		 */
		public function isScoped(): bool {
			return $this->target->register !== null && $this->target->schema !== null;
		}//end isScoped()
	}//end class
}//end if
