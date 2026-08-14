<?php

/**
 * Pipelinq PointsRuleEngine.
 *
 * Evaluates PointsRule objects for a given trigger and context. Supports
 * conditie filters (category, excludeCategory, segment, dayOfWeek, timeRange,
 * channel) and formule types (fixed, percentage, stepped). Highest-priority
 * matching rule wins (non-cumulative); tier multipliers are applied AFTER the
 * formula and BEFORE rounding (REQ-LOY-002, REQ-LOY-003).
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Stateless points-rule evaluator.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) rule/condition/formula evaluation is inherently branchy; split across small focused methods
 */
class PointsRuleEngine {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Evaluate all rules for a programme + trigger and return matches by priority desc.
	 *
	 * @param string $programmeId The programme UUID.
	 * @param string $trigger The trigger type (purchase, signup, etc.).
	 * @param array<string, mixed> $context The evaluation context.
	 *
	 * @return array<int, array<string, mixed>> Matching rules, highest prioriteit first.
	 */
	public function evaluateRules(string $programmeId, string $trigger, array $context): array {
		$rules = $this->loadRules(programmeId: $programmeId, trigger: $trigger);
		$matches = [];

		foreach ($rules as $rule) {
			if ($this->isWithinValidity(rule: $rule, context: $context) === false) {
				continue;
			}

			$condition = $rule['condition'] ?? [];
			if (is_array($condition) === false) {
				$condition = [];
			}

			if ($this->evaluateCondition(condition: $condition, context: $context) === true) {
				$matches[] = $rule;
			}
		}

		usort(
			$matches,
			static fn (array $a, array $b): int => (int)($b['priority'] ?? 1) <=> (int)($a['priority'] ?? 1)
		);

		return $matches;
	}//end evaluateRules()

	/**
	 * Return the single highest-priority rule (or null if no matches).
	 *
	 * @param array<int, array<string, mixed>> $rules Output of evaluateRules.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getHighestPriorityRule(array $rules): ?array {
		return $rules[0] ?? null;
	}//end getHighestPriorityRule()

	/**
	 * Evaluate a JSON conditie object against a context.
	 *
	 * Supported keys: category (string|string[]), excludeCategory (string[]),
	 * segment (string[]), dayOfWeek (string|string[]), timeRange ("HH:MM-HH:MM"),
	 * channel (string|string[]).
	 *
	 * @param array<string, mixed> $condition The conditie object.
	 * @param array<string, mixed> $context Context: category, segment, channel, timestamp...
	 *
	 * @return bool True when conditions are met (or absent).
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function evaluateCondition(array $condition, array $context): bool {
		if ($condition === []) {
			return true;
		}

		if ($this->passesExcludeCategory(condition: $condition, context: $context) === false) {
			return false;
		}

		if ($this->passesCategory(condition: $condition, context: $context) === false) {
			return false;
		}

		if ($this->passesSegment(condition: $condition, context: $context) === false) {
			return false;
		}

		if ($this->passesChannel(condition: $condition, context: $context) === false) {
			return false;
		}

		if ($this->passesDayOfWeek(condition: $condition, context: $context) === false) {
			return false;
		}

		if ($this->passesTimeRange(condition: $condition, context: $context) === false) {
			return false;
		}

		return true;
	}//end evaluateCondition()

	/**
	 * Whether the excludeCategory condition (if present) allows the context category.
	 *
	 * @param array<string, mixed> $condition The conditie object.
	 * @param array<string, mixed> $context Evaluation context.
	 *
	 * @return bool
	 */
	private function passesExcludeCategory(array $condition, array $context): bool {
		if (isset($condition['excludeCategory']) === false) {
			return true;
		}

		$excluded = (array)$condition['excludeCategory'];
		$contextCategory = (string)($context['category'] ?? '');
		if ($contextCategory !== '' && in_array($contextCategory, $excluded, true) === true) {
			return false;
		}

		return true;
	}//end passesExcludeCategory()

	/**
	 * Whether the category condition (if present) matches the context category.
	 *
	 * @param array<string, mixed> $condition The conditie object.
	 * @param array<string, mixed> $context Evaluation context.
	 *
	 * @return bool
	 */
	private function passesCategory(array $condition, array $context): bool {
		if (isset($condition['category']) === false) {
			return true;
		}

		$allowed = (array)$condition['category'];
		$contextCategory = (string)($context['category'] ?? '');
		if ($contextCategory === '' || in_array($contextCategory, $allowed, true) === false) {
			return false;
		}

		return true;
	}//end passesCategory()

