<?php

/**
 * Pipelinq RegisterSchemaFindAll.
 *
 * Lists the objects of one register and schema.
 *
 * @category Service
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Support;

/**
 * Runs the host's ObjectService findAll() over a register and schema plus
 * extra equality filters. The host class provides getObjectService().
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait RegisterSchemaFindAll {

	/**
	 * Find the objects of a register and schema; what the ObjectService throws is not caught.
	 *
	 * @param array{0: string, 1: string} $ids     The [register, schema] ids.
	 * @param array<string, mixed>        $filters Extra equality filters, after register and schema.
	 *
	 * @return mixed The ObjectService result.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function findAllIn(array $ids, array $filters = []): mixed {
		[$register, $schema] = $ids;
		return $this->getObjectService()->findAll(
			config: [
				'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
			]
		);
	}//end findAllIn()
}//end trait
