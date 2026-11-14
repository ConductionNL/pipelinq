<?php

/**
 * Contract of PortalObjectRepository
 *
 * The public surface of PortalObjectRepository that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of PortalObjectRepository.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalObjectRepositoryInterface {

	/**
	 * Find a single portal object by id within a schema.
	 *
	 * Returns null when the object does not exist OR is not in the portal
	 * register/schema — callers therefore cannot reach objects outside the
	 * portal domain (no IDOR across registers).
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param string $id The object id/uuid.
	 *
	 * @return array<string, mixed>|null The object as an array, or null.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function find(string $schemaSlug, string $id): ?array;

	/**
	 * Find all portal objects of a schema matching a set of equality filters.
	 *
	 * The register and schema are always injected here, never taken from the
	 * caller's filters, so a caller can only ever read within the portal
	 * schema it asked for.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param array<string, mixed> $filters Extra equality filters (field => value).
	 *
	 * @return array<int, array<string, mixed>> The matching objects as arrays.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function findAll(string $schemaSlug, array $filters = []): array;

	/**
	 * Persist a portal object. When $id is null OpenRegister mints a new uuid.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param array<string, mixed> $data The object payload.
	 * @param string|null $id The object id for an update.
	 *
	 * @return array<string, mixed> The saved object as an array.
	 *
	 * @throws RuntimeException If persistence fails.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function save(string $schemaSlug, array $data, ?string $id = null): array;
	/**
	 * The id of one row, or null when it carries none.
	 *
	 * @param array<string, mixed> $object The row.
	 *
	 * @return string|null The id, or null.
	 *
	 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
	 */
	public function idOf(array $object): ?string;
}//end interface
