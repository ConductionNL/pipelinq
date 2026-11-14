<?php

/**
 * Contract of DossierQuestionService
 *
 * The public surface of DossierQuestionService that other components call, so they depend
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
 * Contract of DossierQuestionService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DossierQuestionServiceInterface {

	/**
	 * File a question about a dossier the resident owns.
	 *
	 * @param string $subjectRef   The verified portal subject reference.
	 * @param string $collectionId The dossier id the resident names.
	 * @param string $question     The question text.
	 * @param string $title        An optional subject line; the dossier title stands in.
	 *
	 * @return array<string, mixed>|null The new ticket's id and status, or null when the dossier is not the resident's.
	 *
	 * @throws \RuntimeException When the ticket cannot be saved.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function ask(string $subjectRef, string $collectionId, string $question, string $title = ''): ?array;

	/**
	 * Add the resident's reply to their own question.
	 *
	 * @param string $subjectRef The verified portal subject reference.
	 * @param string $ticketId   The ticket the resident replies on.
	 * @param string $message    The reply text.
	 *
	 * @return array<string, mixed>|null The ticket's id, status and replies, or null when it is not the resident's question.
	 *
	 * @throws \RuntimeException When the ticket cannot be saved.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-replies-to-an-answer-req-qcd-004
	 */
	public function reply(string $subjectRef, string $ticketId, string $message): ?array;
}//end interface
