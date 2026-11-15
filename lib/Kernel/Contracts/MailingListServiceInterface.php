<?php

/**
 * Contract of MailingListService
 *
 * The public surface of MailingListService that other components call, so they depend
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
 * Contract of MailingListService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MailingListServiceInterface {

	/**
	 * Create a mailing list after validation.
	 *
	 * `createdBy` is stamped from the authenticated user id; a
	 * client-supplied value is ignored (ADR-005).
	 *
	 * @param array<string, mixed> $payload Inbound payload.
	 * @param string $createdByUid Authenticated user id.
	 *
	 * @return array{list?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function createMailingList(array $payload, string $createdByUid): array;

	/**
	 * Fetch one mailing list by UUID or slug.
	 *
	 * @param string $listId MailingList UUID or slug.
	 *
	 * @return array<string, mixed>|null The list payload, or null when it
	 *                                   does not exist.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function getMailingListById(string $listId): ?array;

	/**
	 * List mailing lists with a pagination envelope.
	 *
	 * @param int $page 1-based page number (clamped to >= 1).
	 * @param int $limit Page size (clamped 1..100).
	 *
	 * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function listMailingLists(int $page, int $limit): array;

	/**
	 * Patch a mailing list's editable fields.
	 *
	 * Identity fields (`createdBy`, `createdAt`) are never taken from the
	 * request. An unknown key is dropped rather than merged.
	 *
	 * @param string $listId MailingList UUID or slug.
	 * @param array<string, mixed> $patch Fields to change.
	 *
	 * @return array{list?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function updateMailingList(string $listId, array $patch): array;
}//end interface
