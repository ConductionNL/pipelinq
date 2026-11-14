<?php

/**
 * Pipelinq LabelRenameService.
 *
 * Renames a label in the shared list and rewrites the old name on the leads,
 * clients and articles that carry it, so a filter on the new name finds them.
 * Only an administrator renames: the check runs before anything is read or
 * written, and every write goes through OpenRegister as the caller with its
 * access check on.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Labels
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-an-administrator-manages-the-labels-on-beheer-marketing
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Labels;

use OCA\Pipelinq\BackgroundJob\LabelRenameJob;
use OCA\Pipelinq\Kernel\Contracts\LabelRenameServiceInterface;
use OCA\Pipelinq\Util\SerialisedToArray;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Renames a label and rewrites it on its items.
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-an-administrator-manages-the-labels-on-beheer-marketing
 */
class LabelRenameService implements LabelRenameServiceInterface {
	use SerialisedToArray;

	/**
	 * The register the label fragment declares the schema in.
	 *
	 * @var string
	 */
	public const REGISTER = 'pipelinq';

	/**
	 * The label schema slug.
	 *
	 * @var string
	 */
	public const LABEL_SCHEMA = 'itemLabel';

	/**
	 * The fields that hold label names, by schema slug.
	 *
	 * @var array<string, string>
	 */
	public const TARGETS = [
		'lead'    => 'labels',
		'client'  => 'labels',
		'article' => 'tags',
	];

	/**
	 * How many items a rename rewrites in the request; the rest goes to a background job.
	 *
	 * @var integer
	 */
	public const INLINE_LIMIT = 200;

	/**
	 * How many items one read returns.
	 *
	 * @var integer
	 */
	private const PAGE = 100;

	/**
	 * The longest name the label schema accepts.
	 *
	 * @var integer
	 */
	private const MAX_NAME = 80;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container    DI container (OpenRegister's ObjectService).
	 * @param IAppManager        $appManager   Answers whether OpenRegister is installed.
	 * @param IUserSession       $userSession  The caller's session.
	 * @param IGroupManager      $groupManager Answers whether the caller is an administrator.
	 * @param IJobList           $jobList      Queues the rest of a large rename.
	 * @param LoggerInterface    $logger       Logger.
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-an-administrator-manages-the-labels-on-beheer-marketing
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppManager $appManager,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Rename a label and rewrite its old name on the items that carry it.
	 *
	 * @param string $labelId The label's id.
	 * @param string $name    The new name.
	 *
	 * @return array{id: string, name: string, rewritten: int, queued: bool}
	 *
	 * @throws LabelRenameException When the caller is not an administrator (403), the name is
	 *                              empty or too long (400), the label is unknown (404), another
	 *                              label already has the name (409) or OpenRegister is missing (503).
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
	 */
	public function rename(string $labelId, string $name): array {
		$userId = $this->requireAdmin();
		$name = $this->validName(name: $name);

		$objects = $this->objectService();
		$label = $this->existingLabel(objects: $objects, labelId: $labelId);
		$this->assertNameIsFree(objects: $objects, labelId: $labelId, name: $name);

		$from = (string)($label['name'] ?? '');
		if ($from === $name) {
			return ['id' => $labelId, 'name' => $name, 'rewritten' => 0, 'queued' => false];
		}

		$this->saveName(objects: $objects, label: $label, labelId: $labelId, name: $name);
		$rewritten = $this->rewrite(rename: ['objects' => $objects, 'from' => $from, 'to' => $name], budget: self::INLINE_LIMIT);
		$queued = $this->queueRemainder(objects: $objects, from: $from, to: $name, userId: $userId);

		return ['id' => $labelId, 'name' => $name, 'rewritten' => $rewritten, 'queued' => $queued];
	}//end rename()

	/**
	 * The signed-in administrator's user id.
	 *
	 * @return string
	 *
	 * @throws LabelRenameException When the caller is not an administrator (403).
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
	 */
	private function requireAdmin(): string {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $this->groupManager->isAdmin($userId) === false) {
			throw new LabelRenameException(message: 'Only an administrator can rename a label.', status: 403);
		}

		return $userId;
	}//end requireAdmin()

	/**
	 * The trimmed name, when it has 1 to MAX_NAME characters.
	 *
	 * @param string $name The new name.
	 *
	 * @return string
	 *
	 * @throws LabelRenameException When the name is empty or too long (400).
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
	 */
	private function validName(string $name): string {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
			throw new LabelRenameException(message: 'A label name has 1 to 80 characters.', status: 400);
		}

