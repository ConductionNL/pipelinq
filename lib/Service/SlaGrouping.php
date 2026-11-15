<?php

/**
 * Pipelinq SlaGrouping.
 *
 * How one SLA attainment computation groups: the group keys a breach or a
 * tracked object falls in, and the display name of a group, both read off
 * the grouping context (groupBy, policies, clients).
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
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The grouping rules of one attainment computation.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
final class SlaGrouping {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $context The grouping context: groupBy, policies and clients.
	 */
	public function __construct(
		private readonly array $context,
	) {
	}//end __construct()

	/**
	 * The groups a met object or a breach event counts in. One group, except
	 * when grouping by target: a met object counts once per target it met.
	 *
	 * A breach event's own fields win; otherwise the values come from the
	 * tracked object it belongs to, so met and breached rows of one group
	 * describe the same objects. A tier falls back to the policy's own tier,
	 * which is how the engine chose the policy.
	 *
	 * @param array<string, mixed> $subject The SLA policy identity (policyId), the target kinds counted (kinds),
	 *                                      the tracked object (tracked, [] when unknown) and the breach event
	 *                                      (event, [] for a met object).
	 *
	 * @return array<int, string> Group keys.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function keysFor(array $subject): array {
		['policyId' => $policyId, 'kinds' => $kinds, 'tracked' => $tracked, 'event' => $event] = $subject;
		$policyTier = (string)($this->context['policies'][$policyId]['customerTier'] ?? '');
		if ($policyTier === '*') {
			$policyTier = '';
		}

		return match ($this->context['groupBy']) {
			'policy' => [self::firstFilled(values: [$policyId])],
			'target' => $kinds,
			'tier' => [
				self::firstFilled(
					values: [
						$event['customerTier'] ?? null,
						$tracked['slaTier'] ?? null,
						$tracked['customerTier'] ?? null,
						$policyTier,
					]
				),
			],
			'team' => [self::firstFilled(values: [$event['team'] ?? null, $tracked['team'] ?? null])],
			'customer' => [self::firstFilled(values: [$event['organisationId'] ?? null, $tracked['client'] ?? null])],
			default => ['all'],
		};
	}//end keysFor()


	/**
	 * The display name of a group: the policy's or client's name where the
	 * key is an id, the key itself otherwise.
	 *
	 * @param string $key Group key.
	 *
	 * @return string Group display name.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function nameOf(string $key): string {
		$name = match ($this->context['groupBy']) {
			'policy' => (string)($this->context['policies'][$key]['name'] ?? ''),
			'customer' => (string)($this->context['clients'][$key] ?? ''),
			default => '',
		};

		if ($name === '') {
			return $key;
		}

		return $name;
	}//end nameOf()


	/**
	 * The first non-empty string, or `unspecified`.
	 *
	 * @param array<int, mixed> $values Candidates, in order of preference.
	 *
	 * @return string The value.
	 */
	private static function firstFilled(array $values): string {
		foreach ($values as $value) {
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return 'unspecified';
	}//end firstFilled()
}//end class
