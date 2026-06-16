<?php

/**
 * Pipelinq AvgRepository.
 *
 * Shared OpenRegister access helper for the AVG (GDPR data-subject request)
 * workflow schemas. Centralises register/schema resolution from app config, the
 * find / findAll / saveObject / deleteObject calls (ADR-022 real OR API only),
 * and object-to-array normalisation so the individual AVG services stay thin and
 * server-authoritative.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Avg
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Repository for AVG workflow objects backed by OpenRegister.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires only the OR container,
 *  app config and logger a repository legitimately needs.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
 */
class AvgRepository
{
    /**
     * Schema key for the AvgVerzoek schema.
     *
     * @var string
     */
    public const SCHEMA_VERZOEK = 'avgVerzoek_schema';

    /**
     * Schema key for the TermijnEvent schema.
     *
     * @var string
     */
    public const SCHEMA_TERMIJN_EVENT = 'termijnEvent_schema';

    /**
     * Schema key for the BewijsItem schema.
     *
     * @var string
     */
    public const SCHEMA_BEWIJS_ITEM = 'bewijsItem_schema';

    /**
     * Schema key for the ExportBundle schema.
     *
     * @var string
     */
    public const SCHEMA_EXPORT_BUNDLE = 'exportBundle_schema';

    /**
     * Schema key for the Weigering schema.
     *
     * @var string
     */
    public const SCHEMA_WEIGERING = 'weigering_schema';

    /**
     * Schema key for the RedactieActie schema.
     *
     * @var string
     */
    public const SCHEMA_REDACTIE_ACTIE = 'redactieActie_schema';

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
     * Find a single object by id within a schema, normalised to an array.
     *
     * @param string $schemaKey The schema config key.
     * @param string $id        The object UUID.
     *
     * @return array<string, mixed> The object data.
     *
     * @throws OCSNotFoundException If the object is not found in this app's schema.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
     */
    public function find(string $schemaKey, string $id): array
    {
        [$register, $schema] = $this->config(schemaKey: $schemaKey);

        try {
            $object = $this->objectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException('Object niet gevonden.');
        }

        return $this->toArray(object: $object);
    }//end find()

    /**
     * Find a single object without throwing; returns null when absent.
     *
     * @param string $schemaKey The schema config key.
     * @param string $id        The object UUID.
     *
     * @return array<string, mixed>|null The object data or null.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
     */
    public function findOrNull(string $schemaKey, string $id): ?array
    {
        try {
            return $this->find(schemaKey: $schemaKey, id: $id);
        } catch (\Throwable $e) {
            return null;
        }
    }//end findOrNull()

    /**
     * Find all objects of a schema matching the given equality filters.
     *
     * @param string               $schemaKey The schema config key.
     * @param array<string, mixed> $filters   Additional equality filters.
     *
     * @return array<int, array<string, mixed>> The matching objects.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
     */
    public function findAll(string $schemaKey, array $filters=[]): array
    {
        [$register, $schema] = $this->config(schemaKey: $schemaKey);

        try {
            $results = $this->objectService()->findAll(
                config: [
                    'filters' => array_merge(
                        ['register' => $register, 'schema' => $schema],
                        $filters
                    ),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq AVG: findAll failed',
                ['schema' => $schemaKey, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $objects = [];
        foreach (($results ?? []) as $result) {
            $objects[] = $this->toArray(object: $result);
        }

        return $objects;
    }//end findAll()

    /**
     * Persist (create or update) an object in a schema.
     *
     * @param string               $schemaKey The schema config key.
     * @param array<string, mixed> $object    The object data.
     * @param string|null          $id        The UUID to update, or null to create.
     *
     * @return array<string, mixed> The saved object as an array.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
     */
    public function save(string $schemaKey, array $object, ?string $id=null): array
    {
        [$register, $schema] = $this->config(schemaKey: $schemaKey);

        unset($object['@self']);

        $saved = $this->objectService()->saveObject(
            object: $object,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $id
        );

        return $this->toArray(object: $saved);
    }//end save()

    /**
     * Hard-delete an object from a schema.
     *
     * @param string $schemaKey The schema config key.
     * @param string $id        The object UUID.
     *
     * @return void
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.6
     */
    public function delete(string $schemaKey, string $id): void
    {
        [$register, $schema] = $this->config(schemaKey: $schemaKey);

        try {
            $this->objectService()->deleteObject(register: $register, schema: $schema, uuid: $id);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq AVG: delete failed',
                ['schema' => $schemaKey, 'id' => $id, 'exception' => $e->getMessage()]
            );
        }
    }//end delete()

    /**
     * Extract the stable identifier from a normalised object.
     *
     * Prefers the OpenRegister @self.id / @self.uuid, falling back to top-level
     * id / uuid keys.
     *
     * @param array<string, mixed> $object The object data.
     *
     * @return string The identifier, or empty string when none is present.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
     */
    public function idOf(array $object): string
    {
        $self = ($object['@self'] ?? []);
        if (is_array($self) === true) {
            $selfId = (string) ($self['id'] ?? $self['uuid'] ?? '');
            if ($selfId !== '') {
                return $selfId;
            }
        }

        return (string) ($object['id'] ?? $object['uuid'] ?? '');
    }//end idOf()

    /**
     * Resolve the register + a schema config key into their stored IDs.
     *
     * @param string $schemaKey The app-config schema key.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws OCSNotFoundException If the register or schema is not configured.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
     */
    private function config(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

        if ($register === '' || $schema === '') {
            throw new OCSNotFoundException('AVG register of schema is niet geconfigureerd.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws RuntimeException If OpenRegister is not available.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
     */
    private function objectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end objectService()

    /**
     * Normalise an OR object (entity or array) into a plain array.
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

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return (array) $object;
    }//end toArray()
}//end class
