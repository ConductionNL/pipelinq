<?php

/**
 * Pipelinq SystemTagCrudService.
 *
 * Service for low-level CRUD operations on SystemTag-based configurable lists.
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

use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;
use Psr\Log\LoggerInterface;

/**
 * Low-level CRUD operations for SystemTag-based lists.
 */
class SystemTagCrudService
{
    /**
     * Known pipelinq object types for tag cross-referencing.
     *
     * @var string[]
     */
    private const PIPELINQ_OBJECT_TYPES = [
        'pipelinq_lead_source',
        'pipelinq_request_channel',
    ];

    /**
     * Constructor.
     *
     * @param ISystemTagManager      $tagManager The system tag manager.
     * @param ISystemTagObjectMapper $tagMapper  The system tag object mapper.
     * @param LoggerInterface        $logger     The logger.
     */
    public function __construct(
        private ISystemTagManager $tagManager,
        private ISystemTagObjectMapper $tagMapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Fetch tag IDs mapped to a given object type.
     *
     * @param string $objectType The object type.
     *
     * @return array The tag IDs.
     */
    public function getTagIdsForType(string $objectType): array
    {
        $tagIds = $this->tagMapper->getTagIdsForObjects(
            objIds: [$objectType],
            objectType: $objectType
        );

        if (empty($tagIds[$objectType]) === true) {
            return [];
        }

        return $tagIds[$objectType];
    }//end getTagIdsForType()

    /**
     * Resolve tag IDs to tag data arrays.
     *
     * @param array $tagIds The tag IDs to resolve.
     *
     * @return array The resolved tag data.
     */
    public function resolveTagData(array $tagIds): array
    {
        $tags = $this->tagManager->getTagsByIds($tagIds);

        $result = [];
        foreach ($tags as $tag) {
            $result[] = [
                'id'   => (int) $tag->getId(),
                'name' => $tag->getName(),
            ];
        }

        return $result;
    }//end resolveTagData()

    /**
     * Create a new system tag or reuse an existing one with the same name.
     *
     * @param string $name The tag name.
     *
     * @return object The system tag.
     */
    public function createOrReuseSystemTag(string $name): object
    {
        try {
            return $this->tagManager->createTag(
                tagName: $name,
                userVisible: true,
                userAssignable: false
            );
        } catch (TagAlreadyExistsException $e) {
            return $this->tagManager->getTag(
                tagName: $name,
                userVisible: true,
                userAssignable: false
            );
        }
    }//end createOrReuseSystemTag()

    /**
     * Assign a tag to an object type.
     *
     * @param string $objectType The object type.
     * @param int    $tagId      The tag ID to assign.
     *
     * @return void
     */
    public function assignTag(string $objectType, int $tagId): void
    {
        $this->tagMapper->assignTags(
            objId: $objectType,
            objectType: $objectType,
            tagIds: [$tagId]
        );
    }//end assignTag()

    /**
     * Unassign a tag from an object type and optionally delete the global tag.
     *
     * @param string $objectType The object type.
     * @param int    $tagId      The tag ID to remove.
     *
     * @return void
     */
    public function unassignAndCleanup(string $objectType, int $tagId): void
    {
        $this->tagMapper->unassignTags(
            objId: $objectType,
            objectType: $objectType,
            tagIds: [$tagId]
        );

        $stillUsed = $this->isTagUsedByOtherTypes(
            tagId: $tagId,
            excludeType: $objectType
        );

        if ($stillUsed === false) {
            $this->tagManager->deleteTags([(string) $tagId]);
        }
    }//end unassignAndCleanup()

    /**
     * Rename a system tag.
     *
     * @param int    $tagId   The tag ID.
     * @param string $newName The new name.
     *
     * @return void
     */
    public function renameSystemTag(int $tagId, string $newName): void
    {
        $this->tagManager->updateTag(
            tagId: (string) $tagId,
            newName: $newName,
            userVisible: true,
            userAssignable: false,
            description: null
        );
    }//end renameSystemTag()

    /**
     * Check if a tag is still used by other pipelinq object types.
     *
     * @param int    $tagId       The tag ID to check.
     * @param string $excludeType The object type to exclude from the check.
     *
     * @return bool True if the tag is still used by another type.
     */
    private function isTagUsedByOtherTypes(int $tagId, string $excludeType): bool
    {
        foreach (self::PIPELINQ_OBJECT_TYPES as $otherType) {
            if ($otherType === $excludeType) {
                continue;
            }

            $otherTagIds = $this->tagMapper->getTagIdsForObjects(
                objIds: [$otherType],
                objectType: $otherType
            );

            if (empty($otherTagIds[$otherType]) === false
                && in_array($tagId, $otherTagIds[$otherType], true) === true
            ) {
                return true;
            }
        }

        return false;
    }//end isTagUsedByOtherTypes()
}//end class