	/**
	 * Whether the segment condition (if present) matches the context segment.
	 *
	 * @param array<string, mixed> $condition The conditie object.
	 * @param array<string, mixed> $context Evaluation context.
	 *
	 * @return bool
	 */
	private function passesSegment(array $condition, array $context): bool {
		if (isset($condition['segment']) === false) {
			return true;
		}

		$allowed = (array)$condition['segment'];
		$contextSegment = (string)($context['segment'] ?? '');
		if ($contextSegment === '' || in_array($contextSegment, $allowed, true) === false) {
			return false;
		}

		return true;
	}//end passesSegment()

	/**
	 * Whether the channel condition (if present) matches the context channel.
	 *
	 * @param array<string, mixed> $condition The conditie object.
	 * @param array<string, mixed> $context Evaluation context.
	 *
	 * @return bool
	 */
	private function passesChannel(array $condition, array $context): bool {
		if (isset($condition['channel']) === false) {
			return true;
		}

		$allowed = (array)$condition['channel'];
		$contextChannel = (string)($context['channel'] ?? '');
		if ($contextChannel === '' || in_array($contextChannel, $allowed, true) === false) {
			return false;
		}

		return true;
	}//end passesChannel()

	/**
	 * Whether the dayOfWeek condition (if present) matches the context timestamp.
	 *
	 * @param array<string, mixed> $condition The conditie object.
	 * @param array<string, mixed> $context Evaluation context.
	 *
	 * @return bool
	 */
	private function passesDayOfWeek(array $condition, array $context): bool {
		if (isset($condition['dayOfWeek']) === false) {
			return true;
		}

		$allowed = array_map('strtolower', (array)$condition['dayOfWeek']);
		$day = $this->dayOfWeekFor(isoTimestamp: (string)($context['timestamp'] ?? ''));

		return in_array($day, $allowed, true);
	}//end passesDayOfWeek()

	/**
	 * Whether the timeRange condition (if present) matches the context timestamp.
	 *
	 * @param array<string, mixed> $condition The conditie object.
	 * @param array<string, mixed> $context Evaluation context.
	 *
	 * @return bool
	 */
	private function passesTimeRange(array $condition, array $context): bool {
		if (isset($condition['timeRange']) === false) {
			return true;
		}

		$timeRange = (string)$condition['timeRange'];

		return $this->isWithinTimeRange(timeRange: $timeRange, timestamp: (string)($context['timestamp'] ?? ''));
	}//end passesTimeRange()

	/**
	 * Calculate points from a formule + amount + tier multiplier.
	 *
	 * Supported formule types: fixed {value}, percentage {value}, stepped
	 * {brackets: [{amount,points}]}. Multiplier applied BEFORE floor rounding.
	 *
	 * @param array<string, mixed> $formula The formule.
	 * @param float $amount Transaction amount in EUR.
	 * @param float $multiplier Tier multiplier (default 1.0).
	 *
	 * @return int Points awarded (floored).
	 */
	public function calculatePoints(array $formula, float $amount, float $multiplier = 1.0): int {
		$type = (string)($formula['type'] ?? '');

		$raw = 0.0;
		switch ($type) {
			case 'fixed':
				$raw = (float)($formula['value'] ?? 0);
				break;
			case 'percentage':
				$raw = $amount * (float)($formula['value'] ?? 0);
				break;
			case 'stepped':
				$brackets = $formula['brackets'] ?? [];
				if (is_array($brackets) === true) {
					foreach ($brackets as $bracket) {
						$bracketAmount = (float)($bracket['amount'] ?? 0);
						if ($amount >= $bracketAmount) {
							$raw = (float)($bracket['points'] ?? 0);
						}
					}
				}
				break;
			default:
				$raw = 0.0;
		}//end switch

		$multiplied = $raw * max(0.0, $multiplier);

		return (int)floor($multiplied);
	}//end calculatePoints()

