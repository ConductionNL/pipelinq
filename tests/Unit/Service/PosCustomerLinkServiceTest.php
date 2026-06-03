<?php

/**
 * Unit tests for PosCustomerLinkService.
 *
 * Exercises the customer-link logic against a fake OpenRegister ObjectService
 * (in-memory store keyed by schema id) and the REAL PosAccessPolicy: contact
 * lookup (tenant-scoped search + privacy + last-purchase decoration), IDOR-safe
 * customer attachment, the on-account-requires-customer rule, the
 * server-computed purchase-history roll-up, and privacy-respecting consent sync.
 * No HTTP and no live OpenRegister are involved.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosCustomerLinkService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A fake ObjectService for the customer-link tests.
 *
 * Answers find() from an in-memory store, findAll() by applying the equality
 * filters plus a naive substring `search` over name/email/phone, and captures
 * saveObject() writes.
 */
class FakeCustomerObjectService
{
    /** @var array<string, array<string, array<string, mixed>>> */
    public array $store = [];

    /** @var array<int, array<string, mixed>> */
    public array $saves = [];

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $register, string $schema): ?array
    {
        return $this->store[$schema][$id] ?? null;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array $config): array
    {
        $filters = $config['filters'] ?? [];
        $schema  = (string) ($filters['schema'] ?? '');
        $search  = isset($config['search']) ? strtolower((string) $config['search']) : null;
        $rows    = array_values($this->store[$schema] ?? []);

        $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            foreach (['customer', 'status'] as $key) {
                if (isset($filters[$key]) === true && ($row[$key] ?? null) !== $filters[$key]) {
                    return false;
                }
            }

            return true;
        }));

        if ($search !== null) {
            $rows = array_values(array_filter($rows, static function (array $row) use ($search): bool {
                $hay = strtolower(
                    ($row['name'] ?? '').' '.($row['email'] ?? '').' '.($row['phone'] ?? '')
                );
                return str_contains($hay, $search);
            }));
        }

        $limit = $config['limit'] ?? null;
        if ($limit !== null) {
            $rows = array_slice($rows, 0, (int) $limit);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    public function saveObject(array $object, array $extend, string $register, string $schema, ?string $uuid): array
    {
        $id = $uuid ?? (string) ($object['id'] ?? 'generated');
        $object['id'] = $id;
        $this->store[$schema][$id] = $object;
        $this->saves[] = ['schema' => $schema, 'uuid' => $id, 'object' => $object];

        return $object;
    }
}

/**
 * Tests for PosCustomerLinkService.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Each behaviour is asserted in
 *  its own focused test.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the fakes the service
 *  legitimately exercises.
 */
class PosCustomerLinkServiceTest extends TestCase
{
    private PosCustomerLinkService $service;

    private FakeCustomerObjectService $objects;

    private IGroupManager $groupManager;

    /** @var array<string, string> */
    private array $configOverrides = [];

    private const POS_UID = 'cashier';

    private const CONTACT_SCHEMA = 'contact_schema';

    private const TX_SCHEMA = 'posTransaction_schema';

    /**
     * Wire the service with fakes and a POS-operator caller.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objects = new FakeCustomerObjectService();

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default = ''): string {
                if (array_key_exists($key, $this->configOverrides) === true) {
                    return $this->configOverrides[$key];
                }
                if ($key === 'register') {
                    return 'reg';
                }
                if (str_ends_with($key, '_schema') === true) {
                    return $key;
                }
                return $default;
            }
        );
        $appConfig->method('getValueInt')->willReturnCallback(
            static function (string $app, string $key, int $default = 0): int {
                return $default;
            }
        );

        $this->groupManager = $this->createMock(IGroupManager::class);
        // Default: the caller is a POS-group member (operator), not an admin.
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturnCallback(
            static fn (string $uid, string $group): bool => ($uid === self::POS_UID && $group === 'pos')
        );

        $policy = new PosAccessPolicy(appConfig: $appConfig, groupManager: $this->groupManager);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) {
            if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                return $this->objects;
            }
            throw new \RuntimeException('unknown service '.$id);
        });

        $this->service = new PosCustomerLinkService(
            container: $container,
            appConfig: $appConfig,
            policy: $policy,
            logger: $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * Seed a contact into the in-memory store.
     *
     * @param array<string, mixed> $extra Extra contact fields.
     *
     * @return string The contact id.
     */
    private function seedContact(string $id, string $name, array $extra = []): string
    {
        $this->objects->store[self::CONTACT_SCHEMA][$id] = array_merge(
            ['id' => $id, 'name' => $name, 'email' => strtolower($name).'@example.nl', 'phone' => ''],
            $extra
        );

        return $id;
    }

