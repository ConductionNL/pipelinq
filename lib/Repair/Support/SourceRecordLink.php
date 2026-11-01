<?php

/**
 * Pipelinq SourceRecordLink.
 *
 * One ingested shillinq record and the pipelinq master entity it was linked to.
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
 *
 * @spec openspec/specs/product-vendor-master/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair\Support;

/**
 * One ingested shillinq record and the pipelinq master entity it was linked to,
 * written as an MDM sourceRecord for provenance.
 *
 * @spec openspec/specs/product-vendor-master/spec.md
 */
class SourceRecordLink {
	/**
	 * Constructor.
	 *
	 * @param string               $entityType    'product' or 'vendor'.
	 * @param string               $nativeId      The source-system native ID.
	 * @param string               $masterEntity  The pipelinq master UUID.
	 * @param array<string, mixed> $rawAttributes The unmodified shillinq payload.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $entityType,
		public readonly string $nativeId,
		public readonly string $masterEntity,
		public readonly array $rawAttributes,
	) {
	}//end __construct()

	/**
	 * The shillinq export the record came from.
	 *
	 * @return string 'shillinq-products' or 'shillinq-vendors'.
	 *
	 * @spec openspec/specs/product-vendor-master/spec.md
	 */
	public function sourceSystem(): string {
		return 'shillinq-' . $this->entityType . 's';
	}//end sourceSystem()

	/**
	 * The sourceRecord object to save.
	 *
	 * @param string $now The ISO-8601 time of the write.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/product-vendor-master/spec.md
	 */
	public function toRecord(string $now): array {
		$sourceSystem = $this->sourceSystem();
		return [
			'sourceRecordId' => "{$sourceSystem}:{$this->nativeId}",
			'sourceSystem' => $sourceSystem,
			'nativeId' => $this->nativeId,
			'entityType' => $this->entityType,
			'currentMasterEntity' => $this->masterEntity,
			'rawAttributes' => $this->rawAttributes,
			'mappedAttributes' => [],
			'firstSeen' => $now,
			'lastSeen' => $now,
			'lastChange' => $now,
			'linkageMethod' => 'migration',
			'linkageConfidence' => 1.0,
			'confidence' => 1.0,
		];
	}//end toRecord()
}//end class
