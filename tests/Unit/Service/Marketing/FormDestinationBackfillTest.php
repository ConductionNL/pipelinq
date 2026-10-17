<?php

/**
 * Unit tests for FormDestinationBackfill.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
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
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\CampaignLinkDecorator;
use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\Marketing\CampaignFormDestination;
use OCA\Pipelinq\Service\Marketing\FormDestinationBackfill;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../../Support/InMemoryListObjectStore.php';

/**
 * Forms made before forms named a destination get `lead`, once.
 */
class FormDestinationBackfillTest extends TestCase {

	/**
	 * The object service double: the hosted forms it returns and the saves it saw.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * A hosted form as OpenRegister returns it.
	 *
	 * @param string               $uuid The form's uuid.
	 * @param array<string, mixed> $data The form's data.
	 */
	private static function form(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end form()

	/**
	 * The backfill over a set of hosted forms.
	 *
	 * @param array<int, mixed> $forms    What the hosting register holds.
	 * @param bool              $readable Whether the hosting register can be read.
	 */
	private function backfill(array $forms, bool $readable = true): FormDestinationBackfill {
		$this->objects = new class ($forms, $readable) {

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $queries = [];

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * @param array<int, mixed> $forms    The forms.
			 * @param bool              $readable Whether reads work.
			 */
			public function __construct(private array $forms, private bool $readable) {
			}//end __construct()

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->queries[] = ['config' => $config, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ($this->readable === false) {
					throw new RuntimeException('Register portaliq not found');
				}

				return array_slice($this->forms, (int)$config['offset'], (int)$config['limit']);
			}//end findAll()

			/**
			 * @param array<string, mixed> $object The object.
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				if ($uuid === 'form-broken') {
					throw new RuntimeException('refused');
				}

				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac];

				return new ObjectEntity();
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objects);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$store = new InMemoryListObjectStore(
			[
				'campaign' => [
					['uuid' => 'camp-1', 'name' => 'Webinar AI voor gemeenten'],
					['uuid' => 'camp-2', 'name' => 'Open dag', 'formRef' => 'form-by-ref'],
				],
			]
		);

		return new FormDestinationBackfill(
			$container,
			new CampaignService($store, $appConfig, new CampaignLinkDecorator($appConfig)),
			new CampaignFormDestination(),
			$this->createMock(LoggerInterface::class)
		);
	}//end backfill()

	/**
	 * A form without a destination gets the lead of its campaign, and keeps everything else it had.
	 */
	public function testAFormWithoutADestinationSubmitsIntoItsCampaignsLead(): void {
		$fields = [['id' => 'name'], ['id' => 'email']];
		$backfill = $this->backfill(
			forms: [self::form('form-1', ['sourceApp' => 'pipelinq', 'externalReference' => 'pipelinq:campaign:camp-1', 'fields' => $fields, 'status' => 'active'])]
		);

		$result = $backfill->run();

		$this->assertSame(['status' => 'done', 'named' => 1, 'skipped' => 0, 'failed' => 0], $result);
		$save = $this->objects->saves[0];
		$this->assertSame(['portaliq', 'form', 'form-1', false], [$save['register'], $save['schema'], $save['uuid'], $save['rbac']]);
		$this->assertSame('lead', $save['object']['destination']['schema']);
		$this->assertSame('camp-1', $save['object']['destination']['fixed']['campaignId']);
		$this->assertSame('Webinar AI voor gemeenten', $save['object']['destination']['fixed']['title']);
		$this->assertSame($fields, $save['object']['fields'], 'The form keeps its own fields.');
		$this->assertSame('active', $save['object']['status']);
		$this->assertSame(['register' => 'portaliq', 'schema' => 'form', 'sourceApp' => 'pipelinq'], $this->objects->queries[0]['config']['filters']);
		$this->assertFalse($this->objects->queries[0]['rbac']);
	}//end testAFormWithoutADestinationSubmitsIntoItsCampaignsLead()

	/**
	 * Once: a form that names a destination is left alone, whatever it names.
	 */
	public function testAFormThatNamesADestinationIsLeftAlone(): void {
		$backfill = $this->backfill(
			forms: [
				self::form('form-1', ['sourceApp' => 'pipelinq', 'destination' => ['register' => 'pipelinq', 'schema' => 'ticket']]),
				self::form('form-2', ['sourceApp' => 'pipelinq', 'destination' => ['register' => 'pipelinq', 'schema' => '']]),
			]
		);

		$result = $backfill->run();

		$this->assertSame(['status' => 'done', 'named' => 1, 'skipped' => 1, 'failed' => 0], $result);
		$this->assertSame(['form-2'], array_column($this->objects->saves, 'uuid'), 'Half a destination is no destination.');
	}//end testAFormThatNamesADestinationIsLeftAlone()

	/**
	 * Another app's form is never touched, even when the store ignores the filter and returns it.
	 */
	public function testAnotherAppsFormIsNeverTouched(): void {
		$backfill = $this->backfill(
			forms: [
				self::form('theirs', ['sourceApp' => 'dossiq']),
				self::form('unowned', []),
				['@self' => ['id' => 'row-form'], 'sourceApp' => 'pipelinq', 'externalReference' => 'pipelinq:campaign:camp-1'],
				['sourceApp' => 'pipelinq'],
			]
		);

		$result = $backfill->run();

		$this->assertSame(1, $result['named']);
		$this->assertSame(['row-form'], array_column($this->objects->saves, 'uuid'));
		$this->assertArrayNotHasKey('@self', $this->objects->saves[0]['object']);
	}//end testAnotherAppsFormIsNeverTouched()

	/**
	 * A form whose reference is lost is matched by the campaign's stored form id; an unknown one still gets a lead.
	 */
	public function testAFormWithoutAReferenceIsMatchedByItsId(): void {
		$backfill = $this->backfill(
			forms: [
				self::form('form-by-ref', ['sourceApp' => 'pipelinq']),
				self::form('form-orphan', ['sourceApp' => 'pipelinq', 'externalReference' => 'something else']),
			]
		);

		$backfill->run();

		$this->assertSame('camp-2', $this->objects->saves[0]['object']['destination']['fixed']['campaignId']);
		$this->assertSame(['title' => 'Landing page', 'source' => 'landing-page'], $this->objects->saves[1]['object']['destination']['fixed']);
	}//end testAFormWithoutAReferenceIsMatchedByItsId()

	/**
	 * A refused save is counted and the rest carries on; more than a page of forms is read to the end.
	 */
	public function testARefusedSaveIsCountedAndTheRestCarriesOn(): void {
		$forms = [self::form('form-broken', ['sourceApp' => 'pipelinq'])];
		for ($i = 0; $i < 120; $i++) {
			$forms[] = self::form('form-' . $i, ['sourceApp' => 'pipelinq']);
		}

		$result = $this->backfill(forms: $forms)->run();

		$this->assertSame(['status' => 'done', 'named' => 120, 'skipped' => 0, 'failed' => 1], $result);
		$this->assertCount(2, $this->objects->queries);
	}//end testARefusedSaveIsCountedAndTheRestCarriesOn()

	/**
	 * Without the hosting register, or without OpenRegister, the step says so and writes nothing.
	 */
	public function testNothingToReadIsNotAFailure(): void {
		$this->assertSame('read-failed', $this->backfill(forms: [], readable: false)->run()['status']);
		$this->assertSame([], $this->objects->saves);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no such service'));
		$appConfig = $this->createMock(IAppConfig::class);
		$backfill = new FormDestinationBackfill(
			$container,
			new CampaignService(new InMemoryListObjectStore(), $appConfig, new CampaignLinkDecorator($appConfig)),
			new CampaignFormDestination(),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(['status' => 'no-openregister', 'named' => 0, 'skipped' => 0, 'failed' => 0], $backfill->run());
	}//end testNothingToReadIsNotAFailure()
}//end class
