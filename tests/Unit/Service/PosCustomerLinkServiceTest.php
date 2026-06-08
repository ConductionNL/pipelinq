<?php

/**
 * Unit tests for PosCustomerLinkService.
 *
 * Covers the substring-match search logic, the doNotContact privacy flag
 * enforcement on consent sync, the on-account-requires-customer invariant,
 * the admin-config readers (search fields / history depth / sync toggle /
 * require-customer-for-on-account) and the history depth / status filter
 * scrubber. The OR ObjectService is mocked so the tests stay isolated.
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

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\PosCustomerLinkService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PosCustomerLinkService.
 */
class PosCustomerLinkServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var PosCustomerLinkService
     */
    private PosCustomerLinkService $service;

    /**
     * Mock app config.
     *
     * @var IAppConfig&\PHPUnit\Framework\MockObject\MockObject
     */
    private IAppConfig $appConfig;

    /**
     * Mock OR ObjectService.
     *
     * @var ObjectService&\PHPUnit\Framework\MockObject\MockObject
     */
    private ObjectService $objectService;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $container          = $this->createMock(ContainerInterface::class);
        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $logger             = $this->createMock(LoggerInterface::class);

        $container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new PosCustomerLinkService(
            $container,
            $this->appConfig,
            $logger,
        );
    }//end setUp()

    /**
     * Default-config app config reads: register, contact_schema,
     * posTransaction_schema all populated; admin keys at defaults.
     *
     * @return void
     */
    private function stubAppConfig(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default = '') {
                $values = [
                    'register'                    => 'reg-1',
                    'contact_schema'              => 'contact',
                    'posTransaction_schema'       => 'posTransaction',
                    'customerSearchFields'        => 'name,email,phone',
                    'customerHistoryDepth'        => '10',
                    'enablePipelinqSync'          => 'true',
                    'requireCustomerForOnAccount' => 'true',
                ];

                return $values[$key] ?? $default;
            });
    }//end stubAppConfig()

    /**
     * searchCustomers rejects queries shorter than 2 characters.
     *
     * @return void
     */
    public function testSearchRejectsShortQuery(): void
    {
        $this->stubAppConfig();

        $this->expectException(OCSBadRequestException::class);
        $this->service->searchCustomers(query: 'a');
    }//end testSearchRejectsShortQuery()

    /**
     * searchCustomers performs a case-insensitive substring match across the
     * enabled search fields and returns decorated rows.
     *
     * @return void
     */
    public function testSearchMatchesAcrossEnabledFields(): void
    {
        $this->stubAppConfig();
        $this->objectService->method('findAll')->willReturn([
            ['id' => 'c1', 'name' => 'Maria García',   'email' => 'maria@example.nl',  'phone' => '+31 6 1234 5678'],
            ['id' => 'c2', 'name' => 'Henk de Vries',  'email' => 'henk@example.nl',   'phone' => '+31 6 8765 4321'],
            ['id' => 'c3', 'name' => 'Lisa van Loon',  'email' => 'lisa@example.nl',   'phone' => '+31 6 1111 2222'],
        ]);

        $results = $this->service->searchCustomers(query: 'maria');

        $this->assertCount(1, $results);
        $this->assertSame('c1', $results[0]['id']);
        $this->assertSame('Maria García', $results[0]['name']);
        $this->assertFalse($results[0]['doNotContact']);
    }//end testSearchMatchesAcrossEnabledFields()

    /**
     * searchCustomers decorates contacts with the doNotContact badge so the
     * lookup modal can surface the privacy flag (REQ-PCL-007).
     *
     * @return void
     */
    public function testSearchSurfacesDoNotContactFlag(): void
    {
        $this->stubAppConfig();
        $this->objectService->method('findAll')->willReturn([
            ['id' => 'c1', 'name' => 'Anonymous AB', 'email' => 'a@b.c', 'doNotContact' => true],
        ]);

        $results = $this->service->searchCustomers(query: 'anon');

        $this->assertTrue($results[0]['doNotContact']);
        $this->assertSame('Niet benaderen', $results[0]['doNotContactBadge']);
    }//end testSearchSurfacesDoNotContactFlag()

    /**
     * assertOnAccountHasCustomer raises when on-account is set without a customer.
     *
     * @return void
     */
    public function testOnAccountRequiresCustomer(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->expectExceptionMessageMatches("/op rekening/");

        $this->service->assertOnAccountHasCustomer([
            'tenderType' => 'onAccount',
            'customer'   => '',
        ]);
    }//end testOnAccountRequiresCustomer()

    /**
     * assertOnAccountHasCustomer respects the admin toggle: when
     * requireCustomerForOnAccount is 'false', missing customer is allowed.
     *
     * @return void
     */
    public function testOnAccountInvariantDisabledByAdminToggle(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default = '') {
                if ($key === 'requireCustomerForOnAccount') {
                    return 'false';
                }

                return $default;
            });

        // Should NOT throw.
        $this->service->assertOnAccountHasCustomer([
            'tenderType' => 'onAccount',
            'customer'   => '',
        ]);

        $this->addToAssertionCount(1);
    }//end testOnAccountInvariantDisabledByAdminToggle()

    /**
     * assertOnAccountHasCustomer is a no-op when the customer is set or the
     * tender is not on-account.
     *
     * @return void
     */
    public function testOnAccountWithCustomerPasses(): void
    {
        $this->service->assertOnAccountHasCustomer([
            'tenderType' => 'onAccount',
            'customer'   => 'c-uuid',
        ]);

        $this->service->assertOnAccountHasCustomer([
            'tenderType' => 'cash',
            'customer'   => '',
        ]);

        $this->addToAssertionCount(2);
    }//end testOnAccountWithCustomerPasses()

    /**
     * syncConsent returns 'skipped' (and does not write) when the contact
     * carries the doNotContact flag (REQ-PCL-007 Scenario 2).
     *
     * @return void
     */
    public function testSyncConsentSkippedForDoNotContact(): void
    {
        $this->stubAppConfig();
        $this->objectService->expects($this->never())->method('saveObject');

        $status = $this->service->syncConsent(
            contact: [
                'id'           => 'c1',
                'doNotContact' => true,
            ],
            consent: true
        );

        $this->assertSame('skipped', $status);
    }//end testSyncConsentSkippedForDoNotContact()

    /**
     * syncConsent writes the consent to the contact via OR ObjectService and
     * returns 'success' on a clean write.
     *
     * @return void
     */
    public function testSyncConsentWritesContact(): void
    {
        $this->stubAppConfig();
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturn(['id' => 'c1', 'marketingConsent' => true]);

        $status = $this->service->syncConsent(
            contact: [
                'id'           => 'c1',
                'name'         => 'Maria',
                'doNotContact' => false,
            ],
            consent: true
        );

        $this->assertSame('success', $status);
    }//end testSyncConsentWritesContact()

    /**
     * syncConsent catches OR throwables and returns 'failed' (POS write is
     * authoritative; the contact-side error must never block the POS save).
     *
     * @return void
     */
    public function testSyncConsentReturnsFailedOnError(): void
    {
        $this->stubAppConfig();
        $this->objectService->method('saveObject')->willThrowException(new \RuntimeException('boom'));

        $status = $this->service->syncConsent(
            contact: [
                'id'           => 'c1',
                'doNotContact' => false,
            ],
            consent: true
        );

        $this->assertSame('failed', $status);
    }//end testSyncConsentReturnsFailedOnError()

    /**
     * getCustomer raises a 404 when the contact does not exist.
     *
     * @return void
     */
    public function testGetCustomerNotFoundRaises404(): void
    {
        $this->stubAppConfig();
        $this->objectService->method('find')->willReturn(null);

        $this->expectException(OCSNotFoundException::class);
        $this->service->getCustomer(contactUuid: 'missing-uuid');
    }//end testGetCustomerNotFoundRaises404()

    /**
     * enabledSearchFields defaults to the full set when the config string is
     * empty or invalid.
     *
     * @return void
     */
    public function testEnabledSearchFieldsDefaultsToAll(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default = '') {
                if ($key === 'customerSearchFields') {
                    return '';
                }

                return $default;
            });

        $fields = $this->service->enabledSearchFields();

        $this->assertSame(['name', 'email', 'phone'], $fields);
    }//end testEnabledSearchFieldsDefaultsToAll()

    /**
     * enabledSearchFields filters out unknown values and respects the admin
     * subset.
     *
     * @return void
     */
    public function testEnabledSearchFieldsFiltersUnknownValues(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default = '') {
                if ($key === 'customerSearchFields') {
                    return 'email,bogus,phone';
                }

                return $default;
            });

        $fields = $this->service->enabledSearchFields();

        $this->assertSame(['email', 'phone'], $fields);
    }//end testEnabledSearchFieldsFiltersUnknownValues()

    /**
     * historyDepth defaults to 10 on empty / non-numeric values and caps at 50.
     *
     * @return void
     */
    public function testHistoryDepthBounds(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default = '') {
                if ($key === 'customerHistoryDepth') {
                    return '999';
                }

                return $default;
            });

        $this->assertSame(50, $this->service->historyDepth());
    }//end testHistoryDepthBounds()

    /**
     * isSyncEnabled defaults to true; 'false' string disables.
     *
     * @return void
     */
    public function testSyncEnabledTogglesOnFalseString(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default = '') {
                if ($key === 'enablePipelinqSync') {
                    return 'false';
                }

                return $default;
            });

        $this->assertFalse($this->service->isSyncEnabled());
    }//end testSyncEnabledTogglesOnFalseString()

    /**
     * requiresCustomerForOnAccount defaults to true.
     *
     * @return void
     */
    public function testRequiresCustomerForOnAccountDefault(): void
    {
        $this->appConfig->method('getValueString')->willReturnArgument(2);

        $this->assertTrue($this->service->requiresCustomerForOnAccount());
    }//end testRequiresCustomerForOnAccountDefault()

    /**
     * getCustomerHistory excludes drafts / parked carts and sorts descending
     * by createdAt (most recent first).
     *
     * @return void
     */
    public function testGetCustomerHistoryFiltersDraftsAndSorts(): void
    {
        $this->stubAppConfig();
        $this->objectService->method('findAll')->willReturn([
            ['id' => 't1', 'status' => 'confirmed', 'confirmedAt' => '2026-05-01T10:00:00Z', 'total' => 10],
            ['id' => 't2', 'status' => 'draft',     'confirmedAt' => '2026-05-02T10:00:00Z', 'total' => 99],
            ['id' => 't3', 'status' => 'settled',   'confirmedAt' => '2026-05-03T10:00:00Z', 'total' => 30],
            ['id' => 't4', 'status' => 'parked',    'confirmedAt' => '2026-05-04T10:00:00Z', 'total' => 55],
            ['id' => 't5', 'status' => 'refunded',  'confirmedAt' => '2026-05-02T10:00:00Z', 'total' => 20],
        ]);

        $history = $this->service->getCustomerHistory(contactUuid: 'c1', limit: 10);

        $this->assertCount(3, $history);
        // DESC by createdAt: t3 (2026-05-03) > t5 (2026-05-02) > t1 (2026-05-01).
        $this->assertSame('t3', $history[0]['id']);
        $this->assertSame('t5', $history[1]['id']);
        $this->assertSame('t1', $history[2]['id']);
    }//end testGetCustomerHistoryFiltersDraftsAndSorts()

    /**
     * detachCustomer refuses to mutate a closed (settled / confirmed) transaction.
     *
     * @return void
     */
    public function testDetachRefusesClosedTransaction(): void
    {
        $this->stubAppConfig();
        $this->objectService->method('find')->willReturn(['id' => 't1', 'status' => 'settled']);

        $this->expectException(OCSBadRequestException::class);
        $this->service->detachCustomer(transactionId: 't1');
    }//end testDetachRefusesClosedTransaction()

    /**
     * attachCustomer refuses to attach when the transaction is no longer mutable.
     *
     * @return void
     */
    public function testAttachRefusesClosedTransaction(): void
    {
        $this->stubAppConfig();
        $this->objectService->method('find')->willReturnCallback(static function (string $id, string $register = '', string $schema = '') {
            if ($id === 'c1') {
                return ['id' => 'c1', 'name' => 'Maria', 'doNotContact' => false];
            }

            return ['id' => 't1', 'status' => 'settled'];
        });

        $this->expectException(OCSBadRequestException::class);
        $this->service->attachCustomer(transactionId: 't1', contactUuid: 'c1');
    }//end testAttachRefusesClosedTransaction()

    /**
     * attachCustomer writes customer + marketingConsent + consentSyncStatus
     * on a draft transaction.
     *
     * @return void
     */
    public function testAttachWritesCustomerAndConsent(): void
    {
        $this->stubAppConfig();
        $this->objectService->method('find')->willReturnCallback(static function (string $id, string $register = '', string $schema = '') {
            if ($id === 'c1') {
                return ['id' => 'c1', 'name' => 'Maria', 'doNotContact' => false];
            }

            return ['id' => 't1', 'status' => 'draft'];
        });
        $this->objectService->expects($this->atLeastOnce())
            ->method('saveObject')
            ->willReturnCallback(static function (array $object) {
                return $object;
            });

        $saved = $this->service->attachCustomer(
            transactionId: 't1',
            contactUuid: 'c1',
            marketingConsent: true
        );

        $this->assertSame('c1', $saved['customer']);
        $this->assertTrue($saved['marketingConsent']);
        $this->assertSame('success', $saved['consentSyncStatus']);
    }//end testAttachWritesCustomerAndConsent()
}//end class
