<?php

/**
 * Test stub for OpenRegister's TransitionEngine.
 *
 * Mirrors the public surface of OCA\OpenRegister\Service\Lifecycle\TransitionEngine
 * (the single `transition()` method pipelinq consumes) so the POS services can
 * be unit-tested with a PHPUnit mock without the openregister app installed.
 * Guarded with class_exists() in tests/bootstrap.php so the real class wins when
 * OpenRegister is present.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Applies named lifecycle transitions (stub).
 */
class TransitionEngine {
	/**
	 * Apply a named transition to an object.
	 *
	 * @param string $objectId Object id/uuid/slug.
	 * @param string $action Transition action name.
	 *
	 * @return mixed The saved object after the transition.
	 */
	public function transition(string $objectId, string $action): mixed {
		return null;
	}//end transition()
}//end class
