<?php

/**
 * Pipelinq Notification Registrar
 *
 * @category  Notification
 * @package   OCA\Pipelinq\Notification
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

namespace OCA\Pipelinq\Notification;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers Pipelinq's notifier.
 *
 * @category Notification
 * @package  OCA\Pipelinq\Notification
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://pipelinq.nl
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */
class NotificationRegistrar {
	/**
	 * Register the notifier. A <notification> element in info.xml is never
	 * read by core (and app-info.xsd rejects it); the IBootstrap registration
	 * is the canonical path.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Composition root wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(Notifier::class);
	}//end register()
}//end class
