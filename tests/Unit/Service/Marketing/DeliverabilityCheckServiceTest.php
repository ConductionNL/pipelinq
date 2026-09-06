<?php

/**
 * Unit tests for DeliverabilityCheckService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\DeliverabilityCheckService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DeliverabilityCheckService — DNS classification, caching, and
 * fail-soft behaviour on a DNS failure.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
 */
class DeliverabilityCheckServiceTest extends TestCase {
	private ContainerInterface $container;
	private IAppConfig $appConfig;
	private LoggerInterface $logger;
	private object $objectService;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->objectService = new class {
			/** @var array<string, array<string, mixed>> */
			public array $store = [];

			public function find(string $id, $register = null, $schema = null): ?array {
				return ($this->store[$id] ?? null);
			}//end find()

			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				$this->store[(string)$uuid] = $object;
				return $object;
			}//end saveObject()
		};

		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default) => match ($key) {
				'register' => 'pipelinq',
				default => $default,
			}
		);

		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);
	}//end setUp()

	/**
	 * A found `v=DMARC1` TXT record and a present DKIM selector record
	 * classify as `found` / verified, and the verdict is persisted.
	 *
	 * @return void
	 */
	public function testFoundRecordsClassifyAsFoundAndPersist(): void {
		$this->objectService->store['t-1'] = ['uuid' => 't-1', 'senderDomain' => 'example.test'];

		$service = new class ($this->container, $this->appConfig, $this->logger) extends DeliverabilityCheckService {
			protected function dnsGetRecord(string $hostname, int $type): array|false {
				if (str_starts_with($hostname, '_dmarc.') === true) {
					return [['txt' => 'v=DMARC1; p=reject;']];
				}

				return [['txt' => 'k=rsa; p=...']];
			}
		};

		$verdict = $service->checkTransportById('t-1');

		$this->assertSame('found', $verdict['dmarcStatus']);
		$this->assertTrue($verdict['dkimVerified']);
		$this->assertSame('found', $this->objectService->store['t-1']['dmarcStatus']);
		$this->assertNotSame('', $this->objectService->store['t-1']['deliverabilityCheckedAt']);
	}//end testFoundRecordsClassifyAsFoundAndPersist()

	/**
	 * No DMARC TXT record at all classifies as `missing`.
	 *
	 * @return void
	 */
	public function testNoDmarcRecordClassifiesAsMissing(): void {
		$this->objectService->store['t-2'] = ['uuid' => 't-2', 'senderDomain' => 'example.test'];

		$service = new class ($this->container, $this->appConfig, $this->logger) extends DeliverabilityCheckService {
			protected function dnsGetRecord(string $hostname, int $type): array|false {
				return [];
			}
		};

		$verdict = $service->checkTransportById('t-2');

		$this->assertSame('missing', $verdict['dmarcStatus']);
		$this->assertFalse($verdict['dkimVerified']);
	}//end testNoDmarcRecordClassifiesAsMissing()

	/**
	 * A DMARC TXT record present but not starting with `v=DMARC1`
	 * classifies as `invalid`.
	 *
	 * @return void
	 */
	public function testMalformedDmarcRecordClassifiesAsInvalid(): void {
		$this->objectService->store['t-3'] = ['uuid' => 't-3', 'senderDomain' => 'example.test'];

		$service = new class ($this->container, $this->appConfig, $this->logger) extends DeliverabilityCheckService {
			protected function dnsGetRecord(string $hostname, int $type): array|false {
				if (str_starts_with($hostname, '_dmarc.') === true) {
					return [['txt' => 'not-a-dmarc-record']];
				}

				return false;
			}
		};

		$verdict = $service->checkTransportById('t-3');

		$this->assertSame('invalid', $verdict['dmarcStatus']);
	}//end testMalformedDmarcRecordClassifiesAsInvalid()

	/**
	 * A DNS lookup that throws degrades to `unknown` rather than propagating.
	 *
	 * @return void
	 */
	public function testDnsFailureDegradesSoftToUnknown(): void {
		$this->objectService->store['t-4'] = ['uuid' => 't-4', 'senderDomain' => 'example.test'];

		$service = new class ($this->container, $this->appConfig, $this->logger) extends DeliverabilityCheckService {
			protected function dnsGetRecord(string $hostname, int $type): array|false {
				throw new \RuntimeException('DNS timeout');
			}
		};

		$verdict = $service->checkTransportById('t-4');

		$this->assertSame('unknown', $verdict['dmarcStatus']);
		$this->assertFalse($verdict['dkimVerified']);
	}//end testDnsFailureDegradesSoftToUnknown()

	/**
	 * A fresh cache (checked within the TTL) is returned without a new DNS
	 * lookup, unless `forceRefresh` is set.
	 *
	 * @return void
	 */
	public function testFreshCacheIsReturnedWithoutNewDnsLookup(): void {
		$this->objectService->store['t-5'] = [
			'uuid' => 't-5',
			'senderDomain' => 'example.test',
			'dkimVerified' => true,
			'dmarcStatus' => 'found',
			'deliverabilityCheckedAt' => gmdate('Y-m-d\TH:i:s\Z'),
		];

		$lookups = 0;
		$service = new class ($this->container, $this->appConfig, $this->logger, $lookups) extends DeliverabilityCheckService {
			public int $calls = 0;

			public function __construct($container, $appConfig, $logger, int $lookups) {
				parent::__construct($container, $appConfig, $logger);
			}

			protected function dnsGetRecord(string $hostname, int $type): array|false {
				$this->calls++;
				return [];
			}
		};

		$verdict = $service->checkTransportById('t-5');

		$this->assertSame('found', $verdict['dmarcStatus']);
		$this->assertSame(0, $service->calls, 'a fresh cache must not trigger a new DNS lookup');
	}//end testFreshCacheIsReturnedWithoutNewDnsLookup()

	/**
	 * A missing transport returns null.
	 *
	 * @return void
	 */
	public function testCheckTransportByIdReturnsNullWhenTransportMissing(): void {
		$service = new DeliverabilityCheckService($this->container, $this->appConfig, $this->logger);

		$this->assertNull($service->checkTransportById('does-not-exist'));
	}//end testCheckTransportByIdReturnsNullWhenTransportMissing()
}//end class
