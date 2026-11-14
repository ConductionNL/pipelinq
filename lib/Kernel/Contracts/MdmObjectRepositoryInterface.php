<?php

/**
 * Contract of MdmObjectRepository
 *
 * The public surface of MdmObjectRepository that other components call, so they depend
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
 * Contract of MdmObjectRepository.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MdmObjectRepositoryInterface {

	/**
	 * Find all objects in a schema, optionally filtered.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param array<string, mixed> $filters Extra equality filters.
	 *
	 * @return array<int, array<string, mixed>> The matching objects.
	 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-001--golden-record-per-master-entity
	 */
	public function findAll(string $schemaSlug, array $filters = []): array;

	/**
	 * Fetch a Master Entity by uuid, reading OpenRegister's materialised object.
	 *
	 * Re-homed from the retired MasterEntityService: OpenRegister's
	 * SurvivorshipRecomputeListener now materialises goldenRecord /
	 * attributeProvenance on save, so consumers (AVG, the downstream read API,
	 * the OR-mirror sync) read the golden record straight off the object.
	 *
	 * @param string $masterId The master entity uuid.
	 *
	 * @return array<string, mixed>|null The entity, or null if absent.
	 *
	 * @spec openspec/specs/master-data-management/spec.md
	 */
	public function findMasterEntity(string $masterId): ?array;

	/**
	 * Current UTC timestamp in ISO 8601.
	 *
	 * @return string The timestamp.
	 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-001--golden-record-per-master-entity
	 */
	public function now(): string;

	/**
	 * Persist an object via the OR ObjectService.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param array<string, mixed> $object The object data.
	 * @param string|null $uuid Optional uuid to write to.
	 *
	 * @return array<string, mixed> The saved object as array.
	 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-001--golden-record-per-master-entity
	 */
	public function save(string $schemaSlug, array $object, ?string $uuid = null): array;
}//end interface
