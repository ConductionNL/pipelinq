<?php

/**
 * Contract of NotesService
 *
 * The public surface of NotesService that other components call, so they depend
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

/**
 * Contract of NotesService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NotesServiceInterface {

	public const VALID_TYPES = [
		'pipelinq_client',
		'pipelinq_contact',
		'pipelinq_lead',
		'pipelinq_request',
	];

	/**
	 * Add a note to an entity.
	 *
	 * @param string $objectType The object type.
	 * @param string $objectId The object ID.
	 * @param string $message The note message.
	 *
	 * @return array The created note.
	 *
	 * @spec openspec/specs/entity-notes/spec.md
	 */
	public function addNote(string $objectType, string $objectId, string $message): array;

	/**
	 * Delete all notes for an entity (used when entity is deleted).
	 *
	 * @param string $objectType The object type.
	 * @param string $objectId The object ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/entity-notes/spec.md
	 */
	public function deleteAllNotes(string $objectType, string $objectId): void;

	/**
	 * Delete a single note. Only the author may delete their own note.
	 *
	 * @param int $noteId The note ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/entity-notes/spec.md
	 */
	public function deleteNote(int $noteId): void;

	/**
	 * Get all notes for a given entity.
	 *
	 * @param string $objectType The object type.
	 * @param string $objectId The object ID.
	 *
	 * @return array The notes.
	 *
	 * @spec openspec/specs/entity-notes/spec.md
	 */
	public function getNotes(string $objectType, string $objectId): array;
}//end interface
