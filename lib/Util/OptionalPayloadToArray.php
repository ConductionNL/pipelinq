<?php

/**
 * Pipelinq OptionalPayloadToArray.
 *
 * Turns an OpenRegister read result into a plain array, or null without a payload.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

/**
 * Like {@see PayloadToArray}, but a value without a payload gives null.
 */
trait OptionalPayloadToArray {
	use EntityPayload;

	/**
	 * The value as an array.
	 *
	 * @param mixed $object The read result.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
	 */
	private function toArray(mixed $object): ?array {
		return $this->entityPayload(value: $object);
	}//end toArray()
}//end trait
