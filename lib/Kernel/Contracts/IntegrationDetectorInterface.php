<?php

/**
 * Contract of IntegrationDetector
 *
 * The public surface of IntegrationDetector that other components call, so they depend
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
 * Contract of IntegrationDetector.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntegrationDetectorInterface {

	/**
	 * Both detections, for the admin settings page.
	 *
	 * @return array{shillinq: array{installed: bool, url: string}, xwiki: array{available: bool, source: string}}
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function all(): array;

	/**
	 * Whether Shillinq is installed, and where it opens.
	 *
	 * @return array{installed: bool, url: string} `url` is '' when Shillinq is not installed.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function shillinq(): array;
}//end interface
