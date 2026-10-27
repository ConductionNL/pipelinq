<?php

/**
 * Unit tests for ContactSyncSettingsService.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ContactSyncService;
use OCA\Pipelinq\Service\ContactSyncSettingsService;
use OCA\Pipelinq\Service\ContactVcardWriterService;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The contacts section's settings, status and full run.
 *
 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
 */
class ContactSyncSettingsServiceTest extends TestCase {
	/**
	 * Stored user values, keyed by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * A service over the given records and write-back results.
	 *
	 * @param array<int, array<string, mixed>> $records The clients OpenRegister returns.
	 * @param array<string, string|null>       $written Object id to the contacts UID the write-back answers.
	 *
	 * @return ContactSyncSettingsService The service.
	 */
	private function service(array $records = [], array $written = []): ContactSyncSettingsService {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $userId, string $appName, string $key, mixed $default = '') => ($this->stored[$key] ?? $default)
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $userId, string $appName, string $key, string $value): void {
				$this->stored[$key] = $value;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => match ($key) {
				'register' => '5',
				'client_schema' => '7',
				default => '',
			}
		);
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('isEnabled')->willReturn(true);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn($records);
		$sync = $this->createMock(ContactSyncService::class);
		$sync->method('syncToContacts')->willReturnCallback(
			fn (string $objectType, string $objectId) => ($written[$objectId] ?? null)
		);
		$writer = $this->createConfiguredMock(ContactVcardWriterService::class, ['newCardAddressBookName' => 'Pipelinq CRM']);

		return new ContactSyncSettingsService(
			$config,
			$appConfig,
			$contacts,
			$sync,
			$writer,
			$objects,
			$this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * Write-back is on until the user turns it off.
	 *
	 * @return void
	 */
	public function testWriteBackIsOnUntilTurnedOff(): void {
		$service = $this->service();
		$this->assertTrue($service->writesBack(userId: 'pieter'));
		$service->saveWriteBack(userId: 'pieter', writeBack: false);
		$this->assertFalse($service->writesBack(userId: 'pieter'));
	}//end testWriteBackIsOnUntilTurnedOff()

	/**
	 * The status counts linked records only and names the addressbook.
	 *
	 * @return void
	 */
	public function testTheStatusCountsLinkedRecordsAndNamesTheAddressbook(): void {
		$status = $this->service(
			records: [
				['id' => 'a', 'name' => 'Sanne de Vries', 'contactsUid' => 'uid-a'],
				['id' => 'b', 'name' => 'Not linked'],
			]
		)->status(userId: 'pieter');

		$this->assertSame(1, $status['linked']);
		$this->assertSame('Pipelinq CRM', $status['addressBook']);
		$this->assertTrue($status['writeBack']);
		$this->assertNull($status['lastRun']);
	}//end testTheStatusCountsLinkedRecordsAndNamesTheAddressbook()

	/**
	 * A run writes every linked record, counts the cards, names the failures
	 * and is remembered for the status.
	 *
	 * @return void
	 */
	public function testARunWritesEveryLinkedRecordAndIsRemembered(): void {
		$service = $this->service(
			records: [
				['id' => 'a', 'name' => 'Sanne de Vries', 'contactsUid' => 'uid-a'],
				['id' => 'b', 'name' => 'Bakkerij De Graaf', 'contactsUid' => 'uid-b'],
			],
			written: ['a' => 'uid-a', 'b' => null]
		);

		$run = $service->run(userId: 'pieter', now: '2026-10-07T09:12:00+02:00');

		$this->assertSame(1, $run['updated']);
		$this->assertSame([['name' => 'Bakkerij De Graaf']], $run['failed']);
		$this->assertSame($run, $service->status(userId: 'pieter')['lastRun']);
	}//end testARunWritesEveryLinkedRecordAndIsRemembered()
}//end class
