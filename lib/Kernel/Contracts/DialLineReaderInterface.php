<?php

/**
 * Contract of DialLineReader
 *
 * The public surface of DialLineReader that other components call, so they depend
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
 * Contract of DialLineReader.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DialLineReaderInterface {

	/**
	 * The agent's dial line: whether a call can be placed, the extension, and whether it is busy.
	 *
	 * @param string $userId The agent's Nextcloud uid.
	 *
	 * @return array{enabled: bool, extension: string, busy: bool}
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-click-to-dial-outbound-req-cti-003
	 */
	public function forAgent(string $userId): array;
}//end interface
