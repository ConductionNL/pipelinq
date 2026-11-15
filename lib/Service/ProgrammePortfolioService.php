<?php

/**
 * Pipelinq ProgrammePortfolioService.
 *
 * A programme holds work it does not own. The four rules that make that
 * survivable live here, and each of them exists because of a specific way this
 * goes wrong.
 *
 * ONE WORK ITEM BELONGS TO AT MOST ONE PROGRAMME. Two programmes both claiming
 * a case means effort and progress roll up twice, and neither figure is wrong
 * in a way anybody can see. The second link is refused, naming the programme
 * that already holds it.
 *
 * AN UNRESOLVABLE REFERENCE IS SHOWN, NOT SWALLOWED. When the app owning a
 * referenced object is absent, the item renders as unresolved with its type and
 * id. A link that disappeared and a programme with no links must not look the
 * same.
 *
 * A DERIVED FIGURE NAMES THE MODE THAT PRODUCED IT. A progress percentage with
 * no provenance cannot be argued with, and somebody will argue with it.
 *
 * AN UNCOMPUTABLE FIGURE IS SAID, NOT SHOWN AS ZERO. A programme in
 * `fromEffort` mode on an instance without humaniq reports that progress cannot
 * be computed. Zero would read as "nothing has been done", which is a
 * different and much worse sentence.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/project-portfolio/spec.md#requirement-a-project-shall-hold-work-it-does-not-own-by-reference-req-prj-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ProgrammePortfolioServiceInterface;
use OCA\Pipelinq\Service\Support\JsonSerializableRows;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Link work to a programme, and answer how far along it is.
 *
 * @spec openspec/specs/project-portfolio/spec.md#requirement-progress-shall-declare-which-mode-produced-it-req-prj-003
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of small private steps; each method stays under the method thresholds.
 */
class ProgrammePortfolioService implements ProgrammePortfolioServiceInterface {
	use JsonSerializableRows;

	/**
	 * The app-config key holding the instance's default progress mode.
	 *
	 * @var string
	 */
	public const DEFAULT_MODE_KEY = 'programme_progress_mode';

	/**
	 * The modes a programme's progress can come from.
	 *
	 * @var array<int, string>
	 */
	public const MODES = ['manual', 'fromTasks', 'fromEffort'];

	/**
	 * Upper bound on the rows read per question.
	 *
	 * @var int
	 */
	private const LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the schema ids and the default mode.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The instance's default progress mode.
	 *
	 * @return string One of the MODES.
	 */
	private function defaultMode(): string {
		$configured = trim(
			$this->appConfig->getValueString(AppIdentity::APP_ID, self::DEFAULT_MODE_KEY, 'fromTasks')
		);

		if (in_array($configured, self::MODES, true) === true) {
			return $configured;
		}

		return 'fromTasks';
	}//end defaultMode()

	/**
	 * The mode a programme's progress comes from.
	 *
	 * @param array<string, mixed> $programme The programme.
	 *
	 * @return string One of the MODES.
	 *
	 * @spec openspec/specs/project-portfolio/spec.md#requirement-progress-shall-declare-which-mode-produced-it-req-prj-003
	 */
	public function modeFor(array $programme): string {
		$override = trim((string)($programme['progressMode'] ?? ''));

		if (in_array($override, self::MODES, true) === true) {
			return $override;
		}

		return $this->defaultMode();
	}//end modeFor()

	/**
	 * Whether a work item may be linked to a programme.
	 *
	 * @param string $domainObjectType The `<app>:<schema>` literal.
	 * @param string $domainObjectRef The referenced object's uuid.
	 * @param string $programmeId The programme it would be linked to.
	 * @param array<int, array<string, mixed>> $existingLinks Every work item already written.
	 *
	 * @return array{allowed: bool, reason: string} The answer.
	 *
	 * @spec openspec/specs/project-portfolio/spec.md#requirement-a-project-shall-hold-work-it-does-not-own-by-reference-req-prj-002
	 */
	public function mayLink(
		string $domainObjectType,
		string $domainObjectRef,
		string $programmeId,
		array $existingLinks,
	): array {
		return $this->claimAnswer(
			existingLinks: $existingLinks,
			claim: ['domainObjectType' => trim($domainObjectType), 'domainObjectRef' => trim($domainObjectRef), 'programme' => $programmeId]
		);
	}//end mayLink()

