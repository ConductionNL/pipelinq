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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
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
 *   - escalated → task of type `opvolgtaak` in the configured queue
 *   - resolved / wrong-number / no-answer / abandoned → contactmoment closed only
 *
 * The contactmoment is a `ticket` with `ticketType: contactmoment`
 * (unify-ticket-supertype); it is resolved through {@see TicketService}. The
 * follow-up task keeps its own `task` schema.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.4
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
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
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private TicketService $ticketService,
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
	 *
	 * @return array{outcome: string, interactionId: string, taskId: string|null}
	 *
	 * @throws \InvalidArgumentException When $outcome is not in self::OUTCOMES.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.4
	 */
	public function processDisposition(
		string $interactionId,
		string $subject,
		string $outcome,
		string $notes,
	): array {
		if (in_array($outcome, self::OUTCOMES, true) === false) {
			throw new InvalidArgumentException('Unknown disposition outcome: ' . $outcome);
		}

		$this->updateContactmoment(
			id: $interactionId,
			subject: $subject,
			outcome: $outcome,
			notes: $notes,
		);

		$taskId = null;
		if ($outcome === 'callback') {
			$taskId = $this->createTask(
				type: 'callbackRequest',
				subject: $subject,
				notes: $notes,
				interactionId: $interactionId,
			);
		} elseif ($outcome === 'escalated') {
			$taskId = $this->createTask(
				type: 'followUpTask',
				subject: $subject,
				notes: $notes,
				interactionId: $interactionId,
				queueName: $this->appConfig->getValueString(
					Application::APP_ID,
					'cti_escalation_queue',
					''
				),
			);
		}

		return [
			'outcome' => $outcome,
			'interactionId' => $interactionId,
			'taskId' => $taskId,
		];
	}//end processDisposition()

	/**
	 * Write the disposition back onto the contactmoment ticket.
	 *
	 * @param string $id Contactmoment ticket UUID.
	 * @param string $subject Disposition subject.
	 * @param string $outcome Outcome enum value.
	 * @param string $notes Free-text notes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	private function updateContactmoment(string $id, string $subject, string $outcome, string $notes): void {
		if ($this->ticketService->isConfigured() === false) {
			$this->logger->warning('CTI disposition: ticket register/schema not configured.');
			return;
		}

		try {
			$this->ticketService->save(
				ticketType: TicketService::TYPE_CONTACTMOMENT,
				payload: [
					'disposition_subject' => $subject,
					'disposition_outcome' => $outcome,
					'disposition_notes' => $notes,
				],
				uuid: $id,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI disposition: contactmoment save failed',
				['exception' => $e->getMessage(), 'id' => $id]
			);
		}
	}//end updateContactmoment()

	/**
	 * Create the follow-up task in the task schema.
	 *
	 * @param string $type Task type (terugbelverzoek|opvolgtaak).
	 * @param string $subject Task subject.
	 * @param string $notes Task notes.
	 * @param string $interactionId Linked contactmoment UUID.
	 * @param string|null $queueName Optional queue (for escalation).
	 *
	 * @return string|null The created task UUID or null when creation failed.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Measured 10, threshold 10.
	 *  Each branch is one optional field on the task payload (queue, notes,
	 *  linked interaction, due date). Extracting them would scatter the payload
	 *  shape across helpers without removing a single decision.
	 */
	private function createTask(
		string $type,
		string $subject,
		string $notes,
		string $interactionId,
		?string $queueName = null,
	): ?string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$taskSchema = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
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

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$saved = $objectService->saveObject(
				array_filter(
					[
						'type' => $type,
						'subject' => $subject,
						'description' => $notes,
						'status' => 'open',
						'queueName' => $queueName,
						'interaction' => $interactionId,
					],
					static fn ($value): bool => ($value !== null && $value !== '')
				),
				[],
				$register,
				$taskSchema,
				null
			);

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
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI disposition: task save failed',
				['exception' => $e->getMessage(), 'interactionId' => $interactionId]
			);
			return null;
		}//end try
	}//end createTask()
}//end class
