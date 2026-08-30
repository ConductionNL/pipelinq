<?php

/**
 * Test stub for OpenRegister's Schema entity.
 *
 * Only the surface the pipelinq export listener reads (getProperties) is
 * stubbed; OpenRegister is not a test-time dependency.
 *
 * @category Test
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal Schema stub exposing a properties map.
 */
class Schema {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $properties The schema properties map.
	 */
	public function __construct(
		private array $properties = [],
	) {
	}//end __construct()

	/**
	 * The schema properties (column => definition) map.
	 *
	 * @return array<string, mixed> The properties.
	 */
	public function getProperties(): array {
		return $this->properties;
	}//end getProperties()
}//end class
