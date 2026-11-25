<?php

/**
 * Test stub for OCA\OpenRegister\Contract\RegisterSchemaSlugs.
 *
 * Mirrors OpenRegister's published value object: the register slug and the schema slug a slug search names.
 * Same constructor and defaults.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Contract
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

namespace OCA\OpenRegister\Contract;

if (class_exists(RegisterSchemaSlugs::class) === false) {
	/**
	 * the register slug and the schema slug a slug search names.
	 */
	final class RegisterSchemaSlugs {
		/**
		 * Constructor.
		 *
		 * @param string $register The register slug.
		 * @param string $schema   The schema slug.
		 */
		public function __construct(
			public readonly string $register,
			public readonly string $schema,
		) {
		}//end __construct()
	}//end class
}//end if
