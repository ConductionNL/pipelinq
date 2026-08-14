<?php

/**
 * Test stub for OpenRegister's WebhookService.
 *
 * Mirrors the dispatchEvent() surface the pipelinq ledger service consumes;
 * OpenRegister is not a test-time dependency. Resolved via the
 * `OCA\OpenRegister\ => tests/Stubs/` autoload-dev mapping.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\EventDispatcher\Event;

/**
 * Minimal WebhookService stub.
 */
class WebhookService {
	/**
	 * Dispatch a CloudEvent to registered webhook consumers.
	 *
	 * @param Event $_event The originating event.
	 * @param string $eventName The webhook event name.
	 * @param array<string, mixed> $payload The CloudEvent payload.
	 *
	 * @return void
	 */
	public function dispatchEvent(Event $_event, string $eventName, array $payload): void {
	}//end dispatchEvent()
}//end class
