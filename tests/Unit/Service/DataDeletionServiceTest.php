<?php

/**
 * Unit tests for DataDeletionService.
 *
 * Exercises AVG right-to-be-forgotten pseudonymisation against an in-memory
 * fake ObjectService: customer PII (name/email/phone) is replaced with SHA-256
 * hashes, the Booking records are retained (never deleted), and the aggregate
 * fields (deposit, currency, status, times) are preserved unchanged.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\DataDeletionService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An in-memory fake OpenRegister ObjectService for the booking schema. Answers
 * finds by `linkedContactId` filter and records every save so the test can
 * assert the persisted shape (no deletes are ever performed).
 */
class FakeBookingObjectService
{
    /** @var array<string, array<string, mixed>> Booking store keyed by id. */
    public array $store = [];

    /** @var array<int, array<string, mixed>> Captured saves. */
    public array $saves = [];

    /** @var int Number of deleteObject calls (must stay zero — retention). */
    public int $deletes = 0;

    /**
     * @param array<string, mixed> $config
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array $config): array
    {
        $filters = $config['filters'] ?? [];
        $contact = (string) ($filters['linkedContactId'] ?? '');

        return array_values(array_filter(
            array_values($this->store),
            static function (array $row) use ($contact): bool {
                return (string) ($row['linkedContactId'] ?? '') === $contact;
            }
        ));
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array
    {
        $object['id']        = $uuid;
        $this->store[$uuid]  = $object;
        $this->saves[]       = $object;

        return $object;
    }

    /**
     * Deletes are forbidden under the retention invariant; track to assert zero.
     */
    public function deleteObject(string $uuid): void
    {
        $this->deletes++;
    }
}

/**
 * Tests for DataDeletionService.
 */
class DataDeletionServiceTest extends TestCase
{
    private DataDeletionService $service;

    private FakeBookingObjectService $objects;

    /**
     * Build the service with the fake ObjectService wired into the container.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objects = new FakeBookingObjectService();

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = '') {
                if ($key === 'register') {
                    return 'reg';
                }
                if ($key === 'booking_schema') {
                    return 'booking_schema';
                }
                return $default;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) {
            if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                return $this->objects;
            }
            throw new \RuntimeException('unknown service '.$id);
        });

        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new DataDeletionService($container, $appConfig, $logger);
    }//end setUp()

    /**
     * Seed a booking into the fake store.
     *
     * @param array<string, mixed> $overrides
     *
     * @return void
     */
    private function seedBooking(string $id, string $contactId, array $overrides = []): void
    {
        $this->objects->store[$id] = array_merge(
            [
                'id'              => $id,
                'linkedContactId' => $contactId,
                'customerName'    => 'Jan Jansen',
                'customerEmail'   => 'jan@example.nl',
                'customerPhone'   => '+31612345678',
                'service'         => 'haircut',
                'startTime'       => '2026-07-01T10:00:00+02:00',
                'status'          => 'completed',
                'depositAmount'   => 2500,
                'currency'        => 'EUR',
            ],
            $overrides
        );
    }//end seedBooking()

    /**
     * Pseudonymisation replaces each PII field with its SHA-256 hash.
     *
     * @return void
     */
    public function testPseudonymizeHashesPiiFields(): void
    {
        $this->seedBooking('b1', 'cust-1');

        $count = $this->service->pseudonymizeCustomerBookings('cust-1');

        $this->assertSame(1, $count);
        $stored = $this->objects->store['b1'];

        $this->assertSame(hash('sha256', 'Jan Jansen'), $stored['customerName']);
        $this->assertSame(hash('sha256', 'jan@example.nl'), $stored['customerEmail']);
        $this->assertSame(hash('sha256', '+31612345678'), $stored['customerPhone']);

        // The hash must not be the cleartext value any more.
        $this->assertNotSame('Jan Jansen', $stored['customerName']);
        $this->assertNotSame('jan@example.nl', $stored['customerEmail']);
    }//end testPseudonymizeHashesPiiFields()

