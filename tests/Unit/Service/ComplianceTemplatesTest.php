<?php

/**
 * Unit tests for ComplianceService's campaign template store.
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\IntegriqMarketingConsent;
use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Listing, reading, creating and patching campaign templates through
 * OpenRegister, with the compliance check on every write.
 *
 * @covers \OCA\Pipelinq\Service\ComplianceService
 * @uses \OCA\Pipelinq\Service\Marketing\MailBlockRenderer
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 */
class ComplianceTemplatesTest extends TestCase {

	/**
	 * A compliant email template body.
	 */
	private const BODY = '<p>Hello</p><p>{{unsubscribe_link}}</p>';

	/**
	 * The OpenRegister double.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Whether the container fails to give the ObjectService.
	 *
	 * @var bool
	 */
	private bool $noObjectService = false;

	/**
	 * Set up the OpenRegister double and the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<int, array<string, mixed>> */
			public array $rows = [];

			/** @var array<string, mixed> */
			public array $store = [];

			/** @var array<int, array<string, mixed>> */
			public array $findAllCalls = [];

			/** @var array<int, array<string, mixed>> */
			public array $saves = [];

			/** @var bool */
			public bool $failFind = false;

			/** @var bool */
			public bool $failFindAll = false;

			/** @var bool */
			public bool $failSave = false;

			/**
			 * Find one row by id.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, $register = null, $schema = null): mixed {
				if ($this->failFind === true) {
					throw new RuntimeException('gone');
				}

				return ($this->store[$id] ?? null);
			}

			/**
			 * Every row.
			 *
			 * @param array<string, mixed> $config The config.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$this->findAllCalls[] = $config;
				if ($this->failFindAll === true) {
					throw new RuntimeException('down');
				}

				return $this->rows;
			}

			/**
			 * Save a row.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				if ($this->failSave === true) {
					throw new RuntimeException('refused');
				}

				return $object + ['uuid' => ($uuid ?? 'new-1')];
			}
		};

		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The service over the double.
	 *
	 * @return ComplianceService
	 */
	private function service(): ComplianceService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($this->noObjectService === true) {
					throw new RuntimeException('OpenRegister is not installed');
				}

