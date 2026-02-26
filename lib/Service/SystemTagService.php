<?php

/**
 * Pipelinq SystemTagService.
 *
 * Generic service for managing SystemTag-based configurable lists.
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

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Generic service for managing SystemTag-based configurable lists.
 * Used for lead sources and request channels.
 */
class SystemTagService
{
    /**
     * Constructor.
     *
     * @param SystemTagCrudService $tagCrudService The tag CRUD service.
     * @param LoggerInterface      $logger         The logger.
     */
    public function __construct(
        private SystemTagCrudService $tagCrudService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get all tags for a given object type.
     *
     * @param string $objectType The object type to get tags for.
     *
     * @return array<array{id: int, name: string}> The tags.
     */
    public function getTags(string $objectType): array
    {
        $tagIds = $this->tagCrudService->getTagIdsForType(objectType: $objectType);

        if (empty($tagIds) === true) {
            return [];
        }

        $result = $this->tagCrudService->resolveTagData(tagIds: $tagIds);

        usort($result, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $result;
    }//end getTags()

    /**
     * Add a new tag to the given object type.
     *
     * @param string $objectType The object type.
     * @param string $name       The tag name.
     *
     * @return array{id: int, name: string} The created tag.
     *
     * @throws RuntimeException If tag name already exists for this type.
     */
    public function addTag(string $objectType, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Tag name cannot be empty');
        }

        $this->validateTagNameUnique(
            objectType: $objectType,
            name: $name
        );

        $tag = $this->tagCrudService->createOrReuseSystemTag(name: $name);

        $this->tagCrudService->assignTag(
            objectType: $objectType,
            tagId: (int) $tag->getId()
        );

        $this->logger->info("Pipelinq: Added {$objectType} tag: {$name}");

        return [
            'id'   => (int) $tag->getId(),
            'name' => $tag->getName(),
        ];
    }//end addTag()

    /**
     * Remove a tag from the given object type.
     *
     * @param string $objectType The object type.
     * @param int    $tagId      The tag ID to remove.
     *
     * @return void
     */
    public function removeTag(string $objectType, int $tagId): void
    {
        $this->tagCrudService->unassignAndCleanup(
            objectType: $objectType,
            tagId: $tagId
        );

        $this->logger->info("Pipelinq: Removed {$objectType} tag ID: {$tagId}");
    }//end removeTag()

    /**
     * Rename a tag.
     *
     * @param string $objectType The object type.
     * @param int    $tagId      The tag ID to rename.
     * @param string $newName    The new tag name.
     *
     * @return array{id: int, name: string} The renamed tag.
     *
     * @throws RuntimeException If new name already exists for this type.
     */
    public function renameTag(string $objectType, int $tagId, string $newName): array
    {
        $newName = trim($newName);
        if ($newName === '') {
            throw new InvalidArgumentException('Tag name cannot be empty');
        }

        $this->validateTagNameUnique(
            objectType: $objectType,
            name: $newName,
            excludeId: $tagId
        );

        $this->tagCrudService->renameSystemTag(
            tagId: $tagId,
            newName: $newName
        );

        $this->logger->info("Pipelinq: Renamed {$objectType} tag ID {$tagId} to: {$newName}");

        return [
            'id'   => $tagId,
            'name' => $newName,
        ];
    }//end renameTag()

    /**
     * Ensure default tags exist for an object type (idempotent).
     *
     * @param string   $objectType The object type.
     * @param string[] $defaults   List of default tag names.
     *
     * @return void
     */
    public function ensureDefaults(string $objectType, array $defaults): void
    {
        $existing      = $this->getTags(objectType: $objectType);
        $existingNames = array_map(fn ($t) => strtolower($t['name']), $existing);

        foreach ($defaults as $name) {
            if (in_array(strtolower($name), $existingNames, true) === false) {
                try {
                    $this->addTag(
                        objectType: $objectType,
                        name: $name
                    );
                } catch (\Exception $e) {
                    $this->logger->warning(
                        "Pipelinq: Failed to create default {$objectType} tag '{$name}': ".$e->getMessage()
                    );
                }
            }
        }
    }//end ensureDefaults()

    /**
     * Validate that a tag name is unique within the given object type.
     *
     * @param string $objectType The object type.
     * @param string $name       The tag name to check.
     * @param ?int   $excludeId  Optional tag ID to exclude from the check.
     *
     * @return void
     *
     * @throws RuntimeException If the tag name already exists.
     */
    private function validateTagNameUnique(string $objectType, string $name, ?int $excludeId=null): void
    {
        $existing = $this->getTags(objectType: $objectType);
        foreach ($existing as $tag) {
            if ($excludeId !== null && $tag['id'] === $excludeId) {
                continue;
            }

            if (strcasecmp($tag['name'], $name) === 0) {
                throw new RuntimeException('This tag already exists');
            }
        }
    }//end validateTagNameUnique()
}//end class
