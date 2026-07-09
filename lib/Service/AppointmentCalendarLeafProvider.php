<?php

/**
 * Pipelinq AppointmentCalendarLeafProvider.
 *
 * Bridges the appointment-booking surface (members 02 + 04) to the OpenRegister
 * `calendar` leaf (`integration-calendar` / `email-calendar-sync`). All calendar
 * I/O — read blocked times, push confirmed-booking VEVENTs, move on reschedule —
 * is delegated to the leaf via OR's CalendarEventService. Pipelinq adds NO
 * CalDAV client and NO `X-PIPELINQ-*` VEVENT properties (ADR-022 leaf-first).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/appointment-booking-10-calendar-sync/specs/appointment-booking/spec.md#req-apt-018
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Calendar-leaf bridge for the appointment-booking surface.
 *
 * Two responsibilities, both leaf-delegated:
 *
 *   1. `getBlockedTimes($resourceId, $date)` — pull staff calendar blocks
 *      (lunch, meetings, vacation) for a resource on a date, returned as
 *      `[{startTime: HH:MM, endTime: HH:MM}]`. AvailabilityService (member 02)
 *      calls this through the calendar-provider seam to merge into computed
 *      slot availability.
 *
 *   2. `pushBookingEvent($bookingId)` — create or move a VEVENT for a
 *      confirmed booking in the staff resource's calendar. Called by
 *      BookingService (member 04) from `confirmBooking` / `createBooking`
 *      (when initial status is confirmed) / `rescheduleBooking`.
 *
 * The leaf is OR's `CalendarEventService` (created by `integration-calendar`).
 * It is resolved through the DI container so the openregister app stays an
 * optional runtime dependency (ADR-015 facade). When the leaf is missing the
 * provider degrades gracefully — empty block list for reads, no-op for writes,
 * a single warning logged. Bookings still succeed; only the calendar mirror
 * is skipped.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Mirrors AvailabilityService /
 *  BookingService — leaf bridge orchestration is fundamentally orchestral.
 *
 * @spec openspec/changes/appointment-booking-10-calendar-sync/specs/appointment-booking/spec.md#req-apt-018
 */
class AppointmentCalendarLeafProvider
{

    /**
     * App-config key for the Booking schema id/slug.
     *
     * @var string
     */
    public const BOOKING_SCHEMA_KEY = 'booking_schema';

    /**
     * App-config key for the Resource schema id/slug.
     *
     * @var string
     */
    public const RESOURCE_SCHEMA_KEY = 'resource_schema';

    /**
     * App-config key for the Service schema id/slug.
     *
     * @var string
     */
    public const SERVICE_SCHEMA_KEY = 'service_schema';

    /**
     * App-config key for the customer-mirror schema id/slug.
     *
     * @var string
     */
    public const CUSTOMER_SCHEMA_KEY = 'contact_schema';

    /**
     * Optional leaf override for testing (the CalendarEventService double).
     *
     * @var object|null
     */
    private ?object $leafOverride = null;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container    The DI container (OR leaf lookup).
     * @param IAppConfig         $appConfig    The app configuration.
     * @param IUserManager       $userManager  For Resource.userId → email resolution.
     * @param IURLGenerator      $urlGenerator For the deep-link back to the Booking.
     * @param LoggerInterface    $logger       The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IUserManager $userManager,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Override the leaf with a test double.
     *
     * Production code wires the real OR CalendarEventService via the container.
     * Tests inject a stub exposing `getEventsForObject($uuid)` and
     * `createEvent(...)` without spinning up Nextcloud.
     *
     * @param object|null $leaf The override (or null to clear).
     *
     * @return void
     *
     * @spec openspec/changes/appointment-booking-10-calendar-sync/specs/appointment-booking/spec.md#req-apt-018
     */
    public function setLeaf(?object $leaf): void
    {
        $this->leafOverride = $leaf;
    }//end setLeaf()

