<?php

/**
 * Contract of SystemTagService
 *
 * The public surface of SystemTagService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of SystemTagService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SystemTagServiceInterface {

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
	public function addTag(string $objectType, string $name): array;

	/**
	 * Get all tags for a given object type.
	 *
	 * @param string $objectType The object type to get tags for.
	 *
	 * @return array<array{id: int, name: string}> The tags.
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-tags/tasks.md#task-9
	 */
	public function getTags(string $objectType): array;

	/**
	 * Remove a tag from the given object type.
	 *
	 * @param string $objectType The object type.
	 * @param int $tagId The tag ID to remove.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-tags/tasks.md#task-10
	 */
	public function removeTag(string $objectType, int $tagId): void;

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
	public function renameTag(string $objectType, int $tagId, string $newName): array;
}//end interface