	/**
	 * Refuse an incomplete claim, else answer it against the existing links.
	 *
	 * @param array<int, array<string, mixed>> $existingLinks Every work item already written.
	 * @param array<string, string>            $claim         The trimmed `domainObjectType` and `domainObjectRef`,
	 *                                                        and the `programme` it would be linked to.
	 *
	 * @return array{allowed: bool, reason: string} The answer.
	 */
	private function claimAnswer(array $existingLinks, array $claim): array {
		if ($claim['domainObjectType'] === '' || $claim['domainObjectRef'] === '' || trim($claim['programme']) === '') {
			return ['allowed' => false, 'reason' => 'A programme, an object type and an object are required.'];
		}

		return $this->existingLinkAnswer(existingLinks: $existingLinks, claim: $claim);
	}//end claimAnswer()

	/**
	 * Refuse a work item that is already linked to a programme, allow it otherwise.
	 *
	 * @param array<int, array<string, mixed>> $existingLinks Every work item already written.
	 * @param array<string, string>            $claim         The trimmed `domainObjectType` and `domainObjectRef`,
	 *                                                        and the `programme` it would be linked to.
	 *
	 * @return array{allowed: bool, reason: string} The answer.
	 */
	private function existingLinkAnswer(array $existingLinks, array $claim): array {
		foreach ($existingLinks as $link) {
			$sameWork = ((string)($link['domainObjectType'] ?? '') === $claim['domainObjectType']
				&& (string)($link['domainObjectRef'] ?? '') === $claim['domainObjectRef']);
			if ($sameWork === false) {
				continue;
			}

			$holder = (string)($link['programme'] ?? '');
			if ($holder === trim($claim['programme'])) {
				return ['allowed' => false, 'reason' => 'This work is already in this programme.'];
			}

			// Named, not merely refused: somebody has to know which programme
			// to take it out of.
			return ['allowed' => false, 'reason' => "This work is already in programme {$holder}."];
		}

		return ['allowed' => true, 'reason' => ''];
	}//end existingLinkAnswer()

	/**
	 * Present a work item, resolved or not.
	 *
	 * @param array<string, mixed> $workItem The stored work item.
	 * @param array<string, string> $resolvedTitles Titles the owning apps answered, keyed by uuid.
	 * @param array<int, string> $installedApps The apps this instance has.
	 *
	 * @return array<string, mixed> The row, with `resolved` false when the
	 *   owning app is absent or answered nothing.
	 *
	 * @spec openspec/specs/project-portfolio/spec.md#requirement-a-project-shall-hold-work-it-does-not-own-by-reference-req-prj-002
	 */
	public function presentWorkItem(array $workItem, array $resolvedTitles = [], array $installedApps = []): array {
		$row = self::workItemRow(workItem: $workItem);

		$title = ($resolvedTitles[$row['domainObjectRef']] ?? '');
		$row['resolved'] = ($title !== '');

		if ($row['resolved'] === false && $installedApps !== [] && in_array($row['app'], $installedApps, true) === false) {
			// The owning app is not here. The item stays listed, with what we
			// know of it, because an item that vanished and a programme with
			// no items look identical otherwise.
			$title = trim((string)($workItem['title'] ?? ''));
		}

		return self::labelled(row: $row, title: $title);
	}//end presentWorkItem()

