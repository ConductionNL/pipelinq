<?php

/**
 * Pipelinq ExportUploadTarget.
 *
 * Where the files of one upload run go: the type-specific sink adapter, the resolved credentials and the destination configuration.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

/**
 * The sink, credentials and destination one upload run writes to.
 *
 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-008
 */
final class ExportUploadTarget {
	/**
	 * Constructor.
	 *
	 * @param object               $sink        The sink adapter.
	 * @param array<string, mixed> $credentials The resolved credentials.
	 * @param array<string, mixed> $destination The destination configuration.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-008
	 */
	public function __construct(
		public readonly object $sink,
		public readonly array $credentials,
		public readonly array $destination,
	) {
	}//end __construct()
}//end class
