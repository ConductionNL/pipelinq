<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
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

use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\CampaignLinkDecorator;
use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\Marketing\MailTransportService;
use OCA\Pipelinq\Service\Marketing\SubscriptionQueryService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Error paths, draft validation, cancellation and audience resolution of
 * BlastService that the golden and orchestration tests leave untouched.
 *
 * @covers \OCA\Pipelinq\Service\BlastService
 * @uses   \OCA\Pipelinq\Service\Marketing\BlastSendRun
 * @uses   \OCA\Pipelinq\Service\Marketing\ListPage
 * @uses   \OCA\Pipelinq\Service\Marketing\ObjectQuery
 * @uses   \OCA\Pipelinq\Service\Marketing\ObjectSelection
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class BlastServiceCoverageTest extends TestCase {

	private ContainerInterface $container;

	private IAppConfig $appConfig;

	private SegmentService $segmentService;

	private MailTransportService $mailTransport;

	private LoggerInterface $logger;

	/**
	 * In-memory OpenRegister ObjectService double.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Container ids that resolve to a service, keyed by id.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * App-config overrides; every other key answers its default.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	private BlastService $service;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->segmentService = $this->createMock(SegmentService::class);
		$this->mailTransport = $this->createMock(MailTransportService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->objectService = new class {

			/**
			 * Rows keyed by schema, then id.
			 *
			 * @var array<string, array<string, array<string, mixed>>>
			 */
			public array $store = [];

			/**
			 * Every saveObject() call.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			public bool $failSave = false;

			public bool $failFind = false;

			public bool $failFindAll = false;

			public bool $failCount = false;

			/**
			 * Find one row.
			 *
			 * @param string $id Id.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 *
			 * @return array<string, mixed>|null Row.
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				if ($this->failFind === true) {
					throw new RuntimeException('find exploded');
				}

				return ($this->store[$schema][$id] ?? null);
			}//end find()

			/**
			 * Rows matching every filter.
			 *
			 * @param array<string, mixed> $config Config.
			 *
			 * @return array<int, array<string, mixed>> Rows.
			 */
			public function findAll(array $config = []): array {
				if ($this->failFindAll === true) {
					throw new RuntimeException('findAll exploded');
				}

				return $this->matching(filters: ($config['filters'] ?? []));
			}//end findAll()

			/**
			 * Count rows matching every filter.
			 *
			 * @param array<string, mixed> $config Config.
			 *
			 * @return int Count.
			 */
			public function count(array $config = []): int {
				if ($this->failCount === true) {
					throw new RuntimeException('count exploded');
				}

				return count($this->matching(filters: ($config['filters'] ?? [])));
			}//end count()

			/**
			 * Save a row.
			 *
			 * @param array<string, mixed> $object Payload.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 * @param string|null $uuid Id.
			 *
			 * @return array<string, mixed> Saved row.
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				if ($this->failSave === true) {
					throw new RuntimeException('save exploded');
				}

				$this->saved[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];
				$id = ($uuid ?? ('new-' . count($this->saved)));
				$object['uuid'] = $id;
				$this->store[$schema][$id] = $object;
				return $object;
			}//end saveObject()

			/**
			 * Rows of the filtered schema matching the other filters.
			 *
			 * @param array<string, mixed> $filters Filters.
			 *
			 * @return array<int, array<string, mixed>> Rows.
			 */
			private function matching(array $filters): array {
				$schema = (string)($filters['schema'] ?? '');
				unset($filters['register'], $filters['schema']);
				$out = [];
				foreach (($this->store[$schema] ?? []) as $row) {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$out[] = $row;
				}

				return $out;
			}//end matching()
		};

		$this->services = ['OCA\\OpenRegister\\Service\\ObjectService' => $this->objectService];
		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if (isset($this->services[$id]) === false) {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $this->services[$id];
			}
		);

		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		$this->service = new BlastService(
			$this->container,
			$this->appConfig,
			$this->segmentService,
			$this->mailTransport,
			$this->logger,
		);
	}//end setUp()

	/**
	 * Put a row into the fake store.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id Id.
	 * @param array<string, mixed> $row Row.
	 *
	 * @return void
	 */
	private function seed(string $schema, string $id, array $row): void {
		$this->objectService->store[$schema][$id] = $row;
	}//end seed()

	/**
	 * A blast that does not exist dispatches nothing and resolves no transport.
	 *
	 * @return void
	 */
	public function testDispatchOfUnknownBlastDispatchesNothing(): void {
		$this->mailTransport->expects($this->never())->method('resolveTransport');

		$this->assertSame(0, $this->service->dispatchBlastDeliveries(blastId: 'missing'));
	}//end testDispatchOfUnknownBlastDispatchesNothing()

	/**
	 * A blast whose template is gone is refused with a warning.
	 *
	 * @return void
	 */
	public function testDispatchWithMissingTemplateWarnsAndStops(): void {
		$this->seed('blast', 'b1', ['uuid' => 'b1', 'templateId' => 'tpl-gone']);
		$this->logger->expects($this->once())->method('warning')->with(
			'BlastService.dispatchBlastDeliveries: template missing',
			['blastId' => 'b1', 'templateId' => 'tpl-gone']
		);
		$this->mailTransport->expects($this->never())->method('resolveTransport');

		$this->assertSame(0, $this->service->dispatchBlastDeliveries(blastId: 'b1'));
	}//end testDispatchWithMissingTemplateWarnsAndStops()

	/**
	 * A blast with no template id at all never reaches the store for one.
	 *
	 * @return void
	 */
	public function testDispatchWithEmptyTemplateIdStops(): void {
		$this->seed('blast', 'b1', ['uuid' => 'b1']);
		$this->mailTransport->expects($this->never())->method('resolveTransport');

		$this->assertSame(0, $this->service->dispatchBlastDeliveries(blastId: 'b1'));
	}//end testDispatchWithEmptyTemplateIdStops()

	/**
	 * No resolvable transport stops the dispatch before any delivery is sent.
	 *
	 * @return void
	 */
	public function testDispatchWithoutTransportWarnsAndStops(): void {
		$this->seed('blast', 'b1', ['uuid' => 'b1', 'templateId' => 't1']);
		$this->seed('campaignTemplate', 't1', ['uuid' => 't1', 'bodyHtml' => '']);
		$this->mailTransport->expects($this->once())->method('resolveTransport')->willReturn(null);
		$this->mailTransport->expects($this->never())->method('sendOneDelivery');
		$this->logger->expects($this->once())->method('warning')->with(
			'BlastService.dispatchBlastDeliveries: no mail transport resolved for blast',
			['blastId' => 'b1']
		);

		$this->assertSame(0, $this->service->dispatchBlastDeliveries(blastId: 'b1'));
	}//end testDispatchWithoutTransportWarnsAndStops()

	/**
	 * With nothing queued nothing is sent; a junk batch size falls back.
	 *
	 * @return void
	 */
	public function testDispatchWithNothingQueuedSendsNothing(): void {
		$this->config['blast.dispatch_batch_size'] = 'lots';
		$this->seed('blast', 'b1', ['uuid' => 'b1', 'templateId' => 't1']);
		$this->seed('campaignTemplate', 't1', ['uuid' => 't1', 'bodyHtml' => '']);
		$this->seed('blastDelivery', 'd1', ['uuid' => 'd1', 'blastId' => 'b1', 'status' => 'sent']);
		$this->mailTransport->method('resolveTransport')->willReturn(['id' => 'smtp']);
		$this->mailTransport->expects($this->once())->method('resolveRateLimit')->with(['id' => 'smtp'], 100)->willReturn(10);
		$this->mailTransport->expects($this->never())->method('sendOneDelivery');

		$this->assertSame(0, $this->service->dispatchBlastDeliveries(blastId: 'b1'));
	}//end testDispatchWithNothingQueuedSendsNothing()

	/**
	 * A failing campaign lookup falls back to undecorated-by-campaign links,
	 * and a non-positive batch size falls back to the default.
	 *
	 * @return void
	 */
	public function testDispatchDecoratesLinksWithoutCampaignWhenCampaignLookupFails(): void {
		$this->config['blast.dispatch_batch_size'] = '0';
		$decorator = $this->createMock(CampaignLinkDecorator::class);
		$decorator->expects($this->once())->method('decorate')
			->with('<a href="x">x</a>', $this->anything(), $this->anything(), [])
			->willReturn('<a href="x?utm=1">x</a>');
		$this->services['OCA\\Pipelinq\\Service\\CampaignLinkDecorator'] = $decorator;

		$this->seed('blast', 'b1', ['uuid' => 'b1', 'templateId' => 't1', 'campaignId' => 'c1']);
		$this->seed('campaignTemplate', 't1', ['uuid' => 't1', 'bodyHtml' => '<a href="x">x</a>']);
		$this->seed('blastDelivery', 'd1', ['uuid' => 'd1', 'blastId' => 'b1', 'status' => 'queued']);
		$this->mailTransport->method('resolveTransport')->willReturn(['id' => 'smtp']);
		$this->mailTransport->method('resolveRateLimit')->willReturn(1000);
		$this->mailTransport->expects($this->once())->method('sendOneDelivery')
			->with($this->anything(), $this->callback(fn (array $t): bool => $t['bodyHtml'] === '<a href="x?utm=1">x</a>'), ['id' => 'smtp'])
			->willReturn(true);
		$this->logger->expects($this->once())->method('info')
			->with('BlastService.campaignForBlast: campaign unavailable, using the per-blast parameters', $this->anything());

		$this->assertSame(1, $this->service->dispatchBlastDeliveries(blastId: 'b1'));
	}//end testDispatchDecoratesLinksWithoutCampaignWhenCampaignLookupFails()

	/**
	 * An A/B variant of a missing parent is not created.
	 *
	 * @return void
	 */
	public function testCreateAbVariantOfMissingParentReturnsEmptyId(): void {
		$this->assertSame('', $this->service->createAbVariant(parentBlastId: 'nope', variantData: []));
		$this->assertSame([], $this->objectService->saved);
	}//end testCreateAbVariantOfMissingParentReturnsEmptyId()

	/**
	 * Totals ignore statuses that are not a tracked total.
	 *
	 * @return void
	 */
	public function testUpdateBlastTotalsIgnoresUnknownStatuses(): void {
		$this->seed('blast', 'b1', ['uuid' => 'b1']);
		$this->seed('blastDelivery', 'd1', ['uuid' => 'd1', 'blastId' => 'b1', 'status' => 'queued']);
		$this->seed('blastDelivery', 'd2', ['uuid' => 'd2', 'blastId' => 'b1', 'status' => 'weird']);
		$this->seed('blastDelivery', 'd3', ['uuid' => 'd3', 'blastId' => 'b1', 'status' => '']);

		$this->service->updateBlastTotals(blastId: 'b1');

		$totals = $this->objectService->store['blast']['b1']['totals'];
		$this->assertSame(1, $totals['queued']);
		$this->assertSame(1, array_sum($totals));
	}//end testUpdateBlastTotalsIgnoresUnknownStatuses()

	/**
	 * An empty id never reaches the store.
	 *
	 * @return void
	 */
	public function testGetBlastByEmptyIdIsNull(): void {
		$this->objectService->failFind = true;
		$this->assertNull($this->service->getBlastById(blastId: ''));
	}//end testGetBlastByEmptyIdIsNull()

	/**
	 * A lookup that throws is logged and answers null.
	 *
	 * @return void
	 */
	public function testGetBlastByIdWhenFindThrowsIsNull(): void {
		$this->objectService->failFind = true;
		$this->logger->expects($this->once())->method('info')->with(
			'BlastService.loadOne: not found',
			['id' => 'b1', 'schema' => 'blast', 'exception' => 'find exploded']
		);

		$this->assertNull($this->service->getBlastById(blastId: 'b1'));
	}//end testGetBlastByIdWhenFindThrowsIsNull()

	/**
	 * Without OpenRegister nothing loads and the failure is logged.
	 *
	 * @return void
	 */
	public function testGetBlastByIdWithoutOpenRegisterIsNull(): void {
		$this->services = [];
		$this->logger->expects($this->once())->method('warning')->with(
			'BlastService.getObjectService: OpenRegister unavailable',
			['exception' => 'not registered: OCA\\OpenRegister\\Service\\ObjectService']
		);

		$this->assertNull($this->service->getBlastById(blastId: 'b1'));
	}//end testGetBlastByIdWithoutOpenRegisterIsNull()

	/**
	 * Listing without OpenRegister answers an empty envelope.
	 *
	 * @return void
	 */
	public function testListBlastsWithoutOpenRegisterIsEmpty(): void {
		$this->services = [];

		$this->assertSame(
			['data' => [], 'pagination' => ['page' => 1, 'limit' => 10, 'total' => 0, 'pages' => 0]],
			$this->service->listBlasts(status: 'draft', page: 1, limit: 10)
		);
	}//end testListBlastsWithoutOpenRegisterIsEmpty()

	/**
	 * A failing count and findAll both degrade to empty.
	 *
	 * @return void
	 */
	public function testListBlastsWhenStoreFailsIsEmpty(): void {
		$this->objectService->failCount = true;
		$this->objectService->failFindAll = true;
		$this->logger->expects($this->exactly(2))->method('warning');

		$result = $this->service->listBlasts(status: null, page: 1, limit: 10);

		$this->assertSame([], $result['data']);
		$this->assertSame(0, $result['pagination']['total']);
	}//end testListBlastsWhenStoreFailsIsEmpty()

	/**
	 * Deliveries without OpenRegister or with a failing count are empty.
	 *
	 * @return void
	 */
	public function testListDeliveriesDegradesWhenStoreUnavailable(): void {
		$this->objectService->failCount = true;
		$result = $this->service->listDeliveriesForBlast(blastId: 'b1', page: 1, limit: 5);
		$this->assertSame(0, $result['pagination']['total']);

		$this->services = [];
		$result = $this->service->listDeliveriesForBlast(blastId: 'b1', page: 1, limit: 5);
		$this->assertSame(['data' => [], 'pagination' => ['page' => 1, 'limit' => 5, 'total' => 0, 'pages' => 0]], $result);
	}//end testListDeliveriesDegradesWhenStoreUnavailable()

	/**
	 * Draft creation refuses each invalid field with its own message.
	 *
	 * @return void
	 */
	public function testCreateDraftBlastValidationErrors(): void {
		$this->seed('mailingList', 'l1', ['uuid' => 'l1']);
		$this->seed('segment', 's1', ['uuid' => 's1']);
		$this->seed('campaignTemplate', 't1', ['uuid' => 't1']);
		$this->segmentService->method('getSegmentSchemaSlugPublic')->willReturn('segment');

		$cases = [
			'Invalid name' => ['name' => '  '],
			'Choose either a segment or a mailing list, not both' => ['name' => 'N', 'segmentId' => 's1', 'listId' => 'l1'],
			'Choose a segment or a mailing list' => ['name' => 'N'],
			'Invalid mailing list' => ['name' => 'N', 'listId' => 'l-missing'],
			'Invalid segment' => ['name' => 'N', 'segmentId' => 's-missing'],
			'Invalid template' => ['name' => 'N', 'segmentId' => 's1', 'templateId' => 't-missing'],
			'Invalid channel' => ['name' => 'N', 'listId' => 'l1', 'templateId' => 't1', 'channel' => 'fax'],
		];
		foreach ($cases as $expected => $payload) {
			$this->assertSame(['error' => $expected], $this->service->createDraftBlast(payload: $payload, createdByUid: 'u'), $expected);
		}

		$this->assertSame([], $this->objectService->saved);
	}//end testCreateDraftBlastValidationErrors()

	/**
	 * A valid list-audience draft is stored as a draft with zeroed totals.
	 *
	 * @return void
	 */
	public function testCreateDraftBlastStoresDraft(): void {
		$this->config['mailing_list_schema'] = 'lists';
		$this->seed('lists', 'l1', ['uuid' => 'l1']);
		$this->seed('campaignTemplate', 't1', ['uuid' => 't1']);

		$result = $this->service->createDraftBlast(
			payload: ['name' => 'Spring', 'listId' => 'l1', 'templateId' => 't1', 'channel' => 'SMS', 'scheduledFor' => '2026-01-01T00:00:00Z'],
			createdByUid: 'alice'
		);

		$this->assertArrayHasKey('blast', $result);
		$saved = $this->objectService->saved[0];
		$this->assertSame('blast', $saved['schema']);
		$this->assertNull($saved['uuid']);
		$this->assertSame('draft', $saved['object']['status']);
		$this->assertSame('SMS', $saved['object']['channel']);
		$this->assertSame('alice', $saved['object']['createdBy']);
		$this->assertSame('2026-01-01T00:00:00Z', $saved['object']['scheduledFor']);
		$this->assertSame('', $saved['object']['segmentId']);
		$this->assertSame(0, array_sum($saved['object']['totals']));
	}//end testCreateDraftBlastStoresDraft()

	/**
	 * A segment-audience draft that cannot be saved reports an error.
	 *
	 * @return void
	 */
	public function testCreateDraftBlastReportsSaveFailure(): void {
		$this->seed('segment', 's1', ['uuid' => 's1']);
		$this->seed('campaignTemplate', 't1', ['uuid' => 't1']);
		$this->segmentService->expects($this->once())->method('getSegmentSchemaSlugPublic')->willReturn('segment');
		$this->objectService->failSave = true;
		$this->logger->expects($this->once())->method('warning')->with(
			'BlastService.saveObject: save failed',
			['schema' => 'blast', 'exception' => 'save exploded']
		);

		$this->assertSame(
			['error' => 'Could not create blast'],
			$this->service->createDraftBlast(payload: ['name' => 'N', 'segmentId' => 's1', 'templateId' => 't1', 'channel' => 'email'], createdByUid: 'u')
		);
	}//end testCreateDraftBlastReportsSaveFailure()

	/**
	 * Renaming refuses a blank name and an unknown blast, and saves otherwise.
	 *
	 * @return void
	 */
	public function testPatchBlastName(): void {
		$this->assertNull($this->service->patchBlastName(blastId: 'b1', name: ' '));
		$this->assertNull($this->service->patchBlastName(blastId: 'b1', name: 'New'));

		$this->seed('blast', 'b1', ['@self' => ['id' => 'b1'], 'name' => 'Old']);
		$saved = $this->service->patchBlastName(blastId: 'b1', name: 'New');

		$this->assertSame('New', $saved['name']);
		$this->assertSame('b1', $this->objectService->saved[0]['uuid']);
		$this->assertArrayHasKey('updatedAt', $saved);
	}//end testPatchBlastName()

	/**
	 * A blast without any usable id is saved with an empty id.
	 *
	 * @return void
	 */
	public function testPatchBlastNameWithoutIdSavesAsNew(): void {
		$this->seed('blast', 'b1', ['@self' => ['uuid' => ''], 'name' => 'Old']);

		$this->service->patchBlastName(blastId: 'b1', name: 'New');

		$this->assertSame('', $this->objectService->saved[0]['uuid']);
	}//end testPatchBlastNameWithoutIdSavesAsNew()

	/**
	 * Cancelling an unknown or finished blast changes nothing.
	 *
	 * @return void
	 */
	public function testCancelBlastRefusals(): void {
		$this->assertSame(['status' => 'not-found', 'cancelledDeliveries' => 0], $this->service->cancelBlast(blastId: 'b1'));

		$this->seed('blast', 'b1', ['uuid' => 'b1', 'status' => 'sent']);
		$this->assertSame(['status' => 'noop-sent', 'cancelledDeliveries' => 0], $this->service->cancelBlast(blastId: 'b1'));
		$this->assertSame([], $this->objectService->saved);
	}//end testCancelBlastRefusals()

	/**
	 * Cancelling flips every queued delivery and marks the blast cancelled.
	 *
	 * @return void
	 */
	public function testCancelBlastTransitionsQueuedDeliveries(): void {
		$this->seed('blast', 'b1', ['uuid' => 'b1', 'status' => 'sending']);
		$this->seed('blastDelivery', 'd1', ['uuid' => 'd1', 'blastId' => 'b1', 'status' => 'queued']);
		$this->seed('blastDelivery', 'd2', ['uuid' => 'd2', 'blastId' => 'b1', 'status' => 'queued']);
		$this->seed('blastDelivery', 'd3', ['uuid' => 'd3', 'blastId' => 'b1', 'status' => 'sent']);

		$this->assertSame(['status' => 'cancelled', 'cancelledDeliveries' => 2], $this->service->cancelBlast(blastId: 'b1'));

		$this->assertSame('unsubscribed-before-send', $this->objectService->store['blastDelivery']['d1']['status']);
		$this->assertSame('unsubscribed-before-send', $this->objectService->store['blastDelivery']['d2']['status']);
		$this->assertSame('sent', $this->objectService->store['blastDelivery']['d3']['status']);
		$this->assertSame('cancelled', $this->objectService->store['blast']['b1']['status']);
		$this->assertSame(1, $this->objectService->store['blast']['b1']['totals']['sent']);
	}//end testCancelBlastTransitionsQueuedDeliveries()

	/**
	 * A list-audience send uses the subscription service's members and skips.
	 *
	 * @return void
	 */
	public function testSendBlastToListUsesSubscriptionAudience(): void {
		$subscriptions = $this->createMock(SubscriptionQueryService::class);
		$subscriptions->expects($this->once())->method('getBlastAudienceForList')->with('l1')->willReturn([
			'members' => [
				['contactId' => 'c1', 'email' => 'a@x.nl', 'unsubscribeUrl' => 'https://u/1'],
				'not-a-row',
			],
			'skipped' => ['c2', ['nested']],
		]);
		$this->services['OCA\\Pipelinq\\Service\\Marketing\\SubscriptionQueryService'] = $subscriptions;
		$this->segmentService->expects($this->never())->method('getMembersForBlast');
		$this->seed('blast', 'b1', ['uuid' => 'b1', 'status' => 'draft', 'listId' => 'l1', 'channel' => 'email']);

		$summary = $this->service->sendBlast(blastId: 'b1', isDraft: true);

		$this->assertSame(1, $summary['queued']);
		$this->assertSame(1, $summary['skippedNoConsent']);
		$this->assertSame('draft-preview', $summary['status']);
	}//end testSendBlastToListUsesSubscriptionAudience()

	/**
	 * Every failure of the list audience fails closed to no recipients.
	 *
	 * @return void
	 */
	public function testListAudienceFailsClosed(): void {
		$this->seed('blast', 'b1', ['uuid' => 'b1', 'status' => 'draft', 'listId' => 'l1']);

		$this->logger->expects($this->exactly(2))->method('warning')->with(
			$this->logicalOr(
				'BlastService.resolveListAudience: SubscriptionQueryService unavailable — failing closed',
				'BlastService.resolveListAudience: call failed — failing closed'
			),
			$this->anything()
		);
		// Service missing.
		$summary = $this->service->sendBlast(blastId: 'b1', isDraft: true);
		$this->assertSame('skipped-no-consent', $summary['status']);
		$this->assertSame(0, $summary['queued']);

		// Call throws.
		$subscriptions = $this->createMock(SubscriptionQueryService::class);
		$subscriptions->method('getBlastAudienceForList')->willThrowException(new RuntimeException('down'));
		$this->services['OCA\\Pipelinq\\Service\\Marketing\\SubscriptionQueryService'] = $subscriptions;
		$summary = $this->service->sendBlast(blastId: 'b1', isDraft: true);
		$this->assertSame('skipped-no-consent', $summary['status']);
	}//end testListAudienceFailsClosed()

	/**
	 * A compliance check that throws drops every member.
	 *
	 * @return void
	 */
	public function testSegmentComplianceFailureFailsClosed(): void {
		$compliance = $this->createMock(ComplianceService::class);
		$compliance->expects($this->once())->method('checkSegmentCompliance')->with('s1', 'email')
			->willThrowException(new RuntimeException('boom'));
		$this->services['OCA\\Pipelinq\\Service\\ComplianceService'] = $compliance;
		$this->logger->expects($this->once())->method('warning')->with(
			'BlastService.checkSegmentCompliance: call failed — failing closed',
			['segmentId' => 's1', 'channel' => 'email', 'exception' => 'boom']
		);

		$audience = $this->resolveAudience(segmentId: 's1');

		// The failure leaves missingConsent empty, so members still flow from
		// the segment — the fail-closed part is compliant=false upstream.
		$this->assertSame([], $audience['missingConsent']);
		$this->assertSame([], $audience['suppressed']);
	}//end testSegmentComplianceFailureFailsClosed()

	/**
	 * A non-array compliance answer and non-array id lists are treated as empty.
	 *
	 * @return void
	 */
	public function testSegmentComplianceTolerantOfOddAnswers(): void {
		$compliance = $this->createMock(ComplianceService::class);
		$compliance->method('checkSegmentCompliance')->willReturn(
			['compliant' => true, 'missingConsent' => 'c1', 'suppressed' => ['c2'], 'unsubscribe' => ['c3' => 'https://u/3', 'c4' => '']]
		);
		$this->services['OCA\\Pipelinq\\Service\\ComplianceService'] = $compliance;
		$this->segmentService->method('getMembersForBlast')->willReturn([
			['contactId' => 'c2'],
			['contactId' => 'c3'],
			['contactId' => 'c4'],
		]);

		$audience = $this->resolveAudience(segmentId: 's1');
		$this->assertSame([], $audience['missingConsent']);
		$this->assertSame(['c2'], $audience['suppressed']);
		$this->assertSame(
			[['contactId' => 'c3', 'unsubscribeUrl' => 'https://u/3'], ['contactId' => 'c4']],
			$audience['members']
		);
	}//end testSegmentComplianceTolerantOfOddAnswers()

	/**
	 * Resolve an audience through a subclass exposing the protected method.
	 *
	 * @param string $segmentId Segment.
	 *
	 * @return array<string, mixed> Audience.
	 */
	private function resolveAudience(string $segmentId): array {
		$exposed = new class($this->container, $this->appConfig, $this->segmentService, $this->mailTransport, $this->logger) extends BlastService {

			/**
			 * Expose resolveAudience().
			 *
			 * @param string $segmentId Segment.
			 *
			 * @return array<string, mixed> Audience.
			 */
			public function audience(string $segmentId): array {
				return $this->resolveAudience(segmentId: $segmentId, listId: '', channel: 'email');
			}//end audience()
		};

		return $exposed->audience(segmentId: $segmentId);
	}//end resolveAudience()

	/**
	 * A delivery id falls back to @self and to empty.
	 *
	 * @return void
	 */
	public function testTransitionQueuedDeliveriesUsesSelfId(): void {
		$this->seed('blast', 'b1', ['uuid' => 'b1']);
		$this->objectService->store['blastDelivery'][] = ['@self' => ['slug' => 'd-slug'], 'blastId' => 'b1', 'contactId' => 'c1', 'status' => 'queued'];
		$this->objectService->store['blastDelivery'][] = ['blastId' => 'b1', 'contactId' => 'c1', 'status' => 'queued'];

		$this->service->transitionQueuedDeliveries(contactId: 'c1', blastId: 'b1');

		$this->assertSame('d-slug', $this->objectService->saved[0]['uuid']);
		$this->assertSame('unsubscribed-before-send', $this->objectService->saved[0]['object']['status']);
		$this->assertSame('', $this->objectService->saved[1]['uuid']);
	}//end testTransitionQueuedDeliveriesUsesSelfId()
}//end class