	/**
	 * The row of a work item, labelled by its reference and not yet resolved.
	 *
	 * @param array<string, mixed> $workItem The stored work item.
	 *
	 * @return array{id: string, programme: string, domainObjectType: string, domainObjectRef: string, app: string, title: string, resolved: bool}
	 */
	private static function workItemRow(array $workItem): array {
		$type = (string)($workItem['domainObjectType'] ?? '');
		$ref = (string)($workItem['domainObjectRef'] ?? '');

		return [
			'id' => (string)($workItem['id'] ?? $workItem['uuid'] ?? ''),
			'programme' => (string)($workItem['programme'] ?? ''),
			'domainObjectType' => $type,
			'domainObjectRef' => $ref,
			'app' => trim(explode(':', $type)[0]),
			'title' => $ref,
			'resolved' => false,
		];
	}//end workItemRow()

	/**
	 * The row labelled by a title, if it has one.
	 *
	 * The reference is the last resort: a row with no readable label at
	 * all is worse than one labelled by the id it points at.
	 *
	 * @param array<string, mixed> $row   The work item row.
	 * @param mixed                $title The title found for it, or ''.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function labelled(array $row, mixed $title): array {
		if ($title !== '') {
			$row['title'] = $title;
		}

		return $row;
	}//end labelled()

	/**
	 * How far along a programme is, and where the figure came from.
	 *
	 * @param array<string, mixed> $programme The programme.
	 * @param array<int, array<string, mixed>> $tasks Its tasks, for `fromTasks`.
	 * @param array<string, mixed>|null $effort Hours booked and estimated, for
	 *   `fromEffort`, or null when humaniq cannot be resolved.
	 *
	 * @return array{mode: string, progress: int|null, computable: bool, reason: string}
	 *   The figure, always naming its mode.
	 *
	 * @spec openspec/specs/project-portfolio/spec.md#requirement-progress-shall-declare-which-mode-produced-it-req-prj-003
	 */
	public function progressFor(array $programme, array $tasks = [], ?array $effort = null): array {
		$mode = $this->modeFor(programme: $programme);

		if ($mode === 'manual') {
			return $this->manualProgress(programme: $programme);
		}

		if ($mode === 'fromTasks') {
			return $this->taskProgress(tasks: $tasks);
		}

		return $this->effortProgress(effort: $effort);
	}//end progressFor()

	/**
	 * Progress in `manual` mode: the figure somebody typed, if any.
	 *
	 * @param array<string, mixed> $programme The programme.
	 *
	 * @return array{mode: string, progress: int|null, computable: bool, reason: string}
	 */
	private function manualProgress(array $programme): array {
		$typed = ($programme['manualProgress'] ?? null);
		$entered = is_numeric($typed);

		$progress = null;
		$reason = 'No progress has been entered.';
		if ($entered === true) {
			$progress = (int)$typed;
			$reason = '';
		}

		return ['mode' => 'manual', 'progress' => $progress, 'computable' => $entered, 'reason' => $reason];
	}//end manualProgress()

	/**
	 * Progress in `fromTasks` mode: the share of closed tasks.
	 *
	 * @param array<int, array<string, mixed>> $tasks The programme's tasks.
	 *
	 * @return array{mode: string, progress: int|null, computable: bool, reason: string}
	 */
	private function taskProgress(array $tasks): array {
		$total = count($tasks);
		if ($total === 0) {
			return $this->uncomputableProgress(mode: 'fromTasks', reason: 'This programme has no tasks to derive progress from.');
		}

		$closed = count(
			array_filter($tasks, static fn (array $task): bool => ($task['status'] ?? '') === 'closed')
		);

		return [
			'mode' => 'fromTasks',
			'progress' => (int)round((($closed / $total) * 100)),
			'computable' => true,
			'reason' => '',
		];
	}//end taskProgress()

