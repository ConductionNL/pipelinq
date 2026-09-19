<?php

/**
 * Unit tests for PartyOrganisationTreeService.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\PartyOrganisationTreeService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the guarded customer organisation tree.
 */
class PartyOrganisationTreeServiceTest extends TestCase {
	/**
	 * The organisations the double holds, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $organisations = [];

	/**
	 * Every object written, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * The administered depth cap the double answers with.
	 *
	 * @var string
	 */
	private string $maxDepth = '6';

	/**
	 * Build the service over doubles.
	 *
	 * @return PartyOrganisationTreeService The service under test.
	 */
	private function service(): PartyOrganisationTreeService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'reg-1',
					'client_schema' => 'sch-client',
					'party_organisation_max_depth' => $this->maxDepth,
				];

				return ($values[$key] ?? $default);
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntityInterface {
				$data = ($this->organisations[(string)$id] ?? null);
				if ($data === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($data);
				$entity->method('getObject')->willReturn($data);

				return $entity;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (): array {
				return array_values($this->organisations);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->written[] = $object;
				$this->organisations[(string)($object['id'] ?? '')] = $object;

				// The CONTRACT's return type is non-nullable, and a callback
				// that answered null would throw inside the service, be caught
				// by its own error handling and surface as a plausible 500.
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);

				return $entity;
			}
		);

		return new PartyOrganisationTreeService(
			$appConfig,
			$objectService,
			$this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * Seed a three-node chain: root, child, grandchild.
	 *
	 * @return void
	 */
	private function seedChain(): void {
		$this->organisations = [
			'root' => ['id' => 'root', 'name' => 'Woningcorporatie', 'organisationPath' => '/root'],
			'child' => [
				'id' => 'child',
				'name' => 'Regio Noord',
				'parentOrganisation' => 'root',
				'organisationPath' => '/root/child',
			],
			'grandchild' => [
				'id' => 'grandchild',
				'name' => 'Vestiging Groningen',
				'parentOrganisation' => 'child',
				'organisationPath' => '/root/child/grandchild',
			],
			'other' => ['id' => 'other', 'name' => 'Losse klant', 'organisationPath' => '/other'],
		];
	}//end seedChain()

	/**
	 * A cycle is refused on the write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-organisations-shall-nest-as-a-guarded-tree-carrying-their-own-fields-req-pfi-005
	 */
	public function testACycleIsRefusedOnTheWrite(): void {
		$this->seedChain();
		$service = $this->service();

		$result = $service->setParent(nodeId: 'root', parentId: 'child');

		$this->assertSame(409, $result['status']);
		$this->assertStringContainsString('cycle', $result['error']);
		$this->assertSame([], $this->written, 'A refused move writes nothing.');
	}//end testACycleIsRefusedOnTheWrite()

	/**
	 * An organisation cannot be its own parent.
	 *
	 * @return void
	 */
	public function testAnOrganisationCannotBeItsOwnParent(): void {
		$this->seedChain();

		$result = $this->service()->setParent(nodeId: 'child', parentId: 'child');

		$this->assertSame(409, $result['status']);
		$this->assertSame([], $this->written);
	}//end testAnOrganisationCannotBeItsOwnParent()

	/**
	 * Moving a node moves its subtree, in one act.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-organisations-shall-nest-as-a-guarded-tree-carrying-their-own-fields-req-pfi-005
	 */
	public function testMovingANodeMovesItsSubtree(): void {
		$this->seedChain();
		$service = $this->service();

		$result = $service->setParent(nodeId: 'child', parentId: 'other');

		$this->assertSame(200, $result['status']);
		$this->assertSame(2, $result['moved'], 'The node and its one descendant.');

		$paths = [];
		foreach ($this->written as $object) {
			$paths[(string)$object['id']] = (string)$object['organisationPath'];
		}

		$this->assertSame('/other/child', $paths['child']);
		$this->assertSame(
			'/other/child/grandchild',
			$paths['grandchild'],
			'A descendant left on the old path points at a parent that no longer holds it.'
		);
	}//end testMovingANodeMovesItsSubtree()

	/**
	 * A move past the administered cap is refused, naming the cap.
	 *
	 * @return void
	 */
	public function testADepthPastTheCapIsRefused(): void {
		$this->seedChain();
		$this->maxDepth = '2';
		$service = $this->service();

		$result = $service->setParent(nodeId: 'child', parentId: 'other');

		$this->assertSame(409, $result['status']);
		$this->assertStringContainsString('cap of 2', $result['error']);
		$this->assertSame([], $this->written);
	}//end testADepthPastTheCapIsRefused()

	/**
	 * A node can be made a root again, and its subtree follows.
	 *
	 * The control for the refusals above: without it a service that refused
	 * every move would pass all three.
	 *
	 * @return void
	 */
	public function testANodeCanBeMadeARoot(): void {
		$this->seedChain();
		$service = $this->service();

		$result = $service->setParent(nodeId: 'child', parentId: null);

		$this->assertSame(200, $result['status']);
		$this->assertSame('/child', $result['path']);
	}//end testANodeCanBeMadeARoot()

	/**
	 * The path helpers answer what the guards rest on.
	 *
	 * @return void
	 */
	public function testThePathHelpersAnswerPlainly(): void {
		$service = $this->service();

		$this->assertSame('/a', $service->pathUnder(nodeId: 'a', parentPath: ''));
		$this->assertSame('/a/b', $service->pathUnder(nodeId: 'b', parentPath: '/a'));
		$this->assertSame(2, $service->depthOf(path: '/a/b'));
		$this->assertSame(0, $service->depthOf(path: ''));
		$this->assertTrue($service->isOnPath(nodeId: 'a', path: '/a/b'));
		$this->assertFalse($service->isOnPath(nodeId: 'c', path: '/a/b'));
	}//end testThePathHelpersAnswerPlainly()
}//end class
