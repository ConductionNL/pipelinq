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

use OCA\Pipelinq\AppInfo\Application;
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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.4
 */
class CtiDispositionService
{
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
     * @param IAppConfig         $appConfig App config.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Process the disposition for a completed contactmoment.
     *
     * @param string $contactmomentId The contactmoment UUID.
     * @param string $subject         Disposition subject (free text).
     * @param string $outcome         One of self::OUTCOMES.
     * @param string $notes           Free-text notes from the agent.
     *
     * @return array{outcome: string, contactmomentId: string, taskId: string|null}
     *
     * @throws \InvalidArgumentException When $outcome is not in self::OUTCOMES.
     */
    public function processDisposition(
        string $contactmomentId,
        string $subject,
        string $outcome,
        string $notes,
    ): array {
        if (in_array($outcome, self::OUTCOMES, true) === false) {
            throw new \InvalidArgumentException('Unknown disposition outcome: '.$outcome);
        }

        $this->updateContactmoment(
            id: $contactmomentId,
            subject: $subject,
            outcome: $outcome,
            notes: $notes,
        );

        $taskId = null;
        if ($outcome === 'callback') {
            $taskId = $this->createTask(
                type: 'terugbelverzoek',
                subject: $subject,
                notes: $notes,
                contactmomentId: $contactmomentId,
            );
        } else if ($outcome === 'escalated') {
            $taskId = $this->createTask(
                type: 'opvolgtaak',
                subject: $subject,
                notes: $notes,
                contactmomentId: $contactmomentId,
                queueName: $this->appConfig->getValueString(
                    Application::APP_ID,
                    'cti_escalation_queue',
                    ''
                ),
            );
        }

        return [
            'outcome'         => $outcome,
            'contactmomentId' => $contactmomentId,
            'taskId'          => $taskId,
        ];
    }//end processDisposition()

    /**
     * Write the disposition back onto the contactmoment.
     *
     * @param string $id      Contactmoment UUID.
     * @param string $subject Disposition subject.
     * @param string $outcome Outcome enum value.
     * @param string $notes   Free-text notes.
     *
     * @return void
     */
    private function updateContactmoment(string $id, string $subject, string $outcome, string $notes): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'contactmoment_schema', '');
        if ($register === '' || $schema === '') {
            $this->logger->warning('CTI disposition: contactmoment register/schema not configured.');
            return;
        }

        try {
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
            $objectService->saveObject(
                [
                    'disposition_subject' => $subject,
                    'disposition_outcome' => $outcome,
                    'disposition_notes'   => $notes,
                ],
                [],
                $register,
                $schema,
                $id
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
     * @param string      $type            Task type (terugbelverzoek|opvolgtaak).
     * @param string      $subject         Task subject.
     * @param string      $notes           Task notes.
     * @param string      $contactmomentId Linked contactmoment UUID.
     * @param string|null $queueName       Optional queue (for escalation).
     *
     * @return string|null The created task UUID or null when creation failed.
     */
    private function createTask(
        string $type,
        string $subject,
        string $notes,
        string $contactmomentId,
        ?string $queueName=null,
    ): ?string {
        $register   = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $taskSchema = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
        if ($register === '' || $taskSchema === '') {
            $this->logger->warning('CTI disposition: task register/schema not configured -- skipping task creation.');
            return null;
        }

        try {
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
            $saved         = $objectService->saveObject(
                array_filter(
                    [
                        'type'          => $type,
                        'subject'       => $subject,
                        'description'   => $notes,
                        'status'        => 'open',
                        'queueName'     => $queueName,
                        'contactmoment' => $contactmomentId,
                    ],
                    static fn($value): bool => ($value !== null && $value !== '')
                ),
                [],
                $register,
                $taskSchema,
                null
            );

            $id = null;
            if (is_array($saved) === true) {
                $id = ($saved['id'] ?? ($saved['uuid'] ?? null));
            } else if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {
                $id = $saved->getUuid();
            }

            if ($id !== null) {
                return (string) $id;
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->error(
                'CTI disposition: task save failed',
                ['exception' => $e->getMessage(), 'contactmomentId' => $contactmomentId]
            );
            return null;
        }//end try
    }//end createTask()
}//end class
