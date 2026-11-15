<?php

/**
 * Characterisation test for PipelinqEvidenceSourceProvider::harvest().
 *
 * Pins, for a DSAR case, every register read (filters, limit, order), the
 * evidence items in order with their payloads, and the warning when a schema
 * read fails while the other sources are still harvested.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-016-pipelinq-evidence-source-registration
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Mcp\McpAnswer;
use OCA\Pipelinq\Service\PipelinqEvidenceSourceProvider;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The harvest, read by read and item by item.
 */
class EvidenceHarvestCharacterisationTest extends TestCase {
	/**
	 * Every read, item and log line of one harvest.
	 *
	 * @return void
	 */
	public function testTheHarvestReadsEverySourceAndCollectsTheSubjectsObjects(): void {
		$ids = ['register' => '11', 'client_schema' => '21', 'contact_schema' => '22', 'lead_schema' => '23', 'ticket_schema' => '26'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $ids[$key] ?? $default);
		$store = [
			'21' => [['id' => 's-1'], ['uuid' => 'x']],
			'22' => [['uuid' => 'c-1', 'contactsUid' => 's-1'], 'not a row', ['id' => 'c-2', 'client' => 's-1']],
			'26' => [['id' => 'r-1', 'ticketType' => 'request', 'contact' => 's-1'], ['id' => 'm-1', 'ticketType' => 'interaction', 'client' => 's-1']],
		];
		$reads = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use (&$reads, $store): array {
				$reads[] = $config;
				$filters = $config['filters'];
				if ($filters['schema'] === '23') {
					throw new RuntimeException('lead table gone');
				}

				$rows = ($store[$filters['schema']] ?? []);
				if (isset($filters['ticketType']) === false) {
					return $rows;
				}

				return array_values(array_filter($rows, static fn (array $r): bool => $r['ticketType'] === $filters['ticketType']));
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$logs = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$logs, $level): void {
					$logs[] = [$level, $message, $context];
				}
			);
		}

		$provider = new PipelinqEvidenceSourceProvider(
			appConfig: $appConfig,
			container: $container,
			logger: $logger,
			ticketService: new TicketService(appConfig: $appConfig, logger: new NullLogger(), objectService: $objects, mcp: new McpAnswer(new NullLogger())),
		);
		$items = $provider->harvest('case-7', ['subjectId' => 's-1']);

		$filters = array_map(static fn (array $c): array => $c['filters'], $reads);
		self::assertSame([5000], array_values(array_unique(array_map(static fn (array $c): int => $c['limit'], $reads))));
		self::assertSame(['register', 'schema'], array_keys($filters[0]));
		self::assertSame(['11'], array_values(array_unique(array_column($filters, 'register'))));
		$payloads = array_map(static fn ($i): array => $i->getPayload(), $items);
		self::assertSame(['s-1', 'c-1', 'c-2', 'r-1', 'm-1'], array_column($payloads, 'objectId'));
		self::assertSame(['case-7'], array_values(array_unique(array_column($payloads, 'caseUuid'))));
		self::assertSame(
			array_map(static fn (array $p): string => 'sha256:' . hash('sha256', $p['schema'] . ':' . $p['objectId']), $payloads),
			array_map(static fn ($i): string => $i->getContentHash(), $items)
		);
		self::assertContains(['warning', 'Pipelinq DSAR evidence harvest: schema read failed', ['schema' => '23', 'exception' => 'lead table gone']], $logs);
		self::assertSame(['info', 'Pipelinq DSAR evidence harvest complete', ['case' => 'case-7', 'items' => 5]], $logs[count($logs) - 1]);
		self::assertSame(array_column($payloads, 'schema'), array_map(static fn ($i): string => $i->getPayload()['schema'], $items));
		$schemasRead = array_map(static fn (array $f): string => $f['schema'] . (isset($f['ticketType']) === true ? ':' . $f['ticketType'] : ''), $filters);
		self::assertSame(['21', '22', '23', '26:request', '26:interaction'], $schemasRead);
		self::assertSame(['client', 'contact', 'contact', 'ticket_request', 'ticket_contactmoment'], array_column($payloads, 'schema'));
	}//end testTheHarvestReadsEverySourceAndCollectsTheSubjectsObjects()
}//end class
