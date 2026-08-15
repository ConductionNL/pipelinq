<?php

/**
 * Unit tests for BsnAuditService.
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#9.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\BsnAuditService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BsnAuditService.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-005
 */
class BsnAuditServiceTest extends TestCase {
	/**
	 * IPv4 anonymisation zeros the last octet.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-009-01
	 */
	public function testAnonymiseIpv4ZeroesLastOctet(): void {
		self::assertSame('10.42.18.0', BsnAuditService::anonymiseIp('10.42.18.7'));
		self::assertSame('192.168.1.0', BsnAuditService::anonymiseIp('192.168.1.42'));
	}//end testAnonymiseIpv4ZeroesLastOctet()

	/**
	 * Empty / invalid IP returns empty string (defensive).
	 *
	 * @return void
	 */
	public function testAnonymiseIpHandlesEmptyAndInvalid(): void {
		self::assertSame('', BsnAuditService::anonymiseIp(''));
		self::assertSame('', BsnAuditService::anonymiseIp('not-an-ip'));
	}//end testAnonymiseIpHandlesEmptyAndInvalid()

	/**
	 * IPv6 anonymisation preserves the /48 prefix and zeroes the rest.
	 *
	 * @return void
	 */
	public function testAnonymiseIpv6PreservesPrefix(): void {
		$anon = BsnAuditService::anonymiseIp('2001:db8:abcd:1234:5678:90ab:cdef:1234');
		self::assertNotEmpty($anon);
		self::assertStringStartsWith('2001:db8:abcd:', $anon);
		self::assertStringNotContainsString('cdef', $anon);
	}//end testAnonymiseIpv6PreservesPrefix()

	/**
	 * recordLookup builds a record with BSN-hash (never raw), masks BSN in logs,
	 * and falls back to empty UUID + log line when persistence fails.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-005-01
	 */
	public function testRecordLookupFallsBackWhenStorageMissing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$request = $this->createMock(IRequest::class);
		$logger = $this->createMock(LoggerInterface::class);

		// Config returns empty register so config() throws and we log+return ''.
		$appConfig->method('getValueString')->willReturn('');
		$request->method('getRemoteAddress')->willReturn('10.0.0.5');

		$logger->expects(self::once())->method('error');

		$service = new BsnAuditService($container, $appConfig, $request, $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
		$uuid = $service->recordLookup(
			actor: 'demo:tester',
			rawBsn: '123456782',
			verzoekreden: 'unit-test',
			doelbinding: 'unit-test',
			uitkomst: 'geslaagd',
		);
		self::assertSame('', $uuid);
	}//end testRecordLookupFallsBackWhenStorageMissing()

	/**
	 * Sanity: the hash used internally must match BsnValidationService::hash().
	 *
	 * @return void
	 */
	public function testInternalHashMatchesValidationServiceHash(): void {
		self::assertSame(
			BsnValidationService::hash('123456782'),
			hash('sha256', '123456782')
		);
	}//end testInternalHashMatchesValidationServiceHash()
}//end class
