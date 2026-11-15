<?php

/**
 * Test stub for OpenRegister's LinkTarget.
 *
 * Mirrors the value object EmailLinkService::linkEmail() takes as its target;
 * OpenRegister is not a test-time dependency.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal LinkTarget stub: the object (uuid, register, schema) a link points at.
 */
final class LinkTarget {

	/**
	 * Schema id; 0 when none.
	 *
	 * @var integer
	 */
	private int $schemaId = 0;

	/**
	 * Constructor.
	 *
	 * @param string $objectUuid The object's uuid.
	 * @param int    $registerId The object's register id.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly int $registerId,
	) {
	}//end __construct()

	/**
	 * A copy in the given schema.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return self The copy.
	 */
	public function inSchema(int $schemaId): self {
		$copy           = clone $this;
		$copy->schemaId = $schemaId;
		return $copy;
	}//end inSchema()

	/**
	 * The schema id.
	 *
	 * @return int The schema id.
	 */
	public function schemaId(): int {
		return $this->schemaId;
	}//end schemaId()
}//end class
