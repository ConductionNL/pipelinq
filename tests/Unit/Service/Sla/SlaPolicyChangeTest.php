<?php

/**
 * Unit tests for SlaPolicyChange.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Sla
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Sla;

use OCA\Pipelinq\Service\Sla\SlaPolicyChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The operation and justification of an SLA policy write.
 */
#[CoversClass(SlaPolicyChange::class)]
class SlaPolicyChangeTest extends TestCase {

	/**
	 * Without an id the write is a create; with one it is an update.
	 *
	 * @return void
	 */
	public function testTheIdDecidesTheAction(): void {
		$this->assertSame(expected: 'create', actual: (new SlaPolicyChange(payload: [], register: 'r', schema: 's'))->action());
		$this->assertSame(expected: 'update', actual: (new SlaPolicyChange(payload: [], register: 'r', schema: 's', id: 'p1'))->action());
	}//end testTheIdDecidesTheAction()

	/**
	 * The justification is read from the payload, empty when it carries none.
	 *
	 * @return void
	 */
	public function testTheJustificationComesFromThePayload(): void {
		$change = new SlaPolicyChange(payload: ['justification' => 'audit'], register: 'r', schema: 's');
		$this->assertSame(expected: 'audit', actual: $change->justification());
		$this->assertSame(expected: '', actual: (new SlaPolicyChange(payload: [], register: 'r', schema: 's'))->justification());
	}//end testTheJustificationComesFromThePayload()

	/**
	 * The audit context carries the action, actor, justification, both sides and a timestamp.
	 *
	 * @return void
	 */
	public function testTheAuditContext(): void {
		$change = new SlaPolicyChange(payload: ['justification' => 'audit', 'name' => 'new'], register: 'r', schema: 's', id: 'p1');

		$context = $change->auditContext(actorId: 'anouk', before: ['name' => 'old']);
		$timestamp = $context['timestamp'];
		unset($context['timestamp']);

		$this->assertSame(
			expected: [
				'action' => 'update',
				'actor' => 'anouk',
				'justification' => 'audit',
				'before' => ['name' => 'old'],
				'after' => ['justification' => 'audit', 'name' => 'new'],
			],
			actual: $context
		);
		$this->assertNotFalse(condition: \DateTimeImmutable::createFromFormat(format: \DateTimeInterface::ATOM, datetime: $timestamp));
	}//end testTheAuditContext()
}//end class
