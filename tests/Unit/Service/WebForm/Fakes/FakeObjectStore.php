<?php

/**
 * Test fake for the website form suite: OpenRegister ObjectService findAll and deleteObject over an in-memory list.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes;

use Throwable;

/**
 * Stored rows by schema id; findAll narrows by schema only, so callers must judge bodies themselves.
 */
class FakeObjectStore {

	/**
	 * Every findAll() config received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $queries = [];

	/**
	 * Every deleteObject() call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $deleted = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, array<int, mixed>> $rows        Schema id to stored rows.
	 * @param Throwable|null                   $deleteThrow What deleteObject throws, when set.
	 */
	public function __construct(
		public array $rows = [],
		public ?Throwable $deleteThrow = null,
	) {
	}//end __construct()

	/**
	 * Rows of the filtered schema, one page.
	 *
	 * @param array<string, mixed> $config        The query.
	 * @param bool                 $_rbac         RBAC flag.
	 * @param bool                 $_multitenancy Multitenancy flag.
	 *
	 * @return array<int, mixed> The rows.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$this->queries[] = $config + ['_rbac' => $_rbac];
		$rows = ($this->rows[(string)($config['filters']['schema'] ?? '')] ?? []);

		return array_slice($rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 1000));
	}//end findAll()

	/**
	 * Delete one object.
	 *
	 * @param string $uuid          The id.
	 * @param mixed  $register      The register.
	 * @param mixed  $schema        The schema.
	 * @param bool   $_rbac         RBAC flag.
	 * @param bool   $_multitenancy Multitenancy flag.
	 * @param mixed  $currentUser   The acting user.
	 *
	 * @return bool True.
	 */
	public function deleteObject(string $uuid, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true, mixed $currentUser = null): bool {
		if ($this->deleteThrow !== null) {
			throw $this->deleteThrow;
		}

		$this->deleted[] = ['uuid' => $uuid, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'user' => $currentUser];

		return true;
	}//end deleteObject()
}//end class
