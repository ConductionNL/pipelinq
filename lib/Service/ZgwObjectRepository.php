<?php

/**
 * Pipelinq ZgwObjectRepository.
 *
 * Thin persistence helper over OpenRegister's ObjectService for the four ZGW
 * bridge schemas (zgwEndpoint, zgwClient, nrcAbonnement, zgwResourceMapping).
 * Centralises register/schema resolution and the real OR API surface
 * (find/findAll/saveObject) so the typed component clients stay focused on ZGW
 * protocol concerns. Reuses OR object persistence (ADR-022) rather than a
 * bespoke store.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Exception\ZgwBridgeException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Persistence helper for the ZGW bridge schemas.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#1.4
 */
class ZgwObjectRepository
{
    /**
     * Map of logical entity name to its app-config schema key.
     *
     * @var array<string, string>
     */
    private const SCHEMA_KEYS = [
        'zgwEndpoint'        => 'zgwEndpoint_schema',
        'zgwClient'          => 'zgwClient_schema',
        'nrcAbonnement'      => 'nrcAbonnement_schema',
        'zgwResourceMapping' => 'zgwResourceMapping_schema',
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig The app config (register/schema IDs).
     * @param ContainerInterface $container The container (for OpenRegister ObjectService).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the configured register ID and the schema ID for an entity.
     *
     * @param string $entity The logical entity name (e.g. "zgwEndpoint").
     *
     * @return array{0:string, 1:string} The [register, schema] ID pair.
     *
     * @throws ZgwBridgeException When the register or schema is not configured.
     */
    private function config(string $entity): array
    {
        $schemaKey = (self::SCHEMA_KEYS[$entity] ?? '');
        $register  = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema    = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

        if ($register === '' || $schema === '') {
            throw new ZgwBridgeException(
                message: sprintf('ZGW-register of -schema "%s" is niet geconfigureerd.', $entity)
            );
        }

        return [$register, $schema];
    }//end config()

    /**
     * Get the OpenRegister ObjectService via the container.
     *
     * @return object The ObjectService.
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Normalise an OR object (entity or array) to an associative array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
     */
    public function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialised = $object->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return (array) $object;
    }//end toArray()

    /**
     * Find ZGW objects of an entity type matching the given filters.
     *
     * @param string               $entity  The logical entity name.
     * @param array<string, mixed> $filters Additional field filters.
     *
     * @return array<int, array<string, mixed>> The matching objects as arrays.
     */
    public function findBy(string $entity, array $filters=[]): array
    {
        [$register, $schema] = $this->config(entity: $entity);

        try {
            $results = $this->getObjectService()->findAll(
                [
                    'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
                    'limit'   => 999,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'ZgwObjectRepository: findBy failed',
                ['entity' => $entity, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $out = [];
        foreach ($results as $result) {
            $out[] = $this->toArray(object: $result);
        }

        return $out;
    }//end findBy()

    /**
     * Find the first ZGW object matching a single field value.
     *
     * @param string $entity The logical entity name.
     * @param string $field  The field to match.
     * @param string $value  The value to match.
     *
     * @return array<string, mixed>|null The first match, or null.
     */
    public function findOneByField(string $entity, string $field, string $value): ?array
    {
        $matches = $this->findBy(entity: $entity, filters: [$field => $value]);
        return ($matches[0] ?? null);
    }//end findOneByField()

    /**
     * Persist a ZGW object (create or update by UUID).
     *
     * @param string               $entity The logical entity name.
     * @param array<string, mixed> $data   The object data (without @self).
     * @param string|null          $uuid   Optional UUID to update an existing object.
     *
     * @return array<string, mixed> The saved object as an array.
     *
     * @throws ZgwBridgeException When persistence fails.
     */
    public function save(string $entity, array $data, ?string $uuid=null): array
    {
        [$register, $schema] = $this->config(entity: $entity);
        unset($data['@self']);

        try {
            $saved = $this->getObjectService()->saveObject(
                object: $data,
                extend: [],
                register: $register,
                schema: $schema,
                uuid: $uuid
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'ZgwObjectRepository: save failed',
                ['entity' => $entity, 'exception' => $e->getMessage()]
            );
            throw new ZgwBridgeException(message: 'Opslaan van ZGW-object mislukt: '.$e->getMessage(), code: 0, previous: $e);
        }

        return $this->toArray(object: $saved);
    }//end save()
}//end class
