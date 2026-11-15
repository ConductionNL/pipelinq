<?php

/**
 * Test stub for OC\AppScriptDependency.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OC;

/**
 * Stub for OC\AppScriptDependency: the apps an app's scripts load after.
 */
class AppScriptDependency {
	/**
	 * Constructor.
	 *
	 * @param string   $id   The app id.
	 * @param string[] $deps The app ids to load after.
	 */
	public function __construct(
		private string $id,
		private array $deps = [],
	) {
	}

	/**
	 * Return the app id.
	 *
	 * @return string The app id.
	 */
	public function getId(): string {
		return $this->id;
	}

	/**
	 * Return the app ids to load after.
	 *
	 * @return string[] The dependencies.
	 */
	public function getDeps(): array {
		return $this->deps;
	}

	/**
	 * Add an app id to load after, once.
	 *
	 * @param string $id The app id.
	 *
	 * @return void
	 */
	public function addDep(string $id): void {
		if (in_array($id, $this->deps, true) === false) {
			$this->deps[] = $id;
		}
	}
}
