<?php

/**
 * Pipelinq PostgresExportAdapter.
 *
 * Export sink adapter for PostgreSQL (COPY FROM). Surfaces the COPY row count
 * as the destination acknowledgement.
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
 * PostgreSQL export sink adapter.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008-01
 */
class PostgresExportAdapter extends AbstractOpenConnectorSink {
	/**
	 * The destination type slug.
	 *
	 * @return string The type ('postgres').
	 */
	public function getType(): string {
		return 'postgres';
	}//end getType()

	/**
	 * Surface the COPY row count as the acknowledgement.
	 *
	 * @param array<string, mixed> $result The transfer result metadata.
	 *
	 * @return string The copy count (or a generic ack when absent).
	 */
	protected function acknowledge(array $result): string {
		if (isset($result['copyCount']) === true) {
			return 'COPY ' . (string)$result['copyCount'];
		}

		return (string)($result['path'] ?? '');
	}//end acknowledge()
}//end class
