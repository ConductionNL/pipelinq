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
use OCA\Pipelinq\Kernel\Contracts\SystemTagServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Generic service for managing SystemTag-based configurable lists.
 * Used for lead sources and request channels.
 *
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-tags/tasks.md
 */
class SystemTagService implements SystemTagServiceInterface {
	/**
	 * Constructor.
	 *
	 * @param SystemTagCrudService $tagCrudService The tag CRUD service.
	 * @param LoggerInterface $logger The logger.
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
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-tags/tasks.md#task-9
	 */
	public function getTags(string $objectType): array {
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
	 * @param string $name The tag name.
	 *
	 * @return array{id: int, name: string} The created tag.
	 *
	 * @throws RuntimeException If tag name already exists for this type.
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-tags/tasks.md#task-7
	 */
	public function addTag(string $objectType, string $name): array {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidArgumentException('Tag name cannot be empty');
		}

		$this->validateTagNameUnique(
			objectType: $objectType,
			tag: ['id' => null, 'name' => $name]
		);

		$tag = $this->tagCrudService->createOrReuseSystemTag(name: $name);

		$this->tagCrudService->assignTag(
			objectType: $objectType,
			tagId: (int)$tag->getId()
		);

		$this->logger->info("Pipelinq: Added {$objectType} tag: {$name}");

		return [
			'id' => (int)$tag->getId(),
			'name' => $tag->getName(),
		];
	}//end addTag()

	/**
	 * Remove a tag from the given object type.
	 *
	 * @param string $objectType The object type.
	 * @param int $tagId The tag ID to remove.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-tags/tasks.md#task-10
	 */
	public function removeTag(string $objectType, int $tagId): void {
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
	 * @param int $tagId The tag ID to rename.
	 * @param string $newName The new tag name.
	 *
	 * @return array{id: int, name: string} The renamed tag.
	 *
	 * @throws RuntimeException If new name already exists for this type.
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-tags/tasks.md#task-11
	 */
	public function renameTag(string $objectType, int $tagId, string $newName): array {
		return $this->applyRename(objectType: $objectType, tag: ['id' => $tagId, 'name' => self::nonEmptyName(name: $newName)]);
	}//end renameTag()

	/**
	 * The trimmed tag name.
	 *
	 * @param string $name The requested name.
	 *
	 * @return string The trimmed name.
	 *
	 * @throws InvalidArgumentException When the name is empty.
	 */
	private static function nonEmptyName(string $name): string {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidArgumentException('Tag name cannot be empty');
		}

		return $name;
	}//end nonEmptyName()

	/**
	 * Rename a tag to a validated, trimmed name and log it.
	 *
	 * @param string $objectType The object type.
	 * @param array{id: int, name: string} $tag The tag ID and its new name.
	 *
	 * @return array{id: int, name: string} The renamed tag.
	 *
	 * @throws RuntimeException If new name already exists for this type.
	 */
	private function applyRename(string $objectType, array $tag): array {
		$this->validateTagNameUnique(objectType: $objectType, tag: $tag);

		$this->tagCrudService->renameSystemTag(
			tagId: $tag['id'],
			newName: $tag['name']
		);

		$this->logger->info("Pipelinq: Renamed {$objectType} tag ID {$tag['id']} to: {$tag['name']}");

		return $tag;
	}//end applyRename()

	/**
	 * Ensure default tags exist for an object type (idempotent).
	 *
	 * @param string $objectType The object type.
	 * @param string[] $defaults List of default tag names.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-tags/tasks.md#task-8
	 */
	public function ensureDefaults(string $objectType, array $defaults): void {
		$existing = $this->getTags(objectType: $objectType);
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
						"Pipelinq: Failed to create default {$objectType} tag '{$name}': " . $e->getMessage()
					);
				}
			}
		}
	}//end ensureDefaults()

	/**
	 * Validate that a tag name is unique within the given object type.
	 *
	 * @param string $objectType The object type.
	 * @param array{id: int|null, name: string} $tag The tag name to check, and the tag ID to exclude from the check (or null).
	 *
	 * @return void
	 *
	 * @throws RuntimeException If the tag name already exists.
	 */
	private function validateTagNameUnique(string $objectType, array $tag): void {
		$excludeId = $tag['id'];
		$existing = $this->getTags(objectType: $objectType);
		foreach ($existing as $other) {
			if ($excludeId !== null && $other['id'] === $excludeId) {
				continue;
			}

			if (strcasecmp($other['name'], $tag['name']) === 0) {
				throw new RuntimeException('This tag already exists');
			}
		}
	}//end validateTagNameUnique()
}//end class
