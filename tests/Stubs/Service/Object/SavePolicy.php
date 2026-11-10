<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\SavePolicy.
 *
 * Mirrors OpenRegister's value object from openregister #9123: the flags of a save.
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

if (class_exists(SavePolicy::class) === false) {
	/**
	 * The flags of one save.
	 */
	final class SavePolicy {
		/**
		 * Constructor.
		 *
		 * @param boolean $silent        Whether to suppress side-effects.
		 * @param boolean $validation    Whether to validate against the schema.
		 * @param boolean $failIfExists  Whether a duplicate is an error.
		 * @param boolean $dedupOverride Whether to bypass duplicate detection.
		 */
		public function __construct(
			public readonly bool $silent = false,
			public readonly bool $validation = true,
			public readonly bool $failIfExists = false,
			public readonly bool $dedupOverride = false,
		) {
		}//end __construct()
	}//end class
}//end if
