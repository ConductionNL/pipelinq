<?php

/**
 * Pipelinq DeferredHandlers
 *
 * @category  Listener
 * @package   OCA\Pipelinq\Listener
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

namespace OCA\Pipelinq\Listener;

/**
 * The listeners whose work runs deferred, by handler key: the allow-list
 * {@see \OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob} resolves a
 * persisted handler key against.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://pipelinq.nl
 *
 * @spec openspec/specs/event-listener-work-placement/spec.md#requirement-deferred-post-event-work-runs-in-one-actor-forwarded-job
 */
final class DeferredHandlers {

	/**
	 * Handler key -> listener class.
	 *
	 * @var array<string, class-string<DeferredObjectWork>>
	 */
	public const MAP = [
		AvailabilityCacheInvalidationListener::HANDLER_KEY => AvailabilityCacheInvalidationListener::class,
		DealCreatedListener::HANDLER_KEY => DealCreatedListener::class,
		DealUpdatedListener::HANDLER_KEY => DealUpdatedListener::class,
		EventCancelledListener::HANDLER_KEY => EventCancelledListener::class,
		ExpenseApprovalListener::HANDLER_KEY => ExpenseApprovalListener::class,
		SlaObjectCreatedListener::HANDLER_KEY => SlaObjectCreatedListener::class,
		SlaObjectUpdatedListener::HANDLER_KEY => SlaObjectUpdatedListener::class,
		SurveyDispatchListener::HANDLER_KEY => SurveyDispatchListener::class,
	];

}//end class
