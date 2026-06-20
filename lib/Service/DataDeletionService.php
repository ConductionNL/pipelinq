<?php

/**
 * Pipelinq DataDeletionService.
 *
 * AVG / GDPR right-to-be-forgotten for the appointment-booking module.
 * Pseudonymises the customer-identifying fields (`customerName`, `customerEmail`,
 * `customerPhone`) on every Booking linked to a customer with deterministic
 * SHA-256 hashes; never deletes Booking records (NL Boekhoudplicht — 7-year
 * retention). Aggregates (counts, totals, revenue) remain unchanged because the
 * underlying records are preserved.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/appointment-booking-12-compliance-i18n/specs/appointment-booking/spec.md#REQ-APT-017
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * AVG right-to-be-forgotten pseudonymisation for Bookings.
 *
 * @spec openspec/changes/appointment-booking-12-compliance-i18n/specs/appointment-booking/spec.md#REQ-APT-017
 */
class DataDeletionService
{
    /**
     * The booking schema config key (set by appointment-booking-01-data-model).
     */
    private const BOOKING_SCHEMA_KEY = 'booking_schema';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (lazy ObjectService).
     * @param IAppConfig         $appConfig The app config (register + schema ids).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Pseudonymise a customer's identifying fields on every Booking they own.
     *
     * REQ-APT-017 / AVG (right-to-be-forgotten). The Booking record itself is
     * retained (NL Boekhoudplicht — 7-year retention); only `customerName`,
     * `customerEmail`, and `customerPhone` are replaced with deterministic
     * SHA-256 hashes. Aggregates (counts, totals) are unchanged because rows
     * remain in place.
     *
     * @param string $customerId The customer UID whose Bookings to pseudonymise.
     *
     * @return array<string, int> Summary {bookings: <count>} for logging/audit.
     *
     * @spec openspec/changes/appointment-booking-12-compliance-i18n/specs/appointment-booking/spec.md#REQ-APT-017
     */
    public function pseudonymizeCustomerBookings(string $customerId): array
    {
        $summary = ['bookings' => 0];

        if ($customerId === '') {
            return $summary;
        }

        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, self::BOOKING_SCHEMA_KEY, '');
        if ($register === '' || $schema === '') {
            $this->logger->warning(
                'Pipelinq: AVG pseudonymisation skipped — booking register/schema not configured',
                ['customerId' => $customerId]
            );

            return $summary;
        }

        $bookings = $this->findBookingsForCustomer(register: $register, schema: $schema, customerId: $customerId);
        foreach ($bookings as $booking) {
            $uuid = (string) ($booking['bookingId'] ?? $booking['@self']['id'] ?? $booking['uuid'] ?? $booking['id'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $payload = $this->pseudonymizeFields(booking: $booking);
            if ($this->persist(register: $register, schema: $schema, payload: $payload, uuid: $uuid) === true) {
                $summary['bookings']++;
            }
        }

        $this->logger->info(
            'Pipelinq: AVG booking pseudonymisation completed',
            [
                'customerId' => $customerId,
                'summary'    => $summary,
                'timestamp'  => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ]
        );

        return $summary;
    }//end pseudonymizeCustomerBookings()

    /**
     * Replace the customer-identifying fields with SHA-256 hashes.
     *
     * Only `customerName`, `customerEmail`, `customerPhone` are touched; every
     * other field (status, startAt, price, serviceId, resourceAssignments, etc.)
     * is left untouched so aggregates remain valid. The `pseudonymizedAt` field
     * records the timestamp of the operation.
     *
     * @param array<string, mixed> $booking The booking record.
     *
     * @return array<string, mixed> The pseudonymised payload.
     */
    private function pseudonymizeFields(array $booking): array
    {
        $payload = $booking;
        foreach (['customerName', 'customerEmail', 'customerPhone'] as $field) {
            $original = (string) ($payload[$field] ?? '');
            if ($original === '') {
                $payload[$field] = null;
                continue;
            }

            $payload[$field] = hash('sha256', $original);
        }

        $payload['pseudonymizedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        return $payload;
    }//end pseudonymizeFields()

    /**
     * Find all bookings owned by the given customer (paginated guard).
     *
     * @param string $register   The register id.
     * @param string $schema     The booking schema id.
     * @param string $customerId The customer UID.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findBookingsForCustomer(string $register, string $schema, string $customerId): array
    {
        try {
            $rows = $this->getObjectService()->findAll(
                filters: ['customerId' => $customerId],
                register: $register,
                schema: $schema,
                limit: 10000
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Pipelinq: AVG booking lookup failed',
                ['customerId' => $customerId, 'exception' => $e->getMessage()]
            );

            return [];
        }

        if (is_array($rows) === false) {
            return [];
        }

        return array_map(fn (mixed $row): array => $this->toArray(object: $row), array_values($rows));
    }//end findBookingsForCustomer()

    /**
     * Persist a pseudonymised booking via OR's ObjectService.
     *
     * @param string               $register The register id.
     * @param string               $schema   The schema id.
     * @param array<string, mixed> $payload  The payload.
     * @param string               $uuid     The booking UUID.
     *
     * @return bool True when persisted.
     */
    private function persist(string $register, string $schema, array $payload, string $uuid): bool
    {
        try {
            $this->getObjectService()->saveObject(
                object: $payload,
                extend: [],
                register: $register,
                schema: $schema,
                uuid: $uuid
            );

            return true;
        } catch (Throwable $e) {
            $this->logger->warning(
                'Pipelinq: AVG booking persist failed',
                ['uuid' => $uuid, 'exception' => $e->getMessage()]
            );

            return false;
        }
    }//end persist()

    /**
     * Normalise an OR entity or array into a plain associative array.
     *
     * @param mixed $object The OR entity or array.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialised = $object->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return [];
    }//end toArray()

    /**
     * Lazily resolve the OR ObjectService.
     *
     * @return object The ObjectService.
     *
     * @throws \RuntimeException When OR is unavailable.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            throw new RuntimeException('OpenRegister ObjectService is unavailable.', 0, $e);
        }
    }//end getObjectService()
}//end class
