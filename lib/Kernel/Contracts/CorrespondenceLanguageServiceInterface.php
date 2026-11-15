<?php

/**
 * Contract of CorrespondenceLanguageService
 *
 * The public surface of CorrespondenceLanguageService that other components call, so they depend
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
 * Contract of CorrespondenceLanguageService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CorrespondenceLanguageServiceInterface {

	/**
	 * The language tags this instance can actually render.
	 *
	 * @return array<int, string> The available tags, sorted, always including
	 *   the floor.
	 *
	 * @spec openspec/specs/parties/spec.md#requirement-the-selectable-languages-are-the-ones-the-instance-can-render-req-pcl-002
	 */
	public function available(): array;

	/**
	 * The instance default language, or '' when it has none.
	 *
	 * @return string The tag.
	 *
	 * @spec openspec/specs/parties/spec.md#requirement-a-party-carries-the-language-it-asked-to-be-written-in-req-pcl-001
	 */
	public function instanceDefault(): string;

	/**
	 * The same answer, for a party read by id.
	 *
	 * @param string $partyId The party record's uuid.
	 *
	 * @return array<string, mixed> `status` plus the answer, or `error`.
	 *
	 * @spec openspec/specs/parties/spec.md#requirement-the-resolver-is-published-and-no-caller-reads-the-property-directly-req-pcl-005
	 */
	public function resolve(string $partyId): array;

	/**
	 * Set or clear a party's preference.
	 *
	 * @param string $partyId The party record's uuid.
	 * @param string $tag The BCP 47 tag, or '' to clear it.
	 *
	 * @return array<string, mixed> `status` plus the answer, or `error`.
	 *
	 * @spec openspec/specs/parties/spec.md#requirement-the-selectable-languages-are-the-ones-the-instance-can-render-req-pcl-002
	 */
	public function setPreference(string $partyId, string $tag): array;
}//end interface
