<?php

/**
 * Pipelinq S3ExportAdapter.
 *
 * Export sink adapter for AWS S3. Writes an object via OpenConnector and
 * surfaces the S3 ETag as the destination acknowledgement.
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
 * AWS S3 export sink adapter.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008-01
 */
class S3ExportAdapter extends AbstractOpenConnectorSink {
	/**
	 * The destination type slug.
	 *
	 * @return string The type ('s3').
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function getType(): string {
		return 's3';
	}//end getType()

	/**
	 * Surface the S3 ETag as the acknowledgement.
	 *
	 * @param array<string, mixed> $result The transfer result metadata.
	 *
	 * @return string The S3 ETag (or a generic ack when absent).
	 */
	protected function acknowledge(array $result): string {
		return (string)($result['etag'] ?? $result['ETag'] ?? ($result['path'] ?? ''));
	}//end acknowledge()
}//end class
