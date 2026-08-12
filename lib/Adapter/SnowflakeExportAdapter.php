<?php

/**
 * Pipelinq SnowflakeExportAdapter.
 *
 * Export sink adapter for Snowflake (stage + COPY). Surfaces the Snowflake
 * query id as the destination acknowledgement.
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
 * Snowflake export sink adapter.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008-01
 */
class SnowflakeExportAdapter extends AbstractOpenConnectorSink {
	/**
	 * The destination type slug.
	 *
	 * @return string The type ('snowflake').
	 */
	public function getType(): string {
		return 'snowflake';
	}//end getType()

	/**
	 * Surface the Snowflake query id as the acknowledgement.
	 *
	 * @param array<string, mixed> $result The transfer result metadata.
	 *
	 * @return string The query id (or a generic ack when absent).
	 */
	protected function acknowledge(array $result): string {
		return (string)($result['queryId'] ?? $result['query_id'] ?? ($result['path'] ?? ''));
	}//end acknowledge()
}//end class
