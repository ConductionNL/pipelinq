<?php

/**
 * Contract of ContactCaptureService
 *
 * The public surface of ContactCaptureService that other components call, so they depend
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
 * Contract of ContactCaptureService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactCaptureServiceInterface {

	/**
	 * The longest text read; anything beyond is not a signature.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 5000;

	/**
	 * Propose contact fields for a text.
	 *
	 * @param string      $text   The pasted text.
	 * @param string|null $userId The user the model is asked for, when hermiq answers.
	 *
	 * @return array{fields: array<string, string>, emails: list<string>, phones: list<string>, unassigned: list<string>, labelledBy: string}
	 *         The proposed fields, every email and phone found, the lines left for the person, and whether hermiq labelled any.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
	 */
	public function parse(string $text, ?string $userId = null): array;
}//end interface
