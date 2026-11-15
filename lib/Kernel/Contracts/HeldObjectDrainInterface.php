<?php

/**
 * Contract of HeldObjectDrain
 *
 * The public surface of HeldObjectDrain that other components call, so they depend
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
 * Contract of HeldObjectDrain.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HeldObjectDrainInterface {

	/**
	 * Drain the open objects of one held schema.
	 *
	 * @param string               $register    The register id or slug.
	 * @param string               $schema      The held schema id or slug.
	 * @param string               $keyProperty The property naming the form a held object came from.
	 * @param array<string, mixed> $openFilter  Property filters that select the still-open objects.
	 * @param bool                 $apply       True to submit and delete; false to report only.
	 *
	 * @return array<string, mixed> The report: `delivered`, `refused` and `unmapped` lists, and
	 *   `pending`, what is still held after this run.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
	 */
	public function drain(string $register, string $schema, string $keyProperty, array $openFilter, bool $apply): array;
}//end interface