    /**
     * Booking records are retained — never deleted — and stay findable.
     *
     * @return void
     */
    public function testPseudonymizeRetainsRecords(): void
    {
        $this->seedBooking('b1', 'cust-1');

        $this->service->pseudonymizeCustomerBookings('cust-1');

        $this->assertArrayHasKey('b1', $this->objects->store);
        $this->assertSame(0, $this->objects->deletes, 'No booking may be deleted (7-year retention).');
        $this->assertTrue($this->objects->store['b1']['pseudonymized']);
        $this->assertArrayHasKey('pseudonymizedAt', $this->objects->store['b1']);
    }//end testPseudonymizeRetainsRecords()

    /**
     * Aggregate / operational fields are preserved unchanged.
     *
     * @return void
     */
    public function testPseudonymizePreservesAggregates(): void
    {
        $this->seedBooking('b1', 'cust-1', ['depositAmount' => 4200, 'status' => 'no-show']);

        $this->service->pseudonymizeCustomerBookings('cust-1');
        $stored = $this->objects->store['b1'];

        $this->assertSame(4200, $stored['depositAmount']);
        $this->assertSame('EUR', $stored['currency']);
        $this->assertSame('no-show', $stored['status']);
        $this->assertSame('haircut', $stored['service']);
        $this->assertSame('2026-07-01T10:00:00+02:00', $stored['startTime']);
    }//end testPseudonymizePreservesAggregates()

    /**
     * Only the targeted customer's bookings are affected.
     *
     * @return void
     */
    public function testPseudonymizeScopedToCustomer(): void
    {
        $this->seedBooking('b1', 'cust-1');
        $this->seedBooking('b2', 'cust-2');

        $count = $this->service->pseudonymizeCustomerBookings('cust-1');

        $this->assertSame(1, $count);
        // The other customer's booking is untouched (still cleartext).
        $this->assertSame('Jan Jansen', $this->objects->store['b2']['customerName']);
    }//end testPseudonymizeScopedToCustomer()

    /**
     * Multiple bookings for the same customer are all pseudonymised.
     *
     * @return void
     */
    public function testPseudonymizeHandlesMultipleBookings(): void
    {
        $this->seedBooking('b1', 'cust-1');
        $this->seedBooking('b2', 'cust-1', ['customerName' => 'Other Name']);

        $count = $this->service->pseudonymizeCustomerBookings('cust-1');

        $this->assertSame(2, $count);
        $this->assertSame(hash('sha256', 'Jan Jansen'), $this->objects->store['b1']['customerName']);
        $this->assertSame(hash('sha256', 'Other Name'), $this->objects->store['b2']['customerName']);
    }//end testPseudonymizeHandlesMultipleBookings()

    /**
     * An empty customer id is rejected.
     *
     * @return void
     */
    public function testPseudonymizeRejectsEmptyCustomerId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->pseudonymizeCustomerBookings('');
    }//end testPseudonymizeRejectsEmptyCustomerId()

    /**
     * getConfig throws when the booking schema is not configured.
     *
     * @return void
     */
    public function testGetConfigThrowsWhenUnconfigured(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');
        $container = $this->createMock(ContainerInterface::class);
        $logger    = $this->createMock(LoggerInterface::class);

        $service = new DataDeletionService($container, $appConfig, $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Booking register or schema not configured.');
        $service->getConfig();
    }//end testGetConfigThrowsWhenUnconfigured()

    /**
     * getObjectService throws when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testGetObjectServiceThrowsWhenUnavailable(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new \Exception('nope'));
        $logger = $this->createMock(LoggerInterface::class);

        $service = new DataDeletionService($container, $appConfig, $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available.');
        $service->getObjectService();
    }//end testGetObjectServiceThrowsWhenUnavailable()
}//end class
