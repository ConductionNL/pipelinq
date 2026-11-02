<?php

/**
 * Pipelinq LedgerOrigin.
 *
 * Where a points movement comes from: the rule, the source document and who processed it.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Loyalty
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Loyalty;

/**
 * Where a points movement comes from: the rule, the source document and who processed it.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 */
class LedgerOrigin {
	/**
	 * Constructor.
	 *
	 * @param string|null          $ruleId         Optional PointsRule UUID.
	 * @param array<string, mixed> $sourceDocument Source linkage.
	 * @param string               $processedBy    Processor identifier.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?string $ruleId,
		public readonly array $sourceDocument,
		public readonly string $processedBy,
	) {
	}//end __construct()
}//end class