	/**
	 * Progress in `fromEffort` mode: hours booked against hours estimated.
	 *
	 * @param array<string, mixed>|null $effort Hours booked and estimated, or null when humaniq cannot be resolved.
	 *
	 * @return array{mode: string, progress: int|null, computable: bool, reason: string}
	 */
	private function effortProgress(array|null $effort): array {
		if ($effort === null) {
			// NOT zero. Zero reads as "nothing has been done", which is a
			// different and much worse sentence than "we cannot tell".
			return $this->uncomputableProgress(mode: 'fromEffort', reason: 'Hours cannot be read on this instance, so progress cannot be computed.');
		}

		$estimated = (float)($effort['estimated'] ?? 0);
		if ($estimated <= 0.0) {
			return $this->uncomputableProgress(mode: 'fromEffort', reason: 'Nothing has been estimated, so progress cannot be computed.');
		}

		$booked = (float)($effort['booked'] ?? 0);

		return ['mode' => 'fromEffort', 'progress' => (int)round((($booked / $estimated) * 100)), 'computable' => true, 'reason' => ''];
	}//end effortProgress()

	/**
	 * The progress answer for a mode that cannot produce a figure.
	 *
	 * @param string $mode   The progress mode.
	 * @param string $reason Why no figure can be computed.
	 *
	 * @return array{mode: string, progress: null, computable: false, reason: string}
	 */
	private function uncomputableProgress(string $mode, string $reason): array {
		return [
			'mode' => $mode,
			'progress' => null,
			'computable' => false,
			'reason' => $reason,
		];
	}//end uncomputableProgress()

	/**
	 * The work items of one programme.
	 *
	 * @param string $programmeId The programme's uuid.
	 *
	 * @return array<int, array<string, mixed>> The work items.
	 */
	public function workItemsOf(string $programmeId): array {
		return $this->read(
			schemaKey: 'programmeWorkItem_schema',
			filters: ['programme' => trim($programmeId)],
		);
	}//end workItemsOf()

	/**
	 * Every work item that references one object, whatever the programme.
	 *
	 * @param string $domainObjectType The `<app>:<schema>` literal.
	 * @param string $domainObjectRef The object's uuid.
	 *
	 * @return array<int, array<string, mixed>> The work items.
	 */
	private function workItemsReferencing(string $domainObjectType, string $domainObjectRef): array {
		return $this->read(
			schemaKey: 'programmeWorkItem_schema',
			filters: [
				'domainObjectType' => trim($domainObjectType),
				'domainObjectRef' => trim($domainObjectRef),
			],
		);
	}//end workItemsReferencing()

	/**
	 * The tasks of one programme.
	 *
	 * @param string $programmeId The programme's uuid.
	 *
	 * @return array<int, array<string, mixed>> The tasks.
	 */
	public function tasksOf(string $programmeId): array {
		return $this->read(
			schemaKey: 'programmeTask_schema',
			filters: ['programme' => trim($programmeId)],
		);
	}//end tasksOf()

	/**
	 * Link a piece of work to a programme.
	 *
	 * @param string $programmeId The programme.
	 * @param string $domainObjectType The `<app>:<schema>` literal.
	 * @param string $domainObjectRef The object's uuid.
	 * @param string $title The object's title as it reads now.
	 *
	 * @return array<string, mixed> `status` plus either `workItem` or `error`.
	 *
	 * @spec openspec/specs/project-portfolio/spec.md#requirement-a-project-shall-hold-work-it-does-not-own-by-reference-req-prj-002
	 */
	public function linkWork(
		string $programmeId,
		string $domainObjectType,
		string $domainObjectRef,
		string $title = '',
	): array {
		return $this->linkedWorkItem(
			workItem: [
				'programme' => trim($programmeId),
				'domainObjectType' => trim($domainObjectType),
				'domainObjectRef' => trim($domainObjectRef),
				'title' => trim($title),
			]
		);
	}//end linkWork()

