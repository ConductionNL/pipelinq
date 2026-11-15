<?php

/**
 * Contract of SubscriptionQueryService
 *
 * The public surface of SubscriptionQueryService that other components call, so they depend
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
 * Contract of SubscriptionQueryService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubscriptionQueryServiceInterface {

	/**
	 * Per-state counts for one list.
	 *
	 * @param string $listId MailingList UUID or slug.
	 *
	 * @return array{pending: int, confirmed: int, unsubscribed: int, bounced: int, total: int}
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function countsForList(string $listId): array;

	/**
	 * Every membership one contact holds.
	 *
	 * @param string $contactId Contact UUID or slug.
	 *
	 * @return array<int, array<string, mixed>> Subscription payloads.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function listSubscriptionsForContact(string $contactId): array;

	/**
	 * The memberships of one list, with a pagination envelope.
	 *
	 * @param string $listId MailingList UUID or slug.
	 * @param int $page 1-based page number.
	 * @param int $limit Page size (clamped 1..100).
	 *
	 * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function listSubscriptionsForList(string $listId, int $page, int $limit): array;
}//end interface
