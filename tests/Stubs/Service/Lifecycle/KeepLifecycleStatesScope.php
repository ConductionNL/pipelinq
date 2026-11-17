<?php

/**
 * Test stub for OpenRegister's KeepLifecycleStatesScope.
 *
 * Mirrors the public surface of OCA\OpenRegister\Service\Lifecycle\KeepLifecycleStatesScope
 * so the demo seed can be unit-tested without the openregister app installed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * A scope in which a create keeps the lifecycle state it carries.
 */
final class KeepLifecycleStatesScope {

	/**
	 * How many scopes are open.
	 *
	 * @var integer
	 */
	private static int $depth = 0;

	/**
	 * Run an operation inside the scope.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed What the operation returns.
	 */
	public static function run(callable $operation): mixed {
		self::$depth++;
		try {
			$result = $operation();
		} finally {
			self::$depth--;
		}

		return $result;
	}//end run()

	/**
	 * Whether a scope is open.
	 *
	 * @return bool True inside the scope.
	 */
	public static function isActive(): bool {
		return self::$depth !== 0;
	}//end isActive()
}//end class