    /**
     * Seed a transaction into the in-memory store.
     *
     * @param array<string, mixed> $extra Extra transaction fields.
     *
     * @return void
     */
    private function seedTransaction(string $id, array $extra = []): void
    {
        $this->objects->store[self::TX_SCHEMA][$id] = array_merge(
            ['id' => $id, 'status' => 'draft', 'cashier' => self::POS_UID],
            $extra
        );
    }

    /**
     * A non-POS user is rejected from contact lookup.
     *
     * @return void
     */
    public function testSearchRejectsNonPosUser(): void
    {
        $this->expectException(OCSForbiddenException::class);
        $this->service->searchContacts(query: 'Maria', limit: 20, userId: 'stranger');
    }//end testSearchRejectsNonPosUser()

    /**
     * A too-short query is rejected.
     *
     * @return void
     */
    public function testSearchRejectsShortQuery(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->searchContacts(query: 'M', limit: 20, userId: self::POS_UID);
    }//end testSearchRejectsShortQuery()

    /**
     * Search returns decorated, tenant-scoped contact rows.
     *
     * @return void
     */
    public function testSearchReturnsDecoratedContacts(): void
    {
        $this->seedContact('c1', 'Maria', ['doNotContact' => true]);
        $this->seedContact('c2', 'Henk');
        // A settled purchase for Maria so lastPurchaseDate is derived.
        $this->seedTransaction('t1', ['customer' => 'c1', 'status' => 'settled', 'total' => 10.0, 'settledAt' => '2026-05-20T10:00:00+00:00']);

        $results = $this->service->searchContacts(query: 'Maria', limit: 20, userId: self::POS_UID);

        $this->assertCount(1, $results);
        $this->assertSame('c1', $results[0]['id']);
        $this->assertTrue($results[0]['doNotContact']);
        $this->assertSame('2026-05-20T10:00:00+00:00', $results[0]['lastPurchaseDate']);
    }//end testSearchReturnsDecoratedContacts()

    /**
     * Attaching a customer scopes to access and persists the link + consent.
     *
     * @return void
     */
    public function testAttachCustomerPersistsLinkAndConsent(): void
    {
        $this->seedContact('c1', 'Maria');
        $this->seedTransaction('t1');

        $result = $this->service->attachCustomer(
            transactionId: 't1',
            customerId: 'c1',
            marketingConsent: true,
            tenderType: 'cash',
            userId: self::POS_UID
        );

        $this->assertSame('c1', $result['customer']);
        $this->assertTrue($result['marketingConsent']);
        $this->assertSame('cash', $result['tenderType']);
        // Consent mirrored onto the contact.
        $this->assertTrue($this->objects->store[self::CONTACT_SCHEMA]['c1']['marketingConsent']);
    }//end testAttachCustomerPersistsLinkAndConsent()

    /**
     * A caller who is neither the cashier-owner nor a POS member is refused
     * (IDOR closed) even though the transaction exists.
     *
     * @return void
     */
    public function testAttachRejectsForeignTransaction(): void
    {
        $this->seedContact('c1', 'Maria');
        // Transaction owned by another cashier; current caller is the generic
        // POS user but for this test we drive it as a different, non-member uid.
        $this->seedTransaction('t1', ['cashier' => 'someoneElse']);

        // 'auditor' is not the cashier and not in the pos group -> forbidden.
        $this->expectException(OCSForbiddenException::class);
        $this->service->attachCustomer(
            transactionId: 't1',
            customerId: 'c1',
            marketingConsent: false,
            tenderType: null,
            userId: 'auditor'
        );
    }//end testAttachRejectsForeignTransaction()

    /**
     * A missing transaction resolves to not-found, not a leak.
     *
     * @return void
     */
    public function testAttachMissingTransactionIsNotFound(): void
    {
        $this->seedContact('c1', 'Maria');

        $this->expectException(OCSNotFoundException::class);
        $this->service->attachCustomer(
            transactionId: 'ghost',
            customerId: 'c1',
            marketingConsent: false,
            tenderType: null,
            userId: self::POS_UID
        );
    }//end testAttachMissingTransactionIsNotFound()

    /**
     * On-account tender without a customer is rejected.
     *
     * @return void
     */
    public function testOnAccountRequiresCustomer(): void
    {
        $this->seedTransaction('t1');

        $this->expectException(OCSBadRequestException::class);
        $this->service->attachCustomer(
            transactionId: 't1',
            customerId: null,
            marketingConsent: false,
            tenderType: 'onAccount',
            userId: self::POS_UID
        );
    }//end testOnAccountRequiresCustomer()

