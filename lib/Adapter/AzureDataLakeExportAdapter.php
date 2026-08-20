<?php

/**
 * Pipelinq AzureDataLakeExportAdapter.
 *
 * Export sink adapter for Azure Data Lake Gen2. Surfaces the written blob
 * properties (ETag / last-modified) as the destination acknowledgement.
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
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Adapter;

/**
 * Azure Data Lake export sink adapter.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008-01
 */
class AzureDataLakeExportAdapter extends AbstractOpenConnectorSink {
	/**
	 * The destination type slug.
	 *
	 * @return string The type ('azure_data_lake').
	 */
	public function getType(): string {
		return 'azure_data_lake';
	}//end getType()

	/**
	 * Surface the blob ETag / properties as the acknowledgement.
	 *
	 * @param array<string, mixed> $result The transfer result metadata.
	 *
	 * @return string The blob properties (or a generic ack when absent).
	 */
	protected function acknowledge(array $result): string {
		return (string)($result['etag'] ?? $result['blobProperties'] ?? ($result['path'] ?? ''));
	}//end acknowledge()
}//end class
