<?php

/**
 * DeliverabilityCheckService: loading, persisting and degrading edges.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
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

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\DeliverabilityCheckService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What the check does when the transport, OpenRegister or the save is not
 * there, and which id and slugs it writes back under.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\DeliverabilityCheckService
 */
class DeliverabilityCheckServiceEdgesTest extends TestCase {

	/**
	 * A recording stand-in for OpenRegister's ObjectService.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * App-config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Whether the container fails to resolve the ObjectService.
	 *
	 * @var boolean
	 */
	private bool $unavailable = false;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objects = new class {
			/** @var mixed What find() hands back, or a Throwable to throw. */
			public mixed $found = null;

			/** @var \Throwable|null Thrown by saveObject() when set. */
			public ?\Throwable $saveFails = null;

			/** @var array<int, array<int, mixed>> The calls seen. */
			public array $calls = [];

			public function find(string $id, $register = null, $schema = null): mixed {
				$this->calls[] = ['find', $id, $register, $schema];
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}//end find()

			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				$this->calls[] = ['save', $object, $register, $schema, $uuid];
				if ($this->saveFails !== null) {
					throw $this->saveFails;
				}

				return $object;
			}//end saveObject()
		};
	}//end setUp()

	/**
	 * The service under test, with DNS answering a valid DMARC record and a DKIM key.
	 *
	 * @return DeliverabilityCheckService
	 */
	private function service(): DeliverabilityCheckService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($this->unavailable === true) {
					throw new RuntimeException('no openregister');
				}

				return $this->objects;
			}
		);

		return new class ($container, $appConfig, $this->logger) extends DeliverabilityCheckService {
			/**
			 * Answer every lookup with a valid record.
			 *
			 * @param string  $hostname The host.
			 * @param integer $type     The record type.
			 *
			 * @return array<int, array<string, string>>
			 */
			protected function dnsGetRecord(string $hostname, int $type): array|false {
				return [['txt' => 'v=DMARC1; p=reject']];
			}//end dnsGetRecord()
		};
	}//end service()

	/**
	 * An empty id never reaches OpenRegister.
	 *
	 * @return void
	 */
	public function testAnEmptyIdIsNotLookedUp(): void {
		$this->assertNull($this->service()->checkTransportById(id: ''));
		$this->assertSame([], $this->objects->calls);
	}//end testAnEmptyIdIsNotLookedUp()

	/**
	 * Without OpenRegister the lookup is null and the failure is logged.
	 *
	 * @return void
	 */
	public function testUnavailableOpenRegisterYieldsNull(): void {
		$this->unavailable = true;
		$this->logger->expects($this->once())->method('warning')->with(
			'DeliverabilityCheckService.getObjectService: unavailable',
			['exception' => 'no openregister']
		);

		$this->assertNull($this->service()->checkTransportById(id: 't-1'));
	}//end testUnavailableOpenRegisterYieldsNull()

	/**
	 * A throwing find is logged at info and yields null; the default
	 * register and schema slugs are used when nothing is configured.
	 *
	 * @return void
	 */
	public function testAThrowingFindYieldsNull(): void {
		$this->objects->found = new RuntimeException('missing');
		$this->logger->expects($this->once())->method('info')->with(
			'DeliverabilityCheckService.loadOne: not found',
			['id' => 't-1', 'exception' => 'missing']
		);

		$this->assertNull($this->service()->checkTransportById(id: 't-1'));
		$this->assertSame([['find', 't-1', 'pipelinq', 'mailTransport']], $this->objects->calls);
	}//end testAThrowingFindYieldsNull()

	/**
	 * An entity is read through jsonSerialize; the verdict is saved under
	 * the configured register and schema, keyed by the @self uuid.
	 *
	 * @return void
	 */
	public function testASerialisableEntityIsCheckedAndSavedUnderItsSelfId(): void {
		$this->config = ['register' => 'crm', 'mailTransport_schema' => 'transport'];
		$this->objects->found = new class implements \JsonSerializable {
			/**
			 * The serialised transport.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['senderDomain' => 'example.org', 'id' => '', '@self' => ['uuid' => '', 'id' => 'self-7']];
			}//end jsonSerialize()
		};

		$verdict = $this->service()->checkTransportById(id: 't-7');

		$this->assertTrue($verdict['dkimVerified']);
		$this->assertSame('found', $verdict['dmarcStatus']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $verdict['checkedAt']);

		$save = $this->objects->calls[1];
		$this->assertSame(['save', 'crm', 'transport', 'self-7'], [$save[0], $save[2], $save[3], $save[4]]);
		$this->assertSame(true, $save[1]['dkimVerified']);
		$this->assertSame('found', $save[1]['dmarcStatus']);
		$this->assertSame($verdict['checkedAt'], $save[1]['deliverabilityCheckedAt']);
		$this->assertSame('example.org', $save[1]['senderDomain']);
	}//end testASerialisableEntityIsCheckedAndSavedUnderItsSelfId()

	/**
	 * A found value that is neither an array nor serialisable is no transport.
	 *
	 * @return void
	 */
	public function testAnUnreadableEntityYieldsNull(): void {
		$this->objects->found = new \stdClass();

		$this->assertNull($this->service()->checkTransportById(id: 't-1'));
		$this->assertCount(1, $this->objects->calls);
	}//end testAnUnreadableEntityYieldsNull()

	/**
	 * Without a sender domain nothing is looked up or saved.
	 *
	 * @return void
	 */
	public function testNoSenderDomainIsUnknown(): void {
		$this->assertSame(
			['dkimVerified' => false, 'dmarcStatus' => 'unknown', 'checkedAt' => ''],
			$this->service()->checkTransport(transport: ['uuid' => 't-1', 'senderDomain' => ''])
		);
		$this->assertSame([], $this->objects->calls);
	}//end testNoSenderDomainIsUnknown()

	/**
	 * An unparseable checkedAt is not a fresh cache; a transport without any
	 * id is checked but not saved.
	 *
	 * @return void
	 */
	public function testAnUnparseableTimestampIsRecheckedAndAnIdlessTransportNotSaved(): void {
		$verdict = $this->service()->checkTransport(
			transport: ['senderDomain' => 'example.org', 'deliverabilityCheckedAt' => 'yesterday-ish', 'dmarcStatus' => 'missing', '@self' => 'x']
		);

		$this->assertSame('found', $verdict['dmarcStatus']);
		$this->assertSame([], $this->objects->calls);
	}//end testAnUnparseableTimestampIsRecheckedAndAnIdlessTransportNotSaved()

	/**
	 * A forced refresh ignores a fresh cache; without OpenRegister the
	 * verdict is still returned, only not saved.
	 *
	 * @return void
	 */
	public function testAForcedRefreshWithoutOpenRegisterStillAnswers(): void {
		$this->unavailable = true;
		$this->logger->expects($this->once())->method('warning');
		$fresh = gmdate('Y-m-d\TH:i:s\Z', (time() - 60));

		$verdict = $this->service()->checkTransport(
			transport: ['slug' => 't-1', 'senderDomain' => 'example.org', 'deliverabilityCheckedAt' => $fresh, 'dmarcStatus' => 'missing'],
			forceRefresh: true
		);

		$this->assertSame('found', $verdict['dmarcStatus']);
		$this->assertTrue($verdict['dkimVerified']);
	}//end testAForcedRefreshWithoutOpenRegisterStillAnswers()

	/**
	 * A failed save is logged and the verdict still comes back.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsLogged(): void {
		$this->objects->saveFails = new RuntimeException('read only');
		$this->logger->expects($this->once())->method('warning')->with(
			'DeliverabilityCheckService.persistVerdict: save failed',
			['id' => 't-1', 'exception' => 'read only']
		);

		$verdict = $this->service()->checkTransport(transport: ['uuid' => 't-1', 'senderDomain' => 'example.org']);

		$this->assertSame('found', $verdict['dmarcStatus']);
		$this->assertSame('t-1', $this->objects->calls[0][4]);
	}//end testAFailedSaveIsLogged()
}//end class