    /**
     * Return calendar blocks for a resource on a date.
     *
     * Pulls the leaf-synced VEVENTs that overlap the local day window and
     * filters them down to `[{startTime: 'HH:MM', endTime: 'HH:MM'}]` in the
     * resource's day-of for the date. Resources WITHOUT a `calendarSyncId`
     * are skipped (return empty list); the AvailabilityService still works
     * off the in-register Booking blocks.
     *
     * Errors are absorbed: the leaf being unavailable MUST NOT prevent the
     * portal from offering slots — only the calendar-merged ingest is lost.
     *
     * @param string $resourceId Resource UUID/slug.
     * @param string $date       ISO date `YYYY-MM-DD`.
     *
     * @return array<int, array{startTime: string, endTime: string}>
     *
     * @spec openspec/changes/appointment-booking-10-calendar-sync/specs/appointment-booking/spec.md#req-apt-018
     */
    public function getBlockedTimes(string $resourceId, string $date): array
    {
        if ($resourceId === '' || $date === '') {
            return [];
        }

        $resource = $this->loadResource(resourceId: $resourceId);
        if ($resource === null) {
            return [];
        }

        $calendarSyncId = (string) ($resource['calendarSyncId'] ?? '');
        if ($calendarSyncId === '') {
            return [];
        }

        $leaf = $this->resolveLeaf();
        if ($leaf === null) {
            return [];
        }

        if (method_exists($leaf, 'getEventsForObject') === false) {
            return [];
        }

        try {
            // @phpstan-ignore-next-line dynamic leaf seam (OR CalendarEventService).
            $events = $leaf->getEventsForObject($calendarSyncId);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Pipelinq: calendar leaf getEventsForObject failed',
                ['resource' => $resourceId, 'date' => $date]
            );
            return [];
        }

        if (is_array($events) === false) {
            return [];
        }

