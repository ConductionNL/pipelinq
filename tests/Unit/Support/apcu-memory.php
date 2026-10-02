<?php

/**
 * An in-memory stand-in for the APCu functions, for tests run without the
 * extension. Require it only from a test running in a separate process.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

if (function_exists('apcu_fetch') === false) {
	$GLOBALS['__apcuMemory'] = [];

	/**
	 * Whether a key is stored.
	 *
	 * @param string $keys The key.
	 *
	 * @return bool
	 */
	function apcu_exists($keys): bool {
		return array_key_exists($keys, $GLOBALS['__apcuMemory']);
	}

	/**
	 * Fetch a stored value.
	 *
	 * @param string $key     The key.
	 * @param bool   $success Set to whether the key was found.
	 *
	 * @return mixed The value, or false.
	 */
	function apcu_fetch($key, &$success = null): mixed {
		$success = array_key_exists($key, $GLOBALS['__apcuMemory']);
		return $success === true ? $GLOBALS['__apcuMemory'][$key] : false;
	}

	/**
	 * Store a value; the ttl is ignored.
	 *
	 * @param string $key The key.
	 * @param mixed  $var The value.
	 * @param int    $ttl The time to live.
	 *
	 * @return bool
	 */
	function apcu_store($key, $var = null, int $ttl = 0): bool {
		$GLOBALS['__apcuMemory'][$key] = $var;
		return true;
	}
}//end if
