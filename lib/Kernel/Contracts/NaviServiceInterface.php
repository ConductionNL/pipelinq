<?php

/**
 * Contract of NaviService
 *
 * The public surface of NaviService that other components call, so they depend
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
 * Contract of NaviService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NaviServiceInterface {

	/**
	 * Main entry point. Detects intent, fetches context, returns the response.
	 *
	 * When a `conversationId` is supplied the preceding turns of that
	 * conversation are read back and a query whose own words classify as
	 * `unknown` inherits the previous turn's intent and subject instead of
	 * falling through to the clarification prompt. The turn is then appended to
	 * the record so the next follow-up can do the same.
	 *
	 * @param string $query Natural-language query from the user.
	 * @param string $userId Authenticated user id. Also scopes the
	 *                       conversation record; OpenRegister
	 *                       enforces object multi-tenancy itself.
	 * @param string|null $conversationId Conversation identifier minted by the
	 *                                    controller, or null for a one-shot
	 *                                    query that accumulates nothing.
	 *
	 * @return array{
	 *   query: string,
	 *   resultType: string,
	 *   chartData?: array<string, mixed>,
	 *   tableData?: array<string, mixed>,
	 *   textResponse: string,
	 *   suggestedFollowUps: array<int, string>,
	 *   conversationId?: string|null
	 * }
	 *
	 * @spec openspec/specs/dashboard/spec.md#conversational-follow-up
	 */
	public function processQuery(string $query, string $userId, ?string $conversationId = null): array;
}//end interface
