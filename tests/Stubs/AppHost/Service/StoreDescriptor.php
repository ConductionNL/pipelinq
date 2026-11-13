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
	/** @var string */
	public readonly string $appId;

	/** @var string */
	public readonly string $schema;

	/** @var string */
	public readonly string $defaultRegister;

	/** @var array<string, string> */
	public readonly array $cardFields;

	/** @var array<int, string> */
	public readonly array $types;

	/** @var array<int, string> */
	public readonly array $publishFields;

	/** @var array<int, string> */
	public readonly array $publishGroups;

	/**
	 * Constructor.
	 *
	 * Mirrors OpenRegister: built from a listing part and a sharing part, read
	 * through the same flat readonly properties as before.
	 *
	 * @param StoreListing $listing Registry connection app, remote schema and register, card field map.
	 * @param StoreSharing $sharing Shareable types and the publish opt-in.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly StoreListing $listing,
		public readonly StoreSharing $sharing = new StoreSharing(),
	) {
		$this->appId           = $listing->appId;
		$this->schema          = $listing->schema;
		$this->defaultRegister = $listing->defaultRegister;
		$this->cardFields      = $listing->cardFields;
		$this->types           = $sharing->types;
		$this->publishFields   = $sharing->publishFields;
		$this->publishGroups   = $sharing->publishGroups;
	}//end __construct()

	/**
	 * Whether this descriptor opted in to publishing.
	 *
	 * @return bool
	 */
	public function isPublishable(): bool {
		return $this->publishFields !== [] && $this->publishGroups !== [];
	}//end isPublishable()
}//end class
