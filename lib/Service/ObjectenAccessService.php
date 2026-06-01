<?php

/**
 * Pipelinq Objecten Access Service
 *
 * Manages per-schema group access restrictions for the Objecten API.
 * Stored in IAppConfig under keys objecten_access_<schemaSlug>.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Service for managing per-schema Objecten API access restrictions.
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-1.1
 */
class ObjectenAccessService
{

    private const CONFIG_PREFIX = 'objecten_access_';


    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly IGroupManager $groupManager,
    ) {

    }//end __construct()


    /**
     * Returns the full access map for all configured schemas.
     *
     * @return array<string, string[]> Map of schemaSlug → groupIds.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function getAccessMap(): array
    {
        $keys = $this->appConfig->getKeys(app: Application::APP_ID);
        $map  = [];

        foreach ($keys as $key) {
            if (str_starts_with(haystack: $key, needle: self::CONFIG_PREFIX) === false) {
                continue;
            }

            $slug        = substr(string: $key, offset: strlen(string: self::CONFIG_PREFIX));
            $encoded     = $this->appConfig->getValueString(app: Application::APP_ID, key: $key, default: '[]');
            $map[$slug]  = json_decode(json: $encoded, associative: true) ?? [];
        }

        return $map;

    }//end getAccessMap()


    /**
     * Stores the list of group IDs allowed to access a schema.
     *
     * @param string   $schemaSlug Schema slug identifier.
     * @param string[] $groupIds   Nextcloud group IDs that may access this schema.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function setSchemaAccess(string $schemaSlug, array $groupIds): void
    {
        $key = self::CONFIG_PREFIX . $schemaSlug;

        if (empty($groupIds) === true) {
            $this->appConfig->deleteKey(app: Application::APP_ID, key: $key);
            return;
        }

        $this->appConfig->setValueString(
            app: Application::APP_ID,
            key: $key,
            value: json_encode(array_values(array: $groupIds)),
        );

    }//end setSchemaAccess()


    /**
     * Checks whether a user is allowed to access a schema.
     *
     * Returns true if no access map exists for the schema (open default).
     * Returns true if the user is in any of the configured groups.
     *
     * @param string $schemaSlug Schema slug to check.
     * @param string $userId     Nextcloud user UID.
     *
     * @return bool
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function isAllowed(string $schemaSlug, string $userId): bool
    {
        $key     = self::CONFIG_PREFIX . $schemaSlug;
        $encoded = $this->appConfig->getValueString(app: Application::APP_ID, key: $key, default: '');

        if (empty($encoded) === true) {
            return true;
        }

        $groupIds = json_decode(json: $encoded, associative: true) ?? [];

        if (empty($groupIds) === true) {
            return true;
        }

        foreach ($groupIds as $groupId) {
            if ($this->groupManager->isInGroup($userId, $groupId) === true) {
                return true;
            }
        }

        return false;

    }//end isAllowed()


}//end class
