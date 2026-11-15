<?php

/**
 * Pipelinq RenameTimeEntrySchemaSlug repair step.
 *
 * Moves the time-entry schema's slug from `timeEntry` to `billingTimeEntry`
 * IN PLACE, before InitializeSettings imports the register.
 *
 * WHY IT MOVES AT ALL. A schema slug is global per organisation, and three apps
 * declared a `timeEntry`: humaniq's HR booking, planninq's project booking, and
 * this app's billing/WIP record. `SchemaMapper::find()` matches `LOWER(slug)`,
 * so whichever row it reached first answered for all three. humaniq is the
 * agreed owner of the hours; this app's record is the BILLING side of one —
 * client, lead, billing category, approval, WIP sync, invoice batch — and keeps
 * a `timeEntry` reference to the humaniq booking it bills.
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
 * Idempotent: a no-op once the slug is already namespaced, and a no-op on an install that
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

use OCA\Pipelinq\Repair\Support\InPlaceSchemaSlugRename;
use OCP\Migration\IRepairStep;

/**
 * Renames the time-entry schema slug in place, ahead of the register import.
 *
 * @spec exclude No canonical spec covers the fleet-wide slug collision
 *  migration. Pointing this at an existing spec would report conformance to a
 *  requirement that says nothing about it.
 */
class RenameTimeEntrySchemaSlug extends InPlaceSchemaSlugRename implements IRepairStep {
	/**
	 * The slug this step migrates away from.
	 *
	 * @var string
	 */
	protected const OLD_SLUG = 'timeEntry';

	/**
	 * The namespaced slug the register fragment now declares.
	 *
	 * @var string
	 */
	protected const NEW_SLUG = 'billingTimeEntry';

	/**
	 * The output when no schema carries the old slug.
	 *
	 * @var string
	 */
	protected const ABSENT_NOTICE = 'RenameTimeEntrySchemaSlug: no time-entry schema on this install; nothing to do.';

	/**
	 * The log line when the old slug is carried by more than one schema.
	 *
	 * @var string
	 */
	protected const DUPLICATE_NOTICE = 'RenameTimeEntrySchemaSlug: duplicate time-entry slugs; refusing to guess.';

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the fleet-wide slug collision
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function getName(): string {
		return 'Namespace the pipelinq time-entry schema slug';
	}//end getName()
}//end class
