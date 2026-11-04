<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Listener\TicketPhoneListener;
use OCA\Pipelinq\Service\PhoneHistoryService;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Pipelinq\Service\PhoneHistoryService
 */
class PhoneHistoryServiceTest extends TestCase {
	/**
	 * The stored tickets, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * The filters of every findAll call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * How many saves reached the store.
	 *
	 * @var integer
	 */
	private int $saves = 0;

	/**
	 * The service over an in-memory ticket store.
	 *
	 * @return PhoneHistoryService The service.
	 */
	private function service(): PhoneHistoryService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['ticket_schema' => '40'][$key] ?? $default)
		);
		$normaliser = new PhoneNormaliser($config, $this->createMock(LoggerInterface::class));

		/** @var ObjectServiceInterface&MockObject $objects */
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(function (array $config = []): array {
			$this->queries[] = $config;
			$filters = $config['filters'];
			$rows = [];
			foreach ($this->store as $id => $data) {
				if (isset($filters['phoneE164']) === true && ($data['phoneE164'] ?? null) !== $filters['phoneE164']) {
					continue;
				}

				$rows[] = array_merge($data, ['id' => $id, '@self' => ['created' => '2026-10-01T09:00:00+00:00']]);
			}

			return array_slice($rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 50));
		});
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		$objects->method('saveObject')->willReturnCallback(function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntityInterface {
			$this->assertArrayNotHasKey('@self', $object);
			$this->store[$uuid] = $object;
			$this->saves++;
			return $this->createMock(ObjectEntityInterface::class);
		});

		$tickets = $this->createMock(TicketService::class);
		$tickets->method('isConfigured')->willReturn(true);
		$tickets->method('getObjectService')->willReturn($objects);
		$tickets->method('getRegisterId')->willReturn('7');
		$tickets->method('getSchemaId')->willReturn('40');

		return new PhoneHistoryService($tickets, $normaliser, new TicketPhoneListener($config, $normaliser), $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A number typed with a leading zero, with spaces, with dashes or with 0031 finds the one ticket.
	 *
	 * @return void
	 */
	public function testANumberIsFoundHoweverItWasTyped(): void {
		$this->store = [
			't-1' => ['title' => 'Vraag over afvalpas', 'ticketType' => 'interaction', 'phoneE164' => '+31612345678', 'occurredAt' => '2026-10-02T10:00:00+00:00'],
			't-2' => ['title' => 'Andere beller', 'ticketType' => 'interaction', 'phoneE164' => '+31687654321'],
		];
		$service = $this->service();

		foreach (['06-12345678', '+31 6 1234 5678', '0031612345678', '06 1234 5678'] as $typed) {
			$found = $service->findTickets(raw: $typed);
			$this->assertSame('+31612345678', $found['number'], $typed);
			$this->assertSame(['t-1'], array_column($found['results'], 'id'), $typed);
			$this->assertSame('Vraag over afvalpas', $found['results'][0]['title']);
			$this->assertSame('2026-10-02T10:00:00+00:00', $found['results'][0]['occurredAt']);
		}

		$this->assertSame('+31612345678', end($this->queries)['filters']['phoneE164']);
		$this->assertSame(['occurredAt' => 'desc'], end($this->queries)['sort']);
	}//end testANumberIsFoundHoweverItWasTyped()

	/**
	 * Text that is no phone number runs no query.
	 *
	 * @return void
	 */
	public function testTextThatIsNoNumberRunsNoQuery(): void {
		$found = $this->service()->findTickets(raw: 'afvalpas');

		$this->assertSame(['number' => null, 'results' => []], $found);
		$this->assertSame([], $this->queries);
	}//end testTextThatIsNoNumberRunsNoQuery()

	/**
	 * The back-fill stores the number once; a second run changes nothing.
	 *
	 * @return void
	 */
	public function testTheBackfillRunsTwiceAndWritesOnce(): void {
		$this->store = [
			't-1' => ['title' => 'CTI inbound call', 'from_number' => '+31612345678', 'direction' => 'inbound'],
			't-2' => ['title' => 'Webformulier', 'reporterPhone' => '06-87654321'],
			't-3' => ['title' => 'Geen nummer'],
		];
		$service = $this->service();

		$this->assertSame(['updated' => 2, 'skipped' => 1], $service->backfillTicketPhones());
		$this->assertSame('+31612345678', $this->store['t-1']['phoneE164']);
		$this->assertSame('+31687654321', $this->store['t-2']['phoneE164']);
		$this->assertSame('CTI inbound call', $this->store['t-1']['title']);

		$this->assertSame(['updated' => 0, 'skipped' => 3], $service->backfillTicketPhones());
		$this->assertSame(2, $this->saves);
	}//end testTheBackfillRunsTwiceAndWritesOnce()
}//end class
