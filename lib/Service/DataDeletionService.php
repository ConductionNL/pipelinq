<?php

/**
 * Pipelinq DataDeletionService.
 *
 * AVG right-to-be-forgotten pseudonymisation for appointment bookings. The
 * customer's PII snapshot on each Booking is replaced with SHA-256 hashes while
 * the Booking record itself is retained for the NL Boekhoudplicht 7-year
 * retention period. Aggregates (counts, deposit totals) are left untouched.
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
 * @spec openspec/changes/appointment-booking-12-compliance-i18n/tasks.md#section-1
 * @spec openspec/changes/appointment-booking-12-compliance-i18n/specs/appointment-booking/spec.md#requirement-req-apt-017-compliance-and-audit-trails
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

/**
 * Service implementing AVG right-to-be-forgotten for bookings.
 *
 * Pseudonymises — never deletes — the customer PII held on Booking records so
 * the financial/aggregate data survives the statutory 7-year retention period
 * while the identifying personal data is irreversibly hashed.
 *
 * @spec openspec/changes/appointment-booking-12-compliance-i18n/tasks.md#section-1
 */
class DataDeletionService
{
    /**
     * The PII fields on a Booking that are pseudonymised. The remaining fields
     * (service, times, status, deposit, currency) are aggregates/operational
     * data and are deliberately left untouched.
     *
     * @var array<int, string>
     */
    private const PII_FIELDS = [
        'customerName',
        'customerEmail',
        'customerPhone',
    ];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (resolves the OR ObjectService lazily).
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
     * Resolve the OpenRegister ObjectService.
     *
     * Returned as `object` (not the concrete class) so unit tests can inject an
     * in-memory fake without a live OpenRegister install — the same convention
     * used by the POS services in this app.
     *
     * @return object The OpenRegister ObjectService.
     *
     * @throws RuntimeException If OpenRegister is not available.
     *
     * @spec openspec/changes/appointment-booking-12-compliance-i18n/tasks.md#section-1
     */
    public function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Resolve the configured booking register and schema ids.
     *
     * @return array{register: string, schema: string} The register and schema ids.
     *
     * @throws \RuntimeException If the booking register/schema is not configured.
     *
     * @spec openspec/changes/appointment-booking-12-compliance-i18n/tasks.md#section-1
     */
    public function getConfig(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'booking_schema', '');

        if ($register === '' || $schema === '') {
            throw new RuntimeException('Booking register or schema not configured.');
        }

        return [
            'register' => $register,
            'schema'   => $schema,
        ];
    }//end getConfig()

    /**
     * Pseudonymise all bookings linked to a customer (AVG right-to-be-forgotten).
     *
     * Every Booking whose `linkedContactId` matches the customer has its name,
     * email and phone snapshot replaced with the SHA-256 hash of the original
     * value. The Booking record is retained (7-year Boekhoudplicht); the
     * aggregate fields (deposit, currency, status, times) are NOT modified. The
     * `pseudonymized`/`pseudonymizedAt` markers are stamped and the action is
     * logged with a timestamp.
     *
     * @param string $customerId The UUID of the customer contact entity.
     *
     * @return int The number of bookings pseudonymised.
     *
     * @throws \RuntimeException If the booking register/schema is not configured.
     *
     * @spec openspec/changes/appointment-booking-12-compliance-i18n/tasks.md#section-1
     * @spec openspec/changes/appointment-booking-12-compliance-i18n/specs/appointment-booking/spec.md#requirement-req-apt-017-compliance-and-audit-trails
     */
    public function pseudonymizeCustomerBookings(string $customerId): int
    {
        if ($customerId === '') {
            throw new RuntimeException('A customer id is required for pseudonymisation.');
        }

        $config        = $this->getConfig();
        $objectService = $this->getObjectService();

        $results = $objectService->findAll(
            config: [
                'filters' => [
                    'register'        => $config['register'],
                    'schema'          => $config['schema'],
                    'linkedContactId' => $customerId,
                ],
            ]
        );

        $timestamp     = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $pseudonymised = 0;

        foreach (($results ?? []) as $result) {
            $booking = $this->toArray(object: $result);

            $id = (string) ($booking['id'] ?? ($booking['@self']['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            // Replace each PII field with its SHA-256 hash. A field that is
            // already absent/empty is hashed as the empty string so the shape
            // stays stable and the original value can never be recovered.
            foreach (self::PII_FIELDS as $field) {
                $original        = (string) ($booking[$field] ?? '');
                $booking[$field] = hash('sha256', $original);
            }

            $booking['pseudonymized']   = true;
            $booking['pseudonymizedAt'] = $timestamp;

            // Strip the OR envelope before persisting so we do not echo it back.
            unset($booking['@self']);

            $objectService->saveObject(
                object: $booking,
                extend: [],
                register: $config['register'],
                schema: $config['schema'],
                uuid: $id
            );

            $pseudonymised++;
        }//end foreach

        // Log the action with a timestamp. The customer id is logged (it is an
        // internal UUID, not the special-category PII itself); the hashed PII is
        // never logged in the clear.
        $this->logger->info(
            'AVG right-to-be-forgotten: pseudonymised customer bookings',
            [
                'customerId' => $customerId,
                'count'      => $pseudonymised,
                'timestamp'  => $timestamp,
            ]
        );

        return $pseudonymised;
    }//end pseudonymizeCustomerBookings()

    /**
     * Normalise an OpenRegister result (entity or array) to an array.
     *
     * @param mixed $object The OR result.
     *
     * @return array<string, mixed> The booking data as an array.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return (array) $object;
    }//end toArray()
}//end class
