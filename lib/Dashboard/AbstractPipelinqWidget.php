<?php

/**
 * Base of the pipelinq dashboard widgets.
 *
 * What every pipelinq widget shares: the widget icon, no header link, and
 * loading the shared webpack chunks before the widget's own entry script.
 *
 * @category Dashboard
 * @package  OCA\Pipelinq\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

namespace OCA\Pipelinq\Dashboard;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\Util;

/**
 * Shared icon, header URL and script loading of the pipelinq dashboard widgets.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-crm-dashboard-layout
 */
abstract class AbstractPipelinqWidget implements IWidget {
	/**
	 * The widget's own webpack entry, without the app-id prefix (e.g. `myLeadsWidget`).
	 *
	 * @return string The entry name
	 */
	abstract protected function scriptEntry(): string;

	/**
	 * Get the CSS class for the widget icon.
	 *
	 * @return string The icon CSS class
	 * @spec openspec/specs/dashboard/spec.md#requirement-crm-dashboard-layout
	 */
	public function getIconClass(): string {
		return 'icon-pipelinq-widget';
	}//end getIconClass()

	/**
	 * Get the URL for the widget header link.
	 *
	 * @return string|null The URL or null if none
	 * @spec openspec/specs/dashboard/spec.md#requirement-crm-dashboard-layout
	 */
	public function getUrl(): ?string {
		return null;
	}//end getUrl()

	/**
	 * Load the widget scripts and styles.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) — Nextcloud Util API is static by design
	 * @spec openspec/specs/dashboard/spec.md#requirement-crm-dashboard-layout
	 */
	public function load(): void {
		// Shared vendor chunks emitted by webpack splitChunks. Util::addScript
		// dedupes by (app, file), so the order across widgets settles on:
		// vendor → nc-vue → widget JS, which is the dependency order.
		Util::addScript(Application::APP_ID, Application::APP_ID . '-shared-vendor');
		Util::addScript(Application::APP_ID, Application::APP_ID . '-shared-nc-vue');
		Util::addScript(Application::APP_ID, Application::APP_ID . '-' . $this->scriptEntry());
		Util::addStyle(Application::APP_ID, 'dashboardWidgets');
	}//end load()
}//end class
