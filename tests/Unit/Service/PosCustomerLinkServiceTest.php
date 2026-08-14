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
class PosCustomerLinkServiceTest extends TestCase {

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
	protected function setUp(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->objectService = $this->createMock(originalClassName: ObjectService::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		$this->service = new PosCustomerLinkService(
			container: $container,
			appConfig: $this->appConfig,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * Default-config app config reads: register, contact_schema,
	 * posTransaction_schema all populated; admin keys at defaults.
	 *
	 * @return void
	 */
	private function stubAppConfig(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					$values = [
						'register' => 'reg-1',
						'contact_schema' => 'contact',
						'posTransaction_schema' => 'posTransaction',
						'customerSearchFields' => 'name,email,phone',
						'customerHistoryDepth' => '10',
						'enablePipelinqSync' => 'true',
						'requireCustomerForOnAccount' => 'true',
					];

					return $values[$key] ?? $default;
				}
			);

	}//end stubAppConfig()

	/**
	 * SearchCustomers rejects queries shorter than 2 characters.
	 *
	 * @return void
	 */
	public function testSearchRejectsShortQuery(): void {
		$this->stubAppConfig();

		$this->expectException(exception: OCSBadRequestException::class);
		$this->service->searchCustomers(query: 'a');

	}//end testSearchRejectsShortQuery()

	/**
	 * SearchCustomers performs a case-insensitive substring match across the
	 * enabled search fields and returns decorated rows.
	 *
	 * @return void
	 */
	public function testSearchMatchesAcrossEnabledFields(): void {
		$this->stubAppConfig();
		$this->objectService->method('findAll')->willReturn(
			[
				['id' => 'c1', 'name' => 'Maria García',  'email' => 'maria@example.nl', 'phone' => '+31 6 1234 5678'],
				['id' => 'c2', 'name' => 'Henk de Vries', 'email' => 'henk@example.nl',  'phone' => '+31 6 8765 4321'],
				['id' => 'c3', 'name' => 'Lisa van Loon', 'email' => 'lisa@example.nl',  'phone' => '+31 6 1111 2222'],
			]
		);

		$results = $this->service->searchCustomers(query: 'maria');

		$this->assertCount(expectedCount: 1, haystack: $results);
		$this->assertSame(expected: 'c1', actual: $results[0]['id']);
		$this->assertSame(expected: 'Maria García', actual: $results[0]['name']);
		$this->assertFalse(condition: $results[0]['doNotContact']);

	}//end testSearchMatchesAcrossEnabledFields()

	/**
	 * SearchCustomers decorates contacts with the doNotContact badge so the
	 * lookup modal can surface the privacy flag (REQ-PCL-007).
	 *
	 * @return void
	 */
	public function testSearchSurfacesDoNotContactFlag(): void {
		$this->stubAppConfig();
		$this->objectService->method('findAll')->willReturn(
			[
				['id' => 'c1', 'name' => 'Anonymous AB', 'email' => 'a@b.c', 'doNotContact' => true],
			]
		);

		$results = $this->service->searchCustomers(query: 'anon');

		$this->assertTrue(condition: $results[0]['doNotContact']);
		$this->assertSame(expected: 'Niet benaderen', actual: $results[0]['doNotContactBadge']);

	}//end testSearchSurfacesDoNotContactFlag()

	/**
	 * AssertOnAccountHasCustomer raises when on-account is set without a customer.
	 *
	 * @return void
	 */
	public function testOnAccountRequiresCustomer(): void {
		$this->expectException(exception: OCSBadRequestException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/op rekening/');

		$this->service->assertOnAccountHasCustomer(
			transaction: [
				'tenderType' => 'onAccount',
				'customer' => '',
			]
		);

	}//end testOnAccountRequiresCustomer()

	/**
	 * AssertOnAccountHasCustomer respects the admin toggle: when
	 * requireCustomerForOnAccount is 'false', missing customer is allowed.
	 *
	 * @return void
	 */
	public function testOnAccountInvariantDisabledByAdminToggle(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					if ($key === 'requireCustomerForOnAccount') {
						return 'false';
					}

					return $default;
				}
			);

		// Should NOT throw.
		$this->service->assertOnAccountHasCustomer(
			transaction: [
				'tenderType' => 'onAccount',
				'customer' => '',
			]
		);

		$this->addToAssertionCount(count: 1);

	}//end testOnAccountInvariantDisabledByAdminToggle()

