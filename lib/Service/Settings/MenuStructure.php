<?php

/**
 * Which structure the app shows: the simple one or the full one.
 *
 * Pipelinq builds two shapes of itself from one manifest. `simple` is the
 * default: nine menu entries under four captions, the work of a contact
 * centre's day. `full` is the navigation as it was before the profile existed.
 * An administrator picks one on the admin settings page, and the page
 * controller hands the choice to the frontend as initial state.
 *
 * Sales, Marketing, Point of sale, Products, Contracts and Loyalty are
 * modules. They stay out of the simple menu until an administrator names them
 * in `menu_modules`. Off or on, every page of a module opens from the Modules
 * page, and every page keeps its own permission checks.
 *
 * Nothing is removed in either structure. Both are built from the same
 * manifest, so every page keeps its route and every deep link keeps working.
 *
 * The frontend half is `src/utils/structureProfile.js` and
 * `src/utils/menuModules.js`. `MenuStructureTest` fails when the two sides
 * spell a key, a word or a module differently.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Settings;

/**
 * The structure settings: their keys, their values and how a stored value reads.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md
 */
class MenuStructure {

	/**
	 * The appconfig key, and the initial-state key the frontend reads.
	 *
	 * @var string
	 */
	public const KEY = 'menu_structure';

	/**
	 * The default structure.
	 *
	 * @var string
	 */
	public const SIMPLE = 'simple';

	/**
	 * The structure as it was before the profile existed.
	 *
	 * @var string
	 */
	public const FULL = 'full';

	/**
	 * The appconfig key that lists the modules shown in the simple menu.
	 *
	 * @var string
	 */
	public const MODULES_KEY = 'menu_modules';

	/**
	 * The modules an administrator can switch into the simple menu.
	 *
	 * The same keys, in the same order, as `modules` in
	 * `src/menu-layout.simple.json`.
	 *
	 * @var list<string>
	 */
	public const MODULES = [
		'sales',
		'marketing',
		'pos',
		'products',
		'contracts',
		'loyalty',
	];

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
	 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-101
	 */
	public function normalise(string $stored): string {
		if (strtolower(trim($stored)) === self::FULL) {
			return self::FULL;
		}

		return self::SIMPLE;
	}//end normalise()

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
	 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-104
	 */
	public function normaliseModules(string $stored): string {
		$wanted = array_map(
			static fn (string $word): string => strtolower(trim($word)),
			explode(',', $stored)
		);

		return implode(',', array_values(array_filter(
			self::MODULES,
			static fn (string $module): bool => in_array(needle: $module, haystack: $wanted, strict: true)
		)));
	}//end normaliseModules()
}//end class
