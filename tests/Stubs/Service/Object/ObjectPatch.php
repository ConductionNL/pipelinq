<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\ObjectPatch.
 *
 * Mirrors OpenRegister's value object from openregister #9096: what
 * patchObject() takes. Same constructor and defaults.
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

if (class_exists(ObjectPatch::class) === false) {
	/**
	 * A partial update of one object: what to merge, where it lives and who writes it.
	 */
	final class ObjectPatch {
		/**
		 * Constructor.
		 *
		 * @param string               $objectId    The object id, uuid or slug.
		 * @param array<string, mixed> $data        The fields to merge onto the stored object.
		 * @param ObjectFindTarget     $target      The register and schema to find it in.
		 * @param IUser|null           $currentUser The acting user.
		 */
		public function __construct(
			public readonly string $objectId,
			public readonly array $data,
			public readonly ObjectFindTarget $target = new ObjectFindTarget(),
			public readonly ?IUser $currentUser = null,
		) {
		}//end __construct()
	}//end class
}//end if