				return $this->objects;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'crm',
				'campaignTemplate_schema' => 'tpl',
				default => $default,
			}
		);

		return new ComplianceService(
			$container,
			$appConfig,
			$this->createMock(SegmentService::class),
			$this->logger,
			$this->createMock(SegmentSignalService::class),
			$this->createMock(IntegriqMarketingConsent::class),
		);

	}//end service()

	/**
	 * A page of templates is the slice of that page, with the envelope.
	 *
	 * @return void
	 */
	public function testListTemplatesPagesTheStore(): void {
		$this->objects->rows = [['name' => 'a'], ['name' => 'b'], ['name' => 'c'], ['name' => 'd'], ['name' => 'e']];

		$result = $this->service()->listTemplates(page: 2, limit: 2);

		$this->assertSame([['name' => 'c'], ['name' => 'd']], $result['data']);
		$this->assertSame(['page' => 2, 'limit' => 2, 'total' => 5, 'pages' => 3], $result['pagination']);
		$this->assertSame([['filters' => ['register' => 'crm', 'schema' => 'tpl']]], $this->objects->findAllCalls);

	}//end testListTemplatesPagesTheStore()

	/**
	 * Page and limit are clamped; an empty store has no pages.
	 *
	 * @return void
	 */
	public function testListTemplatesClampsPageAndLimit(): void {
		$result = $this->service()->listTemplates(page: 0, limit: 500);

		$this->assertSame([], $result['data']);
		$this->assertSame(['page' => 1, 'limit' => 100, 'total' => 0, 'pages' => 0], $result['pagination']);

		$this->assertSame(1, $this->service()->listTemplates(page: -3, limit: 0)['pagination']['limit']);

	}//end testListTemplatesClampsPageAndLimit()

	/**
	 * A failing read lists nothing and is logged.
	 *
	 * @return void
	 */
	public function testListTemplatesIsEmptyWhenTheReadFails(): void {
		$this->objects->rows = [['name' => 'a']];
		$this->objects->failFindAll = true;
		$this->logger->expects($this->once())->method('warning')->with('ComplianceService.loadTemplatesRaw: findAll failed', ['exception' => 'down']);

		$result = $this->service()->listTemplates(page: 1, limit: 10);

		$this->assertSame([], $result['data']);
		$this->assertSame(0, $result['pagination']['total']);

	}//end testListTemplatesIsEmptyWhenTheReadFails()

	/**
	 * Without OpenRegister there are no templates, and that is logged.
	 *
	 * @return void
	 */
	public function testListTemplatesIsEmptyWithoutOpenRegister(): void {
		$this->noObjectService = true;
		$this->logger->expects($this->once())->method('warning')->with('ComplianceService.getObjectService: OpenRegister unavailable', ['exception' => 'OpenRegister is not installed']);

		$this->assertSame([], $this->service()->listTemplates(page: 1, limit: 10)['data']);
		$this->assertSame([], $this->objects->findAllCalls);

	}//end testListTemplatesIsEmptyWithoutOpenRegister()

	/**
	 * A template is read by id; an empty id, a miss and a failing read answer null.
	 *
	 * @return void
	 */
	public function testGetTemplateById(): void {
		$this->objects->store['t-1'] = ['uuid' => 't-1', 'name' => 'Welcome'];
		$service = $this->service();

		$this->assertSame(['uuid' => 't-1', 'name' => 'Welcome'], $service->getTemplateById(templateId: 't-1'));
		$this->assertNull($service->getTemplateById(templateId: 'missing'));
		$this->assertNull($service->getTemplateById(templateId: ''));

		$this->objects->failFind = true;
		$this->logger->expects($this->once())->method('info')->with('ComplianceService.getTemplateById: not found', ['templateId' => 't-1', 'exception' => 'gone']);
		$this->assertNull($service->getTemplateById(templateId: 't-1'));

	}//end testGetTemplateById()

	/**
	 * A template without OpenRegister cannot be read.
	 *
	 * @return void
	 */
	public function testGetTemplateByIdWithoutOpenRegisterIsNull(): void {
		$this->objects->store['t-1'] = ['uuid' => 't-1'];
		$this->noObjectService = true;

		$this->assertNull($this->service()->getTemplateById(templateId: 't-1'));

	}//end testGetTemplateByIdWithoutOpenRegisterIsNull()

	/**
	 * A create is refused for a blank name, an unknown channel and a non-compliant body.
	 *
	 * @return void
	 */
	public function testCreateTemplateRefusesInvalidInput(): void {
		$service = $this->service();

		$this->assertSame(['error' => 'Invalid name'], $service->createTemplate(payload: ['name' => '  ', 'channel' => 'email'], createdByUid: 'u'));
		$this->assertSame(['error' => 'Invalid channel'], $service->createTemplate(payload: ['name' => 'N', 'channel' => 'fax'], createdByUid: 'u'));
		$refused = $service->createTemplate(payload: ['name' => 'N', 'channel' => 'email', 'bodyHtml' => '<p>Hi</p>', 'footerOverride' => 'Street 1'], createdByUid: 'u');
		$this->assertStringContainsString('{{unsubscribe_link}}', $refused['error']);
		$this->assertSame([], $this->objects->saves);

	}//end testCreateTemplateRefusesInvalidInput()

	/**
	 * A valid create stores the normalised template, stamped with its author.
	 *
	 * @return void
	 */
	public function testCreateTemplateStoresTheNormalisedTemplate(): void {
		$result = $this->service()->createTemplate(
			payload: [
				'name' => 'Welcome',
				'channel' => 'EMAIL',
				'subject' => 'Hi',
				'bodyHtml' => self::BODY,
				'footerOverride' => 'Street 1, Town',
				'articleIds' => [' a-1 ', 'a-2', 'a-1', '', ['x'], 7],
				'editorMode' => 'blocks',
				'blocks' => ['k' => ['type' => 'text']],
			],
			createdByUid: 'alice'
		);

		$this->assertCount(1, $this->objects->saves);
		$save = $this->objects->saves[0];
		$this->assertSame('crm', $save['register']);
		$this->assertSame('tpl', $save['schema']);
		$this->assertNull($save['uuid']);
		$object = $save['object'];
		$this->assertSame('Welcome', $object['name']);
		$this->assertSame('email', $object['channel']);
		$this->assertSame('', $object['bodyText']);
		$this->assertSame(['a-1', 'a-2', '7'], $object['articleIds']);
		$this->assertSame('blocks', $object['editorMode']);
		$this->assertSame([['type' => 'text']], $object['blocks']);
		$this->assertSame('alice', $object['createdBy']);
		$this->assertSame($object['createdAt'], $object['updatedAt']);
		$this->assertSame('new-1', $result['template']['uuid']);

	}//end testCreateTemplateStoresTheNormalisedTemplate()

	/**
	 * An SMS template needs no footer, and a non-list of articles stores none.
	 *
	 * @return void
	 */
	public function testCreateSmsTemplateNeedsNoFooter(): void {
		$result = $this->service()->createTemplate(payload: ['name' => 'Text', 'channel' => 'sms', 'bodyText' => 'Hi', 'articleIds' => 'a-1'], createdByUid: 'bob');

		$this->assertSame('sms', $result['template']['channel']);
		$this->assertSame([], $result['template']['articleIds']);
		$this->assertSame('html', $result['template']['editorMode']);

	}//end testCreateSmsTemplateNeedsNoFooter()

	/**
	 * A failing save answers an error and is logged.
	 *
	 * @return void
	 */
	public function testCreateTemplateReportsAFailedSave(): void {
		$this->objects->failSave = true;
		$this->logger->expects($this->once())->method('warning')->with('ComplianceService.saveTemplateObject: save failed', ['exception' => 'refused']);

		$result = $this->service()->createTemplate(payload: ['name' => 'N', 'channel' => 'sms'], createdByUid: 'u');

		$this->assertSame(['error' => 'Could not create template'], $result);

	}//end testCreateTemplateReportsAFailedSave()

	/**
	 * A create without OpenRegister is not stored.
	 *
	 * @return void
	 */
	public function testCreateTemplateWithoutOpenRegister(): void {
		$this->noObjectService = true;

		$this->assertSame(['error' => 'Could not create template'], $this->service()->createTemplate(payload: ['name' => 'N', 'channel' => 'sms'], createdByUid: 'u'));

	}//end testCreateTemplateWithoutOpenRegister()

	/**
	 * Patching an unknown template is refused.
	 *
	 * @return void
	 */
	public function testPatchTemplateRefusesAnUnknownTemplate(): void {
		$this->assertSame(['error' => 'Template not found'], $this->service()->patchTemplate(templateId: 'nope', patch: ['name' => 'X']));
		$this->assertSame([], $this->objects->saves);

	}//end testPatchTemplateRefusesAnUnknownTemplate()

	/**
	 * A patch overlays the editable string fields only, and saves under the existing id.
	 *
	 * @return void
	 */
	public function testPatchTemplateOverlaysEditableFields(): void {
		$this->objects->store['t-1'] = [
			'@self' => ['uuid' => 'uuid-1'],
			'name' => 'Old',
			'channel' => 'email',
			'bodyHtml' => self::BODY,
			'footerOverride' => 'Street 1',
			'createdBy' => 'alice',
			'articleIds' => ['a-1'],
		];

		$result = $this->service()->patchTemplate(
			templateId: 't-1',
			patch: ['name' => 'New', 'subject' => 42, 'channel' => 'sms', 'createdBy' => 'mallory', 'articleIds' => ['b', 'b', ' c ']]
		);

		$this->assertCount(1, $this->objects->saves);
		$save = $this->objects->saves[0];
		$this->assertSame('uuid-1', $save['uuid']);
		$this->assertSame('New', $save['object']['name']);
		$this->assertArrayNotHasKey('subject', $save['object']);
		$this->assertSame('email', $save['object']['channel']);
		$this->assertSame('alice', $save['object']['createdBy']);
		$this->assertSame(['b', 'c'], $save['object']['articleIds']);
		$this->assertArrayHasKey('updatedAt', $save['object']);
		$this->assertSame('New', $result['template']['name']);

	}//end testPatchTemplateOverlaysEditableFields()

	/**
	 * A patch that makes an email template non-compliant is refused and not saved.
	 *
	 * @return void
	 */
	public function testPatchTemplateRevalidates(): void {
		$this->objects->store['t-1'] = ['uuid' => 't-1', 'name' => 'Old', 'channel' => 'email', 'bodyHtml' => self::BODY, 'footerOverride' => 'Street 1'];

		$result = $this->service()->patchTemplate(templateId: 't-1', patch: ['footerOverride' => ' ']);

		$this->assertStringContainsString('physical address', $result['error']);
		$this->assertSame([], $this->objects->saves);

	}//end testPatchTemplateRevalidates()

	/**
	 * A patch whose save fails answers an error; the id falls back to `id`.
	 *
	 * @return void
	 */
	public function testPatchTemplateReportsAFailedSave(): void {
		$this->objects->store['t-1'] = ['id' => 'id-9', 'name' => 'Old', 'channel' => 'sms'];
		$this->objects->failSave = true;

		$result = $this->service()->patchTemplate(templateId: 't-1', patch: ['bodyText' => 'Hi']);

		$this->assertSame(['error' => 'Could not update template'], $result);
		$this->assertSame('id-9', $this->objects->saves[0]['uuid']);

	}//end testPatchTemplateReportsAFailedSave()
}//end class
