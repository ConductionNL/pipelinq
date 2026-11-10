<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test/analysis stub for OpenRegister's AppHost StoreListing (ADR-080).
 *
 * Declaration-only: the real class wins whenever OpenRegister is installed.
 *
 * @category Test
 * @package  OCA\OpenRegister\AppHost\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

/**
 * Stub of OpenRegister's StoreListing: where a store reads from and how a card is built.
 */
final class StoreListing {
	/**
	 * Constructor.
	 *
	 * @param string                $appId           App whose IAppConfig holds the registry connection.
	 * @param string                $schema          Remote schema slug.
	 * @param string                $defaultRegister Remote register used when `registry_register` is empty.
	 * @param array<string, string> $cardFields      Card field => remote property.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $schema,
		public readonly string $defaultRegister,
		public readonly array $cardFields = [
			'slug' => 'slug',
			'title' => 'title',
			'description' => 'description',
			'category' => 'category',
			'version' => 'version',
		],
	) {
	}//end __construct()
}//end class
