<?php

/**
 * Pipelinq LeadRotationService.
 *
 * Assigns a new lead without an assignee to the eligible colleague with the
 * fewest leads in the last 30 days, under their cap, and records why on the
 * lead (pipeline-lead-rotation).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Shares new leads in turn across the colleagues whose skill covers them.
 *
 * Eligible: the skill covers the lead's category, available, not paused and
 * under the 30-day cap. Of those, the one with the fewest leads in the last
 * 30 days wins; a tie goes to the one whose last lead is oldest.
 *
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
 */
class LeadRotationService {
	/**
	 * The app config switch for automatic lead assignment.
	 *
	 * @var string
	 */
	public const ENABLED_KEY = 'lead_rotation_enabled';

	/**
	 * The reason written when nobody is eligible.
	 *
	 * @var string
	 */
	public const NO_ELIGIBLE_REASON = 'rotation found no eligible colleague';

	/**
	 * The window the leads of a colleague are counted over.
	 *
	 * @var string
	 */
	private const WINDOW = 'P30D';

	/**
	 * Upper bound on the leads read per colleague, as RoutingService bounds its workload.
	 *
	 * @var int
	 */
	private const LEAD_LIMIT = 999;

	/**
	 * Constructor.
	 *
	 * @param RoutingService $routing The skill and availability matching.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 * @param IAppConfig $appConfig The app config.
	 * @param ITimeFactory $timeFactory The clock.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private RoutingService $routing,
		private ObjectServiceInterface $objectService,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether an administrator switched automatic lead assignment on.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
	 */
	public function isEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, self::ENABLED_KEY, 'false') === 'true';
	}//end isEnabled()

	/**
	 * Assign a lead in turn.
	 *
	 * @param array<string, mixed> $lead The lead's current data.
	 *
	 * @return array<string, mixed>|null The lead with `assignee` and
	 *                                   `assignmentReason`, only the reason when
	 *                                   nobody is eligible, or null when the lead
	 *                                   already has an assignee.
	 *
	 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
	 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-a-colleague-can-pause-their-share-req-lrt-002
	 */
	public function assign(array $lead): ?array {
		if (trim((string)($lead['assignee'] ?? '')) !== '') {
			return null;
		}

		$choice = $this->choose(category: (string)($lead['category'] ?? ''));
		if ($choice === null) {
			$lead['assignmentReason'] = self::NO_ELIGIBLE_REASON;
			return $lead;
		}

		$lead['assignee'] = $choice['userId'];
		$lead['assignmentReason'] = self::reasonFor(choice: $choice);

		return $lead;
	}//end assign()

	/**
	 * The eligible colleague for a category, with their count, cap and skill.
	 *
	 * @param string $category The lead's category.
	 *
	 * @return array{userId: string, count: int, last: string, cap: int|null, skill: string}|null
	 */
	private function choose(string $category): ?array {
		$profiles = $this->routing->filterByAvailability(
			profiles: $this->routing->findMatchingAgents(category: $category)
		);

		$cutoff = $this->now()->sub(new DateInterval(self::WINDOW));
		$eligible = [];
		foreach ($profiles as $profile) {
			$candidate = $this->candidate(profile: $profile, cutoff: $cutoff);
			if ($candidate !== null) {
				$eligible[] = $candidate;
			}
		}

		if ($eligible === []) {
			return null;
		}

		usort(
			$eligible,
			static fn (array $a, array $b): int => [$a['count'], $a['last'], $a['userId']] <=> [$b['count'], $b['last'], $b['userId']]
		);

		return $eligible[0];
	}//end choose()

	/**
	 * A profile as a candidate, or null when it is paused, has no user or is
	 * at its cap.
	 *
	 * @param array<string, mixed> $profile The agent profile.
	 * @param DateTimeImmutable $cutoff The start of the window.
	 *
	 * @return array{userId: string, count: int, last: string, cap: int|null, skill: string}|null
	 */
	private function candidate(array $profile, DateTimeImmutable $cutoff): ?array {
		$userId = (string)($profile['userId'] ?? '');
		if ($userId === '' || ($profile['pauseLeadAssignment'] ?? false) === true) {
			return null;
		}

		[$count, $last] = $this->recentLeads(userId: $userId, cutoff: $cutoff);
		$cap = self::capOf(profile: $profile);
		if ($cap !== null && $count >= $cap) {
			return null;
		}

		return [
			'userId' => $userId,
			'count' => $count,
			'last' => $last,
			'cap' => $cap,
			'skill' => (string)($profile['matchedSkill'] ?? ''),
		];
	}//end candidate()

	/**
	 * The profile's 30-day cap: a whole number of at least 1, else no cap.
	 *
	 * @param array<string, mixed> $profile The agent profile.
	 *
	 * @return int|null
	 */
	private static function capOf(array $profile): ?int {
		$cap = ($profile['leadCapPer30Days'] ?? null);
		if (is_int($cap) === false && (is_string($cap) === false || preg_match('/^[0-9]+$/', $cap) !== 1)) {
			return null;
		}

		$cap = (int)$cap;
		if ($cap < 1) {
			return null;
		}

		return $cap;
	}//end capOf()

	/**
	 * How many leads a colleague got since the cutoff, and when the last one
	 * was created ('' when none).
	 *
	 * @param string $userId The colleague.
	 * @param DateTimeImmutable $cutoff The start of the window.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function recentLeads(string $userId, DateTimeImmutable $cutoff): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		if ($register === '' || $schema === '') {
			return [0, ''];
		}

		try {
			$leads = $this->objectService->findAll(
				[
					'filters' => ['register' => $register, 'schema' => $schema, 'assignee' => $userId],
					'limit' => self::LEAD_LIMIT,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'LeadRotationService: failed to count recent leads',
				['exception' => $e->getMessage(), 'userId' => $userId]
			);
			return [0, ''];
		}

		$count = 0;
		$last = '';
		foreach ($leads as $lead) {
			$created = self::createdAt(lead: $lead);
			if ($created === null || $created < $cutoff) {
				continue;
			}

			$count++;
			// In UTC, so the ISO strings of different offsets compare in time order.
			$last = max($last, $created->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM));
		}

		return [$count, $last];
	}//end recentLeads()

	/**
	 * When a lead was created, from its metadata.
	 *
	 * @param mixed $lead The lead entity or array.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function createdAt(mixed $lead): ?DateTimeImmutable {
		if (is_object($lead) === true && method_exists($lead, 'jsonSerialize') === true) {
			$lead = $lead->jsonSerialize();
		}

		$created = '';
		if (is_array($lead) === true && is_array($lead['@self'] ?? null) === true) {
			$created = (string)($lead['@self']['created'] ?? '');
		}

		if ($created === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($created);
		} catch (\Exception $e) {
			return null;
		}
	}//end createdAt()

	/**
	 * Why the lead went to this colleague.
	 *
	 * @param array{userId: string, count: int, last: string, cap: int|null, skill: string} $choice The choice.
	 *
	 * @return string
	 */
	private static function reasonFor(array $choice): string {
		$cap = 'no cap';
		if ($choice['cap'] !== null) {
			$cap = 'cap ' . $choice['cap'];
		}

		$reason = sprintf('rotation: fewest leads in 30 days (%d of %s)', $choice['count'], $cap);
		if ($choice['skill'] !== '') {
			$reason .= ', skill ' . $choice['skill'];
		}

		return $reason;
	}//end reasonFor()

	/**
	 * The current time.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return DateTimeImmutable::createFromInterface($this->timeFactory->now());
	}//end now()
}//end class
