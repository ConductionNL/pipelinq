<?php

/**
 * Pipelinq PersonFetch.
 *
 * What fetching a person from the cache or the BRP gave.
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

use OCA\Pipelinq\Service\BsnAudit\LookupResult;

/**
 * The person (if any) a lookup fetched, with its outcome for the audit trail.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002-01
 */
class PersonFetch {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null $person     The person, or null.
	 * @param LookupResult              $result     Outcome, response code and correlation id.
	 * @param bool                      $fromCache  Whether the person came from the cache.
	 * @param int|float|null            $durationMs How long the BRP took to answer.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?array $person,
		public readonly LookupResult $result,
		public readonly bool $fromCache,
		public readonly int|float|null $durationMs,
	) {
	}//end __construct()

	/**
	 * This fetch with a stored person and a succeeded outcome.
	 *
	 * @param array<string, mixed> $person The stored person.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002-01
	 */
	public function succeeded(array $person): self {
		$result = new LookupResult(outcome: 'succeeded', responseCode: $this->result->responseCode, correlationId: $this->result->correlationId);
		return new self(person: $person, result: $result, fromCache: $this->fromCache, durationMs: $this->durationMs);
	}//end succeeded()

	/**
	 * This fetch with another outcome, keeping the person and the duration.
	 *
	 * @param LookupResult $result The outcome.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002-01
	 */
	public function withResult(LookupResult $result): self {
		return new self(person: $this->person, result: $result, fromCache: $this->fromCache, durationMs: $this->durationMs);
	}//end withResult()

	/**
	 * This fetch with another person.
	 *
	 * @param array<string, mixed>|null $person The person.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002-01
	 */
	public function withPerson(?array $person): self {
		return new self(person: $person, result: $this->result, fromCache: $this->fromCache, durationMs: $this->durationMs);
	}//end withPerson()

	/**
	 * Whether the person is under geheimhouding (indicatieGeheim 1).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002-01
	 */
	public function isSecret(): bool {
		return $this->person !== null && (string)($this->person['indicationSecret'] ?? '0') === '1';
	}//end isSecret()
}//end class
