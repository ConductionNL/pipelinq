<?php

/**
 * Pipelinq ObjectenAccessService.
 *
 * Service for managing per-schema group access control for the Objects API.
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
 * @spec openspec/changes/admin-settings/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Manages per-schema group access restrictions for the Objects API.
 *
 * Stores group-ID arrays as JSON under IAppConfig key `objecten_access_<slug>`.
 * If no map exists for a schema the default is open access (backward compatible).
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-1
 */
class ObjectenAccessService
{

    private const KEY_PREFIX = 'objecten_access_';

    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig    The app config.
     * @param IGroupManager   $groupManager The group manager.
     * @param LoggerInterface $logger       The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the full access map for all configured schemas.
     *
     * @return array<string, string[]> Map of schemaSlug → [groupId, ...]
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function getAccessMap(): array
    {
        $map  = [];
        $keys = $this->appConfig->getKeys(Application::APP_ID);

        foreach ($keys as $key) {
            if (str_starts_with(haystack: $key, needle: self::KEY_PREFIX) === false) {
                continue;
            }

            $slug       = substr(string: $key, offset: strlen(self::KEY_PREFIX));
            $raw        = $this->appConfig->getValueString(Application::APP_ID, $key, '[]');
            $map[$slug] = json_decode(json: $raw, associative: true) ?? [];
        }

        return $map;

    }//end getAccessMap()

    /**
     * Set the allowed groups for a specific schema.
     *
     * @param string   $schemaSlug The schema slug.
     * @param string[] $groupIds   The group IDs to allow.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function setSchemaAccess(string $schemaSlug, array $groupIds): void
    {
        $key = self::KEY_PREFIX.$schemaSlug;
        $this->appConfig->setValueString(
            app: Application::APP_ID,
            key: $key,
            value: json_encode(array_values($groupIds))
        );

        $this->logger->info(
            'ObjectenAccessService: schema access updated',
            ['slug' => $schemaSlug, 'groups' => $groupIds]
        );

    }//end setSchemaAccess()

    /**
     * Check whether a user is allowed to access a specific schema.
     *
     * Returns true when:
     * - No access map exists for the schema (open default), or
     * - The user is a member of at least one configured group.
     *
     * @param string $schemaSlug The schema slug.
     * @param string $userId     The user ID to check.
     *
     * @return bool True if access is allowed.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function isAllowed(string $schemaSlug, string $userId): bool
    {
        $key = self::KEY_PREFIX.$schemaSlug;
        $raw = $this->appConfig->getValueString(Application::APP_ID, $key, '');

        if ($raw === '') {
            return true;
        }

        $groupIds = json_decode(json: $raw, associative: true) ?? [];

        if (empty($groupIds) === true) {
            return true;
        }

        foreach ($groupIds as $groupId) {
            if ($this->groupManager->isInGroup(userId: $userId, group: $groupId) === true) {
                return true;
            }
        }

        return false;

    }//end isAllowed()
}//end class
