<?php

/**
 * Unit tests for DataDeletionService (AVG pseudonymisation of Bookings).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\DataDeletionService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fake ObjectService used by DataDeletionServiceTest.
 *
 * Holds an in-memory list of bookings keyed by uuid; records every save so the
 * tests can assert which fields were pseudonymised. `findAll()` mirrors the OR
 * signature used by the service (filters + register + schema + limit).
 */
class FakeDataDeletionObjectService
{

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $bookings = [];

    /**
     * @var array<int, array{uuid: string, payload: array<string, mixed>}>
     */
    public array $saves = [];

    public string $expectedRegister = '';

    public string $expectedSchema = '';

    /**
     * @var array<string, mixed>
     */
    public array $lastFilters = [];

    /**
     * Mirror OR ObjectService::findAll for the customerId filter only.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array $filters, string $register, string $schema, int $limit): array
    {
        $this->expectedRegister = $register;
        $this->expectedSchema   = $schema;
        $this->lastFilters      = $filters;
        $customerId = (string) ($filters['customerId'] ?? '');
        if ($customerId === '') {
            return array_values($this->bookings);
        }

        return array_values(
                array_filter(
            $this->bookings,
            static fn (array $b): bool => ($b['customerId'] ?? null) === $customerId
        )
                );
    }//end findAll()

    /**
     * Mirror OR ObjectService::saveObject.
     *
     * @param array<string, mixed> $object
     * @param array<int, string>   $extend
     *
     * @return array<string, mixed>
     */
    public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array
    {
        $object['id']          = $uuid;
        $this->bookings[$uuid] = $object;
        $this->saves[]         = ['uuid' => $uuid, 'payload' => $object];

        return $object;
    }//end saveObject()
}//end class

/**
 * Tests for DataDeletionService.
 */
class DataDeletionServiceTest extends TestCase
{

    /**
     * The service under test.
     */
    private DataDeletionService $service;

    /**
     * The fake ObjectService backing the service.
     */
    private FakeDataDeletionObjectService $objectService;

