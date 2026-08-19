<?php

/**
 * Pipelinq BookingAdminController.
 *
 * Thin controller for booking lifecycle actions invoked from the staff admin
 * UI (member 11 of the appointment-booking chain). All business logic, status
 * transition checks and side-effects (availability cache invalidation,
 * confirmation/reminder emails, calendar push, no-show fee charging) live in
 * BookingService and AppointmentEmailService. Every endpoint requires an
 * authenticated user; object-level scoping is enforced inside the service so
 * a booking from another tenant resolves to 404.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AppointmentEmailService;
use OCA\Pipelinq\Service\BookingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin-facing booking lifecycle endpoints (REQ-APT-015).
 *
 * Every method requires an authenticated user (#[NoAdminRequired]) and runs
 * the action through {@see BookingService}, which performs the status-machine
 * guard, history append, availability cache bust and (where relevant) email /
 * calendar dispatch. Failures map to 404 / 422 / 500 — invalid transitions
 * return 422 with the service's exception message so the UI can surface the
 * reason ("Booking already completed" etc.).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates the booking
 *  service, the optional email seam, the user session and i18n.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
class BookingAdminController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                $request      The HTTP request.
     * @param BookingService          $bookings     The booking lifecycle service.
     * @param AppointmentEmailService $emailService The email service (reminder seam).
     * @param IUserSession            $userSession  The current user session.
     * @param IL10N                   $l10n         Localization service.
     * @param LoggerInterface         $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private BookingService $bookings,
        private AppointmentEmailService $emailService,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Reschedule a booking to a new start time.
     *
     * Creates a fresh confirmed booking linked back via `previousBookingId`
     * and transitions the original to `rescheduled`. Returns the new
     * booking UUID so the caller can navigate to it.
     *
     * @param string $id The original booking UUID.
     *
     * @return JSONResponse The new booking UUID, or an error.
     *
     * @spec openspec/specs/appointment-booking/spec.md
     */
    #[NoAdminRequired]
    public function reschedule(string $id): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorised();
        }

        $newStartAt = (string) $this->request->getParam('newStartAt', '');
        if ($newStartAt === '') {
            return $this->error(message: $this->l10n->t('newStartAt is required'), status: Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        return $this->run(
            label: 'reschedule',
            handler: function () use ($id, $newStartAt): array {
                $newId = $this->bookings->rescheduleBooking(bookingId: $id, newStartAt: $newStartAt);
                return ['bookingId' => $newId];
            }
        );
    }//end reschedule()

    /**
     * Cancel a booking.
     *
     * Staff cancellations always skip the cancellation charge (REQ-APT-009
     * scenario 3); the service derives the policy from the linked Service.
     *
     * @param string $id The booking UUID.
     *
     * @return JSONResponse The result, or an error.
     */
    #[NoAdminRequired]
    public function cancel(string $id): JSONResponse
    {
        $uid = $this->requireUser();
        if ($uid === null) {
            return $this->unauthorised();
        }

        $reason = (string) $this->request->getParam('reason', '');
        return $this->run(
            label: 'cancel',
            handler: function () use ($id, $reason, $uid): array {
                $this->bookings->cancelBooking(bookingId: $id, reason: $reason, cancelledBy: $uid);
                return ['cancelled' => true];
            }
        );
    }//end cancel()

    /**
     * Mark a booking as completed.
     *
     * @param string $id The booking UUID.
     *
     * @return JSONResponse The result, or an error.
     */
    #[NoAdminRequired]
    public function markCompleted(string $id): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorised();
        }

        return $this->run(
            label: 'markCompleted',
            handler: function () use ($id): array {
                $this->bookings->completeBooking(bookingId: $id);
                return ['completed' => true];
            }
        );
    }//end markCompleted()

    /**
     * Mark a booking as no-show.
     *
     * Triggers no-show fee charging via the configured payment provider
     * (member 08) when Service.noShowFee > 0 and increments the customer's
     * no-show count.
     *
     * @param string $id The booking UUID.
     *
     * @return JSONResponse The result, or an error.
     */
    #[NoAdminRequired]
    public function markNoShow(string $id): JSONResponse
    {
        $uid = $this->requireUser();
        if ($uid === null) {
            return $this->unauthorised();
        }

        return $this->run(
            label: 'markNoShow',
            handler: function () use ($id, $uid): array {
                $this->bookings->markNoShow(bookingId: $id, staffUserId: $uid);
                return ['markedNoShow' => true];
            }
        );
    }//end markNoShow()

    /**
     * Force-send a reminder email for a booking.
     *
     * Calls AppointmentEmailService::sendReminder which stamps
     * reminderSentAt — letting staff re-fire reminders if the cron-driven
     * dispatch was missed. Returns 503 when no email provider is wired
     * (graceful degradation, mirroring the BookingService confirmation seam).
     *
     * @param string $id The booking UUID.
     *
     * @return JSONResponse The result, or an error.
     */
    #[NoAdminRequired]
    public function sendReminder(string $id): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorised();
        }

        return $this->run(
            label: 'sendReminder',
            handler: function () use ($id): array {
                $sent = $this->emailService->sendReminder(bookingId: $id);
                return ['sent' => $sent];
            }
        );
    }//end sendReminder()

    /**
     * Manually confirm a booking whose deposit cleared out-of-band.
     *
     * Useful when the payment provider's webhook is unreliable or when
     * staff record a cash deposit at the counter. Transitions the booking
     * from pending-deposit to confirmed.
     *
     * @param string $id The booking UUID.
     *
     * @return JSONResponse The result, or an error.
     */
    #[NoAdminRequired]
    public function confirmDeposit(string $id): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorised();
        }

        $reason = (string) $this->request->getParam('reason', '');
        if ($reason === '') {
            $reason = 'Deposit confirmed by staff';
        }

        return $this->run(
            label: 'confirmDeposit',
            handler: function () use ($id, $reason): array {
                $this->bookings->confirmBooking(bookingId: $id, reason: $reason);
                return ['confirmed' => true];
            }
        );
    }//end confirmDeposit()

    /**
     * Resolve the acting user UID or return null when unauthenticated.
     *
     * The Nextcloud SecurityMiddleware enforces authentication by default,
     * but the controller adds defence-in-depth and lets us emit a clean 401
     * envelope with a localised message instead of the middleware HTML.
     *
     * @return string|null The acting user UID, or null when unauthenticated.
     */
    private function requireUser(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end requireUser()

    /**
     * Build a 401 envelope.
     *
     * @return JSONResponse The 401 response.
     */
    private function unauthorised(): JSONResponse
    {
        return $this->error(message: $this->l10n->t('Authentication required'), status: Http::STATUS_UNAUTHORIZED);
    }//end unauthorised()

    /**
     * Build a JSON error envelope with the given status.
     *
     * @param string $message The human-readable error.
     * @param int    $status  The HTTP status.
     *
     * @return JSONResponse The error response.
     */
    private function error(string $message, int $status): JSONResponse
    {
        return new JSONResponse(['error' => $message], $status);
    }//end error()

    /**
     * Run a handler with shared error mapping (404 / 422 / 500).
     *
     * @param string   $label   Short label for logger context.
     * @param callable $handler The handler producing the success payload.
     *
     * @return JSONResponse The response.
     */
    private function run(string $label, callable $handler): JSONResponse
    {
        try {
            return new JSONResponse($handler());
        } catch (InvalidArgumentException $e) {
            return $this->error(message: $e->getMessage(), status: Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            $this->logger->error('BookingAdminController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return $this->error(message: $this->l10n->t('An unexpected error occurred'), status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end run()
}//end class
