<?php

/**
 * Contract of MainRegisterReader
 *
 * The public surface of MainRegisterReader that other components call, so they depend
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
 * Contract of MainRegisterReader.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MainRegisterReaderInterface {

	/**
	 * Find a single main-register object by id as the signed-in user, under
	 * their OpenRegister RBAC and organisation, or null when they may not read it.
	 *
	 * @param string $schemaKey The schema slug.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function findAsCaller(string $schemaKey, string $id): ?array;
}//end interface
