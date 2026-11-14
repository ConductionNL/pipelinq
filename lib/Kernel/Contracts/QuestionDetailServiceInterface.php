<?php

/**
 * Contract of QuestionDetailService
 *
 * The public surface of QuestionDetailService that other components call, so they depend
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
 * Contract of QuestionDetailService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface QuestionDetailServiceInterface {

	/**
	 * The conversation on one question: the question, every answer, every
	 * reply, and the conversion into a Woo request, each with its moment.
	 *
	 * An answer comes from `portalAnswers`. The current `customerMessage` is
	 * added when that list does not end with it (a question answered before
	 * the list existed, or an answer saved elsewhere); it then carries the
	 * ticket's last change as its moment, as the request detail does.
	 *
	 * @param string $ticketId The question, already proven to be the resident's.
	 *
	 * @return array<int, array{id: string, occurredAt: string, message: string}> The entries, oldest first.
	 *
	 * @spec openspec/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-question-the-dossier-it-was-about-and-the-answers-req-qdp-001
	 */
	public function timeline(string $ticketId): array;

	/**
	 * What the question is about: the Woo request it became, when it did, and
	 * the documents of the dossier as they were when the resident asked, each
	 * with its public link when it had one.
	 *
	 * @param string $ticketId The question, already proven to be the resident's.
	 *
	 * @return array<int, array{id: string, title: string, url: string, note: string}> The items.
	 *
	 * @spec openspec/specs/dossier-questions/spec.md#requirement-a-converted-question-links-to-the-woo-request-req-qdp-002
	 */
	public function dossierItems(string $ticketId): array;
}//end interface
