<?php

/**
 * Contract of pipelinq's portal contribution
 *
 * The part of pipelinq's contribution to the shared portal that other
 * components read, so they depend on this contract instead of the
 * duck-typed provider portaliq discovers.
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
 * Contract of pipelinq's portal contribution.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalContributionInterface {

	/**
	 * The rule key pipelinq's answer notice carries (QuestionAnsweredNotice).
	 *
	 * @var string
	 */
	public const RULE_QUESTION_ANSWERED = 'pipelinq.question.answered';

	/**
	 * Build the declarative portal manifest for one resolved subject.
	 *
	 * @param array<string, mixed> $subject The resolved portal subject.
	 *
	 * @return array<string, mixed>|null The manifest, or null when not contributing.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function getContribution(array $subject): ?array;
}//end interface
