<?php

/**
 * Tests for LeadRelatedSummaryController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Controller\LeadRelatedSummaryController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Lead\LeadRelatedSummary;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Who reads a lead's Related summary.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-the-related-card-names-earlier-deals-and-hours
 */
class LeadRelatedSummaryControllerTest extends TestCase {
	/**
	 * The controller for a caller who may or may not read the lead.
	 *
	 * @param bool $mayAccess Whether the policy lets the caller read it.
	 *
	 * @return LeadRelatedSummaryController The controller.
	 */
	private function controller(bool $mayAccess): LeadRelatedSummaryController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$entity = new ObjectEntity();
		$entity->setUuid('l1');
		$entity->setObject(['client' => 'c1', 'assignee' => 'someone']);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn($entity);
		$summary = $this->createMock(LeadRelatedSummary::class);
		$summary->method('forLead')->willReturn(['earlier' => '2 earlier deals, both won', 'hours' => '14.5']);

		return new LeadRelatedSummaryController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			policy: $this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['mayAccess' => $mayAccess]),
			appConfig: $this->createMock(IAppConfig::class),
			objectService: $objects,
			summary: $summary,
		);
	}//end controller()

	/**
	 * A reader of the lead gets the summary; anyone else 403.
	 *
	 * @return void
	 */
	public function testOnlyAReaderOfTheLeadGetsTheSummary(): void {
		$ok = $this->controller(mayAccess: true)->show(id: 'l1');
		self::assertSame(200, $ok->getStatus());
		self::assertSame(['earlier' => '2 earlier deals, both won', 'hours' => '14.5'], $ok->getData());
		self::assertSame(403, $this->controller(mayAccess: false)->show(id: 'l1')->getStatus());
	}//end testOnlyAReaderOfTheLeadGetsTheSummary()
}//end class
