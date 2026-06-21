<?php

/**
 * Pipelinq StufRegisterAccess.
 *
 * Thin wrapper around OpenRegister's ObjectService for the three StUF
 * schemas. Centralises register/schema resolution and JSON serialisation so
 * the rest of the adapter never touches the OR API directly.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\IAppContainer;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Thin OpenRegister ObjectService wrapper for StUF schemas.
 */
class StufRegisterAccess
{
    public const SCHEMA_ENDPOINT = 'stufEndpoint';

    public const SCHEMA_MESSAGE = 'stufMessage';

    public const SCHEMA_MAPPING = 'zaaksysteemMapping';

    /**
     * Constructor.
     *
     * @param IAppContainer   $container The DI container.
     * @param IAppConfig      $appConfig The app config (register id lookup).
     * @param LoggerInterface $logger    The logger.
     */
    public function __construct(
        private IAppContainer $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Save (create or update) an object of the given StUF schema.
     *
     * @param string $schema The schema slug (one of the SCHEMA_* constants).
     * @param array  $data   The object payload.
     *
     * @return array The saved object as a plain array.
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-008
     */
    public function saveObject(string $schema, array $data): array
    {
        $service    = $this->getObjectService();
        $registerId = $this->getRegisterId();
        $saved      = $service->saveObject($data, [], $registerId, $schema, null);
        return $this->normalise(value: $saved);
    }//end saveObject()

    /**
     * Find one object by filter; returns null when there is no match.
     *
     * @param string               $schema  The schema slug.
     * @param array<string,scalar> $filters The filter map merged into OR `findAll`.
     *
     * @return array|null The object as plain array, or null.
     */
    public function findOne(string $schema, array $filters): ?array
    {
        $results = $this->findAll(schema: $schema, filters: $filters, limit: 1);
        return ($results[0] ?? null);
    }//end findOne()

    /**
     * Find many objects matching the filter.
     *
     * @param string               $schema  The schema slug.
     * @param array<string,scalar> $filters The filter map.
     * @param int                  $limit   The page size.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findAll(string $schema, array $filters=[], int $limit=100): array
    {
        try {
            $service = $this->getObjectService();
            $objects = $service->findAll(
                    [
                        'filters' => array_merge(['register' => $this->getRegisterId(), 'schema' => $schema], $filters),
                        'limit'   => $limit,
                    ]
                    );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: 'StUF register findAll failed: {err}',
                context: ['err' => $e->getMessage(), 'schema' => $schema]
            );
            return [];
        }

        if (is_array(value: $objects) === false) {
            return [];
        }

        $result = [];
        foreach ($objects as $obj) {
            $result[] = $this->normalise(value: $obj);
        }

        return $result;
    }//end findAll()

    /**
     * Resolve the OR register id for pipelinq from IAppConfig.
     *
     * @return string The register id.
     */
    private function getRegisterId(): string
    {
        return $this->appConfig->getValueString(app: Application::APP_ID, key: 'register', default: '');
    }//end getRegisterId()

    /**
     * Resolve the ObjectService from the DI container.
     *
     * @return object The ObjectService instance.
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
    }//end getObjectService()

    /**
     * Normalise an OR result (entity, array, or JsonSerializable) to plain array.
     *
     * @param mixed $value The value.
     *
     * @return array<string,mixed>
     */
    private function normalise(mixed $value): array
    {
        if (is_array(value: $value) === true) {
            return $value;
        }

        if (is_object(value: $value) === true && method_exists(object_or_class: $value, method: 'jsonSerialize') === true) {
            $serialised = $value->jsonSerialize();
            if (is_array(value: $serialised) === true) {
                return $serialised;
            }
        }

        return [];
    }//end normalise()
}//end class
