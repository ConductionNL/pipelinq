<?php

/**
 * Contract of ContactMomentLeafProvider
 *
 * The public surface of ContactMomentLeafProvider that other components call, so they depend
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
 * Contract of ContactMomentLeafProvider.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactMomentLeafProviderInterface {

	/**
	 * Default page size for a host's contact moments.
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT = 50;

	/**
	 * The contact moments filed on one host object, newest first.
	 *
	 * @param string $hostId The host object's uuid.
	 * @param int $limit Page size, capped at MAX_LIMIT.
	 * @param string $partyId The party whose standing indicators ride along, or
	 *   an empty string to answer without them.
	 *
	 * @return array<string, mixed> `status` plus either `contactMoments` or `error`.
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-contact-moments-are-a-data-provider-leaf-with-append-req-cmd-003
	 */
	public function list(string $hostId, int $limit = self::DEFAULT_LIMIT, string $partyId = ''): array;

	/**
	 * Append one contact moment to a host object.
	 *
	 * @param string $hostId The host object's uuid, written as `caseReference`.
	 * @param array<string, mixed> $payload Subject, channel, direction, outcome, summary.
	 *
	 * @return array<string, mixed> `status` plus either `contactMoment` or `error`.
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-contact-moments-are-a-data-provider-leaf-with-append-req-cmd-003
	 */
	public function create(string $hostId, array $payload): array;
}//end interface
