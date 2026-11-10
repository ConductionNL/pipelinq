<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\ObjectSaveOptions.
 *
 * Mirrors OpenRegister's value object from openregister #9123: what saveObject() takes besides the object.
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

if (class_exists(ObjectSaveOptions::class) === false) {
	/**
	 * Where and how one object is saved.
	 */
	final class ObjectSaveOptions {
		/**
		 * Constructor.
		 *
		 * @param ObjectFindTarget              $target        The register and schema.
		 * @param string|null                   $uuid          The uuid to update; null creates.
		 * @param array<int|string, mixed>|null $extend        Properties to extend.
		 * @param array<string, mixed>|null     $uploadedFiles Files to attach.
		 * @param SavePolicy                    $policy        Silent, validation, fail-if-exists and dedup flags.
		 * @param SaveActor                     $actor         The acting user and the owner.
		 */
		public function __construct(
			public readonly ObjectFindTarget $target = new ObjectFindTarget(),
			public readonly ?string $uuid = null,
			public readonly ?array $extend = [],
			public readonly ?array $uploadedFiles = null,
			public readonly SavePolicy $policy = new SavePolicy(),
			public readonly SaveActor $actor = new SaveActor(),
		) {
		}//end __construct()
	}//end class
}//end if
