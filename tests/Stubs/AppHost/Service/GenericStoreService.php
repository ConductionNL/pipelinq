<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test/analysis stub for OpenRegister's AppHost GenericStoreService (ADR-080).
 *
 * OpenRegister owns store DISCOVERY: the SSRF-guarded, redirect-refusing,
 * token-private fetch lives there once, for every app. This stub exists only so
 * pipelinq's StoreController resolves when OpenRegister is absent from the
 * analysis path; it deliberately implements NOTHING. A stub that returned
 * plausible cards would let a test pass against behaviour no engine provides.
 *
 * @category Test
 * @package  OCA\OpenRegister\AppHost\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

/**
 * Stub of OpenRegister's GenericStoreService.
 */
class GenericStoreService {
	/**
	 * Outcome: the request succeeded.
	 */
	public const OUTCOME_OK = 'ok';

	/**
	 * Outcome: no registry configured — no network call was made.
	 */
	public const OUTCOME_NOT_CONFIGURED = 'not_configured';

	/**
	 * Outcome: registry unreachable / timed out / non-2xx / redirected.
	 */
	public const OUTCOME_UNREACHABLE = 'store_unreachable';

	/**
	 * Outcome: registry returned an unparseable / unexpected body.
	 */
	public const OUTCOME_INVALID = 'store_invalid_response';

	/**
	 * Whether a remote registry is configured for this store.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 *
	 * @return bool
	 */
	public function isConfigured(StoreDescriptor $descriptor): bool {
		return false;
	}//end isConfigured()

	/**
	 * Search the remote store.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param string|null $query Optional free-text search term.
	 * @param string|null $kind Optional `kind` discriminator filter.
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>}
	 */
	public function search(StoreDescriptor $descriptor, ?string $query = null, ?string $kind = null): array {
		return ['outcome' => self::OUTCOME_NOT_CONFIGURED, 'cards' => []];
	}//end search()

	/**
	 * Resolve a single remote item by slug.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param string $slug The item slug.
	 *
	 * @return array<string, mixed>|null
	 */
	public function resolve(StoreDescriptor $descriptor, string $slug): ?array {
		return null;
	}//end resolve()
}//end class