	/**
	 * AssertOnAccountHasCustomer is a no-op when the customer is set or the
	 * tender is not on-account.
	 *
	 * @return void
	 */
	public function testOnAccountWithCustomerPasses(): void {
		$this->service->assertOnAccountHasCustomer(
			transaction: [
				'tenderType' => 'onAccount',
				'customer' => 'c-uuid',
			]
		);

		$this->service->assertOnAccountHasCustomer(
			transaction: [
				'tenderType' => 'cash',
				'customer' => '',
			]
		);

		$this->addToAssertionCount(count: 2);

	}//end testOnAccountWithCustomerPasses()

	/**
	 * SyncConsent returns 'skipped' (and does not write) when the contact
	 * carries the doNotContact flag (REQ-PCL-007 Scenario 2).
	 *
	 * @return void
	 */
	public function testSyncConsentSkippedForDoNotContact(): void {
		$this->stubAppConfig();
		$this->objectService->expects($this->never())->method('saveObject');

		$status = $this->service->syncConsent(
			contact: [
				'id' => 'c1',
				'doNotContact' => true,
			],
			consent: true
		);

		$this->assertSame(expected: 'skipped', actual: $status);

	}//end testSyncConsentSkippedForDoNotContact()

	/**
	 * SyncConsent writes the consent to the contact via OR ObjectService and
	 * returns 'success' on a clean write.
	 *
	 * @return void
	 */
	public function testSyncConsentWritesContact(): void {
		$this->stubAppConfig();
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturn(['id' => 'c1', 'marketingConsent' => true]);

		$status = $this->service->syncConsent(
			contact: [
				'id' => 'c1',
				'name' => 'Maria',
				'doNotContact' => false,
			],
			consent: true
		);

		$this->assertSame(expected: 'success', actual: $status);

	}//end testSyncConsentWritesContact()

	/**
	 * SyncConsent catches OR throwables and returns 'failed' (POS write is
	 * authoritative; the contact-side error must never block the POS save).
	 *
	 * @return void
	 */
	public function testSyncConsentReturnsFailedOnError(): void {
		$this->stubAppConfig();
		$this->objectService->method('saveObject')->willThrowException(new \RuntimeException('boom'));

		$status = $this->service->syncConsent(
			contact: [
				'id' => 'c1',
				'doNotContact' => false,
			],
			consent: true
		);

		$this->assertSame(expected: 'failed', actual: $status);

	}//end testSyncConsentReturnsFailedOnError()

	/**
	 * GetCustomer raises a 404 when the contact does not exist.
	 *
	 * @return void
	 */
	public function testGetCustomerNotFoundRaises404(): void {
		$this->stubAppConfig();
		$this->objectService->method('find')->willReturn(null);

		$this->expectException(exception: OCSNotFoundException::class);
		$this->service->getCustomer(contactUuid: 'missing-uuid');

	}//end testGetCustomerNotFoundRaises404()

