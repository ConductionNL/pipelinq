<?php

/**
 * Unit tests for ContactMoments.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\ContactReport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\ContactReport;

use DateTimeImmutable;
use JsonSerializable;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCA\Pipelinq\Service\ContactReport\ContactMoments;
use OCA\Pipelinq\Service\ContactReport\ContactReportFilter;
use OCA\Pipelinq\Service\TicketService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The contact moments of a period: how stored tickets are read, windowed and filtered.
 */
#[CoversClass(ContactMoments::class)]
#[UsesClass(ContactReportFilter::class)]
final class ContactMomentsTest extends TestCase {

	/**
	 * The ticket reader.
	 *
	 * @var TicketServiceInterface&MockObject
	 */
	private TicketServiceInterface&MockObject $tickets;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tickets = $this->createMock(TicketServiceInterface::class);
		$this->groups  = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * A stored ticket becomes a moment with its day, trimmed texts, fallbacks and seconds.
	 *
	 * @return void
	 */
	public function testATicketIsReadAsAMoment(): void {
		$this->stored(rows: [
			[
				'occurredAt' => '2026-03-02T09:15:00+01:00',
				'channel'    => ' telefoon ',
				'assignee'   => 'pieter',
				'title'      => ' Paspoort ',
				'outcome'    => 'afgehandeld',
				'duration'   => 'PT4M30S',
			],
			['occurredAt' => '2026-03-03T10:00:00+01:00', 'channel' => '', 'assignee' => 7, 'title' => null],
		]);

		$moments = $this->moments()->between(from: '', to: '', filter: new ContactReportFilter());

		$this->assertCount(2, $moments);
		$this->assertInstanceOf(DateTimeImmutable::class, $moments[0]['at']);
		$this->assertSame('2026-03-02T09:15:00+01:00', $moments[0]['at']->format(DATE_ATOM));
		unset($moments[0]['at'], $moments[1]['at']);
		$this->assertSame(
			['day' => '2026-03-02', 'channel' => 'telefoon', 'employee' => 'pieter', 'subject' => 'Paspoort', 'outcome' => 'afgehandeld', 'seconds' => 270],
			$moments[0]
		);
		$this->assertSame(
			['day' => '2026-03-03', 'channel' => 'unknown', 'employee' => 'unknown', 'subject' => '', 'outcome' => '', 'seconds' => null],
			$moments[1]
		);
	}//end testATicketIsReadAsAMoment()

