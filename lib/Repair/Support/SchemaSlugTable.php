<?php

/**
 * Pipelinq SchemaSlugTable.
 *
 * The schema-slug reads and writes the slug-rename repair steps share.
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

/**
 * Looks up and renames this application's schema rows by slug.
 *
 * Built by the repair step that owns the connection; every failure is left to
 * that step, which reports it in its own words.
 *
 * @spec exclude No canonical spec covers the schema slug migrations. Pointing this
 *  at an existing spec would report conformance to a requirement that says nothing
 *  about it.
 */
final class SchemaSlugTable {
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
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Ids of this application's schemas carrying the given slug.
	 *
	 * @param string $slug The schema slug to look for.
	 *
	 * @return array<int, mixed> The ids.
	 *
	 * @throws Exception When the schema table cannot be read.
	 *
	 * @spec exclude No canonical spec covers the schema slug migrations.
	 */
	public function idsWithSlug(string $slug): array {
		$rows = $this->db->executeQuery(
			'SELECT id FROM `*PREFIX*openregister_schemas` WHERE slug = ? AND application = ?',
			[$slug, self::APPLICATION]
		)->fetchAll(\PDO::FETCH_COLUMN);

		return array_values((array)$rows);
	}//end idsWithSlug()

	/**
	 * Give one schema row a new slug, in place, so its id and objects stay.
	 *
	 * @param mixed  $id   The schema id.
	 * @param string $slug The new slug.
	 *
	 * @return void
	 *
	 * @throws Exception When the row cannot be written.
	 *
	 * @spec exclude No canonical spec covers the schema slug migrations.
	 */
	public function renameSchema(mixed $id, string $slug): void {
		$this->db->executeStatement(
			'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?',
			[$slug, $id]
		);
	}//end renameSchema()
}//end class
