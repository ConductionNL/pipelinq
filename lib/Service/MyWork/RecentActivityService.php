<?php

/**
 * Pipelinq RecentActivityService.
 *
 * The latest audit-trail entries of the work assigned to a user, for the
 * Recent activity section of My work: what happened lately to the tickets,
 * leads and follow-ups that are theirs.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\MyWork
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\MyWork;

use DateTimeInterface;
use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\WorklistServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the audit trail of a user's assigned work, newest first.
 *
 * The user's items come from the worklist, which reads them as the user, so
 * only objects the user may read are looked at. The trail is read for the
 * most recently changed items only, one read per item, to keep the page
 * cheap for a user with a long list.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
 */
class RecentActivityService {

	/**
	 * How many entries the section shows.
	 *
	 * @var int
	 */
	public const LIMIT = 10;

	/**
	 * How many of the most recently changed items have their trail read.
	 *
	 * @var int
	 */
	public const ITEMS_READ = 20;

	/**
	 * Constructor.
	 *
	 * @param WorklistServiceInterface $worklist     The user's work items.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, for the audit trail.
	 * @param LoggerInterface        $logger        The logger.
	 */
	public function __construct(
		private readonly WorklistServiceInterface $worklist,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The latest audit-trail entries of the user's assigned work.
	 *
	 * Each entry: `{ id, action, changes, user, userName, created, title,
	 * kind, routeName, recordId }`, newest first, at most LIMIT. An item whose
	 * trail cannot be read is left out, so one broken object never empties
	 * the section.
	 *
	 * @param string $userId The Nextcloud UID whose work to look at.
	 *
	 * @return array<int, array<string, string>> The entries.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	public function forUser(string $userId): array {
		$entries = [];
		foreach ($this->recentlyChanged(items: $this->worklist->items(userId: $userId)['items']) as $item) {
			foreach ($this->trailOf(item: $item) as $row) {
				$entries[] = $this->entry(row: $row, item: $item);
			}
		}

		usort($entries, fn (array $a, array $b): int => $this->timestamp(moment: $b['created']) <=> $this->timestamp(moment: $a['created']));

		return array_slice($entries, 0, self::LIMIT);
	}//end forUser()

	/**
	 * The items changed most recently, at most ITEMS_READ of them.
	 *
	 * @param array<int, array<string, mixed>> $items The user's work items.
	 *
	 * @return array<int, array<string, mixed>> The items whose trail is read.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	private function recentlyChanged(array $items): array {
		$items = array_values(array_filter($items, fn (array $item): bool => $this->uuidOf(item: $item) !== ''));
		usort($items, fn (array $a, array $b): int => $this->timestamp(moment: $this->updatedOf(item: $b)) <=> $this->timestamp(moment: $this->updatedOf(item: $a)));

		return array_slice($items, 0, self::ITEMS_READ);
	}//end recentlyChanged()

	/**
	 * The audit-trail rows of one item; none when they cannot be read.
	 *
	 * @param array<string, mixed> $item The work item.
	 *
	 * @return array<int, mixed> The rows.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	private function trailOf(array $item): array {
		try {
			return array_values($this->objectService->getLogs(uuid: $this->uuidOf(item: $item)));
		} catch (Throwable $e) {
			$this->logger->info('Pipelinq: My work read no audit trail for an item', ['exception' => $e->getMessage()]);
			return [];
		}
	}//end trailOf()

	/**
	 * One section entry from an audit-trail row and the item it belongs to.
	 *
	 * @param mixed                $row  The audit-trail row (an entity or an array).
	 * @param array<string, mixed> $item The work item.
	 *
	 * @return array<string, string> The entry.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	private function entry(mixed $row, array $item): array {
		$values = $this->rowValues(row: $row);
		$user = (string)($values['user'] ?? '');

		return [
			'id'        => (string)($values['uuid'] ?? ($values['id'] ?? '')),
			'action'    => (string)($values['action'] ?? ''),
			'changes'   => $this->changedFields(changed: ($values['changed'] ?? null)),
			'user'      => $user,
			'userName'  => (string)($values['userName'] ?? $user),
			'created'   => $this->moment(value: ($values['created'] ?? null)),
			'title'     => (string)($item['title'] ?? ''),
			'kind'      => (string)($item['kind'] ?? ''),
			'routeName' => (string)($item['routeName'] ?? ''),
			'recordId'  => $this->uuidOf(item: $item),
		];
	}//end entry()

	/**
	 * The fields an audit-trail row names as changed, as one line ("status, priority").
	 *
	 * Keys starting with `@` or `_` are bookkeeping, not fields the user set.
	 *
	 * @param mixed $changed The row's `changed` map.
	 *
	 * @return string The field names, or '' when it names none.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	private function changedFields(mixed $changed): string {
		if (is_array($changed) === false) {
			return '';
		}

		$fields = array_filter(
			array_map('strval', array_keys($changed)),
			static fn (string $field): bool => $field !== '' && in_array($field[0], ['@', '_'], true) === false
		);

		return implode(', ', $fields);
	}//end changedFields()

	/**
	 * A moment as a Unix timestamp; 0 when it cannot be read.
	 *
	 * @param string $moment The moment.
	 *
	 * @return int The timestamp.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	private function timestamp(string $moment): int {
		$time = strtotime($moment);

		return $time === false ? 0 : $time;
	}//end timestamp()

	/**
	 * An audit-trail row as an array.
	 *
	 * @param mixed $row The row: an entity, an array, or anything else.
	 *
	 * @return array<string, mixed> Its values; empty for anything else.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	private function rowValues(mixed $row): array {
		if ($row instanceof JsonSerializable) {
			$row = $row->jsonSerialize();
		}

		return is_array($row) === true ? $row : [];
	}//end rowValues()

	/**
	 * A moment as an ISO 8601 string; '' when there is none.
	 *
	 * @param mixed $value A DateTimeInterface or a string.
	 *
	 * @return string The moment.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	private function moment(mixed $value): string {
		if ($value instanceof DateTimeInterface) {
			return $value->format(DateTimeInterface::ATOM);
		}

		return is_string($value) === true ? $value : '';
	}//end moment()

	/**
	 * The item's object uuid: the record's `id`, else its `@self.id`.
	 *
	 * @param array<string, mixed> $item The work item.
	 *
	 * @return string The uuid, or ''.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	private function uuidOf(array $item): string {
		$record = is_array($item['record'] ?? null) === true ? $item['record'] : [];
		return (string)($record['@self']['id'] ?? ($record['id'] ?? ($item['id'] ?? '')));
	}//end uuidOf()

	/**
	 * When the item last changed, as stored in `@self.updated`; '' when unknown.
	 *
	 * @param array<string, mixed> $item The work item.
	 *
	 * @return string The moment.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	private function updatedOf(array $item): string {
		$record = is_array($item['record'] ?? null) === true ? $item['record'] : [];

		return $this->moment(value: ($record['@self']['updated'] ?? null));
	}//end updatedOf()
}//end class
