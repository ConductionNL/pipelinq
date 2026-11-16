<?php

/**
 * Contract of SurveyResponseService
 *
 * The public surface of SurveyResponseService that other components call, so they depend
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
 * Contract of SurveyResponseService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SurveyResponseServiceInterface {

	/**
	 * The survey behind a token, when it may still be answered.
	 *
	 * @param string $token The token.
	 *
	 * @return array<string, mixed> `status` plus either the survey or `state`.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	public function show(string $token): array;

	/**
	 * Accept an answer against a token.
	 *
	 * @param string $token The token.
	 * @param array<string, mixed> $answers The answers.
	 * @param bool|null $optOut Whether the respondent asked never to be asked
	 *   again. Null when the form carried no answer to that question; only an
	 *   explicit true records the opt-out.
	 *
	 * @return array<string, mixed> `status` plus either the response or `state`.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	public function submit(string $token, array $answers, ?bool $optOut = null): array;
}//end interface
