<?php

/**
 * Test fixture for Nextcloud Forms' FormSubmittedEvent.
 *
 * Mirrors the published class (nextcloud/forms lib/Events/FormSubmittedEvent.php):
 * the submission is private, and the event exposes form and submission only
 * through getWebhookSerializable(). Forms is not a test-time dependency, so
 * FormsSubmissionListenerTest requires this file before using it.
 *
 * @category Test
 * @package  OCA\Forms\Events
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

namespace OCA\Forms\Events;

use OCP\EventDispatcher\Event;

/**
 * FormSubmittedEvent fixture, same public surface as Forms.
 */
class FormSubmittedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $form       The form as Form::read() gives it.
	 * @param array<string, mixed> $submission The submission as Submission::read() gives it.
	 */
	public function __construct(
		private array $form,
		private array $submission,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Webhook-serializable representation of this event.
	 *
	 * @return array<string, mixed>
	 */
	public function getWebhookSerializable(): array {
		return [
			'form' => $this->form,
			'submission' => $this->submission,
		];
	}//end getWebhookSerializable()
}//end class
