<?php

/**
 * Pipelinq SchemaMapService.
 *
 * Service for resolving schema IDs to entity types.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for resolving schema IDs to entity types.
 */
class SchemaMapService
{
    /**
     * Schema config key to entity type mapping.
     *
     * @var array<string, string>
     */
    private const SCHEMA_MAPPING = [
        'client_schema'   => 'client',
        'contact_schema'  => 'contact',
        'lead_schema'     => 'lead',
        'request_schema'  => 'request',
        'pipeline_schema' => 'pipeline',
    ];

    /**
     * Cached schema map.
     *
     * @var ?array
     */
    private ?array $schemaMap = null;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings service.
     * @param LoggerInterface $logger          The logger.
     */
    public function __construct(
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve a schema ID to an entity type.
     *
     * @param ?string $schemaId The schema ID to resolve.
     *
     * @return ?string The entity type or null.
     */
    public function resolveEntityType(?string $schemaId): ?string
    {
        if ($schemaId === null || $schemaId === '') {
            return null;
        }

        if ($this->schemaMap === null) {
            $this->buildSchemaMap();
        }

        return $this->schemaMap[$schemaId] ?? null;
    }//end resolveEntityType()

    /**
     * Build the schema ID to entity type map from settings.
     *
     * @return void
     */
    private function buildSchemaMap(): void
    {
        $this->schemaMap = [];

        try {
            $settings = $this->settingsService->getSettings();

            foreach (self::SCHEMA_MAPPING as $configKey => $entityType) {
                $schemaId = $settings[$configKey] ?? '';
                if ($schemaId !== '') {
                    $this->schemaMap[$schemaId] = $entityType;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to build Pipelinq schema map',
                    [
                        'exception' => $e->getMessage(),
                    ]
                    );
        }//end try
    }//end buildSchemaMap()
}//end class
