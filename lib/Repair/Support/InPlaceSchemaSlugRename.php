<?php

/**
 * Pipelinq InPlaceSchemaSlugRename.
 *
 * The guarded in-place rename of one schema slug, shared by the repair steps that
 * each move one slug ahead of the register import. OpenRegister matches a schema
 * by (application, slug) and creates a new one when that misses, so a slug rename
 * in the shipped register fragment would orphan the old schema and its objects;
 * renaming the row in place keeps its id, and so its objects.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair\Support
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair\Support;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use Psr\Log\LoggerInterface;

/**
 * Renames one schema slug in place, unless doing so would be ambiguous.
 *
 * The base of a repair step (the step itself implements IRepairStep and names
 * itself); it names its slugs and its two step-specific notices as constants, and
 * every report starts with its class name. It refuses, and says why, when the
 * schema table cannot be read, when nothing carries the old slug, when both slugs
 * exist, or when the old slug is duplicated.
 *
 * @spec exclude No canonical spec covers the schema slug migrations. Pointing this
 *  at an existing spec would report conformance to a requirement that says nothing
 *  about it.
 */
abstract class InPlaceSchemaSlugRename {
	/**
	 * The slug the step migrates away from.
	 *
	 * @var string
	 */
	protected const OLD_SLUG = '';

	/**
	 * The slug the register fragment now declares.
	 *
	 * @var string
	 */
	protected const NEW_SLUG = '';

	/**
	 * The output when no schema carries the old slug.
	 *
	 * @var string
	 */
	protected const ABSENT_NOTICE = '';

	/**
	 * The log line when the old slug is carried by more than one schema.
	 *
	 * @var string
	 */
	protected const DUPLICATE_NOTICE = '';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Rename the slug, unless doing so would be ambiguous.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the schema slug migrations.
	 */
	public function run(IOutput $output): void {
		$matches = new SlugMatches(
			old: $this->schemaIds(slug: static::OLD_SLUG),
			new: $this->schemaIds(slug: static::NEW_SLUG)
		);
		if ($this->reportsNothingToRename(output: $output, matches: $matches) === true) {
			return;
		}

		$this->renameSchema(output: $output, id: $matches->oldId());
	}//end run()

	/**
	 * Report why nothing is renamed, when that is so.
	 *
	 * @param IOutput     $output  Repair output.
	 * @param SlugMatches $matches The ids under both slugs.
	 *
	 * @return bool True when there is nothing to rename.
	 */
	private function reportsNothingToRename(IOutput $output, SlugMatches $matches): bool {
		if ($matches->isUnreadable() === true) {
			$output->info($this->stepName() . ': schema table unreadable; leaving the slug alone.');
			return true;
		}

		if ($matches->old === []) {
			$output->info(static::ABSENT_NOTICE);
			return true;
		}

		return $this->refusesAmbiguousRename(output: $output, matches: $matches);
	}//end reportsNothingToRename()

	/**
	 * Refuse a rename that would merge two schemas or pick one of several.
	 *
	 * @param IOutput     $output  Repair output.
	 * @param SlugMatches $matches The ids under both slugs.
	 *
	 * @return bool True when the rename is refused.
	 */
	private function refusesAmbiguousRename(IOutput $output, SlugMatches $matches): bool {
		if ($matches->new !== []) {
			$this->refuseMerge(output: $output, matches: $matches);
			return true;
		}

		if (count($matches->old) > 1) {
			$this->logger->warning(
				static::DUPLICATE_NOTICE,
				['ids' => $matches->old]
			);
			$output->warning($this->stepName() . ': duplicate `' . static::OLD_SLUG . '` schemas; refusing to guess.');
			return true;
		}

		return false;
	}//end refusesAmbiguousRename()

	/**
	 * Refuse to merge the two slugs.
	 *
	 * Both slugs present: each may own objects, and renaming would collide
	 * with the new row. Abandoning either set of objects is not a call
	 * a repair step gets to make without being asked.
	 *
	 * @param IOutput     $output  Repair output.
	 * @param SlugMatches $matches The ids under both slugs.
	 *
	 * @return void
	 */
	private function refuseMerge(IOutput $output, SlugMatches $matches): void {
		$this->logger->warning(
			$this->stepName() . ': both slugs exist; refusing to merge them.',
			['old' => $matches->old, 'new' => $matches->new]
		);
		$output->warning(
			$this->stepName() . ': both `' . static::OLD_SLUG . '` and `' . static::NEW_SLUG
			. '` exist; refusing to merge them. Resolve by hand.'
		);
	}//end refuseMerge()

	/**
	 * Move the one old schema to the new slug, in place.
	 *
	 * @param IOutput $output Repair output.
	 * @param mixed   $id     The schema id.
	 *
	 * @return void
	 */
	private function renameSchema(IOutput $output, mixed $id): void {
		try {
			$this->slugTable()->renameSchema(id: $id, slug: static::NEW_SLUG);
		} catch (Exception $e) {
			// A failure here is safe: the import then creates a new schema rather
			// than updating this one, which is the pre-existing behaviour. Loud,
			// because the objects on the old schema stop being reachable.
			$this->logger->error(
				$this->stepName() . ': slug rename failed; the import will create a second schema.',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			$output->warning($this->stepName() . ': slug rename failed; see the log.');
			return;
		}

		$output->info(
			$this->stepName() . ': schema ' . $id . ' renamed `'
			. static::OLD_SLUG . '` -> `' . static::NEW_SLUG . '`; its objects stay attached.'
		);
	}//end renameSchema()

	/**
	 * Ids of this application's schemas carrying the given slug.
	 *
	 * @param string $slug The schema slug to look for.
	 *
	 * @return array<int, mixed>|null The ids, or null when the table cannot be read.
	 */
	private function schemaIds(string $slug): ?array {
		try {
			return $this->slugTable()->idsWithSlug(slug: $slug);
		} catch (Exception $e) {
			$this->logger->warning(
				$this->stepName() . ': could not read the schema table; skipping.',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end schemaIds()

	/**
	 * The schema rows this step reads and renames.
	 *
	 * @return SchemaSlugTable The table.
	 */
	private function slugTable(): SchemaSlugTable {
		return new SchemaSlugTable(db: $this->db);
	}//end slugTable()
	/**
	 * The step's class name, which every report starts with.
	 *
	 * @return string The short class name.
	 */
	private function stepName(): string {
		return substr((string)strrchr(static::class, '\\'), 1);
	}//end stepName()
}//end class
