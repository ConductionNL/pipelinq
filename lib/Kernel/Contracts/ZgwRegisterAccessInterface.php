<?php

/**
 * Contract of ZgwRegisterAccess
 *
 * The public surface of ZgwRegisterAccess that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of ZgwRegisterAccess.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ZgwRegisterAccessInterface {

	public const SCHEMA_ENDPOINT = 'zgwEndpoint';

	public const SCHEMA_ABONN = 'nrcAbonnement';

	public const SCHEMA_MAPPING = 'zgwResourceMapping';

	/**
	 * Look up a single object by UUID or slug.
	 *
	 * @param string $schema Schema slug (one of the SCHEMA_* constants).
	 * @param string $id UUID or slug.
	 *
	 * @return array<string, mixed>|null Object data (array form) or null when missing.
	 *
	 * @spec openspec/specs/zgw-api-bridge/spec.md
	 */
	public function find(string $schema, string $id): ?array;

	/**
	 * List objects matching a filter map.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $filters Filter map (passed straight through to OR).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/zgw-api-bridge/spec.md
	 */
	public function findAll(string $schema, array $filters = []): array;

	/**
	 * Persist (create or update) an object.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $data Object payload (without `@self`).
	 * @param string|null $uuid Existing UUID to update, or null to create.
	 *
	 * @return array<string, mixed>|null Saved object (or null on failure).
	 *
	 * @spec openspec/specs/zgw-api-bridge/spec.md
	 */
	public function save(string $schema, array $data, ?string $uuid = null): ?array;
}//end interface
