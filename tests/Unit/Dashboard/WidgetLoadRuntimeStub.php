<?php

/**
 * Minimal stand-ins for the two Nextcloud runtime classes `OCP\Util::addScript()`
 * and `OCP\Util::addStyle()` reach, for the standalone (no-server) unit harness.
 *
 * Loaded by DashboardWidgetLoadTest only when no real `OC` class exists, so an
 * installed Nextcloud keeps its own classes. `OC::$server` stays null outside
 * that test, so any other `\OC::$server->get()` call fails as it did before.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Dashboard
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses,Squiz.Classes.ValidClassName.NotCamelCaps

/**
 * Stand-in for the server class whose static container `OCP\Server::get()` reads.
 */
class OC {
	/**
	 * The service container; null unless a test installs one.
	 *
	 * @var \Psr\Container\ContainerInterface|null
	 */
	public static $server = null;
}//end class

/**
 * Stand-in for the legacy helper `OCP\Util::addStyle()` delegates to.
 */
class OC_Util {
	/**
	 * The style paths added, in the `<app>/css/<file>` form the server uses.
	 *
	 * @var string[]
	 */
	public static $styles = [];

	/**
	 * Record a style the way the server does.
	 *
	 * @param string      $application The app id.
	 * @param string|null $file        The style file without extension.
	 * @param bool        $prepend     Whether to put it first.
	 *
	 * @return void
	 */
	public static function addStyle($application, $file = null, $prepend = false): void {
		$path = $application . '/css/' . $file;
		if ($prepend === true) {
			array_unshift(self::$styles, $path);
			return;
		}

		self::$styles[] = $path;
	}//end addStyle()
}//end class
