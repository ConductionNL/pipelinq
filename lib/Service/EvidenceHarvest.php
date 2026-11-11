<?php

/**
 * Pipelinq EvidenceHarvest.
 *
 * One DSAR evidence harvest in progress: the case, its subject, the register
 * context it reads from, the source being read and the items collected so far.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-016-pipelinq-evidence-source-registration
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A harvest run; built and filled by PipelinqEvidenceSourceProvider.
 *
 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-016-pipelinq-evidence-source-registration
 */
final class EvidenceHarvest {
	/**
	 * The OpenRegister object service.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * The register id.
	 *
	 * @var string
	 */
	public string $registerId = '';

	/**
	 * The schema id per source key.
	 *
	 * @var array<string, string>
	 */
	public array $schemaIds = [];

	/**
	 * The source key being read.
	 *
	 * @var string
	 */
	public string $sourceKey = '';

	/**
	 * The evidence items collected so far.
	 *
	 * @var array<int, object>
	 */
	public array $items = [];

	/**
	 * Constructor.
	 *
	 * @param string $caseUuid  The case object uuid.
	 * @param string $subjectId The case subject identifier.
	 */
	public function __construct(
		public readonly string $caseUuid,
		public readonly string $subjectId,
	) {
	}//end __construct()
}//end class
