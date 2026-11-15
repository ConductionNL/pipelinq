<?php

/**
 * Pipelinq CtiDispositionService.
 *
 * Handles the post-call disposition workflow (callback / escalation / close).
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
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\Cti\EscalationQueues;
use OCA\Pipelinq\Util\EntityAccessorTrait;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * CTI disposition service.
 *
 * Reads the agent's selected outcome and creates the necessary follow-up
 * artefacts:
 *   - callback  → task of type `terugbelverzoek` (callback-management integration)
 *   - escalated → task of type `opvolgtaak` for the chosen escalation queue's group
 *   - resolved / wrong-number / no-answer / abandoned → contactmoment closed only
 *
 * The contactmoment is a `ticket` with `ticketType: contactmoment`
 * (unify-ticket-supertype); it is resolved through {@see TicketService}. The
 * follow-up task keeps its own `task` schema.
 *
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.4
 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
 */
class CtiDispositionService {
	use EntityAccessorTrait;

	/**
	 * Allowed disposition outcomes.
	 *
	 * @var array<int,string>
	 */
	public const OUTCOMES = [
		'resolved',
		'callback',
		'escalated',
		'wrong-number',
		'no-answer',
		'abandoned',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Container for OR lookups.
	 * @param IAppConfig $appConfig App config.
	 * @param TicketService $ticketService Unified ticket resolver.
	 * @param EscalationQueues $queues The escalation queues.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private TicketService $ticketService,
		private EscalationQueues $queues,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Process the disposition for a completed contactmoment.
	 *
	 * @param string $interactionId The contactmoment UUID.
	 * @param string $subject Disposition subject (free text).
	 * @param string $outcome One of self::OUTCOMES.
	 * @param string $notes Free-text notes from the agent.
	 * @param string $queue The escalation queue id the agent chose; '' for the first queue.
	 *
	 * @return array{outcome: string, interactionId: string, taskId: string|null}
	 *
	 * @throws \InvalidArgumentException When $outcome is not in self::OUTCOMES, or $queue is unknown.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.4
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
	 */
	public function processDisposition(
		string $interactionId,
		string $subject,
		string $outcome,
		string $notes,
		string $queue='',
	): array {
		$groupId = $this->outcomeGroup(outcome: $outcome, queue: $queue);
		$disposition = new CallDisposition(interactionId: $interactionId, subject: $subject, outcome: $outcome, notes: $notes, groupId: $groupId);
		return $this->disposed(disposition: $disposition);
	}//end processDisposition()

	/**
	 * Update the contactmoment with a disposition and raise its follow-up task.
	 *
	 * @param CallDisposition $disposition The disposition.
	 *
	 * @return array{outcome: string, interactionId: string, taskId: string|null}
	 */
	private function disposed(CallDisposition $disposition): array {
		$this->updateContactmoment(disposition: $disposition);

		return [
			'outcome' => $disposition->outcome,
			'interactionId' => $disposition->interactionId,
			'taskId' => $this->followUpTask(disposition: $disposition),
		];
	}//end disposed()

	/**
	 * Reject an unknown outcome, then give the escalation group an escalated outcome goes to.
	 *
	 * @param string $outcome One of self::OUTCOMES.
	 * @param string $queue   The escalation queue id the agent chose; '' for the first queue.
	 *
	 * @return string|null The group id; null when the outcome is not an escalation or no queue is configured.
	 *
	 * @throws \InvalidArgumentException When $outcome is not in self::OUTCOMES, or $queue is unknown.
	 */
	private function outcomeGroup(string $outcome, string $queue): ?string {
		if (in_array($outcome, self::OUTCOMES, true) === false) {
			throw new InvalidArgumentException('Unknown disposition outcome: ' . $outcome);
		}

		if ($outcome === 'escalated') {
			return $this->escalationGroup(queue: $queue);
		}

		return null;
	}//end outcomeGroup()

	/**
	 * Create the follow-up task the outcome asks for: a callback request or an escalation task.
	 *
	 * @param CallDisposition $disposition The disposition.
	 *
	 * @return string|null The created task UUID, or null when none was created.
	 */
	private function followUpTask(CallDisposition $disposition): ?string {
		if ($disposition->outcome === 'callback') {
			return $this->createTask(type: 'callbackRequest', disposition: $disposition);
		}

		if ($disposition->outcome === 'escalated') {
			return $this->createTask(type: 'followUpTask', disposition: $disposition);
		}

		return null;
	}//end followUpTask()

	/**
	 * The escalation queues by id and label, for the wrap-up dialog.
	 *
	 * @return array<int, array{id: string, label: string}> The queues.
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
	 */
	public function queueChoices(): array {
		return array_map(
			static fn (array $queue): array => ['id' => $queue['id'], 'label' => $queue['label']],
			$this->queues->all()
		);
	}//end queueChoices()

	/**
	 * The group of the chosen escalation queue: the first queue when none was
	 * chosen, null when no queue is configured.
	 *
	 * @param string $queue The chosen queue id, or ''.
	 *
	 * @return string|null The group id.
	 *
	 * @throws \InvalidArgumentException When the queue is unknown.
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
	 */
	private function escalationGroup(string $queue): ?string {
		if ($queue === '') {
			$first = ($this->queues->all()[0] ?? null);
			if ($first === null) {
				return null;
			}

			return $first['groupId'];
		}

		$found = $this->queues->find(id: $queue);
		if ($found === null) {
			throw new InvalidArgumentException('Unknown escalation queue: ' . $queue);
		}

		return $found['groupId'];
	}//end escalationGroup()

	/**
	 * Write the disposition back onto the contactmoment ticket.
	 *
	 * @param CallDisposition $disposition The disposition.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	private function updateContactmoment(CallDisposition $disposition): void {
		if ($this->ticketService->isConfigured() === false) {
			$this->logger->warning('CTI disposition: ticket register/schema not configured.');
			return;
		}

		try {
			$this->ticketService->save(
				ticketType: TicketService::TYPE_CONTACTMOMENT,
				payload: [
					'disposition_subject' => $disposition->subject,
					'disposition_outcome' => $disposition->outcome,
					'disposition_notes' => $disposition->notes,
				],
				uuid: $disposition->interactionId,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI disposition: contactmoment save failed',
				['exception' => $e->getMessage(), 'id' => $disposition->interactionId]
			);
		}
	}//end updateContactmoment()

	/**
	 * Create the follow-up task in the task schema.
	 *
	 * @param string          $type        Task type (terugbelverzoek|opvolgtaak).
	 * @param CallDisposition $disposition The disposition the task follows up.
	 *
	 * @return string|null The created task UUID or null when creation failed.
	 */
	private function createTask(string $type, CallDisposition $disposition): ?string {
		$target = $this->taskTarget();
		if ($target === null) {
			return null;
		}

		[$register, $taskSchema] = $target;

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$saved = $objectService->saveObject(
				$this->taskPayload(type: $type, disposition: $disposition),
				[],
				$register,
				$taskSchema,
				null
			);

			return $this->savedTaskId(saved: $saved);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI disposition: task save failed',
				['exception' => $e->getMessage(), 'interactionId' => $disposition->interactionId]
			);
			return null;
		}//end try
	}//end createTask()

	/**
	 * The register and task schema to create tasks in, or null when tasks cannot be created.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function taskTarget(): array|null {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$taskSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'task_schema', '');
		if ($register === '' || $taskSchema === '') {
			$this->logger->warning('CTI disposition: task register/schema not configured -- skipping task creation.');
			return null;
		}

		// Availability established before the reach (ADR-083) — the lookup names
		// OpenRegister only as a string, so without this the dependency is
		// declared nowhere a reader or a gate can see it. This method already
		// returns null when it cannot record a disposition, so the absent case
		// takes that path. Converting to a typed constructor property is the
		// ADR's preferred shape: pipelinq#1160.
		if (class_exists('\OCA\OpenRegister\Service\ObjectService') === false) {
			return null;
		}

		return [$register, $taskSchema];
	}//end taskTarget()

	/**
	 * The task payload; fields without a value (no group, empty notes) are left out.
	 *
	 * @param string          $type        Task type.
	 * @param CallDisposition $disposition The disposition the task follows up.
	 *
	 * @return array<string, string> The payload.
	 */
	private function taskPayload(string $type, CallDisposition $disposition): array {
		return array_filter(
			[
				'type' => $type,
				'subject' => $disposition->subject,
				'description' => $disposition->notes,
				'status' => 'open',
				'assigneeGroupId' => $disposition->groupId,
				'interaction' => $disposition->interactionId,
			],
			static fn ($value): bool => ($value !== null && $value !== '')
		);
	}//end taskPayload()

	/**
	 * The id of a saved task, read from an array or an entity.
	 *
	 * @param mixed $saved What saveObject() returned.
	 *
	 * @return string|null The task id, or null when none can be read.
	 */
	private function savedTaskId(mixed $saved): ?string {
		$id = null;
		if (is_array($saved) === true) {
			$id = ($saved['id'] ?? ($saved['uuid'] ?? null));
		} elseif (is_object($saved) === true) {
			// SaveObject() returns an ObjectEntity whose getUuid() is served by
			// Entity::__call — method_exists() is FALSE for it, so the follow-up
			// task was written and its id thrown away (pipelinq#807).
			$uuid = $this->readEntityValue(entity: $saved, getter: 'getUuid');
			if ($uuid !== '') {
				$id = $uuid;
			}
		}

		if ($id !== null) {
			return (string)$id;
		}

		return null;
	}//end savedTaskId()
}//end class
