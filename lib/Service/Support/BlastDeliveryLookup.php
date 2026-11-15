<?php

/**
 * Pipelinq BlastDeliveryLookup.
 *
 * One BlastDelivery row by id, or null.
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

use OCA\Pipelinq\Util\ObjectServiceTarget;
use Throwable;

/**
 * Reads a BlastDelivery from the host's register and BlastDelivery schema;
 * an unconfigured slug, no ObjectService or a miss is null, and a failed read
 * is logged at info level with the given message. The host class provides a
 * LoggerInterface `$logger` property and getRegisterSlug(),
 * getBlastDeliverySchemaSlug(), getObjectService() and toArray() steps.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait BlastDeliveryLookup {
	use ObjectServiceTarget;
	use LoggedFindAsArray;

	/**
	 * Load one BlastDelivery row by id.
	 *
	 * @param string $id       BlastDelivery UUID or slug.
	 * @param string $notFound Info line logged when the read fails.
	 *
	 * @return array<string, mixed>|null Delivery row or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function findBlastDelivery(string $id, string $notFound): ?array {
		$target = $this->objectTarget(register: $this->getRegisterSlug(), schema: $this->getBlastDeliverySchemaSlug());
		if ($target === null) {
			return null;
		}

		[$register, $schema, $objectService] = $target;

		return $this->loggedFindAsArray(
			find: fn (): mixed => $objectService->find(id: $id, register: $register, schema: $schema),
			onFailure: fn (Throwable $e) => $this->logger->info($notFound, ['id' => $id, 'exception' => $e->getMessage()])
		);
	}//end findBlastDelivery()
}//end trait
