<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\ObjectFindTarget.
 *
 * Mirrors OpenRegister's value object from openregister #9096, #9099 and #9123: the register and schema an object is looked up in.
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

if (class_exists(ObjectFindTarget::class) === false) {
	/**
	 * The register and schema of an object lookup.
	 */
	final class ObjectFindTarget {
		/**
		 * Constructor.
		 *
		 * @param Register|string|int|null $register The register, or its id, uuid or slug.
		 * @param Schema|string|int|null   $schema   The schema, or its id, uuid or slug.
		 */
		public function __construct(
			public readonly Register|string|int|null $register = null,
			public readonly Schema|string|int|null $schema = null,
		) {
		}//end __construct()
	}//end class
}//end if
