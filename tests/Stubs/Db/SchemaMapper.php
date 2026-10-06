<?php

/**
 * Test stub for OpenRegister's SchemaMapper.
 *
 * Declares only the surface pipelinq calls, so unit tests can mock it when
 * OpenRegister is not installed. The real class wins whenever it is loaded.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal SchemaMapper stub.
 */
class SchemaMapper {
	/**
	 * Find a schema by id, uuid or slug.
	 *
	 * @param string|int        $id            The schema id, uuid or slug.
	 * @param array<int, mixed> $_extend       Properties to extend.
	 * @param bool              $_rbac         Whether to apply RBAC.
	 * @param bool              $_multitenancy Whether to apply multitenancy.
	 *
	 * @return object The schema.
	 */
	public function find(string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): object {
		throw new \RuntimeException('SchemaMapper stub: mock find() in the test');
	}//end find()
}//end class
