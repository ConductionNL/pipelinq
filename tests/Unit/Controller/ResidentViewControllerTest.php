<?php
/**
 * Unit tests for ResidentViewController (what the resident sees on a ticket).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Controller\ResidentViewController;
use OCA\Pipelinq\Portal\PortalContributionProvider;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalRequestService;
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCA\Pipelinq\Service\TicketService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCA\Pipelinq\Service\ResidentViewRows;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The preview is built by the portal's own code and answers only a ticket the
 * caller may read.
 */
class ResidentViewControllerTest extends TestCase {

	/**
	 * The OpenRegister double.
	 *
	 * @var ObjectServiceInterface
	 */
	private ObjectServiceInterface $objects;

	/**
	 * Build the controller over real portal presenters.
	 *
	 * @param bool $portaliq    Whether portaliq is installed.
	 * @param bool $exposeName  Whether the default tenant shows the handler's name.
	 *
	 * @return ResidentViewController The controller.
	 */
	private function controller(bool $portaliq = false, bool $exposeName = false): ResidentViewController {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$tickets = $this->createMock(TicketService::class);
		$tickets->method('isConfigured')->willReturn(true);
		$tickets->method('getRegisterId')->willReturn('20');
		$tickets->method('getSchemaId')->willReturn('40');
		$tickets->method('getObjectService')->willReturn($this->objects);

		$requests = new PortalRequestService(
			$this->createMock(MainRegisterReader::class),
			$this->createMock(PortalScopeResolver::class),
			$this->createMock(PortalAuditService::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
		);

		$tenant = $this->createMock(PortalTenantService::class);
		$tenant->method('getConfig')->willReturnCallback(
			static fn (string $id): ?array => $id === PortalTenantService::DEFAULT_TENANT ? ['exposeAssigneeName' => $exposeName] : null
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $app === 'portaliq' && $portaliq);

		return new ResidentViewController(
			$this->createMock(IRequest::class),
			$tickets,
			$requests,
			new PortalContributionProvider(),
			$tenant,
			$apps,
			new ResidentViewRows($this->l10n()),
		);
	}//end controller()

	/**
	 * A ticket entity with the given fields.
	 *
	 * @param array<string, mixed> $data The fields.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function ticket(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('t-1');
		$entity->setObject($data);

		return $entity;
	}//end ticket()

	/**
	 * A request ticket answers the resident's view, without the internal note.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/resident-view-preview/spec.md#requirement-a-request-ticket-previews-the-residents-view-req-rvp-001
	 */
	public function testARequestTicketAnswersTheResidentsView(): void {
		$controller = $this->controller();
		$this->objects->method('find')->willReturn(
			$this->ticket(
				[
					'ticketType' => 'request',
					'title' => 'Bin not emptied',
					'status' => 'in_progress',
					'description' => 'Since Monday',
					'notes' => 'internal: second report this month',
					'priority' => 'high',
					'assignee' => 'm.bakker',
					'customerMessage' => 'We will come by on Thursday.',
				]
			)
		);

		$response = $controller->show(id: 't-1');
		$data     = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Bin not emptied', $data['bespoke']['subject']);
		$this->assertSame('We will come by on Thursday.', $data['bespoke']['notes'][0]['message']);
		$this->assertStringNotContainsString('internal', json_encode($data['bespoke']));
		$this->assertArrayNotHasKey('assignee', $data['bespoke']);
		$this->assertNull($data['portaliq']);
		$this->assertSame(['assignee', 'notes', 'priority'], $data['internalFields']);
	}//end testARequestTicketAnswersTheResidentsView()

	/**
	 * The handler's name shows only when the portal setting shows it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/resident-view-preview/spec.md#requirement-a-request-ticket-previews-the-residents-view-req-rvp-001
	 */
	public function testTheHandlersNameFollowsThePortalSetting(): void {
		$controller = $this->controller(exposeName: true);
		$this->objects->method('find')->willReturn(
			$this->ticket(['ticketType' => 'request', 'title' => 'X', 'assignee' => 'm.bakker'])
		);

		$data = $controller->show(id: 't-1')->getData();

		$this->assertSame('m.bakker', $data['bespoke']['assignee']);
		$this->assertNotContains('assignee', $data['internalFields']);
	}//end testTheHandlersNameFollowsThePortalSetting()

	/**
	 * With portaliq and a client, the organisation's view is the second panel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/resident-view-preview/spec.md#requirement-the-preview-shows-the-organisation-portal-too-req-rvp-002
	 */
	public function testPortaliqShowsTheOrganisationsView(): void {
		$controller = $this->controller(portaliq: true);
		$this->objects->method('find')->willReturn(
			$this->ticket(
				[
					'ticketType' => 'request',
					'client' => 'acme',
					'title' => 'Invoice question',
					'status' => 'new',
					'customerMessage' => 'Answered by mail.',
					'priority' => 'low',
				]
			)
		);

		$data = $controller->show(id: 't-1')->getData();

		$this->assertSame('Answered by mail.', $data['portaliq']['fields']['customerMessage']);
		$this->assertSame('Invoice question', $data['portaliq']['fields']['title']);
		$this->assertArrayNotHasKey('priority', $data['portaliq']['fields']);
		$this->assertContains('priority', $data['internalFields']);
	}//end testPortaliqShowsTheOrganisationsView()

	/**
	 * A complaint ticket has no resident preview.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/resident-view-preview/spec.md#requirement-a-request-ticket-previews-the-residents-view-req-rvp-001
	 */
	public function testAComplaintHasNoBespokePanel(): void {
		$controller = $this->controller();
		$this->objects->method('find')->willReturn($this->ticket(['ticketType' => 'complaint', 'title' => 'Rude caller']));

		$data = $controller->show(id: 't-1')->getData();

		$this->assertNull($data['bespoke']);
	}//end testAComplaintHasNoBespokePanel()

	/**
	 * A ticket the caller may not read answers 404 and no data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/resident-view-preview/spec.md#requirement-the-preview-respects-the-handlers-own-rights-req-rvp-003
	 */
	public function testAnUnreadableTicketIsNotFound(): void {
		$controller = $this->controller();
		$this->objects->method('find')->willThrowException(new RuntimeException('no access'));

		$response = $controller->show(id: 't-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayNotHasKey('bespoke', $response->getData());
	}//end testAnUnreadableTicketIsNotFound()
	/**
	 * A translator that marks what it translated.
	 *
	 * @return IL10N The stub.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:' . $text);
		return $l10n;
	}//end l10n()

	/**
	 * The panels come as labelled, translated rows a facts card draws.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/resident-view-as-configuration/specs/resident-view-preview/spec.md#requirement-the-server-answers-the-preview-as-labelled-rows
	 */
	public function testThePanelsComeAsLabelledRows(): void {
		$rows = new ResidentViewRows($this->l10n());

		$resident = $rows->residentRows(
			bespoke: [
				'number' => 'Z-1', 'subject' => 'Bin', 'category' => null, 'status' => 'new', 'date' => '2026-10-01', 'body' => 'Full',
				'assigneeHidden' => true,
				'notes' => [['author' => 'handler', 'message' => 'On it'], ['author' => 'resident', 'message' => 'Thanks']],
			]
		);
		$this->assertSame(['label' => 'nl:Number', 'value' => 'Z-1'], $resident[0]);
		$this->assertSame(['label' => 'nl:Category', 'value' => '-'], $resident[2]);
		$this->assertSame(['nl:Number', 'nl:Subject', 'nl:Category', 'nl:Status', 'nl:Date', 'nl:Description', 'nl:You', 'nl:Resident'], array_column($resident, 'label'));
		$this->assertCount(7, $rows->residentRows(bespoke: ['assignee' => 'Pieter', 'notes' => []]), 'The handler shows when the portal exposes it');
		$this->assertSame([], $rows->residentRows(bespoke: null));

		$this->assertSame([['label' => 'nl:Subject', 'value' => 'Bin'], ['label' => 'nl:custom', 'value' => '-']], $rows->portalRows(portal: ['fields' => ['title' => 'Bin', 'custom' => '']]));
		$this->assertSame([['label' => 'nl:Stays internal', 'value' => 'nl:Notes, nl:Priority']], $rows->internalRows(fields: ['notes', 'priority']));
		$this->assertSame([], $rows->internalRows(fields: []));
	}//end testThePanelsComeAsLabelledRows()
}//end class
