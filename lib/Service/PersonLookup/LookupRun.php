<?php

/**
 * Pipelinq LookupRun.
 *
 * One person lookup after its person was fetched.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\PersonLookup
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\PersonLookup;

use DateTimeImmutable;

/**
 * The query, the acting role, the request moment and the fetch of one lookup.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002-01
 */
class LookupRun {
	/**
	 * Constructor.
	 *
	 * @param LookupQuery       $query       The lookup asked for.
	 * @param string            $actorRole   The role the actor looks up under.
	 * @param DateTimeImmutable $requestedAt When the lookup started.
	 * @param PersonFetch       $fetch       What fetching the person gave.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly LookupQuery $query,
		public readonly string $actorRole,
		public readonly DateTimeImmutable $requestedAt,
		public readonly PersonFetch $fetch,
	) {
	}//end __construct()
}//end class