	/**
	 * EnabledSearchFields defaults to the full set when the config string is
	 * empty or invalid.
	 *
	 * @return void
	 */
	public function testEnabledSearchFieldsDefaultsToAll(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					if ($key === 'customerSearchFields') {
						return '';
					}

					return $default;
				}
			);

		$fields = $this->service->enabledSearchFields();

		$this->assertSame(expected: ['name', 'email', 'phone'], actual: $fields);

	}//end testEnabledSearchFieldsDefaultsToAll()

	/**
	 * EnabledSearchFields filters out unknown values and respects the admin
	 * subset.
	 *
	 * @return void
	 */
	public function testEnabledSearchFieldsFiltersUnknownValues(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					if ($key === 'customerSearchFields') {
						return 'email,bogus,phone';
					}

					return $default;
				}
			);

		$fields = $this->service->enabledSearchFields();

		$this->assertSame(expected: ['email', 'phone'], actual: $fields);

	}//end testEnabledSearchFieldsFiltersUnknownValues()

	/**
	 * HistoryDepth defaults to 10 on empty / non-numeric values and caps at 50.
	 *
	 * @return void
	 */
	public function testHistoryDepthBounds(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					if ($key === 'customerHistoryDepth') {
						return '999';
					}

					return $default;
				}
			);

		$this->assertSame(expected: 50, actual: $this->service->historyDepth());

	}//end testHistoryDepthBounds()

	/**
	 * IsSyncEnabled defaults to true; 'false' string disables.
	 *
	 * @return void
	 */
	public function testSyncEnabledTogglesOnFalseString(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					if ($key === 'enablePipelinqSync') {
						return 'false';
					}

					return $default;
				}
			);

		$this->assertFalse(condition: $this->service->isSyncEnabled());

	}//end testSyncEnabledTogglesOnFalseString()

	/**
	 * RequiresCustomerForOnAccount defaults to true.
	 *
	 * @return void
	 */
	public function testRequiresCustomerForOnAccountDefault(): void {
		$this->appConfig->method('getValueString')->willReturnArgument(2);

		$this->assertTrue(condition: $this->service->requiresCustomerForOnAccount());

	}//end testRequiresCustomerForOnAccountDefault()

	/**
	 * GetCustomerHistory excludes drafts / parked carts and sorts descending
	 * by createdAt (most recent first).
	 *
	 * @return void
	 */
	public function testGetCustomerHistoryFiltersDraftsAndSorts(): void {
		$this->stubAppConfig();
		$this->objectService->method('findAll')->willReturn(
			[
				['id' => 't1', 'status' => 'confirmed', 'confirmedAt' => '2026-05-01T10:00:00Z', 'total' => 10],
				['id' => 't2', 'status' => 'draft',     'confirmedAt' => '2026-05-02T10:00:00Z', 'total' => 99],
				['id' => 't3', 'status' => 'settled',   'confirmedAt' => '2026-05-03T10:00:00Z', 'total' => 30],
				['id' => 't4', 'status' => 'parked',    'confirmedAt' => '2026-05-04T10:00:00Z', 'total' => 55],
				['id' => 't5', 'status' => 'refunded',  'confirmedAt' => '2026-05-02T10:00:00Z', 'total' => 20],
			]
		);

		$history = $this->service->getCustomerHistory(contactUuid: 'c1', limit: 10);

		$this->assertCount(expectedCount: 3, haystack: $history);
		// DESC by createdAt: t3 (2026-05-03) > t5 (2026-05-02) > t1 (2026-05-01).
		$this->assertSame(expected: 't3', actual: $history[0]['id']);
		$this->assertSame(expected: 't5', actual: $history[1]['id']);
		$this->assertSame(expected: 't1', actual: $history[2]['id']);

	}//end testGetCustomerHistoryFiltersDraftsAndSorts()

	/**
	 * DetachCustomer refuses to mutate a closed (settled / confirmed) transaction.
	 *
	 * @return void
	 */
	public function testDetachRefusesClosedTransaction(): void {
		$this->stubAppConfig();
		$this->objectService->method('find')->willReturn(['id' => 't1', 'status' => 'settled']);

		$this->expectException(exception: OCSBadRequestException::class);
		$this->service->detachCustomer(transactionId: 't1');

	}//end testDetachRefusesClosedTransaction()

	/**
	 * AttachCustomer refuses to attach when the transaction is no longer mutable.
	 *
	 * @return void
	 */
	public function testAttachRefusesClosedTransaction(): void {
		$this->stubAppConfig();
		$this->objectService->method('find')->willReturnCallback(
			static function (string $id, string $register = '', string $schema = '') {
				if ($id === 'c1') {
					return ['id' => 'c1', 'name' => 'Maria', 'doNotContact' => false];
				}

				return ['id' => 't1', 'status' => 'settled'];
			}
		);

		$this->expectException(exception: OCSBadRequestException::class);
		$this->service->attachCustomer(transactionId: 't1', contactUuid: 'c1');

	}//end testAttachRefusesClosedTransaction()

	/**
	 * AttachCustomer writes customer + marketingConsent + consentSyncStatus
	 * on a draft transaction.
	 *
	 * @return void
	 */
	public function testAttachWritesCustomerAndConsent(): void {
		$this->stubAppConfig();
		$this->objectService->method('find')->willReturnCallback(
			static function (string $id, string $register = '', string $schema = '') {
				if ($id === 'c1') {
					return ['id' => 'c1', 'name' => 'Maria', 'doNotContact' => false];
				}

				return ['id' => 't1', 'status' => 'draft'];
			}
		);
		$this->objectService->expects($this->atLeastOnce())
			->method('saveObject')
			->willReturnCallback(
				static function (array $object) {
					return $object;
				}
			);

		$saved = $this->service->attachCustomer(
			transactionId: 't1',
			contactUuid: 'c1',
			marketingConsent: true
		);

		$this->assertSame(expected: 'c1', actual: $saved['customer']);
		$this->assertTrue(condition: $saved['marketingConsent']);
		$this->assertSame(expected: 'success', actual: $saved['consentSyncStatus']);

	}//end testAttachWritesCustomerAndConsent()
}//end class
