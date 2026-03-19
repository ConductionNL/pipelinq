<?php

/**
 * Pipelinq SettingsMapBuilder.
 *
 * Service for building schema and register maps from import results.
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

/**
 * Service for building schema and register maps from import results.
 */
class SettingsMapBuilder
{
    /**
     * Pipelinq register slug.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'pipelinq';

    /**
     * Build a slug-to-ID map from imported schemas.
     *
     * @param array $schemas The imported schemas.
     *
     * @return array The slug-to-ID map.
     */
    public function buildSchemaSlugMap(array $schemas): array
    {
        $schemaMap = [];
        foreach ($schemas as $schema) {
            $this->addSchemaToMap(
                schema: $schema,
                schemaMap: $schemaMap
            );
        }

        return $schemaMap;
    }//end buildSchemaSlugMap()

    /**
     * Find the pipelinq register ID from imported registers.
     *
     * @param array $registers The imported registers.
     *
     * @return mixed The register ID or null.
     */
    public function findRegisterIdBySlug(array $registers): mixed
    {
        foreach ($registers as $register) {
            $registerId = $this->extractRegisterIdIfMatch(register: $register);
            if ($registerId !== null) {
                return $registerId;
            }
        }

        return null;
    }//end findRegisterIdBySlug()

    /**
     * Find the default view ID from imported views.
     *
     * Looks for a view marked as default, or falls back to the first view.
     *
     * @param array $views The imported views.
     *
     * @return ?string The view ID or null.
     */
    public function findDefaultViewId(array $views): ?string
    {
        $defaultId = $this->findMarkedDefaultViewId($views);
        if ($defaultId !== null) {
            return $defaultId;
        }

        return $this->getFirstViewId($views);
    }//end findDefaultViewId()

    /**
     * Find the ID of the view marked as default.
     *
     * @param array $views The imported views.
     *
     * @return ?string The view ID or null.
     */
    private function findMarkedDefaultViewId(array $views): ?string
    {
        foreach ($views as $view) {
            $viewArray = $this->normalizeToArray(value: $view);
            if ($viewArray === null) {
                continue;
            }

            $isDefault = $viewArray['isDefault'] ?? false;
            if ($isDefault === true) {
                return $viewArray['id'] ?? $viewArray['uuid'] ?? null;
            }
        }

        return null;
    }//end findMarkedDefaultViewId()

    /**
     * Get the ID of the first view in the list.
     *
     * @param array $views The imported views.
     *
     * @return ?string The view ID or null.
     */
    private function getFirstViewId(array $views): ?string
    {
        if (empty($views) === true) {
            return null;
        }

        $firstView = $this->normalizeToArray(value: reset($views));
        if ($firstView === null) {
            return null;
        }

        return $firstView['id'] ?? $firstView['uuid'] ?? null;
    }//end getFirstViewId()

    /**
     * Add a single schema entry to the slug map.
     *
     * @param mixed $schema    The schema object or array.
     * @param array $schemaMap The map to populate.
     *
     * @return void
     */
    private function addSchemaToMap(mixed $schema, array &$schemaMap): void
    {
        $schemaArray = $this->normalizeToArray(value: $schema);
        if ($schemaArray === null) {
            return;
        }

        if (isset($schemaArray['slug']) === false) {
            return;
        }

        $schemaMap[$schemaArray['slug']] = $schemaArray['id'] ?? $schemaArray['uuid'] ?? null;
    }//end addSchemaToMap()

    /**
     * Extract register ID if the register matches the pipelinq slug.
     *
     * @param mixed $register The register object or array.
     *
     * @return mixed The register ID or null.
     */
    private function extractRegisterIdIfMatch(mixed $register): mixed
    {
        $registerArray = $this->normalizeToArray(value: $register);
        if ($registerArray === null) {
            return null;
        }

        if (isset($registerArray['slug']) === false) {
            return null;
        }

        if ($registerArray['slug'] !== self::REGISTER_SLUG) {
            return null;
        }

        return $registerArray['id'] ?? $registerArray['uuid'] ?? null;
    }//end extractRegisterIdIfMatch()

    /**
     * Normalize an object or array value to an array.
     *
     * @param mixed $value The value to normalize.
     *
     * @return ?array The array or null if not normalizable.
     */
    private function normalizeToArray(mixed $value): ?array
    {
        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            return $value->jsonSerialize();
        }

        if (is_array($value) === true) {
            return $value;
        }

        return null;
    }//end normalizeToArray()
}//end class
