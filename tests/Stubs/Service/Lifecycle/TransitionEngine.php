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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Applies named lifecycle transitions (stub).
 */
class TransitionEngine {
	/**
	 * Apply a named transition to an object.
	 *
	 * Checked against the real declaration at
	 * openregister `origin/development`,
	 * `lib/Service/Lifecycle/TransitionEngine.php:246`:
	 *   `public function transition(string $objectId, string $action): ObjectEntity`
	 * (its docblock at :235 reads "@return ObjectEntity The saved object after
	 * the transition.")
	 *
	 * This stub previously declared `: mixed`. That is WIDER than the real
	 * class, and a wider stub is blind exactly where the bug lives: a test
	 * double narrowing the return to `array` is a legal covariant override of
	 * `mixed`, so the bare unit run declared it happily — while CI, which runs
	 * inside a Nextcloud with openregister enabled and therefore loads the REAL
	 * class, refused to declare it and died before test 1 with no summary line.
	 * The two run modes disagreed, and the mode that reported "fine" was the one
	 * whose stub could not tell.
	 *
	 * `Service/Lifecycle/TransitionEngine.php` is deliberately NOT in
	 * tests/bootstrap.php's eager pre-declare list, so the real class does win
	 * when it is present — which is exactly why this signature has to match it.
	 *
	 * @param string $objectId Object id/uuid/slug.
	 * @param string $action Transition action name.
	 *
	 * @return ObjectEntity The saved object after the transition.
	 */
	public function transition(string $objectId, string $action): ObjectEntity {
		return new ObjectEntity();
	}//end transition()
}//end class
