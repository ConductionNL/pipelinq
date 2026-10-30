<?php

/**
 * Pipelinq LeadRotationService.
 *
 * Assigns a new, unassigned lead to the eligible colleague with the fewest
 * leads in the last 30 days, under their cap, and records why.
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
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lead rotation: the suggestion's candidates, taken in turn.
 *
 * Reuses RoutingService for the skill match and the availability filter, then
 * drops paused colleagues and those at their 30-day lead cap, and picks the one
 * with the fewest leads in the window. A tie goes to the colleague whose last
 * lead is oldest, so someone back from leave does not get a backlog at once.
 *
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
 */
class LeadRotationService {
	/**
	 * App-config switch for automatic lead assignment ('true' to switch on).
	 *
	 * @var string
	 */
	public const ENABLED_KEY = 'lead_rotation_enabled';

	/**
	 * The window the fairness and the cap are measured over, in days.
	 *
	 * @var int
	 */
	public const WINDOW_DAYS = 30;

	/**
	 * Leads read per colleague when counting the window.
	 *
	 * @var int
	 */
	private const MAX_ROWS = 500;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param RoutingService $routing The skill match and availability filter.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IL10N $l10n Writes the assignment reason.
	 * @param LoggerInterface $logger The logger.
	 * @param ITimeFactory|null $timeFactory The clock for the window.
	 *
	 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private RoutingService $routing,
		private ObjectServiceInterface $objectService,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private ?ITimeFactory $timeFactory = null,
	) {
	}//end __construct()

	/**
	 * Whether an administrator switched automatic lead assignment on.
	 *
	 * @return bool True when on.
	 *
	 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
	 */
	public function isEnabled(): bool {
		$value = strtolower(trim($this->appConfig->getValueString(Application::APP_ID, self::ENABLED_KEY, 'false')));
		return in_array($value, ['true', '1', 'yes', 'on'], true);
	}//end isEnabled()

	/**
	 * Assign a lead without an assignee, or explain why nobody got it.
	 *
	 * @param array<string, mixed> $lead The lead's current data.
	 *
	 * @return array<string, mixed>|null The lead with `assignee` and `assignmentReason`
	 *         set, or null when there is nothing to change (it has an assignee, or
	 *         the same reason is already recorded).
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
			$reason = $this->l10n->t('Rotation found no eligible colleague');
			if (($lead['assignmentReason'] ?? null) === $reason) {
				return null;
			}

			$lead['assignmentReason'] = $reason;
			return $lead;
		}

		$lead['assignee'] = $choice['userId'];
		$lead['assignmentReason'] = $this->reason(choice: $choice);
		return $lead;
	}//end assign()

	/**
	 * The eligible colleague with the fewest leads in the window.
	 *
	 * @param string $category The lead's category.
	 *
	 * @return array{userId: string, count: int, cap: int|null, skill: string, last: string}|null The pick, or null.
	 *
	 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
	 */
	public function choose(string $category): ?array {
		if (trim($category) === '') {
			return null;
		}

		$profiles = $this->routing->filterByAvailability(profiles: $this->routing->findMatchingAgents(category: $category));
		$since = $this->now()->sub(new DateInterval('P'.self::WINDOW_DAYS.'D'));

		$best = null;
		foreach ($profiles as $profile) {
			$candidate = $this->candidate(profile: $profile, since: $since);
			if ($candidate !== null && ($best === null || $this->beats(candidate: $candidate, best: $best) === true)) {
				$best = $candidate;
			}
		}

		return $best;
	}//end choose()

	/**
	 * One profile as a candidate, or null when paused, capped or without user.
	 *
	 * @param array<string, mixed> $profile The agent profile.
	 * @param DateTimeImmutable $since Start of the window.
	 *
	 * @return array{userId: string, count: int, cap: int|null, skill: string, last: string}|null The candidate.
	 */
	private function candidate(array $profile, DateTimeImmutable $since): ?array {
		$userId = trim((string)($profile['userId'] ?? ''));
		if ($userId === '' || ($profile['pauseLeadAssignment'] ?? false) === true) {
			return null;
		}

		$cap = null;
		if (is_numeric($profile['leadCapPer30Days'] ?? null) === true && (int)$profile['leadCapPer30Days'] > 0) {
			$cap = (int)$profile['leadCapPer30Days'];
		}

		$recent = $this->recentLeads(userId: $userId, since: $since);
		if ($cap !== null && $recent['count'] >= $cap) {
			return null;
		}

		return [
			'userId' => $userId,
			'count' => $recent['count'],
			'cap' => $cap,
			'skill' => (string)($profile['matchedSkill'] ?? ''),
			'last' => $recent['last'],
		];
	}//end candidate()

	/**
	 * Whether a candidate goes before the best so far: fewer leads, then the
	 * oldest last lead (a colleague with none goes first).
	 *
	 * @param array<string, mixed> $candidate The candidate.
	 * @param array<string, mixed> $best The best so far.
	 *
	 * @return bool True when the candidate wins.
	 */
	private function beats(array $candidate, array $best): bool {
		if ($candidate['count'] !== $best['count']) {
			return $candidate['count'] < $best['count'];
		}

		return $candidate['last'] < $best['last'];
	}//end beats()

	/**
	 * How many leads a colleague got in the window, and when the last one was.
	 *
	 * @param string $userId The colleague.
	 * @param DateTimeImmutable $since Start of the window.
	 *
	 * @return array{count: int, last: string} The count and the newest creation time ('' when none).
	 */
	private function recentLeads(string $userId, DateTimeImmutable $since): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		if ($register === '' || $schema === '') {
			return ['count' => 0, 'last' => ''];
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => ['register' => $register, 'schema' => $schema, 'assignee' => $userId],
					'limit' => self::MAX_ROWS,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('LeadRotationService: counting leads failed', ['userId' => $userId, 'exception' => $e->getMessage()]);
			return ['count' => 0, 'last' => ''];
		}

		$count = 0;
		$last = '';
		$from = $since->format('c');
		foreach ($rows as $row) {
			$created = $this->createdAt(row: $row, userId: $userId);
			if ($created === null) {
				continue;
			}

			$last = max($last, $created);
			if ($created >= $from) {
				$count++;
			}
		}

		return ['count' => $count, 'last' => $last];
	}//end recentLeads()

	/**
	 * A lead row's creation time as ISO 8601, when it belongs to the colleague.
	 *
	 * The assignee is checked again because OpenRegister's filter DSL ignores
	 * a key it does not recognise, which would count every lead.
	 *
	 * @param mixed $row The lead entity or array.
	 * @param string $userId The colleague.
	 *
	 * @return string|null The creation time normalised to `c`, or null.
	 */
	private function createdAt(mixed $row, string $userId): ?string {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false || (string)($row['assignee'] ?? '') !== $userId) {
			return null;
		}

		$self = ($row['@self'] ?? []);
		if (is_array($self) === false || (string)($self['created'] ?? '') === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable((string)$self['created']))->format('c');
		} catch (Throwable) {
			return null;
		}
	}//end createdAt()

	/**
	 * The reason the lead records for its owner.
	 *
	 * @param array{userId: string, count: int, cap: int|null, skill: string, last: string} $choice The pick.
	 *
	 * @return string The reason.
	 */
	private function reason(array $choice): string {
		if ($choice['cap'] === null) {
			return $this->l10n->t(
				'Rotation: fewest leads in 30 days (%1$s), skill %2$s',
				[(string)$choice['count'], $choice['skill']]
			);
		}

		return $this->l10n->t(
			'Rotation: fewest leads in 30 days (%1$s of cap %2$s), skill %3$s',
			[(string)$choice['count'], (string)$choice['cap'], $choice['skill']]
		);
	}//end reason()

	/**
	 * Now, from the clock when one is injected.
	 *
	 * @return DateTimeImmutable Now.
	 */
	private function now(): DateTimeImmutable {
		if ($this->timeFactory !== null) {
			return $this->timeFactory->now();
		}

		return new DateTimeImmutable();
	}//end now()
}//end class
