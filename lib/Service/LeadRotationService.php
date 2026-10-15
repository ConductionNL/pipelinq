<?php

/**
 * Pipelinq LeadRotationService.
 *
 * Gives a new lead without an assignee to the eligible colleague with the
 * fewest leads assigned in the last 30 days. A colleague is eligible when the
 * skill-routing suggestion would offer them (their skill covers the lead's
 * category and they are available), they have not paused lead assignment and
 * they are under their own 30-day cap. A tie goes to the colleague whose last
 * assignment is oldest, so the turn is measured by outcome and a colleague
 * back from leave does not get a backlog in one morning.
 *
 * The service only decides; it returns the fields to write and never writes
 * them itself.
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

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Picks the colleague a new lead goes to.
 *
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
 */
class LeadRotationService {

	/**
	 * App config key of the admin switch; the value is 'true' or 'false'.
	 *
	 * @var string
	 */
	public const ENABLED_KEY = 'lead_rotation_enabled';

	/**
	 * The window the cap and the count look back over.
	 *
	 * @var int
	 */
	private const WINDOW_DAYS = 30;

	/**
	 * Upper bound on the leads read per colleague.
	 *
	 * @var int
	 */
	private const LEAD_LIMIT = 999;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     The switch, the register and the lead schema.
	 * @param RoutingService         $routing       Finds the colleagues whose skill covers a category.
	 * @param ObjectServiceInterface $objectService Reads each colleague's recent leads.
	 * @param ITimeFactory           $time          The current moment.
	 * @param IL10N                  $l10n          The reason sentence.
	 * @param LoggerInterface        $logger        Logger.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly RoutingService $routing,
		private readonly ObjectServiceInterface $objectService,
		private readonly ITimeFactory $time,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
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
		return $this->appConfig->getValueString(Application::APP_ID, self::ENABLED_KEY, 'false') === 'true';
	}//end isEnabled()

	/**
	 * The fields to write on a new lead: the assignee, why and when, or only why nobody.
	 *
	 * @param array<string, mixed> $lead The lead as stored.
	 *
	 * @return array<string, string> The fields; empty when the lead already has an assignee.
	 *
	 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
	 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-a-colleague-can-pause-their-share-req-lrt-002
	 */
	public function assign(array $lead): array {
		if (trim((string)($lead['assignee'] ?? '')) !== '') {
			return [];
		}

		$now = $this->time->now();
		$candidates = $this->routing->filterByAvailability(
			profiles: $this->routing->findMatchingAgents(category: trim((string)($lead['category'] ?? '')))
		);

		$eligible = $this->eligible(candidates: $candidates, since: $now->modify('-'.self::WINDOW_DAYS.' days'));
		if ($eligible === []) {
			return ['assignmentReason' => $this->l10n->t('Rotation found no eligible colleague.')];
		}

		usort($eligible, static fn (array $a, array $b): int => [$a['count'], $a['last'], $a['userId']] <=> [$b['count'], $b['last'], $b['userId']]);
		$chosen = $eligible[0];

		$cap = $this->l10n->t('no cap');
		if ($chosen['cap'] > 0) {
			$cap = $this->l10n->t('cap %s', [(string)$chosen['cap']]);
		}

		return [
			'assignee' => $chosen['userId'],
			'assignmentReason' => $this->l10n->t(
				'Rotation: fewest leads in 30 days (%1$s, %2$s), skill %3$s.',
				[(string)$chosen['count'], $cap, $chosen['skill']]
			),
			'assignedAt' => $now->format(DateTimeInterface::ATOM),
		];
	}//end assign()

	/**
	 * The candidates rotation may give a lead to, each with their recent count and last assignment.
	 *
	 * @param array<int, array<string, mixed>> $candidates Available colleagues whose skill matches.
	 * @param DateTimeImmutable                $since      Start of the window.
	 *
	 * @return array<int, array{userId: string, count: int, last: string, cap: int, skill: string}>
	 */
	private function eligible(array $candidates, DateTimeImmutable $since): array {
		$eligible = [];
		foreach ($candidates as $profile) {
			$userId = trim((string)($profile['userId'] ?? ''));
			if ($userId === '' || ($profile['pauseLeadAssignment'] ?? false) === true) {
				continue;
			}

			[$count, $last] = $this->recentAssignments(userId: $userId, since: $since);
			$cap = (int)($profile['leadCapPer30Days'] ?? 0);
			if ($cap > 0 && $count >= $cap) {
				continue;
			}

			$eligible[] = ['userId' => $userId, 'count' => $count, 'last' => $last, 'cap' => $cap, 'skill' => (string)($profile['matchedSkill'] ?? '')];
		}

		return $eligible;
	}//end eligible()

	/**
	 * How many leads rotation gave this colleague since the start of the window, and the latest moment.
	 *
	 * @param string            $userId The colleague.
	 * @param DateTimeImmutable $since  Start of the window.
	 *
	 * @return array{0: int, 1: string} The count, and the latest assignedAt as a UTC timestamp string ('' for none).
	 */
	private function recentAssignments(string $userId, DateTimeImmutable $since): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		if ($register === '' || $schema === '') {
			return [0, ''];
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => ['register' => $register, 'schema' => $schema, 'assignee' => $userId],
					'limit' => self::LEAD_LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: lead rotation could not count a colleague\'s leads', ['userId' => $userId, 'exception' => $e->getMessage()]);
			return [0, ''];
		}

		$count = 0;
		$last = '';
		foreach ($rows as $row) {
			$payload = $this->payloadOf(row: $row);
			if (($payload['assignee'] ?? null) !== $userId) {
				continue;
			}

			$assignedAt = $this->moment(value: ($payload['assignedAt'] ?? ''));
			if ($assignedAt === null) {
				continue;
			}

			$stamp = $assignedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
			if ($stamp > $last) {
				$last = $stamp;
			}

			if ($assignedAt >= $since) {
				$count++;
			}
		}//end foreach

		return [$count, $last];
	}//end recentAssignments()

	/**
	 * A stored lead as a plain array.
	 *
	 * @param mixed $row An ObjectEntity or an array.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function payloadOf(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			return (array)$row->getObject();
		}

		return [];
	}//end payloadOf()

	/**
	 * A date-time value, or null when it is empty or unreadable.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null The moment.
	 */
	private function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}
	}//end moment()
}//end class
