<?php

/**
 * Pipelinq ContainerWebhookService.
 *
 * OpenRegister's WebhookService from the container, or null.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

/**
 * Fetches OpenRegister's WebhookService from the container; a failure is null.
 * The host class provides a ContainerInterface `$container` property.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ContainerWebhookService {

	/**
	 * Lazily resolve OpenRegister's WebhookService, or null when OR is absent.
	 *
	 * @return object|null The WebhookService, or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function resolveWebhookService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\WebhookService');
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveWebhookService()
}//end trait