    /**
     * On-account with a customer succeeds and is tagged for AR tracking.
     *
     * @return void
     */
    public function testOnAccountWithCustomerSucceeds(): void
    {
        $this->seedContact('c1', 'Henk');
        $this->seedTransaction('t1');

        $result = $this->service->attachCustomer(
            transactionId: 't1',
            customerId: 'c1',
            marketingConsent: false,
            tenderType: 'onAccount',
            userId: self::POS_UID
        );

        $this->assertSame('onAccount', $result['tenderType']);
        $this->assertSame('c1', $result['customer']);
    }//end testOnAccountWithCustomerSucceeds()

    /**
     * Consent is never synced against a do-not-contact contact.
     *
     * @return void
     */
    public function testConsentNotSyncedForDoNotContact(): void
    {
        $this->seedContact('c1', 'Maria', ['doNotContact' => true, 'marketingConsent' => false]);
        $this->seedTransaction('t1');

        $result = $this->service->attachCustomer(
            transactionId: 't1',
            customerId: 'c1',
            marketingConsent: true,
            tenderType: null,
            userId: self::POS_UID
        );

        // Consent is dropped on the transaction and never written to the contact.
        $this->assertFalse($result['marketingConsent']);
        $this->assertFalse($this->objects->store[self::CONTACT_SCHEMA]['c1']['marketingConsent']);
    }//end testConsentNotSyncedForDoNotContact()

    /**
     * Consent sync is skipped when disabled by admin config.
     *
     * @return void
     */
    public function testConsentSyncRespectsAdminToggle(): void
    {
        $this->configOverrides['pos_sync_marketing_consent'] = 'false';
        $this->seedContact('c1', 'Maria', ['marketingConsent' => false]);
        $this->seedTransaction('t1');

        $result = $this->service->attachCustomer(
            transactionId: 't1',
            customerId: 'c1',
            marketingConsent: true,
            tenderType: null,
            userId: self::POS_UID
        );

        // Transaction records consent; contact is not mutated (sync disabled).
        $this->assertTrue($result['marketingConsent']);
        $this->assertFalse($this->objects->store[self::CONTACT_SCHEMA]['c1']['marketingConsent']);
    }//end testConsentSyncRespectsAdminToggle()

    /**
     * Purchase history is newest-first, excludes drafts, and rolls up spend.
     *
     * @return void
     */
    public function testPurchaseHistoryRollsUpServerSide(): void
    {
        $this->seedContact('c1', 'Maria');
        $this->seedTransaction('t1', ['customer' => 'c1', 'status' => 'settled', 'total' => 45.5, 'settledAt' => '2026-05-20T10:00:00+00:00']);
        $this->seedTransaction('t2', ['customer' => 'c1', 'status' => 'confirmed', 'total' => 23.75, 'confirmedAt' => '2026-05-22T10:00:00+00:00']);
        // A draft must be excluded from history.
        $this->seedTransaction('t3', ['customer' => 'c1', 'status' => 'draft', 'total' => 99.0]);

        $history = $this->service->purchaseHistory(customerId: 'c1', limit: null, userId: self::POS_UID);

        $this->assertSame(2, $history['count']);
        $this->assertSame(69.25, $history['lifetimeSpend']);
        // Newest first: t2 (2026-05-22) before t1 (2026-05-20).
        $this->assertSame('t2', $history['transactions'][0]['id']);
        $this->assertSame('t1', $history['transactions'][1]['id']);
    }//end testPurchaseHistoryRollsUpServerSide()

    /**
     * History for an unknown customer is not-found.
     *
     * @return void
     */
    public function testPurchaseHistoryUnknownCustomer(): void
    {
        $this->expectException(OCSNotFoundException::class);
        $this->service->purchaseHistory(customerId: 'ghost', limit: null, userId: self::POS_UID);
    }//end testPurchaseHistoryUnknownCustomer()

    /**
     * The configured history depth caps the number of rows.
     *
     * @return void
     */
    public function testHistoryDepthOverrideCapsRows(): void
    {
        $this->seedContact('c1', 'Maria');
        for ($i = 1; $i <= 5; $i++) {
            $this->seedTransaction('t'.$i, [
                'customer'  => 'c1',
                'status'    => 'settled',
                'total'     => 1.0,
                'settledAt' => sprintf('2026-05-%02dT10:00:00+00:00', $i),
            ]);
        }

        $history = $this->service->purchaseHistory(customerId: 'c1', limit: 2, userId: self::POS_UID);

        $this->assertSame(2, $history['count']);
        $this->assertCount(2, $history['transactions']);
    }//end testHistoryDepthOverrideCapsRows()
}//end class