    /**
     * The mock app config.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Set up the test.
     */
    protected function setUp(): void
    {
        $this->objectService = new FakeDataDeletionObjectService();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')
            ->willReturnCallback(
                    static function (string $app, string $key, string $default=''): string {
                        if ($app !== Application::APP_ID) {
                            return $default;
                        }

                        return match ($key) {
                            'register'       => 'pipelinq',
                            'booking_schema' => 'booking',
                            default          => $default,
                        };
                    }
                    );

        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new DataDeletionService(
            container: $container,
            appConfig: $this->appConfig,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Returns the empty summary when the customer id is blank.
     */
    public function testPseudonymizeRejectsEmptyCustomerId(): void
    {
        $summary = $this->service->pseudonymizeCustomerBookings('');

        $this->assertSame(['bookings' => 0], $summary);
        $this->assertSame([], $this->objectService->saves, 'No saves should occur for an empty id.');
    }//end testPseudonymizeRejectsEmptyCustomerId()

    /**
     * Replaces customer name, email, and phone with SHA-256 hashes.
     */
    public function testPseudonymizationHashesCustomerFields(): void
    {
        $this->objectService->bookings['b1'] = [
            'bookingId'     => 'b1',
            'customerId'    => 'cust-7',
            'customerName'  => 'Sarah de Vries',
            'customerEmail' => 'sarah@example.com',
            'customerPhone' => '+31 6 1234 5678',
            'serviceId'     => 'svc-haircut',
            'status'        => 'confirmed',
            'price'         => 25.0,
            'startAt'       => '2026-06-10T10:00:00+02:00',
        ];

        $summary = $this->service->pseudonymizeCustomerBookings('cust-7');

        $this->assertSame(1, $summary['bookings']);
        $stored = $this->objectService->bookings['b1'];
        $this->assertSame(hash('sha256', 'Sarah de Vries'), $stored['customerName']);
        $this->assertSame(hash('sha256', 'sarah@example.com'), $stored['customerEmail']);
        $this->assertSame(hash('sha256', '+31 6 1234 5678'), $stored['customerPhone']);
    }//end testPseudonymizationHashesCustomerFields()

    /**
     * Retains the Booking record (no delete) and preserves aggregate fields.
     */
    public function testPseudonymizationRetainsRecordsAndAggregates(): void
    {
        $this->objectService->bookings['b1'] = [
            'bookingId'     => 'b1',
            'customerId'    => 'cust-7',
            'customerName'  => 'Sarah de Vries',
            'customerEmail' => 'sarah@example.com',
            'customerPhone' => '+31 6 1234 5678',
            'serviceId'     => 'svc-haircut',
            'resourceId'    => 'res-1',
            'status'        => 'completed',
            'price'         => 25.0,
            'currency'      => 'EUR',
            'startAt'       => '2026-06-10T10:00:00+02:00',
            'endAt'         => '2026-06-10T10:30:00+02:00',
        ];
        $this->objectService->bookings['b2'] = [
            'bookingId'     => 'b2',
            'customerId'    => 'cust-7',
            'customerName'  => 'Sarah de Vries',
            'customerEmail' => 'sarah@example.com',
            'customerPhone' => '+31 6 1234 5678',
            'serviceId'     => 'svc-color',
            'resourceId'    => 'res-2',
            'status'        => 'cancelled',
            'price'         => 75.0,
            'currency'      => 'EUR',
            'startAt'       => '2026-05-22T14:00:00+02:00',
            'endAt'         => '2026-05-22T15:30:00+02:00',
        ];

        $summary = $this->service->pseudonymizeCustomerBookings('cust-7');

        // Both records still exist (Boekhoudplicht retention).
        $this->assertCount(2, $this->objectService->bookings);
        $this->assertSame(2, $summary['bookings']);

        // Aggregate-relevant fields untouched: total revenue and counts are preserved.
        $total = 0.0;
        foreach ($this->objectService->bookings as $b) {
            $total += (float) $b['price'];
            $this->assertSame('EUR', $b['currency']);
            $this->assertArrayHasKey('serviceId', $b);
            $this->assertArrayHasKey('startAt', $b);
            $this->assertArrayHasKey('status', $b);
        }

        $this->assertSame(100.0, $total);

        // Each record carries a pseudonymisation timestamp.
        foreach ($this->objectService->bookings as $b) {
            $this->assertArrayHasKey('pseudonymizedAt', $b);
            $this->assertNotEmpty($b['pseudonymizedAt']);
        }
    }//end testPseudonymizationRetainsRecordsAndAggregates()

    /**
     * Other customers' bookings are not touched.
     */
    public function testPseudonymizationOnlyTouchesMatchingCustomer(): void
    {
        $this->objectService->bookings['b1'] = [
            'bookingId'     => 'b1',
            'customerId'    => 'cust-7',
            'customerName'  => 'Sarah de Vries',
            'customerEmail' => 'sarah@example.com',
        ];
        $this->objectService->bookings['b2'] = [
            'bookingId'     => 'b2',
            'customerId'    => 'cust-other',
            'customerName'  => 'Other Person',
            'customerEmail' => 'other@example.com',
        ];

        $this->service->pseudonymizeCustomerBookings('cust-7');

        $this->assertSame(hash('sha256', 'Sarah de Vries'), $this->objectService->bookings['b1']['customerName']);
        $this->assertSame('Other Person', $this->objectService->bookings['b2']['customerName']);
        $this->assertSame('other@example.com', $this->objectService->bookings['b2']['customerEmail']);
    }//end testPseudonymizationOnlyTouchesMatchingCustomer()

    /**
     * Missing or empty fields are nulled (not hashed to a constant).
     */
    public function testPseudonymizationHandlesMissingFields(): void
    {
        $this->objectService->bookings['b1'] = [
            'bookingId'    => 'b1',
            'customerId'   => 'cust-7',
            'customerName' => 'Sarah',
            // No email, no phone.
        ];

        $this->service->pseudonymizeCustomerBookings('cust-7');

        $stored = $this->objectService->bookings['b1'];
        $this->assertSame(hash('sha256', 'Sarah'), $stored['customerName']);
        $this->assertNull($stored['customerEmail']);
        $this->assertNull($stored['customerPhone']);
    }//end testPseudonymizationHandlesMissingFields()

    /**
     * Returns the empty summary (and skips) when register or schema not configured.
     */
    public function testPseudonymizationSkipsWhenSchemaUnconfigured(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default=''): string => $default
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);
        $logger = $this->createMock(LoggerInterface::class);

        $service = new DataDeletionService(
            container: $container,
            appConfig: $appConfig,
            logger: $logger,
        );

        $summary = $service->pseudonymizeCustomerBookings('cust-7');

        $this->assertSame(['bookings' => 0], $summary);
        $this->assertSame([], $this->objectService->saves);
    }//end testPseudonymizationSkipsWhenSchemaUnconfigured()

    /**
     * REQ-AVG-014 boundary: the Art-17 find delegates to OR's generic
     * ObjectService::findAll with a PLAIN equality filter on `customerId`,
     * scoped to the booking register + schema — NOT the admin-gated OR
     * DsarService PII-index path (which soft-deletes whole objects). Pinning
     * this guards against a future Seam-3 migration changing the find surface
     * or the field-level pseudonymise-and-keep erasure semantics.
     */
    public function testFindUsesPlainCustomerIdEqualityScopedToBookingSchema(): void
    {
        $this->objectService->bookings['b1'] = [
            'bookingId'    => 'b1',
            'customerId'   => 'cust-7',
            'customerName' => 'Sarah',
        ];

        $this->service->pseudonymizeCustomerBookings('cust-7');

        // Exactly a plain equality filter on the customer identifier.
        $this->assertSame(['customerId' => 'cust-7'], $this->objectService->lastFilters);
        // Scoped to the configured booking register + schema (not a global PII scan).
        $this->assertSame('pipelinq', $this->objectService->expectedRegister);
        $this->assertSame('booking', $this->objectService->expectedSchema);
        // The record is retained (no soft-delete / no object removal).
        $this->assertArrayHasKey('b1', $this->objectService->bookings);
    }//end testFindUsesPlainCustomerIdEqualityScopedToBookingSchema()
}//end class
