<?php

/**
 * Unit tests for ClientContactCardSync.
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
 *
 * @spec openspec/changes/example-set-zuiddrecht/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ClientContactCardSync;
use OCA\Pipelinq\Service\ContactObjectReader;
use OCA\Pipelinq\Service\ContactVcardPropertyBuilder;
use OCA\Pipelinq\Service\ContactVcardWriterService;
use OCP\Contacts\IManager as IContactsManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ClientContactCardSync.
 */
class ClientContactCardSyncTest extends TestCase {

	/**
	 * The acting user's addressbooks.
	 *
	 * @var IContactsManager&MockObject
	 */
	private IContactsManager&MockObject $contacts;

	/**
	 * The client reader.
	 *
	 * @var ContactObjectReader&MockObject
	 */
	private ContactObjectReader&MockObject $objects;

	/**
	 * The property builder.
	 *
	 * @var ContactVcardPropertyBuilder&MockObject
	 */
	private ContactVcardPropertyBuilder&MockObject $props;

	/**
	 * The card writer.
	 *
	 * @var ContactVcardWriterService&MockObject
	 */
	private ContactVcardWriterService&MockObject $writer;

	/**
	 * Build the collaborators with Contacts on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->contacts = $this->createMock(originalClassName: IContactsManager::class);
		$this->objects  = $this->createMock(originalClassName: ContactObjectReader::class);
		$this->props    = $this->createMock(originalClassName: ContactVcardPropertyBuilder::class);
		$this->writer   = $this->createMock(originalClassName: ContactVcardWriterService::class);

		$this->contacts->method('isEnabled')->willReturn(true);
		$this->props->method('buildProperties')->willReturnCallback(
			fn (array $objData, string $objectType): array => ['FN' => ($objData['name'] ?? '')]
		);
	}//end setUp()

	/**
	 * The sync under test.
	 *
	 * @return ClientContactCardSync
	 */
	private function sync(): ClientContactCardSync {
		return new ClientContactCardSync(
			contactsManager: $this->contacts,
			objects: $this->objects,
			propBuilder: $this->props,
			writer: $this->writer,
		);
	}//end sync()

	/**
	 * Each client with a uid gets its card under that uid; one without is skipped.
	 *
	 * @return void
	 */
	public function testWritesACardPerClientUnderItsOwnUid(): void {
		$this->objects->expects($this->once())->method('page')->with('client', 200, 0)->willReturn([
			['id' => 'c-1', 'name' => 'Bakkerij De Molen', 'contactsUid' => 'example-set-zuiddrecht-molen'],
			['id' => 'c-2', 'name' => 'Henk Bakker', 'contactsUid' => 'example-set-zuiddrecht-henk'],
			['id' => 'c-3', 'name' => 'Zonder kaart', 'contactsUid' => ''],
		]);
		$this->writer->expects($this->exactly(count: 2))->method('writeVcard')
			->willReturnCallback(fn (array $properties, ?string $existingUid=null): ?string => $existingUid);

		$report = $this->sync()->run();

		$written = [
			['id' => 'c-1', 'name' => 'Bakkerij De Molen', 'uid' => 'example-set-zuiddrecht-molen'],
			['id' => 'c-2', 'name' => 'Henk Bakker', 'uid' => 'example-set-zuiddrecht-henk'],
		];
		$this->assertSame(expected: $written, actual: $report['written']);
		$this->assertSame(expected: [['id' => 'c-3', 'name' => 'Zonder kaart']], actual: $report['skipped']);
		$this->assertSame(expected: [], actual: $report['failed']);
	}//end testWritesACardPerClientUnderItsOwnUid()

	/**
	 * The card is built from the client and written under the client's uid.
	 *
	 * @return void
	 */
	public function testPassesTheClientPropertiesAndUidToTheWriter(): void {
		$this->objects->method('page')->willReturn([['id' => 'c-1', 'name' => 'Henk Bakker', 'contactsUid' => 'uid-henk']]);
		$this->writer->expects($this->once())->method('writeVcard')
			->with(['FN' => 'Henk Bakker'], 'uid-henk')
			->willReturn('uid-henk');

		$this->assertCount(expectedCount: 1, haystack: $this->sync()->run()['written']);
	}//end testPassesTheClientPropertiesAndUidToTheWriter()

	/**
	 * A write that answers no uid, or another uid, is reported as failed.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsReported(): void {
		$this->objects->method('page')->willReturn([
			['id' => 'c-1', 'name' => 'A', 'contactsUid' => 'uid-a'],
			['id' => 'c-2', 'name' => 'B', 'contactsUid' => 'uid-b'],
		]);
		$this->writer->method('writeVcard')->willReturnOnConsecutiveCalls(null, 'something-else');

		$report = $this->sync()->run();

		$this->assertSame(expected: [], actual: $report['written']);
		$this->assertSame(expected: ['uid-a', 'uid-b'], actual: array_column($report['failed'], 'uid'));
	}//end testAFailedWriteIsReported()

	/**
	 * A dry run reports the cards and writes none.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothing(): void {
		$this->objects->method('page')->willReturn([['id' => 'c-1', 'name' => 'A', 'contactsUid' => 'uid-a']]);
		$this->writer->expects($this->never())->method('writeVcard');

		$this->assertSame(expected: 'uid-a', actual: $this->sync()->run(dryRun: true)['written'][0]['uid']);
	}//end testADryRunWritesNothing()

	/**
	 * A full page reads the next one; a short page ends the reading.
	 *
	 * @return void
	 */
	public function testReadsEveryPage(): void {
		$full = array_map(fn (int $i): array => ['id' => 'c-'.$i, 'name' => 'N', 'contactsUid' => ''], range(1, 200));
		$this->objects->expects($this->exactly(count: 2))->method('page')
			->willReturnOnConsecutiveCalls($full, [['id' => 'c-201', 'name' => 'N', 'contactsUid' => '']]);

		$this->assertCount(expectedCount: 201, haystack: $this->sync()->run()['skipped']);
	}//end testReadsEveryPage()

	/**
	 * Without Contacts the run refuses before reading a client.
	 *
	 * @return void
	 */
	public function testRefusesWithoutContacts(): void {
		$this->contacts = $this->createMock(originalClassName: IContactsManager::class);
		$this->contacts->method('isEnabled')->willReturn(false);
		$this->objects->expects($this->never())->method('page');

		$this->expectException(exception: RuntimeException::class);
		$this->sync()->run();
	}//end testRefusesWithoutContacts()

	/**
	 * Without a configured client schema the run refuses.
	 *
	 * @return void
	 */
	public function testRefusesWithoutAClientSchema(): void {
		$this->objects->method('page')->willReturn(null);

		$this->expectException(exception: RuntimeException::class);
		$this->sync()->run();
	}//end testRefusesWithoutAClientSchema()
}//end class
