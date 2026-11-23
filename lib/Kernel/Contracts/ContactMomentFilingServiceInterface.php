<?php

/**
 * Contract of ContactMomentFilingService
 *
 * The public surface of ContactMomentFilingService that other components call, so they depend
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
 * Contract of ContactMomentFilingService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactMomentFilingServiceInterface {

	/**
	 * File an existing contact moment onto one further case.
	 *
	 * @param string $momentId The contact moment's uuid.
	 * @param string $caseId The case to file it onto.
	 *
	 * @return array<string, mixed> `status` plus either `contactMoment` or `error`.
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-filing-onto-a-further-case-is-an-act-not-a-copy-req-cms-004
	 */
	public function fileOnAlsoCase(string $momentId, string $caseId): array;

	/**
	 * The case a surface shows when it can show only one.
	 *
	 * Never answered by position: a stored primary that is still a member wins,
	 * and only a row that has no usable primary falls back to the first
	 * member, which is what the migration writes anyway.
	 *
	 * @param array<string, mixed> $moment The stored contact moment.
	 *
	 * @return string The primary case reference, or '' when the set is empty.
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-one-reference-is-the-primary-one-and-it-is-named-req-cms-002
	 */
	public function primaryOf(array $moment): string;

	/**
	 * What to say about a contact moment that is on more than one case.
	 *
	 * A case the reader may not see is reported as a count, never by title: the
	 * marker exists to warn an editor, not to leak the docket of a case they
	 * have no business reading.
	 *
	 * @param array<string, mixed> $moment The stored contact moment.
	 * @param string $hostId The case the reader is looking from.
	 * @param callable(string): bool $mayRead Answers whether the reader may see a case.
	 *
	 * @return array{shared: bool, alsoOnCases: array<int, string>, hiddenCount: int}
	 *   The marker.
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-a-shared-contact-moment-says-so-before-it-is-edited-req-cms-005
	 */
	public function sharedMarker(array $moment, string $hostId, callable $mayRead): array;

	/**
	 * Take a contact moment off one case.
	 *
	 * @param string $momentId The contact moment's uuid.
	 * @param string $caseId The case to take it off.
	 * @param string|null $newPrimary The next primary, required when the
	 *   primary itself is being removed.
	 *
	 * @return array<string, mixed> `status` plus either `contactMoment` or `error`.
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-a-contact-moment-cannot-be-left-with-no-case-req-cms-006
	 */
	public function unfileFromCase(string $momentId, string $caseId, ?string $newPrimary = null): array;
}//end interface
