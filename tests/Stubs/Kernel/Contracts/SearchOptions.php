<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\SearchOptions.
 *
 * Mirrors OpenRegister's value object from openregister #9103: the options searchObjects() and searchObjectsPaginated() take.
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

if (class_exists(SearchOptions::class) === false) {
	/**
	 * Options of an object search.
	 */
	final class SearchOptions {
		/**
		 * Constructor.
		 *
		 * @param ObjectSelection        $selection         The id and uses restriction.
		 * @param array<int, mixed>|null $views             Views to apply.
		 * @param boolean                $viewScopeRequired Whether a view scope is required.
		 * @param boolean                $deleted           Whether to include deleted objects.
		 */
		public function __construct(
			public readonly ObjectSelection $selection = new ObjectSelection(),
			public readonly ?array $views = null,
			public readonly bool $viewScopeRequired = false,
			public readonly bool $deleted = false,
		) {
		}//end __construct()
	}//end class
}//end if
