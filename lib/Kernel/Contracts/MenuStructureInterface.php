<?php

/**
 * Contract of MenuStructure
 *
 * The public surface of MenuStructure that other components call, so they depend
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
 * Contract of MenuStructure.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MenuStructureInterface {

	/**
	 * The appconfig key, and the initial-state key the frontend reads.
	 *
	 * @var string
	 */
	public const KEY = 'menu_structure';

	/**
	 * The appconfig key that lists the modules shown in the simple menu.
	 *
	 * @var string
	 */
	public const MODULES_KEY = 'menu_modules';

	/**
	 * The structure a stored value stands for.
	 *
	 * Only the word `full` selects the full structure. An unset key, an empty
	 * string and a typing mistake all read as `simple`, because the default
	 * has to be the answer whenever the setting does not clearly say otherwise.
	 *
	 * @param string $stored The stored appconfig value.
	 *
	 * @return string `simple` or `full`.
	 *
	 * @spec openspec/specs/navigation-ia/spec.md#REQ-NIA-101
	 */
	public function normalise(string $stored): string;

	/**
	 * The modules a stored value switches on, as the list the frontend reads.
	 *
	 * The setting is a comma separated list. A word that is not a module is
	 * dropped, so a typing mistake switches nothing on, and the result keeps
	 * the order of {@see self::MODULES} whatever order was stored.
	 *
	 * @param string $stored The stored appconfig value.
	 *
	 * @return string The known module keys, comma separated. Empty for none.
	 *
	 * @spec openspec/specs/navigation-ia/spec.md#REQ-NIA-104
	 */
	public function normaliseModules(string $stored): string;
}//end interface
