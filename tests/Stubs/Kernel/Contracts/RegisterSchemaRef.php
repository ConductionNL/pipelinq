<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef.
 *
 * Mirrors OpenRegister's value object from openregister #9103: the register and schema searchObjectsBySlug() takes.
 * Same constructor and defaults.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Kernel\Contracts
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

namespace OCA\OpenRegister\Kernel\Contracts;

if (class_exists(RegisterSchemaRef::class) === false) {
	/**
	 * A register and schema, by id, uuid or slug.
	 */
	final class RegisterSchemaRef {
		/**
		 * Constructor.
		 *
		 * @param string|int $register The register id, uuid or slug.
		 * @param string|int $schema   The schema id, uuid or slug.
		 */
		public function __construct(
			public readonly string|int $register,
			public readonly string|int $schema,
		) {
		}//end __construct()
	}//end class
}//end if