	/**
	 * Write a work item unless its object is already linked: 409 when it is.
	 *
	 * @param array<string, string> $workItem The trimmed work item.
	 *
	 * @return array{status: int, error?: string, workItem?: array<string, mixed>}
	 */
	private function linkedWorkItem(array $workItem): array {
		$answer = $this->mayLink(
			domainObjectType: $workItem['domainObjectType'],
			domainObjectRef: $workItem['domainObjectRef'],
			programmeId: $workItem['programme'],
			existingLinks: $this->workItemsReferencing(
				domainObjectType: $workItem['domainObjectType'],
				domainObjectRef: $workItem['domainObjectRef'],
			),
		);

		if ($answer['allowed'] === false) {
			return ['status' => 409, 'error' => $answer['reason']];
		}

		return $this->savedWorkItem(workItem: $workItem);
	}//end linkedWorkItem()

	/**
	 * Write a work item: 201 with the item, or 500 when it could not be saved.
	 *
	 * @param array<string, string> $workItem The trimmed work item.
	 *
	 * @return array{status: int, error?: string, workItem?: array<string, mixed>}
	 */
	private function savedWorkItem(array $workItem): array {
		if ($this->write(schemaKey: 'programmeWorkItem_schema', object: $workItem) === false) {
			return ['status' => 500, 'error' => 'The work item could not be saved.'];
		}

		return ['status' => 201, 'workItem' => $workItem];
	}//end savedWorkItem()

	/**
	 * Read rows of one schema as plain arrays.
	 *
	 * @param string $schemaKey The app-config key holding the schema id.
	 * @param array<string, mixed> $filters Extra filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function read(string $schemaKey, array $filters): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			$this->logger->debug(
				'ProgrammePortfolioService: the programme surface is not configured',
				['schemaKey' => $schemaKey]
			);

			return [];
		}

		$filters = array_merge(['register' => $register, 'schema' => $schema], $filters);
		return $this->plainRows(rows: $this->findRows(schemaKey: $schemaKey, filters: $filters));
	}//end read()

	/**
	 * Run the findAll for one schema, logging a failed read.
	 *
	 * @param string               $schemaKey The app-config key holding the schema id.
	 * @param array<string, mixed> $filters   The full filter set, register and schema included.
	 *
	 * @return array<int, mixed> The raw rows, or none when the read failed.
	 */
	private function findRows(string $schemaKey, array $filters): array {
		try {
			return $this->objectService->findAll(['filters' => $filters, 'limit' => self::LIMIT]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ProgrammePortfolioService: the read failed',
				['schemaKey' => $schemaKey, 'exception' => $e->getMessage()]
			);

			return [];
		}
	}//end findRows()

	/**
	 * Save one object of a programme schema.
	 *
	 * @param string $schemaKey The app-config key holding the schema id.
	 * @param array<string, mixed> $object The object.
	 * @param string|null $uuid Its uuid, for an update.
	 *
	 * @return bool True when the write landed.
	 */
	public function write(string $schemaKey, array $object, ?string $uuid = null): bool {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return false;
		}

		$save = $this->saveCall(object: $object, target: ['register' => $register, 'schema' => $schema, 'uuid' => $uuid]);

		return $this->landed(save: $save, schemaKey: $schemaKey);
	}//end write()

	/**
	 * The ObjectService save of an object, to run later.
	 *
	 * @param array<string, mixed>                                  $object The object.
	 * @param array{register: string, schema: string, uuid: ?string} $target The register and schema ids, and the uuid for an update.
	 *
	 * @return \Closure The save; it returns the saved object.
	 */
	private function saveCall(array $object, array $target): \Closure {
		return fn (): mixed => $this->objectService->saveObject(
			object: $object,
			register: $target['register'],
			schema: $target['schema'],
			uuid: $target['uuid'],
		);
	}//end saveCall()

	/**
	 * Run a save; a failure is logged and answers false.
	 *
	 * @param callable(): mixed $save      The save.
	 * @param string            $schemaKey The app-config key of the schema, for the log.
	 *
	 * @return bool True when the write landed.
	 */
	private function landed(callable $save, string $schemaKey): bool {
		try {
			$save();

			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'ProgrammePortfolioService: the write failed',
				['schemaKey' => $schemaKey, 'exception' => $e->getMessage()]
			);

			return false;
		}
	}//end landed()
}//end class
