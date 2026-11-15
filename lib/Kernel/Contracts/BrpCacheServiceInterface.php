<?php

/**
 * Contract of BrpCacheService
 *
 * The public surface of BrpCacheService that other components call, so they depend
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
 * Contract of BrpCacheService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BrpCacheServiceInterface {

	/**
	 * Return the most recent unexpired BrpPersoon for a raw BSN, or null on cache-miss.
	 *
	 * @param string $rawBsn Raw BSN — hashed in-process only.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-004-01
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-004-02
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::hash is a pure stateless helper.
	 */
	public function get(
		#[\SensitiveParameter]
		string $rawBsn,
	): ?array;

	/**
	 * Mark cache entries for a BSN as expired (retentieTot = now-1s).
	 *
	 * Called by the BRP mutation webhook listener.
	 *
	 * @param string $rawBsn Raw BSN.
	 *
	 * @return int Number of cache entries invalidated.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-004-03
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::hash is a pure stateless helper.
	 */
	public function invalidate(
		#[\SensitiveParameter]
		string $rawBsn,
	): int;

	/**
	 * Persist a BrpPersoon and stamp it with retentieTot = now + ttlHours.
	 *
	 * @param array<string,mixed> $person Normalised BrpPersoon array (without retentieTot).
	 * @param int|null $ttlHours Override TTL — defaults to configured / 24h.
	 *
	 * @return array<string,mixed> The saved object as array (with assigned UUID).
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-004-04
	 */
	public function set(array $person, ?int $ttlHours = null): array;
}//end interface
