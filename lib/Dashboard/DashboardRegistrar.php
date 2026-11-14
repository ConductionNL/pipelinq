<?php

/**
 * Pipelinq Dashboard Registrar
 *
 * @category  Dashboard
 * @package   OCA\Pipelinq\Dashboard
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Dashboard;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers Pipelinq's dashboard widgets.
 *
 * @category Dashboard
 * @package  OCA\Pipelinq\Dashboard
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://pipelinq.nl
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */
class DashboardRegistrar {
	/**
	 * Register the dashboard widgets.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Composition root wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(DealsOverviewWidget::class);
		$context->registerDashboardWidget(MyLeadsWidget::class);
		$context->registerDashboardWidget(RecentActivitiesWidget::class);
		$context->registerDashboardWidget(FindClientWidget::class);
		$context->registerDashboardWidget(StartRequestWidget::class);
		$context->registerDashboardWidget(CreateLeadWidget::class);
	}//end register()
}//end class
