<?php

/**
 * Pipelinq ReadsSchemaRows.
 *
 * Every row of one schema, read without RBAC for a repair step.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair\Support;

use OCA\Pipelinq\Util\SerialisedPayload;
use Throwable;

/**
 * Reads up to BATCH_LIMIT rows of a schema as plain arrays, logging a failed
 * read with the host class's short name. The host class provides a
 * LoggerInterface `$logger` property and a BATCH_LIMIT constant.
 *
 * @spec exclude shared plumbing of the repair steps that use it, no spec surface of its own.
 */
trait ReadsSchemaRows {
	use SerialisedPayload;

	/**
	 * Read every row of one schema.
	 *
	 * RBAC is off for the same reason the writes turn it off: on the CLI
	 * there is no session, so an RBAC-filtered read returns only what
	 * 'Anonymous' may see and the backfill would quietly cover a fraction
	 * of the rows.
	 *
	 * @param SchemaWriteTarget $target The schema to read.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 *
	 * @spec exclude shared plumbing of the repair steps that use it, no spec surface of its own.
	 */
	private function readAll(SchemaWriteTarget $target): array {
		try {
			$rows = $target->objectService->findAll(
				config: [
					'filters' => [
						'register' => $target->register,
						'schema' => $target->schema,
					],
					'limit' => self::BATCH_LIMIT,
				],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				substr(strrchr(self::class, '\\'), 1).': findAll failed',
				['schema' => $target->schema, 'error' => $e->getMessage()]
			);

			return [];
		}//end try

		$out = [];
		foreach ($rows as $row) {
			$data = $this->serialisedPayload(value: $row);
			if ($data !== null) {
				$out[] = $data;
			}
		}

		return $out;
	}//end readAll()
}//end trait
