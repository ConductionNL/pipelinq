<?php

/**
 * Unit tests for the raw append and purge pair on the ObjectService test stub.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Stubs
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

namespace OCA\Pipelinq\Tests\Unit\Stubs;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * The stub must declare appendObjectsRaw() and purgeExpiredObjectsRaw()
 * (openregister#3407, contract-shift openregister#3406) or it stops
 * implementing the contract at class load. Declaring is the half PHP checks;
 * this covers the other half, that the pair behaves like a store.
 *
 * PHPUnit assertions take positional arguments; the named-parameter sniff does
 * not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ObjectServiceRawRowsTest extends TestCase {
	/**
	 * Both halves of the double declare the pair.
	 *
	 * @return void
	 */
	public function testStubAndContractCopyDeclareThePair(): void {
		$this->assertInstanceOf(ObjectServiceInterface::class, new ObjectService());
		$this->assertTrue(method_exists(ObjectServiceInterface::class, 'appendObjectsRaw'));
		$this->assertTrue(method_exists(ObjectServiceInterface::class, 'purgeExpiredObjectsRaw'));
	}//end testStubAndContractCopyDeclareThePair()

	/**
	 * A row without a uuid gets one; a row with one keeps it.
	 *
	 * @return void
	 */
	public function testAppendStampsAUuidWhenAbsentAndKeepsAGivenOne(): void {
		$stub = new ObjectService();

		$written = $stub->appendObjectsRaw(
			[
				['event' => 'view'],
				['uuid' => 'fixed-1', 'event' => 'click'],
			],
			'pipelinq',
			'traffic-event',
		);

		$this->assertSame(2, $written);
		$rows = $stub->rawObjects('pipelinq', 'traffic-event');
		$this->assertSame(['raw-1', 'fixed-1'], array_keys($rows));
		$this->assertSame('raw-1', $rows['raw-1']['uuid']);
		$this->assertSame('click', $rows['fixed-1']['event']);
	}//end testAppendStampsAUuidWhenAbsentAndKeepsAGivenOne()

	/**
	 * The sweep removes rows whose expires has passed, in either accepted form,
	 * keeps the rest, and reports what it removed.
	 *
	 * @return void
	 */
	public function testPurgeDropsOnlyExpiredRowsAndReportsTheCount(): void {
		$stub = new ObjectService();
		$stub->appendObjectsRaw(
			[
				['uuid' => 'gone-iso', 'expires' => '2000-01-01T00:00:00+00:00'],
				['uuid' => 'gone-datetime', 'expires' => new DateTimeImmutable('-1 day')],
				['uuid' => 'stays-future', 'expires' => '2999-01-01T00:00:00+00:00'],
				['uuid' => 'stays-never'],
				['uuid' => 'stays-unparseable', 'expires' => 'not a date'],
			],
			'pipelinq',
			'traffic-event',
		);

		$this->assertSame(2, $stub->purgeExpiredObjectsRaw('pipelinq', 'traffic-event'));
		$this->assertSame(
			['stays-future', 'stays-never', 'stays-unparseable'],
			array_keys($stub->rawObjects('pipelinq', 'traffic-event')),
		);
		$this->assertSame(0, $stub->purgeExpiredObjectsRaw('pipelinq', 'traffic-event'));
	}//end testPurgeDropsOnlyExpiredRowsAndReportsTheCount()

	/**
	 * A sweep is scoped to one register and schema; an unknown scope sweeps nothing.
	 *
	 * @return void
	 */
	public function testPurgeIsScopedToTheRegisterAndSchema(): void {
		$stub = new ObjectService();
		$expired = ['expires' => '2000-01-01T00:00:00+00:00'];
		$stub->appendObjectsRaw([['uuid' => 'a'] + $expired], 'pipelinq', 'traffic-event');
		$stub->appendObjectsRaw([['uuid' => 'b'] + $expired], 'pipelinq', 'telemetry');

		$this->assertSame(1, $stub->purgeExpiredObjectsRaw('pipelinq', 'traffic-event'));
		$this->assertCount(1, $stub->rawObjects('pipelinq', 'telemetry'));
		$this->assertSame(0, $stub->purgeExpiredObjectsRaw('other', 'traffic-event'));
	}//end testPurgeIsScopedToTheRegisterAndSchema()
}//end class
