<?php

/**
 * Contract of ExportDestinationService
 *
 * The public surface of ExportDestinationService that other components call, so they depend
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

use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Contract of ExportDestinationService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExportDestinationServiceInterface {

	/**
	 * Create a destination, validate the OC source reference, and probe it.
	 *
	 * @param array<string, mixed> $data The destination config.
	 *
	 * @return array<string, mixed> The created destination.
	 *
	 * @throws OCSBadRequestException On invalid type or missing required fields.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-001
	 */
	public function createDestination(array $data): array;

	/**
	 * Delete a destination.
	 *
	 * @param string $id The destination UUID.
	 *
	 * @return void
	 *
	 * @throws OCSNotFoundException When absent.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-001
	 */
	public function deleteDestination(string $id): void;

	/**
	 * List all destinations (credentials never included — they live in OC).
	 *
	 * @return array<int, array<string, mixed>> The destinations.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-001
	 */
	public function listDestinations(): array;

	/**
	 * Test connectivity to a destination via its sink adapter.
	 *
	 * Resolves credentials from the referenced OC source, probes the sink, and
	 * records validationStatus + lastValidatedAt. Never throws on a failed
	 * probe (returns false); only a missing destination throws.
	 *
	 * @param string $id The destination UUID.
	 *
	 * @return bool True when the destination is reachable.
	 *
	 * @throws OCSNotFoundException When the destination is absent.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-001
	 */
	public function testConnection(string $id): bool;

	/**
	 * Update a destination (re-validates and re-probes).
	 *
	 * @param string $id The destination UUID.
	 * @param array<string, mixed> $data The new config.
	 *
	 * @return array<string, mixed> The updated destination.
	 *
	 * @throws OCSBadRequestException On invalid input.
	 * @throws OCSNotFoundException When absent.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-001
	 */
	public function updateDestination(string $id, array $data): array;
}//end interface
