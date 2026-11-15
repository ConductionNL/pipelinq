<?php

/**
 * Unit tests for EventLogQuery.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Cti;

use OCA\Pipelinq\Service\Cti\EventLogQuery;
use OCA\Pipelinq\Service\EntityActivityQuery;
use PHPUnit\Framework\TestCase;

/**
 * EventLogQuery and EntityActivityQuery build scoped findAll configurations.
 */
class EventLogQueryTest extends TestCase {

	/**
	 * The event log query is scoped, newest first, and drops empty filters.
	 *
	 * @return void
	 */
	public function testTheEventLogQuery(): void {
		$query = new EventLogQuery(filters: ['platform' => 'voys', 'callId' => '', 'agent' => null, 'direction' => 'inbound', 'zero' => 0], limit: 25, offset: 50);

		self::assertSame(
			[
				'filters' => ['register' => 'reg', 'schema' => 'cti-event', 'platform' => 'voys', 'direction' => 'inbound', 'zero' => 0],
				'limit' => 25,
				'offset' => 50,
				'sort' => ['received_at' => 'desc'],
			],
			$query->config(register: 'reg', schema: 'cti-event')
		);
	}//end testTheEventLogQuery()

	/**
	 * The entity activity query scopes a schema's filters to the register.
	 *
	 * @return void
	 */
	public function testTheEntityActivityQuery(): void {
		$query = new EntityActivityQuery(registerId: '7', entityType: 'client', entityId: 'c-1');

		self::assertSame(['register' => '7', 'schema' => '12', 'client' => 'c-1'], $query->schemaFilters(schemaId: '12', filters: ['client' => 'c-1']));
		self::assertSame('client', $query->entityType);
		self::assertSame('c-1', $query->entityId);
	}//end testTheEntityActivityQuery()
}//end class
