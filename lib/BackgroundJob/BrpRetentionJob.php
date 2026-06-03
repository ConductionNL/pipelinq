<?php

/**
 * Pipelinq BrpRetentionJob.
 *
 * Daily storage-limitation job (AVG art. 5e): deletes every brpPersoon record
 * whose retentieTot has passed, flips the linked contact's verifiedBSN back to
 * false, and leaves the immutable audit trail untouched. Operates only on this
 * app's configured register/schema.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes expired BRP person records and resets contact verification flags.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Standard TimedJob collaborators
 *  plus the OR container needed to delete records and update contacts.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.5
 */
class BrpRetentionJob extends TimedJob
{
    /**
     * Run interval in seconds (daily).
     *
     * @var int
     */
    private const INTERVAL = 86400;

    /**
     * Constructor.
     *
     * @param ITimeFactory       $time      The time factory.
     * @param IAppConfig         $appConfig The app config.
     * @param ContainerInterface $container The DI container (OR ObjectService).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL);
    }//end __construct()

    /**
     * Delete expired BRP person records and reset their contacts.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.5
     */
    protected function run(mixed $argument): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'brpPersoon_schema', '');
        if ($register === '' || $schema === '') {
            $this->logger->debug('BrpRetentionJob: register/schema not configured, skipping');
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning('BrpRetentionJob: OpenRegister unavailable', ['exception' => $e->getMessage()]);
            return;
        }

        $now     = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $deleted = 0;

        try {
            $records = $objectService->findAll(config: ['filters' => ['register' => $register, 'schema' => $schema]]);
        } catch (\Throwable $e) {
            $this->logger->warning('BrpRetentionJob: findAll failed', ['exception' => $e->getMessage()]);
            return;
        }

        foreach (($records ?? []) as $record) {
            $data = $this->toArray(object: $record);
            if ((string) ($data['retentieTot'] ?? '') === '' || (string) $data['retentieTot'] > $now) {
                continue;
            }

            $uuid = (string) ($data['id'] ?? $data['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $this->resetContact(
                objectService: $objectService,
                register: $register,
                contactId: (string) ($data['gekoppeldContact'] ?? ''),
                brpPersoonId: $uuid
            );

            try {
                $objectService->deleteObject(register: $register, schema: $schema, uuid: $uuid);
                $deleted++;
            } catch (\Throwable $e) {
                $this->logger->warning('BrpRetentionJob: delete failed', ['exception' => $e->getMessage()]);
            }
        }//end foreach

        $this->logger->info('BrpRetentionJob: completed', ['deleted' => $deleted]);
    }//end run()

    /**
     * Reset a contact's verifiedBSN flag when its BRP person expires.
     *
     * @param object $objectService The OR ObjectService.
     * @param string $register      The register id.
     * @param string $contactId     The contact UUID.
     * @param string $brpPersoonId  The expiring person id (only reset if it matches).
     *
     * @return void
     */
    private function resetContact(object $objectService, string $register, string $contactId, string $brpPersoonId): void
    {
        if ($contactId === '') {
            return;
        }

        $contactSchema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
        if ($contactSchema === '') {
            return;
        }

        try {
            $contact = $this->toArray(
                object: $objectService->find(id: $contactId, register: $register, schema: $contactSchema)
            );
            if ((string) ($contact['brpPersoonId'] ?? '') !== $brpPersoonId) {
                return;
            }

            $contact['verifiedBSN'] = false;
            unset($contact['@self']);
            $objectService->saveObject(
                object: $contact,
                extend: [],
                register: $register,
                schema: $contactSchema,
                uuid: $contactId
            );
        } catch (\Throwable $e) {
            $this->logger->warning('BrpRetentionJob: contact reset failed', ['exception' => $e->getMessage()]);
        }//end try
    }//end resetContact()

    /**
     * Normalise an OR object into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return (array) $object;
    }//end toArray()
}//end class
