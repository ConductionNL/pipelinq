<?php

/**
 * Pipelinq BrpLeafResults.
 *
 * Reads the answer of the OpenRegister BRP leaf.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The shape checks and the audit stamps of an OpenRegister BRP leaf answer
 * (`{ results: [<raw HAL+JSON person, 0..n>], total, meta }`).
 *
 * @spec openspec/specs/brp-lookup/spec.md
 */
final class BrpLeafResults {
	/**
	 * Whether a decoded leaf body carries a `results` list.
	 *
	 * @param mixed $payload The decoded body.
	 *
	 * @return bool True when it does.
	 *
	 * @spec openspec/specs/brp-lookup/spec.md
	 */
	public function hasResultList(mixed $payload): bool {
		return is_array($payload) === true && isset($payload['results']) === true && is_array($payload['results']) === true;
	}//end hasResultList()
	/**
	 * The result for the BSN asked for.
	 *
	 * A source in mock mode answers every person it knows to any BSN; a
	 * live source answers only the one asked. Read the one asked for, and
	 * the first person when none carries that BSN (as before).
	 *
	 * @param array<mixed> $results The leaf's results.
	 * @param string       $bsn     Raw 9-digit BSN.
	 *
	 * @return mixed The result asked for, else the first.
	 *
	 * @spec openspec/specs/brp-lookup/spec.md
	 */
	public function askedPerson(
		array $results,
		#[\SensitiveParameter]
		string $bsn,
	): mixed {
		foreach ($results as $candidate) {
			if (is_array($candidate) === true && (string)($candidate['burgerservicenummer'] ?? '') === $bsn) {
				return $candidate;
			}
		}

		return $results[0];
	}//end askedPerson()
	/**
	 * Stamp a leaf person with the audit metadata of the leaf's `meta`:
	 * `meta.correlationId` → `_correlationId`, `meta.durationMs` →
	 * `_responseDurationMs`, `meta.status` → `_responseStatus`.
	 *
	 * @param array<string, mixed> $person  The normalised person.
	 * @param array<string, mixed> $payload The leaf's payload.
	 *
	 * @return array<string, mixed> The stamped person.
	 *
	 * @spec openspec/specs/brp-lookup/spec.md
	 */
	public function withLeafMeta(array $person, array $payload): array {
		$meta = [];
		if (is_array($payload['meta'] ?? null) === true) {
			$meta = $payload['meta'];
		}

		$correlationId = ($meta['correlationId'] ?? null);
		if ($correlationId !== null) {
			$correlationId = (string)$correlationId;
		}

		$person['_correlationId'] = $correlationId;
		$person['_responseDurationMs'] = (int)($meta['durationMs'] ?? 0);
		$person['_responseStatus'] = (int)($meta['status'] ?? 200);
		return $person;
	}//end withLeafMeta()
}//end class
