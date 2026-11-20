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

use OCA\Pipelinq\Repair\Support\InPlaceSchemaSlugRename;
use OCP\Migration\IRepairStep;

/**
 * Renames the loyalty-account schema slug in place, ahead of the register import.
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
 *  migration. Pointing this at an existing spec would report conformance to a
 *  requirement that says nothing about it.
 */
class RenameLoyaltyAccountSchemaSlug extends InPlaceSchemaSlugRename implements IRepairStep {
	/**
	 * The Dutch slug this step migrates away from.
	 *
	 * @var string
	 */
	protected const OLD_SLUG = 'klantLoyaltyAccount';

	/**
	 * The English slug the register fragment now declares.
	 *
	 * @var string
	 */
	protected const NEW_SLUG = 'customerLoyaltyAccount';

	/**
	 * The output when no schema carries the old slug.
	 *
	 * @var string
	 */
	protected const ABSENT_NOTICE = 'RenameLoyaltyAccountSchemaSlug: no Dutch loyalty-account schema on this install; nothing to do.';

	/**
	 * The log line when the old slug is carried by more than one schema.
	 *
	 * @var string
	 */
	protected const DUPLICATE_NOTICE = 'RenameLoyaltyAccountSchemaSlug: duplicate Dutch slugs; refusing to guess.';

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
}//end class
