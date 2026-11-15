<?php

/**
 * Pipelinq SlaPolicyQuery.
 *
 * The object an SLA policy is resolved for: its type, the customer tier,
 * organisation and contract it carries, and the resolution instant. Answers
 * whether a policy's type, tier and customer scope match, and orders the
 * matching policies from most to least specific.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Sla
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Sla;

use DateTimeInterface;

/**
 * Policy matching and ranking for one object.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
final class SlaPolicyQuery {
	/**
	 * Customer tier, or ''.
	 *
	 * @var string
	 */
	public readonly string $tier;

	/**
	 * Organisation UUID, or ''.
	 *
	 * @var string
	 */
	public readonly string $organisationId;

	/**
	 * Contract UUID, or ''.
	 *
	 * @var string
	 */
	public readonly string $contractId;

	/**
	 * Constructor.
	 *
	 * @param string               $objectType Object type.
	 * @param array<string, mixed> $metadata   The object's tier, organisationId and contractId.
	 * @param DateTimeInterface    $now        Resolution instant.
	 */
	public function __construct(
		public readonly string $objectType,
		array $metadata,
		public readonly DateTimeInterface $now,
	) {
		$this->tier = (string)($metadata['tier'] ?? '');
		$this->organisationId = (string)($metadata['organisationId'] ?? '');
		$this->contractId = (string)($metadata['contractId'] ?? '');
	}//end __construct()

	/**
	 * Whether the policy's type, tier and customer scope match this object.
	 *
	 * @param array<string, mixed> $policy Policy.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function matches(array $policy): bool {
		return $this->matchesAppliesTo(policy: $policy)
			&& $this->matchesTier(policy: $policy)
			&& $this->matchesScope(policy: $policy);
	}//end matches()

	/**
	 * Order two matching policies: more specific scope first, then lower
	 * `priority`, then newer `validFrom`.
	 *
	 * @param array<string, mixed> $first  One policy.
	 * @param array<string, mixed> $second The other policy.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function compare(array $first, array $second): int {
		$scopeFirst = $this->specificity(policy: $first);
		$scopeSecond = $this->specificity(policy: $second);
		if ($scopeFirst !== $scopeSecond) {
			return $scopeSecond <=> $scopeFirst;
		}

		$priorityFirst = (int)($first['priority'] ?? 100);
		$prioritySecond = (int)($second['priority'] ?? 100);
		if ($priorityFirst !== $prioritySecond) {
			return $priorityFirst <=> $prioritySecond;
		}

		return strcmp((string)($second['validFrom'] ?? ''), (string)($first['validFrom'] ?? ''));
	}//end compare()

	/**
	 * Whether the policy's `appliesTo` matches the object type.
	 *
	 * @param array<string, mixed> $policy Policy.
	 *
	 * @return bool
	 */
	private function matchesAppliesTo(array $policy): bool {
		$applies = (string)($policy['appliesTo'] ?? '*');
		return ($applies === '*' || $applies === $this->objectType);
	}//end matchesAppliesTo()

	/**
	 * Whether the policy's `customerTier` matches, defaulting the
	 * customer's tier to "bronze" when unspecified.
	 *
	 * @param array<string, mixed> $policy Policy.
	 *
	 * @return bool
	 */
	private function matchesTier(array $policy): bool {
		$policyTier = (string)($policy['customerTier'] ?? '*');
		$effectiveTier = 'bronze';
		if ($this->tier !== '') {
			$effectiveTier = $this->tier;
		}

		return ($policyTier === '*' || $policyTier === $effectiveTier);
	}//end matchesTier()

	/**
	 * Whether the policy's `customerScope` (contract/organisation
	 * allow-lists) matches, when a scope is configured at all.
	 *
	 * @param array<string, mixed> $policy Policy.
	 *
	 * @return bool
	 */
	private function matchesScope(array $policy): bool {
		$scope = $policy['customerScope'] ?? null;
		if (is_array($scope) === false) {
			return true;
		}

		$orgs = (array)($scope['organisationIds'] ?? []);
		$contracts = (array)($scope['contractIds'] ?? []);
		if ($orgs !== [] && in_array($this->organisationId, $orgs, true) === false) {
			return false;
		}

		return ($contracts === [] || in_array($this->contractId, $contracts, true) === true);
	}//end matchesScope()

	/**
	 * Score the specificity of a policy's scope for sort ordering.
	 *
	 * @param array<string, mixed> $policy Policy.
	 *
	 * @return int Specificity (higher = more specific).
	 */
	private function specificity(array $policy): int {
		$scope = $policy['customerScope'] ?? null;
		if (is_array($scope) === false) {
			// No scope = wildcard.
			if (($policy['customerTier'] ?? '*') === '*') {
				return 0;
			}

			return 1;
		}

		$contracts = (array)($scope['contractIds'] ?? []);
		$orgs = (array)($scope['organisationIds'] ?? []);
		if ($contracts !== [] && $this->contractId !== '' && in_array($this->contractId, $contracts, true) === true) {
			return 4;
		}

		if ($orgs !== [] && $this->organisationId !== '' && in_array($this->organisationId, $orgs, true) === true) {
			return 3;
		}

		return 2;
	}//end specificity()
}//end class
