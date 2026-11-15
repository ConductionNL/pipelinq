<?php

/**
 * Contract of SearchConsoleImportService
 *
 * The public surface of SearchConsoleImportService that other components call, so they depend
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

/**
 * Contract of SearchConsoleImportService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SearchConsoleImportServiceInterface {

	/**
	 * Whether a usable service account key is on file.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function hasKey(): bool;

	/**
	 * Import the last `$days` days for every configured property.
	 *
	 * @param int $days How many days back from today, inclusive of today.
	 *
	 * @return array{properties: int, rows: int, errors: array<string, string>} Counts and per-property failures.
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function importRecent(int $days = 3): array;

	/**
	 * When the last import finished, ISO 8601, or empty.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function lastImportAt(): string;

	/**
	 * The configured properties, trimmed and deduplicated.
	 *
	 * @return array<int, string> Site URLs such as `https://example.org/` or `sc-domain:example.org`.
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function properties(): array;

	/**
	 * The service account email the admin must add on the property.
	 *
	 * @return string The email, empty without a key.
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function serviceAccountEmail(): string;
}//end interface
