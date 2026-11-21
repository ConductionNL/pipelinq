<?php

/**
 * An in-process ILockingProvider for unit tests.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Holds locks in memory and refuses a lock that is already held, as the real providers do.
 */
final class InMemoryLockingProvider implements ILockingProvider {
	/**
	 * The held locks: path => type.
	 *
	 * @var array<string, int>
	 */
	public array $held = [];

	/**
	 * Every path a lock was acquired on, in order.
	 *
	 * @var array<int, string>
	 */
	public array $acquired = [];

	/**
	 * Whether the path holds a lock of this type.
	 *
	 * @param string $path The locked path.
	 * @param int    $type The lock type.
	 *
	 * @return bool
	 */
	public function isLocked(string $path, int $type): bool {
		return ($this->held[$path] ?? null) === $type;
	}//end isLocked()

	/**
	 * Take a lock, refusing a path that is already locked.
	 *
	 * @param string      $path         The path.
	 * @param int         $type         The lock type.
	 * @param string|null $readablePath Unused.
	 *
	 * @return void
	 *
	 * @throws LockedException When the path is already locked.
	 */
	public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
		if (isset($this->held[$path]) === true) {
			throw new LockedException($path);
		}

		$this->held[$path] = $type;
		$this->acquired[] = $path;
	}//end acquireLock()

	/**
	 * Release a lock.
	 *
	 * @param string $path The path.
	 * @param int    $type The lock type.
	 *
	 * @return void
	 */
	public function releaseLock(string $path, int $type): void {
		unset($this->held[$path]);
	}//end releaseLock()

	/**
	 * Change the type of a held lock.
	 *
	 * @param string $path       The path.
	 * @param int    $targetType The new lock type.
	 *
	 * @return void
	 */
	public function changeLock(string $path, int $targetType): void {
		$this->held[$path] = $targetType;
	}//end changeLock()

	/**
	 * Release every lock.
	 *
	 * @return void
	 */
	public function releaseAll(): void {
		$this->held = [];
	}//end releaseAll()
}//end class
