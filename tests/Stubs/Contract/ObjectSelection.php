<?php

/**
 * Test stub for OCA\OpenRegister\Contract\ObjectSelection.
 *
 * Mirrors OpenRegister's published value object: the ids and uses a search or count is restricted to.
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

if (class_exists(ObjectSelection::class) === false) {
	/**
	 * the ids and uses a search or count is restricted to.
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
