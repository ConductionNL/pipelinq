<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test/analysis stub for OpenRegister's AppHost StoreSharing (ADR-080).
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
 * Stub of OpenRegister's StoreSharing: shareable types and the publish opt-in.
 */
final class StoreSharing {
	/**
	 * Constructor.
	 *
	 * @param array<int, string> $types         Shareable configuration type ids.
	 * @param array<int, string> $publishFields Remote properties a publish may send next to the slug.
	 * @param array<int, string> $publishGroups Nextcloud groups whose members may publish.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $types = [],
		public readonly array $publishFields = [],
		public readonly array $publishGroups = [],
	) {
	}//end __construct()
}//end class
