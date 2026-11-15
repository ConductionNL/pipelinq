<?php

/**
 * Contract of UplListImporter
 *
 * The public surface of UplListImporter that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of UplListImporter.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface UplListImporterInterface {

	/**
	 * The URL the list is downloaded from: the app setting `upl_list_url`, else the national source.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/product-service-catalogue-public/tasks.md
	 */
	public function sourceUrl(): string;

	/**
	 * Download the list.
	 *
	 * @param string $url Where from.
	 *
	 * @return string The CSV.
	 *
	 * @throws RuntimeException When the download fails or returns nothing.
	 *
	 * @spec openspec/changes/product-service-catalogue-public/tasks.md
	 */
	public function download(string $url): string;

	/**
	 * Import a list into the lookup.
	 *
	 * @param string $csv The list as published.
	 *
	 * @return array{read: int, created: int, updated: int, unchanged: int, failed: int} What the run did.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 * @throws \InvalidArgumentException When the list has no name or URI column.
	 *
	 * @spec openspec/changes/product-service-catalogue-public/tasks.md
	 */
	public function import(string $csv): array;
}//end interface
