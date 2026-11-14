<?php

/**
 * Contract of QuestionAnsweredNotice
 *
 * The public surface of QuestionAnsweredNotice that other components call, so they depend
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
 * Contract of QuestionAnsweredNotice.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface QuestionAnsweredNoticeInterface {

	/**
	 * Whether this save answered a resident's portal question.
	 *
	 * A question is a request ticket on the portal channel that carries the
	 * resident's subject reference (PortalContributionProvider `myQuestions`).
	 * It is answered when `customerMessage` changed to a text.
	 *
	 * @param array<string, mixed> $before The ticket before the save.
	 * @param array<string, mixed> $after  The ticket after the save.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/dossier-questions/spec.md#requirement-the-answer-notice-says-the-question-was-answered
	 */
	public function isAnswered(array $before, array $after): bool;

	/**
	 * Write the message for one answered question.
	 *
	 * Never throws: a message that cannot be written costs the notice, not
	 * the answer the employee saved.
	 *
	 * @param array<string, mixed> $ticket   The question, as saved.
	 * @param string               $ticketId The ticket uuid.
	 *
	 * @return bool Whether a message was written.
	 *
	 * @spec openspec/specs/dossier-questions/spec.md#requirement-the-answer-notice-says-the-question-was-answered
	 */
	public function tell(array $ticket, string $ticketId): bool;
}//end interface
