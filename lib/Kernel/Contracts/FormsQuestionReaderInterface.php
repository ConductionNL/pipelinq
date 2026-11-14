<?php

/**
 * Contract of FormsQuestionReader
 *
 * The public surface of FormsQuestionReader that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of FormsQuestionReader.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FormsQuestionReaderInterface {

	/**
	 * A submission's answers, question id to value.
	 *
	 * Forms stores one row per answer, so a question with several chosen
	 * options arrives as several rows and becomes a list. Dates come in Forms'
	 * own formats (`Y-m-d H:i`, `H:i`) and leave in the ones a schema's
	 * `date-time` and `time` formats accept.
	 *
	 * @param int                                 $submissionId The Forms submission id.
	 * @param array<string, array<string, mixed>> $questions    The form's questions, from questions().
	 *
	 * @return array<string, mixed> Question id to answer.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-nextcloud-forms-form-feeds-pipelinq-only-through-a-checked-binding
	 */
	public function answers(int $submissionId, array $questions): array;

	/**
	 * A form's questions as field definitions, keyed by question id.
	 *
	 * @param int $formId The Forms form id.
	 *
	 * @return array<string, array<string, mixed>> Question id to `{ type, text, required, options? }`.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-nextcloud-forms-form-feeds-pipelinq-only-through-a-checked-binding
	 */
	public function questions(int $formId): array;
}//end interface