	/**
	 * Cap a points credit by a per-customer-per-period maximum.
	 *
	 * Counts ledger credit entries for the rule in the period (day/week/month/year)
	 * and returns the remaining quota (0 when reached).
	 *
	 * @param int $earnedInPeriod Points already credited under this rule in the period.
	 * @param int $pointsToAward Points the formula would award.
	 * @param ?int $max The max per period (null = no cap).
	 *
	 * @return int Points to actually award (0..pointsToAward).
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function applyMaxPerCustomer(int $earnedInPeriod, int $pointsToAward, ?int $max): int {
		if ($max === null || $max <= 0) {
			return $pointsToAward;
		}

		$remaining = max(0, $max - $earnedInPeriod);
		return min($pointsToAward, $remaining);
	}//end applyMaxPerCustomer()

	/**
	 * Load all rules for a programme/trigger combination.
	 *
	 * @param string $programmeId The programme UUID.
	 * @param string $trigger The trigger type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadRules(string $programmeId, string $trigger): array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '' || $programmeId === '' || $trigger === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'programmeId' => $programmeId,
						'trigger' => $trigger,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 200,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Pipelinq: rule findAll failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$ruleList = [];
		if (is_array($rows) === true) {
			$ruleList = array_values($rows);
		}

		return array_map([$this, 'toArray'], $ruleList);
	}//end loadRules()

	/**
	 * Whether a rule is within geldigVan/geldigTot for the context timestamp.
	 *
	 * @param array<string, mixed> $rule The PointsRule.
	 * @param array<string, mixed> $context Evaluation context.
	 *
	 * @return bool
	 */
	private function isWithinValidity(array $rule, array $context): bool {
		$effectiveTs = (string)($context['timestamp'] ?? '');
		if ($effectiveTs === '') {
			$effectiveTs = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		}

		$from = (string)($rule['validFrom'] ?? '');
		$to = (string)($rule['validTo'] ?? '');

		if ($from !== '' && substr($effectiveTs, 0, 10) < substr($from, 0, 10)) {
			return false;
		}

		if ($to !== '' && substr($effectiveTs, 0, 10) > substr($to, 0, 10)) {
			return false;
		}

		return true;
	}//end isWithinValidity()

	/**
	 * Compute the lower-case day-of-week name for an ISO timestamp.
	 *
	 * @param string $isoTimestamp ISO timestamp.
	 *
	 * @return string Lower-case day name.
	 */
	private function dayOfWeekFor(string $isoTimestamp): string {
		$dateTime = new DateTimeImmutable('now');
		try {
			if ($isoTimestamp !== '') {
				$dateTime = new DateTimeImmutable($isoTimestamp);
			}
		} catch (\Throwable $e) {
			$dateTime = new DateTimeImmutable('now');
		}

		return strtolower($dateTime->format('l'));
	}//end dayOfWeekFor()

	/**
	 * Check whether the timestamp HH:MM lies within "HH:MM-HH:MM".
	 *
	 * @param string $timeRange "14:00-18:00".
	 * @param string $timestamp ISO timestamp.
	 *
	 * @return bool
	 */
	private function isWithinTimeRange(string $timeRange, string $timestamp): bool {
		$parts = explode('-', $timeRange);
		if (count($parts) !== 2) {
			return true;
		}

		$dateTime = new DateTimeImmutable('now');
		try {
			if ($timestamp !== '') {
				$dateTime = new DateTimeImmutable($timestamp);
			}
		} catch (\Throwable $e) {
			$dateTime = new DateTimeImmutable('now');
		}

		$hhmm = $dateTime->format('H:i');
		return ($hhmm >= trim($parts[0])) && ($hhmm <= trim($parts[1]));
	}//end isWithinTimeRange()

	/**
	 * Resolve register + pointsRule schema id.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function config(): array {
		return [
			$this->appConfig->getValueString(Application::APP_ID, 'register', ''),
			$this->appConfig->getValueString(Application::APP_ID, 'pointsRule_schema', ''),
		];
	}//end config()

	/**
	 * Normalise OR entity/array to a plain array.
	 *
	 * @param mixed $object The entity or array.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod) invoked via array_map([$this, 'toArray'], ...) callable reference, not statically detected
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end toArray()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object The object service.
	 */
	private function getObjectService(): object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			throw new RuntimeException('OpenRegister ObjectService is unavailable.', 0, $e);
		}
	}//end getObjectService()
}//end class