		return $name;
	}//end validName()

	/**
	 * The stored label.
	 *
	 * @param object $objects The ObjectService.
	 * @param string $labelId The label's id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws LabelRenameException When the label does not exist (404).
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
	 */
	private function existingLabel(object $objects, string $labelId): array {
		$label = $this->toArray(value: $this->findLabel(objects: $objects, labelId: $labelId));
		if ($label === []) {
			throw new LabelRenameException(message: 'This label does not exist.', status: 404);
		}

		return $label;
	}//end existingLabel()

	/**
	 * Save the label under its new name.
	 *
	 * @param object $objects The ObjectService.
	 * @param array<string, mixed> $label The stored label.
	 * @param string $labelId The label's id.
	 * @param string $name The new name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
	 */
	private function saveName(object $objects, array $label, string $labelId, string $name): void {
		$label['name'] = $name;
		$objects->saveObject(object: $label, register: self::REGISTER, schema: self::LABEL_SCHEMA, uuid: $labelId);
	}//end saveName()

	/**
	 * Queue the rest of the rewrite when items still carry the old name.
	 *
	 * @param object $objects The ObjectService.
	 * @param string $from The old name.
	 * @param string $to The new name.
	 * @param string $userId The administrator who renamed.
	 *
	 * @return bool Whether a job was queued.
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
	 */
	private function queueRemainder(object $objects, string $from, string $to, string $userId): bool {
		if ($this->anyCarries(objects: $objects, name: $from) === true) {
			$this->jobList->add(LabelRenameJob::class, ['from' => $from, 'to' => $to, 'userId' => $userId]);
			return true;
		}

		return false;
	}//end queueRemainder()

	/**
	 * Rewrite every remaining item, for the background job of a large rename.
	 *
	 * The job puts the administrator who asked in the session first, so the
	 * writes pass OpenRegister's access check as that person.
	 *
	 * @param string $from   The old name.
	 * @param string $to     The new name.
	 * @param string $userId The administrator who renamed the label.
	 *
	 * @return integer How many items were rewritten; 0 when the user is no longer an administrator.
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
	 */
	public function rewriteAll(string $from, string $to, string $userId): int {
		if ($from === '' || $to === '' || $this->groupManager->isAdmin($userId) === false) {
			return 0;
		}

		return $this->rewrite(rename: ['objects' => $this->objectService(), 'from' => $from, 'to' => $to], budget: PHP_INT_MAX);
	}//end rewriteAll()

	/**
	 * Refuse a name another label already has, whatever the case.
	 *
	 * @param object $objects The ObjectService.
	 * @param string $labelId The label being renamed.
	 * @param string $name    The new name.
	 *
	 * @return void
	 *
	 * @throws LabelRenameException With 409 when the name is taken.
	 */
	private function assertNameIsFree(object $objects, string $labelId, string $name): void {
		$rows = $objects->findAll(
			config: ['limit' => 1000, 'filters' => ['register' => self::REGISTER, 'schema' => self::LABEL_SCHEMA]]
		);
		foreach ($rows as $row) {
			$other = $this->toArray(value: $row);
			$otherId = (string)($other['id'] ?? ($other['@self']['id'] ?? ''));
			if ($otherId !== $labelId && mb_strtolower((string)($other['name'] ?? '')) === mb_strtolower($name)) {
				throw new LabelRenameException(message: 'Another label already has this name.', status: 409);
			}
		}
	}//end assertNameIsFree()

	/**
	 * Rewrite the old name on up to `$budget` items.
	 *
	 * @param array{objects: object, from: string, to: string} $rename The ObjectService, the old and the new name.
	 * @param integer                                          $budget How many items to rewrite at most.
	 *
	 * @return integer How many items were rewritten.
	 */
	private function rewrite(array $rename, int $budget): int {
		$done = 0;
		foreach (self::TARGETS as $schema => $field) {
			$scan = ['objects' => $rename['objects'], 'schema' => $schema, 'field' => $field, 'name' => $rename['from']];
			$done = $this->rewriteTarget(rename: $rename, scan: $scan, budget: $budget, done: $done);
		}

		return $done;
	}//end rewrite()

	/**
	 * Rewrite the items of one schema page by page until the budget is spent or no new item turns up.
	 *
	 * @param array{objects: object, from: string, to: string} $rename The ObjectService, the old and the new name.
	 * @param array{objects: object, schema: string, field: string, name: string} $scan The schema, field and name to scan for.
	 * @param integer $budget How many items to rewrite at most, over all schemas.
	 * @param integer $done How many items were rewritten so far.
	 *
	 * @return integer How many items were rewritten, including the earlier ones.
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
	 */
	private function rewriteTarget(array $rename, array $scan, int $budget, int $done): int {
		$seen = [];
		while ($done < $budget) {
			$rows = $this->carriers(scan: $scan, limit: min(self::PAGE, $budget - $done));
			[$fresh, $written, $seen] = $this->rewritePage(rename: $rename, scan: $scan, rows: $rows, seen: $seen);
			$done += $written;

			if ($fresh === 0) {
				break;
			}
		}

		return $done;
	}//end rewriteTarget()

	/**
	 * Rewrite the items of one page that were not seen before.
	 *
	 * @param array{objects: object, from: string, to: string} $rename The ObjectService, the old and the new name.
	 * @param array{objects: object, schema: string, field: string, name: string} $scan The schema, field and name scanned for.
	 * @param array<int, array<string, mixed>> $rows The page.
	 * @param array<string, bool> $seen The ids seen so far in this schema.
	 *
	 * @return array{0: int, 1: int, 2: array<string, bool>} The new items, the items rewritten, and the ids seen.
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
	 */
	private function rewritePage(array $rename, array $scan, array $rows, array $seen): array {
		$fresh = 0;
		$written = 0;
		foreach ($rows as $row) {
			$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
			if ($id === '' || isset($seen[$id]) === true) {
				continue;
			}

			$seen[$id] = true;
			$fresh++;
			$written += (int)$this->rewriteOne(rename: $rename, item: ['schema' => $scan['schema'], 'field' => $scan['field'], 'row' => $row]);
		}

		return [$fresh, $written, $seen];
	}//end rewritePage()

	/**
	 * Replace the old name on one item, keeping each name once.
	 *
	 * @param array{objects: object, from: string, to: string}                        $rename The ObjectService, the old and the new name.
	 * @param array{schema: string, field: string, row: array<string, mixed>} $item   The item's schema slug, the field holding the names, and the item.
	 *
	 * @return boolean True when the item was written.
	 */
	private function rewriteOne(array $rename, array $item): bool {
		['schema' => $schema, 'field' => $field, 'row' => $row] = $item;
		['from' => $from, 'to' => $to] = $rename;
		$names = [];
		foreach ((array)($row[$field] ?? []) as $value) {
			$value = (string)$value;
			if ($value === $from) {
				$value = $to;
			}

			if (in_array($value, $names, true) === false) {
				$names[] = $value;
			}
		}

		$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		$row[$field] = $names;
		try {
			$rename['objects']->saveObject(object: $row, register: self::REGISTER, schema: $schema, uuid: $id);
		} catch (Throwable $e) {
			$this->logger->warning(
				'LabelRenameService: an item kept the old label',
				['schema' => $schema, 'id' => $id, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end rewriteOne()

	/**
	 * Whether any item still carries a name.
	 *
	 * @param object $objects The ObjectService.
	 * @param string $name    The name.
	 *
	 * @return boolean
	 */
	private function anyCarries(object $objects, string $name): bool {
		foreach (self::TARGETS as $schema => $field) {
			if ($this->carriers(scan: ['objects' => $objects, 'schema' => $schema, 'field' => $field, 'name' => $name], limit: 1) !== []) {
				return true;
			}
		}

		return false;
	}//end anyCarries()

	/**
	 * The items whose field holds a name.
	 *
	 * OpenRegister reads an array filter as "contains"; the rows are checked
	 * again here, because a filter it does not apply returns every row.
	 *
	 * @param array{objects: object, schema: string, field: string, name: string} $scan  The ObjectService, the schema slug,
	 *                                                                           the field holding the names and the name.
	 * @param integer                                                            $limit How many rows to read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function carriers(array $scan, int $limit): array {
		['schema' => $schema, 'field' => $field, 'name' => $name] = $scan;
		$rows = $scan['objects']->findAll(
			config: [
				'limit'   => $limit,
				'filters' => [$field => $name, 'register' => self::REGISTER, 'schema' => $schema],
			]
		);

		$out = [];
		foreach ($rows as $row) {
			$payload = $this->toArray(value: $row);
			if (in_array($name, (array)($payload[$field] ?? []), true) === true) {
				$out[] = $payload;
			}
		}

		return $out;
	}//end carriers()

	/**
	 * Read the label, or null when it does not exist.
	 *
	 * @param object $objects The ObjectService.
	 * @param string $labelId The label's id.
	 *
	 * @return mixed The label, or null.
	 */
	private function findLabel(object $objects, string $labelId): mixed {
		try {
			return $objects->find(id: $labelId, register: self::REGISTER, schema: self::LABEL_SCHEMA);
		} catch (Throwable $e) {
			return null;
		}
	}//end findLabel()

	/**
	 * OpenRegister's ObjectService, once OpenRegister is known to be installed.
	 *
	 * @return object
	 *
	 * @throws LabelRenameException With 503 when OpenRegister is not installed.
	 */
	private function objectService(): object {
		if ($this->appManager->isInstalled('openregister') === false) {
			throw new LabelRenameException(message: 'OpenRegister is not available.', status: 503);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()
}//end class
