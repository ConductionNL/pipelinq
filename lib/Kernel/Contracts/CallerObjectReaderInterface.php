<?php

/**
 * Contract of CallerObjectReader
 *
 * The public surface of CallerObjectReader that other components call, so they depend
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
 * Contract of CallerObjectReader.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CallerObjectReaderInterface {

	/**
	 * One object's data, or null.
	 *
	 * @param string $id        The object id.
	 * @param string $schemaKey The app config key holding the schema id, e.g. `client_schema`.
	 *
	 * @return array<string, mixed>|null The object's data.
	 *
	 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
	 */
	public function read(string $id, string $schemaKey): ?array;

	/**
	 * One object's data from a schema named by id or slug, or null.
	 *
	 * @param string $id     The object id.
	 * @param string $schema The schema id or slug.
	 *
	 * @return array<string, mixed>|null The object's data.
	 *
	 * @spec openspec/specs/record-documents/spec.md#requirement-a-configured-record-type-has-a-document
	 */
	public function readFromSchema(string $id, string $schema): ?array;

	/**
	 * This app's register id, or '' when unconfigured.
	 *
	 * @return string The register id.
	 *
	 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
	 */
	public function register(): string;
}//end interface
