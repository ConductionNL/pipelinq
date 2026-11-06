<?php

/**
 * Pipelinq CaseStatusMessage.
 *
 * The case status change a resident is told about: the case, its contact
 * moment, the new status and the resident's BSN.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Berichtenbox
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Berichtenbox;

/**
 * The case status change a resident is told about.
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 */
class CaseStatusMessage {
	/**
	 * Constructor.
	 *
	 * @param string      $caseId        External case id.
	 * @param string|null $interactionId Linked contact moment id, if any.
	 * @param string      $status        New case status.
	 * @param string      $bsn           The resident's plaintext BSN (validated upstream).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly ?string $interactionId,
		public readonly string $status,
		#[\SensitiveParameter]
		public readonly string $bsn,
	) {
	}//end __construct()

	/**
	 * The template variables this message always has.
	 *
	 * @param string $gemeente  The municipality's display name.
	 * @param string $messageId The new message id.
	 *
	 * @return array<string, string> caseId, status, gemeente, deadline and messageId.
	 *
	 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
	 */
	public function variables(string $gemeente, string $messageId): array {
		return [
			'caseId' => $this->caseId,
			'status' => $this->status,
			'gemeente' => $gemeente,
			'deadline' => '',
			'messageId' => $messageId,
		];
	}//end variables()
}//end class
