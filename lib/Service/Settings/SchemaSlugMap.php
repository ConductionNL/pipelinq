<?php

/**
 * Schema slug to app-config key map.
 *
 * OpenRegister's import writes one app-config entry per schema, named
 * `<slug>_schema`, holding that schema's numeric id. Every pipelinq service
 * that writes an object reads its schema id from one of those keys.
 *
 * The mapping is mechanical, and it is written out anyway. An explicit
 * constant lets phpstan PROVE that every slug the store allowlist names has a
 * config key: `SLUG_TO_CONFIG_KEY[$slug]` on a slug absent from this map is a
 * static error rather than a runtime empty string, and an empty schema id
 * writes an object into nothing.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Settings;

/**
 * Maps a schema slug to the app-config key holding its schema id.
 *
 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
 */
final class SchemaSlugMap {
	/**
	 * Schema slug to app-config key.
	 *
	 * Holds the CONFIGURATION schemas only. A record schema has a config key
	 * too, and deliberately does not appear here: this map is what the store's
	 * allowlist is checked against, so an absent slug cannot be written by an
	 * install even if someone widens the allowlist without thinking.
	 *
	 * @var array<string, string>
	 */
	public const SLUG_TO_CONFIG_KEY = [
		'pipeline' => 'pipeline_schema',
		'queue' => 'queue_schema',
		'skill' => 'skill_schema',
		'product' => 'product_schema',
		'productCategory' => 'productCategory_schema',
		'billingCategory' => 'billingCategory_schema',
		'posRole' => 'posRole_schema',
		'posTenderType' => 'posTenderType_schema',
		'receiptTemplate' => 'receiptTemplate_schema',
		'refundReason' => 'refundReason_schema',
		'loyaltyProgramme' => 'loyaltyProgramme_schema',
		'pointsRule' => 'pointsRule_schema',
		'berichtenboxTemplate' => 'berichtenboxTemplate_schema',
	];

	/**
	 * Read the app-config key for a slug, or null when the slug is unmapped.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return string|null The config key, or null when the slug is not configuration.
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	public static function configKeyFor(string $slug): ?string {
		return (self::SLUG_TO_CONFIG_KEY[$slug] ?? null);
	}//end configKeyFor()
}//end class
