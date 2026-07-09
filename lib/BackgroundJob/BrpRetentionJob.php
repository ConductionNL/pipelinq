<?php

/**
 * Pipelinq BrpRetentionJob.
 *
 * Daily background job that enforces the configurable retention window on BrpPersoon
 * records. Records whose retentieTot is in the past are deleted, and the linked Contact's
 * verifiedBSN / brpPersoonId are reset (audit records remain immutable).
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
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Retention enforcement (AVG art. 5 — opslagbeperking).
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-008
 */
class BrpRetentionJob extends TimedJob
{
    /**
     * Default interval (24h).
     */
    private const DEFAULT_INTERVAL_SECONDS = 86400;

    /**
     * Constructor.
     *
     * @param ITimeFactory       $time      Time factory.
     * @param IAppConfig         $appConfig App config.
     * @param ContainerInterface $container DI.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(
            seconds: $this->appConfig->getValueInt(
                Application::APP_ID,
                'brp.retention_interval_seconds',
                self::DEFAULT_INTERVAL_SECONDS
            )
        );
    }//end __construct()

    /**
     * Delete expired BrpPersoon records and reset their contacts.
     *
     * @param mixed $argument Unused.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-008
     */
    protected function run(mixed $argument): void
    {
        try {
            $register      = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
            $persoonSchema = $this->appConfig->getValueString(Application::APP_ID, 'brpPersoon_schema', '');
            $contactSchema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
            if ($register === '' || $persoonSchema === '') {
                $this->logger->info('BRP retention: schemas not configured; skipping');
                return;
            }

            $objects = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $records = $objects->findAll(
                filters: [],
                register: $register,
                schema: $persoonSchema,
            );

            $deleted = 0;
            foreach (($records ?? []) as $record) {
                $arr        = $this->recordToArray(rec: $record);
                $wasDeleted = $this->deleteExpiredPersoon(
                    objects: $objects,
                    record: $arr,
                    register: $register,
                    persoonSchema: $persoonSchema,
                    contactSchema: $contactSchema,
                    now: $now
                );
                if ($wasDeleted === true) {
                    $deleted++;
                }
            }//end foreach

            $this->logger->info('BRP retention sweep complete', ['deleted' => $deleted]);
        } catch (Throwable $e) {
            $this->logger->error('BRP retention job failed', ['error' => $e->getMessage()]);
        }//end try
    }//end run()

    /**
     * Coerce an OpenRegister record (array or entity) to a plain array.
     *
     * @param mixed $rec Record from ObjectService::findAll()/find().
     *
     * @return array<string, mixed> Array representation (empty when unusable).
     */
    private function recordToArray(mixed $rec): array
    {
        if (is_array($rec) === true) {
            return $rec;
        }

        if (method_exists($rec, 'jsonSerialize') === true) {
            return (array) $rec->jsonSerialize();
        }

        return [];
    }//end recordToArray()

    /**
     * Delete a BrpPersoon record when its retention window has elapsed and
     * reset the linked contact.
     *
     * @param object               $objects       OR ObjectService.
     * @param array<string, mixed> $record        Persoon record data.
     * @param string               $register      Register slug.
     * @param string               $persoonSchema BrpPersoon schema slug.
     * @param string               $contactSchema Contact schema slug.
     * @param DateTimeImmutable    $now           Current time (UTC).
     *
     * @return bool True when a record was deleted.
     */
    private function deleteExpiredPersoon(
        object $objects,
        array $record,
        string $register,
        string $persoonSchema,
        string $contactSchema,
        DateTimeImmutable $now
    ): bool {
        $retentieTo = (string) ($record['retentieTot'] ?? '');
        if ($retentieTo === '') {
            return false;
        }

        try {
            $retentieDt = new DateTimeImmutable($retentieTo, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            return false;
        }

        if ($retentieDt > $now) {
            return false;
        }

        $uuid      = (string) ($record['@self']['id'] ?? $record['id'] ?? '');
        $contactId = (string) ($record['gekoppeldContact'] ?? '');

        try {
            $objects->setRegister($register)
                ->setSchema($persoonSchema)
                ->deleteObject(uuid: $uuid);
        } catch (Throwable $e) {
            $this->logger->warning(
                'BRP retention: delete failed',
                ['uuid' => $uuid, 'error' => $e->getMessage()]
            );
            return false;
        }

        if ($contactId !== '' && $contactSchema !== '') {
            $this->resetContact(
                objects: $objects,
                register: $register,
                contactSchema: $contactSchema,
                contactId: $contactId,
                persoonUuid: $uuid
            );
        }

        return true;
    }//end deleteExpiredPersoon()

    /**
     * Reset Contact.verifiedBSN/brpPersoonId once the linked persoon has been deleted.
     *
     * Contact.brpPersoonId is preserved (per spec — it may become a dangling pointer);
     * verifiedBSN is set to false.
     *
     * @param object $objects       OR ObjectService.
     * @param string $register      Register ID.
     * @param string $contactSchema Contact schema ID.
     * @param string $contactId     Contact UUID.
     * @param string $persoonUuid   Recently-deleted BrpPersoon UUID.
     *
     * @return void
     */
    private function resetContact(object $objects, string $register, string $contactSchema, string $contactId, string $persoonUuid): void
    {
        try {
            $existing    = $objects->find(
                id: $contactId,
                register: $register,
                schema: $contactSchema,
            );
            $existingArr = $this->recordToArray(rec: $existing);

            if (empty($existingArr) === true) {
                return;
            }

            if ((string) ($existingArr['brpPersoonId'] ?? '') !== $persoonUuid) {
                return;
            }

            $existingArr['verifiedBSN'] = false;
            // Per spec REQ-BSN-008-01: brpPersoonId stays (dangling pointer to deleted record).
            $objects->saveObject(
                object: $existingArr,
                extend: [],
                register: $register,
                schema: $contactSchema,
                uuid: $contactId,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'BRP retention: contact reset failed',
                ['contactId' => $contactId, 'error' => $e->getMessage()]
            );
        }//end try
    }//end resetContact()
}//end class
