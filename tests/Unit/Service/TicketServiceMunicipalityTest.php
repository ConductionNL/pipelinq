<?php

/**
 * Unit tests for the municipality a new ticket takes from its client.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/requests-municipality-per-contact/specs/served-municipality/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Mcp\McpAnswer;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A new ticket takes its client's municipality when none is given; an update never does.
 */
class TicketServiceMunicipalityTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objects;

	/**
	 * The ticket fields saved, if any.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $saved = null;

	/**
	 * Set up the object service: it records the save and answers client reads.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->saved = $object;
				return $this->createMock(ObjectEntityInterface::class);
			}
		);
	}//end setUp()

	/**
	 * The service, with the client schema configured or not.
	 *
	 * @param bool $clientSchema Whether client_schema is set.
	 *
	 * @return TicketService The service.
	 */
	private function service(bool $clientSchema=true): TicketService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default='') use ($clientSchema): string {
				$values = ['register' => 'reg-1', 'ticket_schema' => 'sch-ticket'];
				if ($clientSchema === true) {
					$values['client_schema'] = 'sch-client';
				}

				return ($values[$key] ?? $default);
			}
		);

		return new TicketService(appConfig: $appConfig, logger: new NullLogger(), objectService: $this->objects, mcp: new McpAnswer(new NullLogger()));
	}//end service()

	/**
	 * Answer a read of client c-1 with the given data.
	 *
	 * @param array<string, mixed> $data The client's data.
	 *
	 * @return void
	 */
	private function clientIs(array $data): void {
		$client = $this->createMock(ObjectEntityInterface::class);
		$client->method('getObject')->willReturn($data);
		$this->objects->expects($this->once())
			->method('find')
			->with('c-1', [], false, 'reg-1', 'sch-client')
			->willReturn($client);
	}//end clientIs()

	/**
	 * A new ticket for a client takes the client's municipality.
	 *
	 * @return void
	 */
	public function testANewTicketTakesItsClientsMunicipality(): void {
		$this->clientIs(['name' => 'Bakkerij De Graaf', 'municipality' => 'mun-goes']);

		$this->service()->save(TicketService::TYPE_REQUEST, ['title' => 'Vraag', 'client' => 'c-1']);

		self::assertSame('mun-goes', $this->saved['municipality']);
		self::assertSame('request', $this->saved['ticketType']);
	}//end testANewTicketTakesItsClientsMunicipality()

	/**
	 * An expanded municipality reference is reduced to its id.
	 *
	 * @return void
	 */
	public function testAnExpandedMunicipalityIsCopiedAsItsId(): void {
		$this->clientIs(['municipality' => ['@self' => ['id' => 'mun-borsele'], 'name' => 'Borsele']]);

		$this->service()->save(TicketService::TYPE_REQUEST, ['title' => 'Vraag', 'client' => 'c-1']);

		self::assertSame('mun-borsele', $this->saved['municipality']);
	}//end testAnExpandedMunicipalityIsCopiedAsItsId()

	/**
	 * A municipality given on create is kept and the client is not read.
	 *
	 * @return void
	 */
	public function testAGivenMunicipalityIsKept(): void {
		$this->objects->expects($this->never())->method('find');

		$this->service()->save(TicketService::TYPE_REQUEST, ['title' => 'Vraag', 'client' => 'c-1', 'municipality' => 'mun-veere']);

		self::assertSame('mun-veere', $this->saved['municipality']);
	}//end testAGivenMunicipalityIsKept()

	/**
	 * An update never copies the client's municipality.
	 *
	 * @return void
	 */
	public function testAnUpdateDoesNotCopy(): void {
		$this->objects->expects($this->never())->method('find');

		$this->service()->save(TicketService::TYPE_REQUEST, ['title' => 'Vraag', 'client' => 'c-1'], 'ticket-uuid');

		self::assertArrayNotHasKey('municipality', $this->saved);
	}//end testAnUpdateDoesNotCopy()

	/**
	 * Without a client, without the client schema, with a client without a
	 * municipality or one that cannot be read, nothing is copied and the ticket is still saved.
	 *
	 * @return void
	 */
	public function testNothingIsCopiedWithoutAMunicipalityToCopy(): void {
		$this->service()->save(TicketService::TYPE_REQUEST, ['title' => 'Vraag']);
		self::assertArrayNotHasKey('municipality', $this->saved);

		$this->service(clientSchema: false)->save(TicketService::TYPE_REQUEST, ['title' => 'Vraag', 'client' => 'c-1']);
		self::assertArrayNotHasKey('municipality', $this->saved);

		$this->clientIs(['name' => 'Zonder gemeente', 'municipality' => '']);
		$this->service()->save(TicketService::TYPE_REQUEST, ['title' => 'Vraag', 'client' => 'c-1']);
		self::assertArrayNotHasKey('municipality', $this->saved);
	}//end testNothingIsCopiedWithoutAMunicipalityToCopy()

	/**
	 * A client that cannot be read leaves the ticket without a municipality, and it is still saved.
	 *
	 * @return void
	 */
	public function testAnUnreadableClientCopiesNothing(): void {
		$this->objects->method('find')->willThrowException(new RuntimeException('not allowed'));

		$this->service()->save(TicketService::TYPE_REQUEST, ['title' => 'Vraag', 'client' => 'c-1']);

		self::assertSame('Vraag', $this->saved['title']);
		self::assertArrayNotHasKey('municipality', $this->saved);
	}//end testAnUnreadableClientCopiesNothing()
}//end class
