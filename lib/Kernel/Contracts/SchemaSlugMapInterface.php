<?php

/**
 * Contract of SchemaSlugMap
 *
 * The public surface of SchemaSlugMap that other components call, so they depend
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
 * Contract of SchemaSlugMap.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SchemaSlugMapInterface {

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
}//end interface
