<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test/analysis stub for OpenRegister's AppHost StoreDescriptor (ADR-080).
 *
 * Declaration-only: the real class wins whenever OpenRegister is installed.
 * Pipelinq's StoreController type-hints it by COMPOSITION rather than extending
 * a cross-app base, so one stub entry is all static analysis needs.
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
 * Stub of OpenRegister's StoreDescriptor.
 */
final class StoreDescriptor {
	/**
	 * Constructor.
	 *
	 * @param string $appId App whose IAppConfig holds the registry connection.
	 * @param string $schema Remote schema slug exposed by the registry.
	 * @param string $defaultRegister Remote register segment used when unset.
	 * @param array<string, string> $cardFields Card field name => remote object property.
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
