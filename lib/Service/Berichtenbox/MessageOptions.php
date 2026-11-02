<?php

/**
 * Pipelinq MessageOptions.
 *
 * What a queued case status message may add: an explicit template, extra
 * template variables and attachments.
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
 * An explicit template, extra template variables and attachments for one message.
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 */
class MessageOptions {
	/**
	 * Constructor.
	 *
	 * @param array|null $templateOverride Explicit template payload; skips the registry lookup.
	 * @param array      $extraVariables   Extra template variables (e.g. gemeente, deadline,
	 *                                     caseType, language).
	 * @param array      $attachments      Attachments per BBK 1.7 (filename / mime /
	 *                                     sizeBytes / openregisterFileRef).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?array $templateOverride = null,
		public readonly array $extraVariables = [],
		public readonly array $attachments = [],
	) {
	}//end __construct()

	/**
	 * The case type given in the extra variables, or ''.
	 *
	 * @return string The case type.
	 *
	 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
	 */
	public function caseType(): string {
		return (string)($this->extraVariables['caseType'] ?? '');
	}//end caseType()

	/**
	 * The language given in the extra variables, or 'nl'.
	 *
	 * @return string The language.
	 *
	 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
	 */
	public function language(): string {
		return (string)($this->extraVariables['language'] ?? 'nl');
	}//end language()

	/**
	 * The defaults with the extra variables laid over them.
	 *
	 * @param array<string, mixed> $defaults The variables every message has.
	 *
	 * @return array<string, mixed> The template variables.
	 *
	 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
	 */
	public function variablesOver(array $defaults): array {
		return array_merge($defaults, $this->extraVariables);
	}//end variablesOver()
}//end class
