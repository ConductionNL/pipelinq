<?php

/**
 * Unit tests for RelationshipTypeService, RelationshipController and RelationshipTypeListener.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Controller\RelationshipController;
use OCA\Pipelinq\Listener\RelationshipTypeListener;
use OCA\Pipelinq\Service\RelationshipTypeService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The server owns the relationship types, refuses unknown and repeated
 * relationships, and derives what a type and target imply.
 */
class RelationshipTypeServiceTest extends TestCase {

	/**
	 * Saved objects, as the object service received them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Rows findAll answers.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $existing = [];

	public function testTheTypesAreOfferedTranslatedWithTheirCategory(): void {
		$options = $this->service()->options();

		$this->assertCount(count(RelationshipTypeService::TYPES), $options);
		$this->assertSame(['value' => 'ouder', 'label' => 'nl:Parent', 'category' => 'nl:Family'], $options[1]);
	}

	public function testATypeImpliesItsInverseAndCategory(): void {
		$this->assertSame(['inverseType' => 'dochterorganisatie', 'category' => 'Organisatie'], $this->service()->impliedBy(type: 'moederorganisatie'));
		$this->assertSame([], $this->service()->impliedBy(type: 'buurman'));
	}

	public function testACreateStoresTheImpliedFields(): void {
		$saved = $this->service()->create(
			fields: ['fromContact' => 'c-1', 'fromType' => 'contact', 'toContact' => 'k-1', 'type' => 'werknemer', 'notes' => '', 'strength' => 'strong', 'id' => 'ignored']
		);

		$this->assertSame('new-1', $saved['id']);
		$this->assertSame(
			['strength' => 'strong', 'fromContact' => 'c-1', 'toContact' => 'k-1', 'fromType' => 'contact', 'type' => 'werknemer', 'inverseType' => 'werkgever', 'category' => 'Professioneel'],
			$this->saved[0]
		);
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'no target' => [['fromContact' => 'c-1', 'type' => 'partner'], 'incomplete'],
			'unknown type' => [['fromContact' => 'c-1', 'toContact' => 'c-2', 'type' => 'buurman'], 'unknown-type'],
			'itself' => [['fromContact' => 'c-1', 'toContact' => 'c-1', 'type' => 'partner'], 'self'],
		];
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param array<string, string> $fields The relationship.
	 * @param string                $reason The refusal.
	 */
	public function testAnIncompleteUnknownOrSelfRelationshipIsRefused(array $fields, string $reason): void {
		$this->expectExceptionObject(new DomainException($reason));
		$this->service()->create(fields: $fields);
	}

	public function testARepeatedRelationshipIsRefusedWithAConflict(): void {
		$this->existing = [['id' => 'r-1']];
		$l10n = $this->l10n();
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => ['fromContact' => 'c-1', 'toContact' => 'c-2', 'type' => 'partner'][$key] ?? null);

		$response = (new RelationshipController($request, $this->service(), $l10n))->create();

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['status' => 'duplicate', 'message' => 'nl:This relationship already exists.'], $response->getData());
		$this->assertSame([], $this->saved);
	}

	public function testTheListenerSetsInverseCategoryAndTargetKind(): void {
		$entity = $this->relationship(['fromContact' => 'c-1', 'toContact' => 'k-1', 'type' => 'contactpersoon']);
		$event = new ObjectCreatingEvent($entity);
		$event->setModifiedData(['note' => 'kept']);

		$this->listener()->handle($event);

		$this->assertSame(
			['note' => 'kept', 'inverseType' => 'organisatie', 'category' => 'Professioneel', 'toType' => 'client'],
			$event->getModifiedData()
		);
	}

	public function testTheListenerLeavesAnUnchangedTargetAndRightFieldsAlone(): void {
		$old = $this->relationship(['fromContact' => 'c-1', 'toContact' => 'c-2', 'type' => 'partner', 'inverseType' => 'partner', 'category' => 'Familie', 'toType' => 'contact']);
		$new = $this->relationship(['fromContact' => 'c-1', 'toContact' => 'c-2', 'type' => 'partner', 'inverseType' => 'partner', 'category' => 'Familie', 'toType' => 'contact', 'notes' => 'x']);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->listener()->handle($event);

		$this->assertSame([], $event->getModifiedData());

		$other = new ObjectCreatingEvent($this->relationship(['type' => 'ouder'], schema: 'sch-other'));
		$this->listener()->handle($other);
		$this->assertSame([], $other->getModifiedData());
	}

	/**
	 * A relationship entity.
	 *
	 * @param array<string, mixed> $data   The data.
	 * @param string               $schema The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function relationship(array $data, string $schema = 'sch-rel'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('r-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}

	private function listener(): RelationshipTypeListener {
		return new RelationshipTypeListener($this->service(), $this->objectService(), $this->appConfig());
	}

	private function service(): RelationshipTypeService {
		return new RelationshipTypeService($this->objectService(), $this->appConfig(), $this->l10n());
	}

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:' . $text);
		return $l10n;
	}

	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => ([
			'register' => 'reg', 'relationship_schema' => 'sch-rel', 'client_schema' => 'sch-client',
		][$key] ?? $default));
		return $appConfig;
	}

	private function objectService(): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(static function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
			if ($schema === 'sch-client' && $id === 'k-1') {
				return new ObjectEntity();
			}
			return null;
		});
		$objectService->method('findAll')->willReturnCallback(fn (): array => $this->existing);
		$objectService->method('saveObject')->willReturnCallback(function (array|ObjectEntity $object): ObjectEntity {
			$this->saved[] = $object;
			$entity = new ObjectEntity();
			$entity->setUuid('new-1');
			$entity->setObject($object);
			return $entity;
		});
		return $objectService;
	}
}
