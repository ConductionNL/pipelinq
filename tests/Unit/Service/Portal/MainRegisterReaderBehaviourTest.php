<?php

/**
 * MainRegisterReader: configuration guards, failure handling and payload
 * normalisation for the portal's reads and writes in the main register.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An unconfigured register or schema answers empty on reads and refuses on
 * writes without asking OpenRegister; a failing OpenRegister call answers
 * empty on reads and refuses generically on writes.
 *
 * @covers \OCA\Pipelinq\Service\Portal\MainRegisterReader
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class MainRegisterReaderBehaviourTest extends TestCase {

	/**
	 * App config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The OpenRegister object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = [
			'register' => '7',
			'ticket_schema' => '42',
			'portalAccount_schema' => '43',
		];
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The reader over the in-memory config.
	 *
	 * @return MainRegisterReader The reader.
	 */
	private function reader(): MainRegisterReader {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		return new MainRegisterReader($appConfig, $this->logger, $this->objectService);
	}//end reader()

	/**
	 * A schema is available only when both the register and the schema are
	 * configured; pinned slugs read their pinned key.
	 *
	 * @return void
	 */
	public function testHasSchemaNeedsBothRegisterAndSchema(): void {
		$reader = $this->reader();
		$this->assertTrue($reader->hasSchema('ticket'));
		$this->assertTrue($reader->hasSchema('crmPortalAccount'));
		$this->assertFalse($reader->hasSchema('nothing'));

		unset($this->config['register']);
		$this->assertFalse($this->reader()->hasSchema('ticket'));
	}//end testHasSchemaNeedsBothRegisterAndSchema()

	/**
	 * Reads of an unconfigured schema answer empty and ask nothing.
	 *
	 * @return void
	 */
	public function testReadsOfAnUnconfiguredSchemaAskNothing(): void {
		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('find');

		$reader = $this->reader();
		$this->assertSame([], $reader->findAll('nothing', ['x' => 'y']));
		$this->assertNull($reader->find('nothing', 't-1'));
		$this->assertNull($reader->findAsCaller('nothing', 't-1'));
		$this->assertNull($reader->find('ticket', ''));
		$this->assertNull($reader->findAsCaller('ticket', ''));
	}//end testReadsOfAnUnconfiguredSchemaAskNothing()

	/**
	 * findAll pins the register and schema, merges the filters and turns
	 * entities and arrays into arrays.
	 *
	 * @return void
	 */
	public function testFindAllNormalisesEveryRow(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 't-1']);

		$this->objectService->expects($this->once())
			->method('findAll')
			->with(['filters' => ['register' => '7', 'schema' => '42', 'portalSubject' => 's-1']], false, false)
			->willReturn([$entity, ['id' => 't-2']]);

		$this->assertSame(
			[['id' => 't-1'], ['id' => 't-2']],
			$this->reader()->findAll('ticket', ['portalSubject' => 's-1'])
		);
	}//end testFindAllNormalisesEveryRow()

	/**
	 * A failing findAll answers empty and warns with the schema key.
	 *
	 * @return void
	 */
	public function testAFailingFindAllAnswersEmptyAndWarns(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Pipelinq portal: main-register findAll failed',
				['schemaKey' => 'ticket', 'exception' => 'db down']
			);

		$this->assertSame([], $this->reader()->findAll('ticket'));
	}//end testAFailingFindAllAnswersEmptyAndWarns()

	/**
	 * A find that throws answers null; a found entity answers its payload.
	 *
	 * @return void
	 */
	public function testFindSwallowsFailuresAndNormalisesTheEntity(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 't-1', 'title' => 'Vraag']);

		$this->objectService->method('find')->willReturnCallback(
			static function (string $id) use ($entity): ?ObjectEntityInterface {
				if ($id === 'boom') {
					throw new RuntimeException('db down');
				}

				return ($id === 'missing') ? null : $entity;
			}
		);

		$reader = $this->reader();
		$this->assertNull($reader->find('ticket', 'boom'));
		$this->assertNull($reader->find('ticket', 'missing'));
		$this->assertSame(['id' => 't-1', 'title' => 'Vraag'], $reader->find('ticket', 't-1'));
	}//end testFindSwallowsFailuresAndNormalisesTheEntity()

	/**
	 * An employee's read answers the object when it is visible to them, and
	 * null when it is not or when the lookup fails.
	 *
	 * @return void
	 */
	public function testFindAsCallerAnswersTheObjectOrNullOnFailure(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 't-1']);

		$this->objectService->expects($this->exactly(3))
			->method('find')
			->willReturnCallback(
				static function (string $id, ?array $extend, bool $files, string $register, string $schema) use ($entity): ?ObjectEntityInterface {
					if ($id === 'boom') {
						throw new RuntimeException('forbidden');
					}

					return ($id !== 'other-organisation' && $register === '7' && $schema === '42') ? $entity : null;
				}
			);

		$reader = $this->reader();
		$this->assertSame(['id' => 't-1'], $reader->findAsCaller('ticket', 't-1'));
		$this->assertNull($reader->findAsCaller('ticket', 'boom'));
		$this->assertNull($reader->findAsCaller('ticket', 'other-organisation'));
	}//end testFindAsCallerAnswersTheObjectOrNullOnFailure()

	/**
	 * A save into an unconfigured schema refuses, names the key, and writes
	 * nothing.
	 *
	 * @return void
	 */
	public function testASaveIntoAnUnconfiguredSchemaRefuses(): void {
		$this->objectService->expects($this->never())->method('saveObject');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Main register schema 'nothing' is not configured.");

		$this->reader()->save('nothing', ['title' => 'Vraag']);
	}//end testASaveIntoAnUnconfiguredSchemaRefuses()

	/**
	 * A save drops a client-supplied @self and passes the update id on.
	 *
	 * @return void
	 */
	public function testASaveDropsSelfAndPassesTheId(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 't-1', 'title' => 'Nieuw']);

		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(['title' => 'Nieuw'], [], '7', '42', 't-1', false, false)
			->willReturn($entity);

		$this->assertSame(
			['id' => 't-1', 'title' => 'Nieuw'],
			$this->reader()->save('ticket', ['title' => 'Nieuw', '@self' => ['owner' => 'someone']], 't-1')
		);
	}//end testASaveDropsSelfAndPassesTheId()

	/**
	 * A failing save logs the cause and refuses with a generic message.
	 *
	 * @return void
	 */
	public function testAFailingSaveRefusesGenerically(): void {
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('constraint violated'));
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Pipelinq portal: main-register save failed',
				['schemaKey' => 'ticket', 'exception' => 'constraint violated']
			);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to persist object.');
		$this->reader()->save('ticket', ['title' => 'Vraag']);
	}//end testAFailingSaveRefusesGenerically()
}//end class
