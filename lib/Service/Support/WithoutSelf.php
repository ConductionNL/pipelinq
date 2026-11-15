<?php

/**
 * Pipelinq WithoutSelf.
 *
 * A payload without its @self block.
 *
 * @category Util
 * @package  OCA\Pipelinq\Service\Support
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

namespace OCA\Pipelinq\Service\Support;

/**
 * Removes the '@self' metadata key a read hands back, so the payload can be
 * saved.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait WithoutSelf {

	/**
	 * Strip OpenRegister metadata before re-saving an existing object.
	 *
	 * The OR engine reads `@self` for register/schema/id; including it on save
	 * is harmless but `register`/`schema`/`uuid` are passed explicitly so we
	 * remove the duplicate to keep payloads small.
	 *
	 * @param array<string, mixed> $payload Booking payload.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function stripSelf(array $payload): array {
		if (array_key_exists('@self', $payload) === true) {
			unset($payload['@self']);
		}

		return $payload;
	}//end stripSelf()
}//end trait
