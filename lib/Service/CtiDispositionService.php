<?php

/**
 * Pipelinq CtiDispositionService.
 *
 * Applies a post-call disposition to a contactmoment and drives the resulting
 * downstream workflow (callback / escalation tasks).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
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
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Disposition workflow service (REQ-CTI-009).
 *
 * On `callback` a `terugbelverzoek` task is created; on `escalated` an
 * `opvolgtaak` is created in the escalation queue. All other outcomes simply
 * close the contactmoment. Tasks are written through the existing `task`
 * schema using the real OpenRegister API (ADR-022); when the cross-app
 * callback-management / queue-management specs land they consume these same
 * `task` records.
 */
class CtiDispositionService
{
    /**
     * The valid disposition outcomes.
     *
     * @var string[]
     */
    private const VALID_OUTCOMES = [
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
     * @param ContainerInterface $container The DI container.
     * @param IAppConfig         $appConfig The app config.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether an outcome value is valid.
     *
     * @param string $outcome The candidate outcome.
     *
     * @return bool True when valid.
     */
    public function isValidOutcome(string $outcome): bool
    {
        return in_array($outcome, self::VALID_OUTCOMES, true);
    }//end isValidOutcome()

    /**
     * Apply a disposition to a contactmoment and drive downstream workflow.
     *
     * @param string $contactmomentId The contactmoment UUID.
     * @param string $subject         The disposition subject.
     * @param string $outcome         The disposition outcome.
     * @param string $notes           The disposition notes.
     *
     * @return array<string, mixed> The updated contactmoment.
     *
     * @throws InvalidArgumentException When the outcome is invalid.
     * @throws RuntimeException         When the contactmoment cannot be found or persistence fails.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.4
     */
    public function processDisposition(string $contactmomentId, string $subject, string $outcome, string $notes): array
    {
        if ($this->isValidOutcome(outcome: $outcome) === false) {
            throw new InvalidArgumentException('Invalid disposition outcome.');
        }

        if (trim($subject) === '') {
            throw new InvalidArgumentException('Disposition subject is required.');
        }

        [$register, $schema] = $this->contactmomentConfig();
        $existing            = $this->findContactmoment(contactmomentId: $contactmomentId);
        if ($existing === null) {
            throw new RuntimeException('Contactmoment not found.');
        }

        $existing['dispositionSubject'] = $subject;
        $existing['dispositionOutcome'] = $outcome;
        $existing['dispositionNotes']   = $notes;
        $existing['callStatus']         = 'completed';

        $updated = $this->getObjectService()
            ->setRegister($register)
            ->setSchema($schema)
            ->updateObject(uuid: $contactmomentId, object: $existing);

        $contactmoment = $this->serialize(result: $updated);

        if ($outcome === 'callback') {
            $this->createTask(
                type: 'terugbelverzoek',
                subject: $subject,
                notes: $notes,
                contactmoment: $contactmoment
            );
        }

        if ($outcome === 'escalated') {
            $this->createTask(
                type: 'opvolgtaak',
                subject: $subject,
                notes: $notes,
                contactmoment: $contactmoment
            );
        }

        return $contactmoment;
    }//end processDisposition()

    /**
     * Create a follow-up task linked to the contactmoment.
     *
     * @param string               $type          The task type (terugbelverzoek|opvolgtaak).
     * @param string               $subject       The task subject.
     * @param string               $notes         The task notes.
     * @param array<string, mixed> $contactmoment The source contactmoment.
     *
     * @return void
     */
    private function createTask(string $type, string $subject, string $notes, array $contactmoment): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
        if ($register === '' || $schema === '') {
            $this->logger->warning('CTI disposition could not create task: task schema unconfigured.');
            return;
        }

        $data = [
            'subject'       => $subject,
            'type'          => $type,
            'status'        => 'open',
            'notes'         => $notes,
            'contactmoment' => (string) ($contactmoment['@self']['id'] ?? ''),
            'client'        => (string) ($contactmoment['client'] ?? ''),
        ];

        if ($type === 'escalated' || $type === 'opvolgtaak') {
            $data['queue'] = $this->appConfig->getValueString(Application::APP_ID, 'cti_escalation_queue', '');
        }

        try {
            $this->getObjectService()->setRegister($register)->setSchema($schema)->saveObject($data);
        } catch (Throwable $e) {
            $this->logger->warning('CTI disposition task creation failed', ['exception' => $e->getMessage()]);
        }
    }//end createTask()

    /**
     * Resolve the contactmoment register and schema IDs.
     *
     * @return array{0: string, 1: string} The register and schema IDs.
     *
     * @throws RuntimeException When unconfigured.
     */
    private function contactmomentConfig(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'contactmoment_schema', '');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('Contactmoment register or schema not configured.');
        }

        return [$register, $schema];
    }//end contactmomentConfig()

    /**
     * Find a contactmoment by UUID.
     *
     * @param string $contactmomentId The contactmoment UUID.
     *
     * @return array<string, mixed>|null The contactmoment, or null.
     */
    private function findContactmoment(string $contactmomentId): ?array
    {
        [$register, $schema] = $this->contactmomentConfig();

        try {
            $object = $this->getObjectService()->find(id: $contactmomentId, register: $register, schema: $schema);
        } catch (Throwable $e) {
            return null;
        }

        if ($object === null) {
            return null;
        }

        return $this->serialize(result: $object);
    }//end findContactmoment()

    /**
     * Serialise an OpenRegister result to a plain array.
     *
     * @param mixed $result The raw result.
     *
     * @return array<string, mixed> The serialised result.
     */
    private function serialize(mixed $result): array
    {
        if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
            $serialized = $result->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }

            return [];
        }

        if (is_array($result) === true) {
            return $result;
        }

        return [];
    }//end serialize()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The object service.
     *
     * @throws RuntimeException When OpenRegister is unavailable.
     */
    private function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()
}//end class
