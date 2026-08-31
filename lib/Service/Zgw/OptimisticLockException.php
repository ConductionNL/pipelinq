<?php

/**
 * Pipelinq OptimisticLockException.
 *
 * Raised by a ZGW resource client (e.g. ZrcClient) when a PATCH operation returns HTTP 412
 * Precondition Failed. The bridge follows up with a fresh GET and packages
 * both representations (the stale pre-image we attempted to PATCH and the
 * fresh server representation) plus the field name whose mismatch caused
 * the conflict so the caller can decide how to merge.
 *
 * Per REQ-ZGW-009 the bridge MUST NOT auto-retry a 412 — that decision is
 * the caller's (typically a workflow that re-prompts the operator with the
 * fresh data).
 *
 * @category Exception
 * @package  OCA\Pipelinq\Service\Zgw
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

/**
 * Optimistic-concurrency (HTTP 412) error.
 *
 * @spec exclude exception type: carries a failure mode, not a behaviour a requirement
 *   can specify
 */
class OptimisticLockException extends ZgwException {
	/**
	 * Constructor.
	 *
	 * @param string $message Human-readable error message.
	 * @param array<string, mixed> $staleRepresentation Stale pre-image we sent.
	 * @param array<string, mixed> $freshRepresentation Fresh server representation.
	 * @param string $conflictingField Field whose mismatch caused the 412.
	 */
	public function __construct(
		string $message,
		public readonly array $staleRepresentation,
		public readonly array $freshRepresentation,
		public readonly string $conflictingField = '',
	) {
		parent::__construct(message: $message);
	}//end __construct()
}//end class
