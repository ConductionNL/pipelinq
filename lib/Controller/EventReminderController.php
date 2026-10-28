<?php

/**
 * Pipelinq EventReminderController.
 *
 * POST /api/campaign-events/{id}/reminder: send an event's reminder to
 * everyone with a confirmed place (PqEvenement: "Herinnering versturen").
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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
 *
 * @spec openspec/changes/event-reminder-and-check-in/specs/campaign-events/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Event\EventReminderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Sends an event's reminder.
 *
 * @spec openspec/changes/event-reminder-and-check-in/specs/campaign-events/spec.md
 */
class EventReminderController extends Controller
{


    /**
     * Constructor.
     *
     * @param IRequest             $request     The request.
     * @param EventReminderService $reminders   Sends the reminder.
     * @param IUserSession         $userSession The acting user.
     */
    public function __construct(
        IRequest $request,
        private readonly EventReminderService $reminders,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: 'pipelinq', request: $request);

    }//end __construct()


    /**
     * Send the reminder of this event.
     *
     * The event is read and stamped as the caller before any mail goes out:
     * a caller who may not read it gets 404, one who may not change it 403.
     *
     * @param string $id The event id.
     *
     * @return JSONResponse `{sent, skipped}`, or an error.
     *
     * @spec openspec/changes/event-reminder-and-check-in/specs/campaign-events/spec.md
     */
    #[NoAdminRequired]
    public function send(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new JSONResponse($this->reminders->send(eventId: $id));
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'event_not_found') === true) {
                return new JSONResponse(['message' => 'Event not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['message' => 'You may not send this event\'s reminder.'], Http::STATUS_FORBIDDEN);
        }

    }//end send()


}//end class
