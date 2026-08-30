<?php

/**
 * Pipelinq ExportSinkInterface.
 *
 * Contract for an external warehouse sink (S3, Azure Data Lake, GCS, BigQuery,
 * Snowflake, SFTP, Postgres). Concrete adapters resolve credentials from an
 * OpenConnector source and write a single file to the destination, returning a
 * destination acknowledgement. Implementations are mocked in tests; building
 * the app never requires live warehouse credentials.
 *
 * @category Adapter
 * @package  OCA\Pipelinq\Adapter
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Adapter;

/**
 * Strategy contract for an external export sink.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008
 */
interface ExportSinkInterface {
	/**
	 * The destination type slug this adapter handles (e.g. 's3').
	 *
	 * @return string The destination type.
	 */
	public function getType(): string;

	/**
	 * Test connectivity to the destination using the given credentials.
	 *
	 * Implementations must not throw; a connectivity failure is reported as a
	 * false return so the caller can record validation_status = "invalid".
	 *
	 * @param array<string, mixed> $credentials The resolved OpenConnector credentials.
	 * @param array<string, mixed> $destination The destination configuration.
	 *
	 * @return bool True when the destination is reachable and writable.
	 */
	public function testConnection(array $credentials, array $destination): bool;

	/**
	 * Upload a single file to the destination.
	 *
	 * @param array<string, mixed> $credentials The resolved OpenConnector credentials.
	 * @param array<string, mixed> $destination The destination configuration.
	 * @param string $remotePath The resolved remote path/object key.
	 * @param string $contents The file bytes to write.
	 *
	 * @return string A destination acknowledgement (ETag, load-job id, query id, ...).
	 *
	 * @throws \RuntimeException When the upload fails after the adapter's own attempt.
	 */
	public function upload(array $credentials, array $destination, string $remotePath, string $contents): string;
}//end interface