	/**
	 * Only contact moments are asked for, and serialisable objects are read like arrays.
	 *
	 * @return void
	 */
	public function testContactMomentTicketsAreReadAndObjectsAreSerialised(): void {
		$object = new class implements JsonSerializable {
			/**
			 * The stored data.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['occurredAt' => '2026-03-02 08:00', 'channel' => 'balie'];
			}//end jsonSerialize()
		};
		$this->tickets->expects($this->once())->method('findByType')
			->with(TicketService::TYPE_CONTACTMOMENT)
			->willReturn([$object]);

		$moments = $this->moments()->between(from: '', to: '', filter: new ContactReportFilter());

		$this->assertSame(['balie'], array_column($moments, 'channel'));
	}//end testContactMomentTicketsAreReadAndObjectsAreSerialised()

	/**
	 * A ticket without a readable time, or that is not a record, is no moment.
	 *
	 * @return void
	 */
	public function testTicketsWithoutAReadableTimeAreSkipped(): void {
		$this->stored(rows: [
			'not a record',
			['channel' => 'telefoon'],
			['occurredAt' => '   '],
			['occurredAt' => 20260302],
			['occurredAt' => 'not a date at all'],
			['occurredAt' => '2026-03-02T09:00:00Z', 'channel' => 'mail'],
		]);

		$moments = $this->moments()->between(from: '', to: '', filter: new ContactReportFilter());

		$this->assertSame(['mail'], array_column($moments, 'channel'));
	}//end testTicketsWithoutAReadableTimeAreSkipped()

	/**
	 * Both bounds are included and a datetime bound is compared by its day.
	 *
	 * @return void
	 */
	public function testTheWindowIncludesBothDays(): void {
		$this->stored(rows: [
			['occurredAt' => '2026-02-28T23:00:00Z', 'title' => 'before'],
			['occurredAt' => '2026-03-01T00:00:00Z', 'title' => 'first'],
			['occurredAt' => '2026-03-31T23:59:00Z', 'title' => 'last'],
			['occurredAt' => '2026-04-01T00:00:00Z', 'title' => 'after'],
		]);
		$moments = $this->moments();

		$this->assertSame(['first', 'last'], array_column($moments->between(from: '2026-03-01T12:00:00', to: '2026-03-31T00:00:00', filter: new ContactReportFilter()), 'subject'));
		$this->assertSame(['first', 'last', 'after'], array_column($moments->between(from: '2026-03-01', to: '', filter: new ContactReportFilter()), 'subject'));
		$this->assertSame(['before', 'first', 'last'], array_column($moments->between(from: '', to: '2026-03-31', filter: new ContactReportFilter()), 'subject'));
	}//end testTheWindowIncludesBothDays()

	/**
	 * The channel and employee filters each narrow the moments.
	 *
	 * @return void
	 */
	public function testChannelAndEmployeeFiltersNarrow(): void {
		$this->stored(rows: [
			['occurredAt' => '2026-03-02T09:00:00Z', 'channel' => 'telefoon', 'assignee' => 'pieter', 'title' => 'a'],
			['occurredAt' => '2026-03-02T09:00:00Z', 'channel' => 'mail', 'assignee' => 'pieter', 'title' => 'b'],
			['occurredAt' => '2026-03-02T09:00:00Z', 'channel' => 'telefoon', 'assignee' => 'sanne', 'title' => 'c'],
		]);
		$moments = $this->moments();

		$this->assertSame(['a', 'c'], array_column($moments->between(from: '', to: '', filter: new ContactReportFilter(channel: 'telefoon')), 'subject'));
		$this->assertSame(['a', 'b'], array_column($moments->between(from: '', to: '', filter: new ContactReportFilter(employee: 'pieter')), 'subject'));
		$this->assertSame(['c'], array_column($moments->between(from: '', to: '', filter: new ContactReportFilter(channel: 'telefoon', employee: 'sanne')), 'subject'));
	}//end testChannelAndEmployeeFiltersNarrow()

	/**
	 * A team filter keeps the moments of the group's members only.
	 *
	 * @return void
	 */
	public function testATeamKeepsItsMembersMomentsOnly(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$this->user(uid: 'pieter'), 'not a user']);
		$this->groups->expects($this->once())->method('get')->with('burgerzaken')->willReturn($group);
		$this->stored(rows: [
			['occurredAt' => '2026-03-02T09:00:00Z', 'assignee' => 'pieter', 'title' => 'a'],
			['occurredAt' => '2026-03-02T09:00:00Z', 'assignee' => 'sanne', 'title' => 'b'],
		]);

		$moments = $this->moments()->between(from: '', to: '', filter: new ContactReportFilter(team: 'burgerzaken'));

		$this->assertSame(['a'], array_column($moments, 'subject'));
	}//end testATeamKeepsItsMembersMomentsOnly()

	/**
	 * An unknown team matches nothing; no team filter never asks for a group.
	 *
	 * @return void
	 */
	public function testAnUnknownTeamMatchesNothing(): void {
		$this->groups->expects($this->once())->method('get')->with('ghosts')->willReturn(null);
		$this->stored(rows: [['occurredAt' => '2026-03-02T09:00:00Z', 'assignee' => 'pieter']]);

		$this->assertSame([], $this->moments()->between(from: '', to: '', filter: new ContactReportFilter(team: 'ghosts')));
	}//end testAnUnknownTeamMatchesNothing()

	/**
	 * Durations are read as plain seconds or ISO 8601, anything else is null.
	 *
	 * @return void
	 */
	public function testDurationsAreReadAsSecondsOrIso(): void {
		$durations = [90, '45', 'P1DT1H2M3S', 'PT0S', '', 'twelve', 1.5, null, '-5'];
		$rows      = array_map(static fn (mixed $duration): array => ['occurredAt' => '2026-03-02T09:00:00Z', 'duration' => $duration], $durations);
		$this->stored(rows: $rows);
		$this->groups->expects($this->never())->method('get');

		$moments = $this->moments()->between(from: '', to: '', filter: new ContactReportFilter());

		$this->assertSame([90, 45, 90123, 0, null, null, null, null, null], array_column($moments, 'seconds'));
	}//end testDurationsAreReadAsSecondsOrIso()

	/**
	 * Store rows for the contact moment type.
	 *
	 * @param array<int, mixed> $rows The stored tickets.
	 *
	 * @return void
	 */
	private function stored(array $rows): void {
		$this->tickets->method('findByType')->willReturn($rows);
	}//end stored()

	/**
	 * A user with an id.
	 *
	 * @param string $uid The id.
	 *
	 * @return IUser The user.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * The subject.
	 *
	 * @return ContactMoments The subject.
	 */
	private function moments(): ContactMoments {
		return new ContactMoments(tickets: $this->tickets, groups: $this->groups);
	}//end moments()
}//end class