        return $this->eventsToBlocks(events: $events, date: $date);
    }//end getBlockedTimes()

    /**
     * Create or move a VEVENT mirroring a confirmed Booking.
     *
     * Idempotency contract: the VEVENT's link key is the Booking UUID (the
     * leaf stores it via `X-OPENREGISTER-OBJECT`). When an event already
     * exists for the booking the original is unlinked first so the new event
     * effectively MOVES (not duplicates) on reschedule. Multi-resource
     * bookings push one VEVENT per staff resource (one calendar per user).
     *
     * @param string $bookingId Booking UUID.
     *
     * @return void
     *
     * @spec openspec/changes/appointment-booking-10-calendar-sync/specs/appointment-booking/spec.md#req-apt-018
     */
    public function pushBookingEvent(string $bookingId): void
    {
        if ($bookingId === '') {
            return;
        }

        $leaf = $this->resolveLeaf();
        if ($leaf === null) {
            return;
        }

        $booking = $this->loadBooking(bookingId: $bookingId);
        if ($booking === null) {
            return;
        }

        $status = (string) ($booking['status'] ?? '');
        if ($status !== 'confirmed') {
            return;
        }

        $service = $this->loadServiceQuiet(serviceId: (string) ($booking['serviceId'] ?? ''));
        $summary = $this->buildSummary(booking: $booking, service: $service);
        $body    = $this->buildDescription(booking: $booking, service: $service);

        $registerId = $this->registerNumeric();
        $schemaId   = $this->schemaNumeric(key: self::BOOKING_SCHEMA_KEY);

        // Reschedule contract: drop existing VEVENTs first so the move
        // semantics hold (the leaf's `unlinkEventsForObject` removes the
        // linkage; the next createEvent installs the fresh time + linkage).
        $this->dropExistingEvents(leaf: $leaf, bookingUuid: $bookingId);

        foreach ($this->resourcesFromBooking(booking: $booking) as $resourceId) {
            $resource = $this->loadResource(resourceId: $resourceId);
            if ($resource === null) {
                continue;
            }

            $attendees = $this->attendeesFor(resource: $resource);
            $payload   = [
                'summary'     => $summary,
                'dtstart'     => (string) ($booking['startAt'] ?? ''),
                'dtend'       => (string) ($booking['endAt'] ?? ''),
                'description' => $body,
                'attendees'   => $attendees,
            ];

            try {
                if (method_exists($leaf, 'createEvent') === false) {
                    $this->logger->warning('Pipelinq: calendar leaf missing createEvent method');
                    return;
                }

                // @phpstan-ignore-next-line dynamic leaf seam (OR CalendarEventService).
                $leaf->createEvent($registerId, $schemaId, $bookingId, $summary, $payload);
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Pipelinq: calendar leaf createEvent failed',
                    ['booking' => $bookingId, 'resource' => $resourceId]
                );
            }
        }//end foreach
    }//end pushBookingEvent()

    /**
     * Render the VEVENT SUMMARY: "<customer> – <service>".
     *
     * Falls back to "Pipelinq booking" when both are unknown so the leaf still
     * accepts the payload (a non-empty summary is required by RFC 5545).
     *
     * @param array<string, mixed>      $booking Booking entity.
     * @param array<string, mixed>|null $service Service entity (may be null).
     *
     * @return string
     */
    private function buildSummary(array $booking, ?array $service): string
    {
        $customer = $this->customerLabel(booking: $booking);
        $svcName  = '';
        if ($service !== null) {
            $svcName = (string) ($service['name'] ?? '');
        }

        if ($customer === '' && $svcName === '') {
            return 'Pipelinq booking';
        }

        if ($customer === '') {
            return $svcName;
        }

        if ($svcName === '') {
            return $customer;
        }

        return sprintf('%s – %s', $customer, $svcName);
    }//end buildSummary()

    /**
     * Build the VEVENT DESCRIPTION carrying the service blurb + deep-link.
     *
     * @param array<string, mixed>      $booking Booking entity.
     * @param array<string, mixed>|null $service Service entity (may be null).
     *
     * @return string
     */
    private function buildDescription(array $booking, ?array $service): string
    {
        $parts  = [];
        $detail = '';
        if ($service !== null) {
            $detail = (string) ($service['description'] ?? ($service['name'] ?? ''));
        }

        if ($detail !== '') {
            $parts[] = $detail;
        }

        $link = $this->deepLinkFor(booking: $booking);
        if ($link !== '') {
            $parts[] = sprintf('Pipelinq: %s', $link);
        }

        if ($parts === []) {
            return '';
        }

        return implode("\n\n", $parts);
    }//end buildDescription()

    /**
     * Render the deep-link back to the Booking detail page.
     *
     * Uses the URL generator for absolute URLs when a route exists; otherwise
     * falls back to the canonical pipelinq deep-link shape.
     *
     * @param array<string, mixed> $booking Booking entity.
     *
     * @return string
     */
    private function deepLinkFor(array $booking): string
    {
        $bookingId = $this->idOf(object: $booking);
        if ($bookingId === '') {
            return '';
        }

        try {
            $base = $this->urlGenerator->getAbsoluteURL(
                sprintf('/index.php/apps/pipelinq/booking/%s', rawurlencode($bookingId))
            );
        } catch (Throwable $e) {
            return sprintf('/apps/pipelinq/booking/%s', rawurlencode($bookingId));
        }

        return $base;
    }//end deepLinkFor()

    /**
     * Resolve the customer's display label from the Booking.
     *
     * Looks at the customer mirror (when configured) for a `name` /
     * `displayName` field, falling back to the Booking's `customerName`
     * snapshot, falling back to the customer id.
     *
     * @param array<string, mixed> $booking Booking entity.
     *
     * @return string
     */
    private function customerLabel(array $booking): string
    {
        $snapshot = (string) ($booking['customerName'] ?? '');
        if ($snapshot !== '') {
            return $snapshot;
        }

        $customerId = (string) ($booking['customerId'] ?? '');
        if ($customerId === '') {
            return '';
        }

        $register = $this->registerSlug();
        $schema   = $this->schemaSlug(key: self::CUSTOMER_SCHEMA_KEY);
        if ($register === '' || $schema === '') {
            return $customerId;
        }

        try {
            $found = $this->getObjectService()->find(
                id: $customerId,
                register: $register,
                schema: $schema
            );
        } catch (Throwable $e) {
            return $customerId;
        }

        $data = $this->toArray(object: $found);
        if ($data === null) {
            return $customerId;
        }

        $name = (string) ($data['name'] ?? '');
        if ($name === '') {
            $name = (string) ($data['displayName'] ?? '');
        }

        if ($name === '') {
            return $customerId;
        }

        return $name;
    }//end customerLabel()

    /**
     * Extract staff email from Resource.userId → Nextcloud user email.
     *
     * Resources without a Nextcloud-user mapping return an empty attendees
     * list — the VEVENT is still created on the leaf-configured calendar so
     * staff still see it in their normal tool.
     *
     * @param array<string, mixed> $resource Resource entity.
     *
     * @return array<int, string>
     */
    private function attendeesFor(array $resource): array
    {
        $userId = (string) ($resource['userId'] ?? '');
        if ($userId === '') {
            return [];
        }

        try {
            $user = $this->userManager->get($userId);
        } catch (Throwable $e) {
            return [];
        }

        if ($user === null) {
            return [];
        }

        $email = (string) $user->getEMailAddress();
        if ($email === '') {
            return [];
        }

        return [$email];
    }//end attendeesFor()

    /**
     * Unlink existing leaf events for this Booking UUID.
     *
     * Implements reschedule = move (not duplicate). Best-effort: a failure
     * here is logged and the new create still runs — the worst case is a
     * stale VEVENT remains alongside the new one, which is visible to staff
     * and can be cleaned up manually.
     *
     * @param object $leaf        The OR CalendarEventService (or stub).
     * @param string $bookingUuid Booking UUID.
     *
     * @return void
     */
    private function dropExistingEvents(object $leaf, string $bookingUuid): void
    {
        if (method_exists($leaf, 'unlinkEventsForObject') === false) {
            return;
        }

        try {
            // @phpstan-ignore-next-line dynamic leaf seam (OR CalendarEventService).
            $leaf->unlinkEventsForObject($bookingUuid);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Pipelinq: calendar leaf unlinkEventsForObject failed',
                ['booking' => $bookingUuid]
            );
        }
    }//end dropExistingEvents()

    /**
     * Collect distinct resource ids assigned to a Booking.
     *
     * @param array<string, mixed> $booking Booking entity.
     *
     * @return array<int, string>
     */
    private function resourcesFromBooking(array $booking): array
    {
        $assignments = ($booking['resourceAssignments'] ?? []);
        if (is_array($assignments) === false) {
            return [];
        }

        $ids = [];
        foreach ($assignments as $assignment) {
            if (is_array($assignment) === false) {
                continue;
            }

            $resourceId = (string) ($assignment['resourceId'] ?? '');
            if ($resourceId === '' || in_array($resourceId, $ids, true) === true) {
                continue;
            }

            $ids[] = $resourceId;
        }

        return $ids;
    }//end resourcesFromBooking()

    /**
     * Convert leaf VEVENT entries to `HH:MM` blocks for the given local date.
     *
     * Events that span a day boundary are clamped to the day window; events
     * that fall entirely outside the day are skipped. Output is unmerged —
     * overlap detection in AvailabilityService is O(n) and duplicate-safe.
     *
     * @param array<int, mixed> $events Leaf VEVENT entries.
     * @param string            $date   ISO date `YYYY-MM-DD`.
     *
     * @return array<int, array{startTime: string, endTime: string}>
     */
    private function eventsToBlocks(array $events, string $date): array
    {
        $dayStart = strtotime($date.'T00:00:00');
        $dayEnd   = strtotime($date.'T23:59:59');
        if ($dayStart === false || $dayEnd === false) {
            return [];
        }

        $blocks = [];
        foreach ($events as $event) {
            $block = $this->eventToBlock(event: $event, dayStart: $dayStart, dayEnd: $dayEnd);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }//end eventsToBlocks()

    /**
     * Project a single leaf event into an `{startTime, endTime}` block.
     *
     * Returns null when the event is malformed or falls entirely outside the
     * day window. Pulled out of `eventsToBlocks` to keep that method below
     * PHPMD's complexity threshold.
     *
     * @param mixed $event    The leaf VEVENT entry (array or jsonSerialize'able).
     * @param int   $dayStart Day-start unix timestamp.
     * @param int   $dayEnd   Day-end unix timestamp.
     *
     * @return array{startTime: string, endTime: string}|null
     */
    private function eventToBlock(mixed $event, int $dayStart, int $dayEnd): ?array
    {
        $data = $this->toArray(object: $event);
        if ($data === null) {
            return null;
        }

        $startIso = (string) ($data['dtstart'] ?? ($data['startAt'] ?? ''));
        $endIso   = (string) ($data['dtend'] ?? ($data['endAt'] ?? ''));
        if ($startIso === '' || $endIso === '') {
            return null;
        }

        $startTs = strtotime($startIso);
        $endTs   = strtotime($endIso);
        if ($startTs === false || $endTs === false || $endTs <= $startTs) {
            return null;
        }

        if ($endTs <= $dayStart || $startTs > $dayEnd) {
            return null;
        }

        return [
            'startTime' => $this->hhmmFromTs(timestamp: max($startTs, $dayStart)),
            'endTime'   => $this->hhmmFromTs(timestamp: min($endTs, ($dayEnd + 1))),
        ];
    }//end eventToBlock()

    /**
     * Format a unix timestamp as `HH:MM` in the server timezone.
     *
     * @param int $timestamp Unix timestamp.
     *
     * @return string
     */
    private function hhmmFromTs(int $timestamp): string
    {
        $dateTime = (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dateTime->format('H:i');
    }//end hhmmFromTs()

    /**
     * Resolve the leaf (OR CalendarEventService) via override or container.
     *
     * @return object|null
     */
    private function resolveLeaf(): ?object
    {
        if ($this->leafOverride !== null) {
            return $this->leafOverride;
        }

        try {
            $leaf = $this->container->get('OCA\\OpenRegister\\Service\\CalendarEventService');
        } catch (Throwable $e) {
            $this->logger->warning('Pipelinq: calendar leaf unavailable, skipping calendar sync');
            return null;
        }

        if (is_object($leaf) === false) {
            return null;
        }

        return $leaf;
    }//end resolveLeaf()

    /**
     * Load a Booking by id (returns null on miss / error).
     *
     * @param string $bookingId Booking UUID.
     *
     * @return array<string, mixed>|null
     */
    private function loadBooking(string $bookingId): ?array
    {
        $register = $this->registerSlug();
        $schema   = $this->schemaSlug(key: self::BOOKING_SCHEMA_KEY);
        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            $found = $this->getObjectService()->find(
                id: $bookingId,
                register: $register,
                schema: $schema
            );
        } catch (Throwable $e) {
            $this->logger->warning('Pipelinq: booking load failed in calendar push', ['booking' => $bookingId]);
            return null;
        }

        return $this->toArray(object: $found);
    }//end loadBooking()

    /**
     * Load a Resource by id (returns null on miss / error).
     *
     * @param string $resourceId Resource UUID/slug.
     *
     * @return array<string, mixed>|null
     */
    private function loadResource(string $resourceId): ?array
    {
        $register = $this->registerSlug();
        $schema   = $this->schemaSlug(key: self::RESOURCE_SCHEMA_KEY);
        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            $found = $this->getObjectService()->find(
                id: $resourceId,
                register: $register,
                schema: $schema
            );
        } catch (Throwable $e) {
            return null;
        }

        return $this->toArray(object: $found);
    }//end loadResource()

    /**
     * Load a Service by id without throwing.
     *
     * @param string $serviceId Service UUID/slug.
     *
     * @return array<string, mixed>|null
     */
    private function loadServiceQuiet(string $serviceId): ?array
    {
        if ($serviceId === '') {
            return null;
        }

        $register = $this->registerSlug();
        $schema   = $this->schemaSlug(key: self::SERVICE_SCHEMA_KEY);
        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            $found = $this->getObjectService()->find(
                id: $serviceId,
                register: $register,
                schema: $schema
            );
        } catch (Throwable $e) {
            return null;
        }

        return $this->toArray(object: $found);
    }//end loadServiceQuiet()

    /**
     * Pull the canonical id out of a normalised OpenRegister object.
     *
     * @param array<string, mixed> $object Object data.
     *
     * @return string
     */
    private function idOf(array $object): string
    {
        if (isset($object['@self']) === true && is_array($object['@self']) === true) {
            $self = $object['@self'];
            if (isset($self['id']) === true) {
                return (string) $self['id'];
            }

            if (isset($self['uuid']) === true) {
                return (string) $self['uuid'];
            }
        }

        if (isset($object['id']) === true) {
            return (string) $object['id'];
        }

        if (isset($object['uuid']) === true) {
            return (string) $object['uuid'];
        }

        return '';
    }//end idOf()

    /**
     * Normalise an OpenRegister entity (or array) to a plain array.
     *
     * @param mixed $object Entity, array, or null.
     *
     * @return array<string, mixed>|null
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential type-narrowing guards; extraction adds no clarity.
     */
    private function toArray(mixed $object): ?array
    {
        if ($object === null) {
            return null;
        }

        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true) {
            if (method_exists($object, 'jsonSerialize') === true) {
                $serialised = $object->jsonSerialize();
                if (is_array($serialised) === true) {
                    return $serialised;
                }
            }

            if (method_exists($object, 'getObject') === true) {
                $payload = $object->getObject();
                if (is_array($payload) === true) {
                    return $payload;
                }
            }

            if (method_exists($object, 'toArray') === true) {
                $arr = $object->toArray();
                if (is_array($arr) === true) {
                    return $arr;
                }
            }

            return (array) $object;
        }//end if

        return null;
    }//end toArray()

    /**
     * Resolve the pipelinq register slug from app config.
     *
     * @return string
     */
    private function registerSlug(): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
    }//end registerSlug()

    /**
     * Resolve a schema slug by app-config key.
     *
     * @param string $key App-config key, e.g. `booking_schema`.
     *
     * @return string
     */
    private function schemaSlug(string $key): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, '');
    }//end schemaSlug()

    /**
     * Resolve the numeric register id (the OR leaf wants `int`).
     *
     * Returns 0 when the register slug is non-numeric — the leaf still
     * accepts the int and stores the slug-derived value via its own lookup,
     * so a 0 here means the X-OPENREGISTER-REGISTER property carries 0 in
     * the worst case (graceful degradation).
     *
     * @return int
     */
    private function registerNumeric(): int
    {
        $value = $this->registerSlug();
        if (ctype_digit($value) === true) {
            return (int) $value;
        }

        return 0;
    }//end registerNumeric()

    /**
     * Resolve the numeric schema id by app-config key.
     *
     * @param string $key App-config key.
     *
     * @return int
     */
    private function schemaNumeric(string $key): int
    {
        $value = $this->schemaSlug(key: $key);
        if (ctype_digit($value) === true) {
            return (int) $value;
        }

        return 0;
    }//end schemaNumeric()

    /**
     * Resolve the OpenRegister ObjectService via the DI container.
     *
     * @return object The ObjectService instance.
     *
     * @throws RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            throw new RuntimeException('OpenRegister ObjectService is unavailable.', 0, $e);
        }
    }//end getObjectService()
}//end class
