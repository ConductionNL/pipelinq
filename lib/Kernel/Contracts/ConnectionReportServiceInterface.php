<?php

/**
 * Contract of ConnectionReportService
 *
 * The public surface of ConnectionReportService that other components call, so they depend
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
 * Contract of ConnectionReportService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConnectionReportServiceInterface {

	/**
	 * Report the outcome of the CTI Test connection for the `cti` row.
	 *
	 * The check resolves the chosen platform's adapter and makes no call to the
	 * platform, so a passing check says exactly that and no more.
	 *
	 * @param array<string, mixed> $outcome `{ok, platform, message}` from CtiService::testConnection().
	 *
	 * @return bool True when the report was dispatched.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-132-pipelinq-reports-what-its-own-checks-observe
	 */
	public function reportCtiCheck(array $outcome): bool;

	/**
	 * Report every social network's broker readiness for its `social-*` row.
	 *
	 * @param array<string, mixed> $readiness Network => `{state, reason}`, from SocialAdapterRegistry::readiness().
	 *
	 * @return array<int, string> The connection keys a report was dispatched for.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-132-pipelinq-reports-what-its-own-checks-observe
	 */
	public function reportSocialReadiness(array $readiness): array;
}//end interface
