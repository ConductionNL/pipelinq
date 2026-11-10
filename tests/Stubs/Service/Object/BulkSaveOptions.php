<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\BulkSaveOptions.
 *
 * Mirrors OpenRegister's value object from openregister #9108: what saveObjects() takes besides the objects.
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

if (class_exists(BulkSaveOptions::class) === false) {
	/**
	 * The options of one bulk save.
	 */
	final class BulkSaveOptions {
		/**
		 * Constructor.
		 *
		 * @param BulkBatchDefaults $defaults   The register and schema rows default to.
		 * @param boolean           $validation Whether to validate.
		 * @param boolean           $events     Whether to emit events.
		 * @param boolean           $audit      Whether to write an audit trail.
		 */
		public function __construct(
			public readonly BulkBatchDefaults $defaults = new BulkBatchDefaults(),
			public readonly bool $validation = false,
			public readonly bool $events = false,
			public readonly bool $audit = true,
		) {
		}//end __construct()
	}//end class
}//end if
