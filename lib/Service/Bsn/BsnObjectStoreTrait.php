<?php

/**
 * Pipelinq BsnObjectStoreTrait.
 *
 * Shared OpenRegister persistence helpers for the BSN/BRP services. Every method
 * scopes reads and writes to THIS app's configured register + schema (resolved
 * from app config), so a record in another app/register resolves to nothing —
 * the IDOR-safe pattern the POS services already use. Only the real OR
 * ObjectService API is called (find / findAll / saveObject / deleteObject).
 *
 * Consuming classes must provide a `ContainerInterface $container`, an
 * `IAppConfig $appConfig` and a `LoggerInterface $logger` constructor property.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Bsn
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Bsn;

use OCA\Pipelinq\AppInfo\Application;
use RuntimeException;

/**
 * Shared OR persistence helpers for the BSN/BRP capability.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.3
 */
trait BsnObjectStoreTrait
{
    /**
     * Resolve the register + a schema config key into their stored IDs.
     *
     * @param string $schemaKey The app-config schema key (e.g. brpPersoon_schema).
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws RuntimeException If the register or schema is not configured.
     */
    private function resolve(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

        if ($register === '' || $schema === '') {
            throw new RuntimeException('BRP register of schema is niet geconfigureerd.');
        }

        return [$register, $schema];
    }//end resolve()

    /**
     * Get the OpenRegister ObjectService (real API only).
     *
     * @return object The object service.
     *
     * @throws RuntimeException If OpenRegister is not available.
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
     * Find all objects of a schema matching the given filters.
     *
     * @param string               $schemaKey The schema config key.
     * @param array<string, mixed> $filters   Extra equality filters.
     *
     * @return array<int, array<string, mixed>> The matching objects as arrays.
     */
    private function findAllBy(string $schemaKey, array $filters=[]): array
    {
        [$register, $schema] = $this->resolve(schemaKey: $schemaKey);

        try {
            $results = $this->objectService()->findAll(
                config: ['filters' => array_merge(['register' => $register, 'schema' => $schema], $filters)]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq BRP: findAll failed', ['exception' => $e->getMessage()]);
            return [];
        }

        $rows = [];
        foreach (($results ?? []) as $result) {
            $rows[] = $this->asArray(object: $result);
        }

        return $rows;
    }//end findAllBy()

    /**
     * Persist an object via the OR ObjectService.
     *
     * @param string               $schemaKey The schema config key.
     * @param array<string, mixed> $object    The object data.
     * @param string|null          $uuid      The object UUID (null to create).
     *
     * @return array<string, mixed> The saved object as an array.
     */
    private function save(string $schemaKey, array $object, ?string $uuid=null): array
    {
        [$register, $schema] = $this->resolve(schemaKey: $schemaKey);
        unset($object['@self']);

        $saved = $this->objectService()->saveObject(
            object: $object,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $uuid
        );

        return $this->asArray(object: $saved);
    }//end save()

    /**
     * Delete an object by UUID within this app's schema.
     *
     * @param string $schemaKey The schema config key.
     * @param string $uuid      The object UUID.
     *
     * @return void
     */
    private function delete(string $schemaKey, string $uuid): void
    {
        if ($uuid === '') {
            return;
        }

        [$register, $schema] = $this->resolve(schemaKey: $schemaKey);

        try {
            $this->objectService()->deleteObject(register: $register, schema: $schema, uuid: $uuid);
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq BRP: delete failed', ['exception' => $e->getMessage()]);
        }
    }//end delete()

    /**
     * Normalise an OR object (entity or array) into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
     */
    private function asArray(mixed $object): array
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
    }//end asArray()
}//end trait
