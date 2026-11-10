<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\ObjectSelection.
 *
 * Mirrors OpenRegister's value object from openregister #9103: which objects a search or count is restricted to.
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

if (class_exists(ObjectSelection::class) === false) {
	/**
	 * Restriction of a search to ids and to the objects using one object.
	 */
	final class ObjectSelection {
		/**
		 * Constructor.
		 *
		 * @param array<int, mixed>|null $ids  Restrict to these ids.
		 * @param string|null            $uses Restrict to objects using this one.
		 */
		public function __construct(
			public readonly ?array $ids = null,
			public readonly ?string $uses = null,
		) {
		}//end __construct()
	}//end class
}//end if
