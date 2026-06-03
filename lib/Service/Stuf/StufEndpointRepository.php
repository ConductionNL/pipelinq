<?php

/**
 * Pipelinq StufEndpointRepository.
 *
 * Read access to StufEndpoint configuration objects and the StufMessage audit
 * log through the OpenRegister ObjectService (real API only).
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Repository for StufEndpoint and StufMessage objects.
 */
class StufEndpointRepository
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     * @param IAppConfig         $appConfig The app config.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * List all StufEndpoint objects.
     *
     * @return array<int, array<string, mixed>> The endpoint rows.
     */
    public function findAll(): array
    {
        [$register, $schema] = $this->config(schemaKey: 'stufEndpoint_schema');

        $results = $this->getObjectService()->findAll(
            ['register' => $register, 'schema' => $schema]
        );

        return $this->normaliseResults(results: $results);
    }//end findAll()

    /**
     * Find a StufEndpoint by its business id (matches the `id` field or slug).
     *
     * @param string $endpointId The endpoint id.
     *
     * @return array<string, mixed>|null The endpoint, or null when not found.
     */
    public function findById(string $endpointId): ?array
    {
        foreach ($this->findAll() as $endpoint) {
            $candidate = (string) ($endpoint['id'] ?? '');
            $slug      = (string) ($endpoint['@self']['slug'] ?? '');
            if ($candidate === $endpointId || $slug === $endpointId) {
                return $endpoint;
            }
        }

        return null;
    }//end findById()

    /**
     * Query StufMessage audit-log rows with optional filters.
     *
     * @param string $endpointId   Filter by endpoint id (empty = any).
     * @param string $berichtSoort Filter by message type (empty = any).
     * @param string $status       Filter by status (empty = any).
     * @param int    $limit        Maximum number of rows.
     *
     * @return array<int, array<string, mixed>> The matching rows.
     */
    public function findMessages(string $endpointId, string $berichtSoort, string $status, int $limit): array
    {
        [$register, $schema] = $this->config(schemaKey: 'stufMessage_schema');

        $filters = [];
        if ($endpointId !== '') {
            $filters['endpointId'] = $endpointId;
        }

        if ($berichtSoort !== '') {
            $filters['berichtSoort'] = $berichtSoort;
        }

        if ($status !== '') {
            $filters['status'] = $status;
        }

        $results = $this->getObjectService()->findAll(
            [
                'register' => $register,
                'schema'   => $schema,
                'filters'  => $filters,
                'limit'    => $limit,
            ]
        );

        return $this->normaliseResults(results: $results);
    }//end findMessages()

    /**
     * Resolve the register + a schema config key into stored IDs.
     *
     * @param string $schemaKey The app-config schema key.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws RuntimeException If unconfigured.
     */
    private function config(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('StUF register/schema not configured: '.$schemaKey);
        }

        return [$register, $schema];
    }//end config()

    /**
     * Normalise an OR findAll result into a list of row arrays.
     *
     * @param mixed $results The ObjectService findAll result.
     *
     * @return array<int, array<string, mixed>> The row arrays.
     */
    private function normaliseResults(mixed $results): array
    {
        if (is_array($results) === false) {
            return [];
        }

        $rows = ($results['results'] ?? $results);
        if (is_array($rows) === false) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) === true) {
                $out[] = $row;
                continue;
            }

            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $serialised = $row->jsonSerialize();
                if (is_array($serialised) === true) {
                    $out[] = $serialised;
                }
            }
        }

        return $out;
    }//end normaliseResults()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The object service.
     *
     * @throws RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()
}//end class
