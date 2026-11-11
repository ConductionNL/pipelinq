<?php

/**
 * Characterisation tests for the SegmentService member projection helpers,
 * the stored-rules reader and the estimate cache read.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins resolveMemberId(), resolveMemberName(), extractRules(),
 * readEstimateCache() and getMembersForBlast()'s refusals.
 */
class SegmentServiceMembersGoldenTest extends TestCase {

	/**
	 * Build the service.
	 *
	 * @return SegmentService
	 */
	private function service(): SegmentService {
		return new SegmentService(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(SchemaMapService::class),
			$this->createMock(ICacheFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SegmentSignalService::class)
		);
	}//end service()

	/**
	 * Call a private method.
	 *
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   The arguments.
	 *
	 * @return mixed
	 */
	private function call(string $method, array $args): mixed {
		$service = $this->service();

		return (new ReflectionMethod($service, $method))->invokeArgs($service, $args);
	}//end call()

	/**
	 * Member ids: top-level first, then @self, scalars only.
	 *
	 * @return void
	 */
	public function testResolveMemberId(): void {
		$cases = [
			[['id' => 'a', 'uuid' => 'b'], 'a'],
			[['id' => '', 'uuid' => 'b'], 'b'],
			[['slug' => 's', '@self' => ['uuid' => 'u']], 's'],
			[['@self' => ['id' => 'i', 'uuid' => 'u']], 'u'],
			[['@self' => ['uuid' => ['x'], 'id' => 7]], '7'],
			[['@self' => ['uuid' => '', 'id' => 'i']], ''],
			[['@self' => ['slug' => false]], ''],
			[['@self' => 'flat'], ''],
			[['@self' => ['uuid' => null, 'slug' => 'sl']], 'sl'],
			[[], ''],
		];
		foreach ($cases as $i => [$entity, $expected]) {
			$this->assertSame($expected, $this->call('resolveMemberId', [$entity]), 'case ' . $i);
		}
	}//end testResolveMemberId()

	/**
	 * Member names: explicit fields, snake_case, and a split full name.
	 *
	 * @return void
	 */
	public function testResolveMemberName(): void {
		$cases = [
			[['firstName' => 'A', 'lastName' => 'B', 'name' => 'X Y'], ['firstName' => 'A', 'lastName' => 'B']],
			[['first_name' => 'A'], ['firstName' => 'A', 'lastName' => '']],
			[['last_name' => 'B', 'name' => 'X Y'], ['firstName' => '', 'lastName' => 'B']],
			[['name' => '  Jan  van  Dijk '], ['firstName' => 'Jan', 'lastName' => 'van Dijk']],
			[['name' => 'Single'], ['firstName' => 'Single', 'lastName' => '']],
			[['name' => '   '], ['firstName' => '', 'lastName' => '']],
			[['name' => 42], ['firstName' => '', 'lastName' => '']],
			[[], ['firstName' => '', 'lastName' => '']],
		];
		foreach ($cases as $i => [$entity, $expected]) {
			$this->assertSame($expected, $this->call('resolveMemberName', [$entity]), 'case ' . $i);
		}
	}//end testResolveMemberName()

	/**
	 * Stored rules: JSON text, arrays and everything else.
	 *
	 * @return void
	 */
	public function testExtractRules(): void {
		$cases = [
			[['rules' => '{"field":"a"}'], ['field' => 'a']],
			[['rules' => '"text"'], null],
			[['rules' => 'not json'], null],
			[['rules' => ''], null],
			[['rules' => ['field' => 'b']], ['field' => 'b']],
			[['rules' => []], []],
			[['rules' => 5], null],
			[[], null],
		];
		foreach ($cases as $i => [$segment, $expected]) {
			$this->assertSame($expected, $this->call('extractRules', [$segment]), 'case ' . $i);
		}
	}//end testExtractRules()

	/**
	 * The estimate cache read: no cache, ints, numeric strings and misses.
	 *
	 * @return void
	 */
	public function testReadEstimateCache(): void {
		$this->assertNull($this->call('readEstimateCache', [null, 'k']));
		$answers = [[12, 12], ['7', 7], ['3.9', 3], [null, null], ['x', null], [[1], null]];
		foreach ($answers as $i => [$stored, $expected]) {
			$cache = $this->createMock(ICache::class);
			$cache->expects($this->once())->method('get')->with('k')->willReturn($stored);
			$this->assertSame($expected, $this->call('readEstimateCache', [$cache, 'k']), 'case ' . $i);
		}
	}//end testReadEstimateCache()

	/**
	 * A missing Segment answers no members.
	 *
	 * @return void
	 */
	public function testMembersForAMissingSegment(): void {
		$this->assertSame([], $this->service()->getMembersForBlast('missing'));
	}//end testMembersForAMissingSegment()
}//end class
