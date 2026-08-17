<?php

/**
 * Pipelinq RenameLoyaltyAccountSchemaSlug repair step.
 *
 * Moves the loyalty-account schema's slug from `klantLoyaltyAccount` to
 * `customerLoyaltyAccount` IN PLACE, before InitializeSettings imports the register.
 *
 * OpenRegister's import matches an existing schema by (application, slug):
 * ImportHandler calls `findByApplicationAndSlug()` and creates a NEW schema when that
 * misses. A slug rename in the shipped register fragment therefore does not rename
 * anything — it CREATES a second schema and silently orphans the first, together with
 * every object already written against it. The old schema keeps its shard table and its
 * rows; the app resolves the new id and reads an empty collection. Nothing errors.
 *
 * That is why this step exists and why it must run FIRST. Renaming the row before the
 * import means the import finds the schema it was always going to find, keeps its id,
 * and updates it in place — so the shard table, and the objects in it, stay attached.
 *
 * The app-config KEY is a separate concern and deliberately does not move; see
 * {@see \OCA\Pipelinq\Service\SettingsLoadService::SCHEMA_CONFIG_KEYS}.
 *
 * Idempotent: a no-op once the slug is already English, and a no-op on an install that
 * never had the schema. Refuses when both slugs exist, because picking one would decide
 * which set of objects to abandon — a choice this step must not make silently.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
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

namespace OCA\Pipelinq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames the loyalty-account schema slug in place, ahead of the register import.
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
 *  migration. Pointing this at an existing spec would report conformance to a
 *  requirement that says nothing about it.
 */
class RenameLoyaltyAccountSchemaSlug implements IRepairStep {
	/**
	 * The Dutch slug this step migrates away from.
	 *
	 * @var string
	 */
	private const OLD_SLUG = 'klantLoyaltyAccount';

	/**
	 * The English slug the register fragment now declares.
	 *
	 * @var string
	 */
	private const NEW_SLUG = 'customerLoyaltyAccount';

	/**
	 * The owning application, as stored on the schema row.
	 *
	 * @var string
	 */
	private const APPLICATION = 'pipelinq';

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
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function getName(): string {
		return 'Rename the pipelinq loyalty-account schema slug to English';
	}//end getName()

	/**
	 * Rename the slug, unless doing so would be ambiguous.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function run(IOutput $output): void {
		$old = $this->schemaIds(slug: self::OLD_SLUG);
		$new = $this->schemaIds(slug: self::NEW_SLUG);

		if ($old === null || $new === null) {
			$output->info('RenameLoyaltyAccountSchemaSlug: schema table unreadable; leaving the slug alone.');
			return;
		}

		if ($old === []) {
			$output->info('RenameLoyaltyAccountSchemaSlug: no Dutch loyalty-account schema on this install; nothing to do.');
			return;
		}

		if ($new !== []) {
			// Both slugs present: each may own objects, and renaming would collide
			// with the English row. Abandoning either set of objects is not a call
			// a repair step gets to make without being asked.
			$this->logger->warning(
				'RenameLoyaltyAccountSchemaSlug: both slugs exist; refusing to merge them.',
				['old' => $old, 'new' => $new]
			);
			$output->warning(
				'RenameLoyaltyAccountSchemaSlug: both `' . self::OLD_SLUG . '` and `' . self::NEW_SLUG
				. '` exist; refusing to merge them. Resolve by hand.'
			);
			return;
		}

		if (count($old) > 1) {
			$this->logger->warning(
				'RenameLoyaltyAccountSchemaSlug: duplicate Dutch slugs; refusing to guess.',
				['ids' => $old]
			);
			$output->warning('RenameLoyaltyAccountSchemaSlug: duplicate `' . self::OLD_SLUG . '` schemas; refusing to guess.');
			return;
		}

		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?',
				[self::NEW_SLUG, $old[0]]
			);
		} catch (Exception $e) {
			// A failure here is safe: the import then creates a new schema rather
			// than updating this one, which is the pre-existing behaviour. Loud,
			// because the objects on the old schema stop being reachable.
			$this->logger->error(
				'RenameLoyaltyAccountSchemaSlug: slug rename failed; the import will create a second schema.',
				['id' => $old[0], 'exception' => $e->getMessage()]
			);
			$output->warning('RenameLoyaltyAccountSchemaSlug: slug rename failed; see the log.');
			return;
		}

		$output->info(
			'RenameLoyaltyAccountSchemaSlug: schema ' . $old[0] . ' renamed `'
			. self::OLD_SLUG . '` -> `' . self::NEW_SLUG . '`; its objects stay attached.'
		);
	}//end run()

	/**
	 * Ids of this application's schemas carrying the given slug.
	 *
	 * @param string $slug The schema slug to look for.
	 *
	 * @return array<int, mixed>|null The ids, or null when the table cannot be read.
	 */
	private function schemaIds(string $slug): ?array {
		try {
			$rows = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_schemas` WHERE slug = ? AND application = ?',
				[$slug, self::APPLICATION]
			)->fetchAll(\PDO::FETCH_COLUMN);

			return array_values((array)$rows);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameLoyaltyAccountSchemaSlug: could not read the schema table; skipping.',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end schemaIds()
}//end class
