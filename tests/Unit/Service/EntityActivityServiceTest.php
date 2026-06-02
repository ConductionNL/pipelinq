<?php

/**
 * Unit tests for EntityActivityService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCA\Pipelinq\Service\EntityActivityService;
use OCA\Pipelinq\Service\NotesService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EntityActivityService.
 */
class EntityActivityServiceTest extends TestCase
{
    /**
     * Mock timeline service.
     *
     * @var ActivityTimelineService&MockObject
     */
    private $timelineService;

    /**
     * Mock notes service.
     *
     * @var NotesService&MockObject
     */
    private $notesService;

    /**
     * The service under test.
     *
     * @var EntityActivityService
     */
    private EntityActivityService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->timelineService = $this->createMock(ActivityTimelineService::class);
        $this->notesService    = $this->createMock(NotesService::class);
        $logger                = $this->createMock(LoggerInterface::class);

        $this->service = new EntityActivityService(
            $this->timelineService,
            $this->notesService,
            $logger
        );
    }//end setUp()

    /**
     * An invalid entity type must raise InvalidArgumentException.
     *
     * @return void
     */
    public function testInvalidEntityTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->getActivity('unknown', 'uuid-1', 'all', 1, 20);
    }//end testInvalidEntityTypeThrows()

    /**
     * type=contactmomenten returns only projected contactmoment items and
     * must not touch the notes service.
     *
     * @return void
     */
    public function testContactmomentenOnly(): void
    {
        $this->timelineService->method('getTimeline')->willReturn(
            [
                'items' => [
                    [
                        'id'          => 'cm-1',
                        'title'       => 'Vraag over vergunning',
                        'description' => 'Burger belde over status',
                        'date'        => '2026-04-14T09:12:00Z',
                        'user'        => 'agent-a',
                        'metadata'    => ['channel' => 'telefoon'],
                    ],
                ],
                'total' => 1,
                'page'  => 1,
                'pages' => 1,
            ]
        );

        $this->notesService->expects($this->never())->method('getNotes');

        $result = $this->service->getActivity('client', 'client-1', 'contactmomenten', 1, 10);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['results']);
        $item = $result['results'][0];
        $this->assertSame('contactmoment', $item['type']);
        $this->assertSame('cm-1', $item['id']);
        $this->assertSame('telefoon', $item['channel']);
        $this->assertSame('agent-a', $item['agent']);
        $this->assertSame('2026-04-14T09:12:00Z', $item['timestamp']);
    }//end testContactmomentenOnly()

    /**
     * type=notes returns only projected note items and must not query the
     * timeline service.
     *
     * @return void
     */
    public function testNotesOnly(): void
    {
        $this->timelineService->expects($this->never())->method('getTimeline');

        $this->notesService->method('getNotes')
            ->with('pipelinq_lead', 'lead-1')
            ->willReturn(
                [
                    [
                        'id'        => 42,
                        'message'   => 'Follow up next week',
                        'authorId'  => 'agent-b',
                        'timestamp' => '2026-04-20T08:00:00+00:00',
                    ],
                ]
            );

        $result = $this->service->getActivity('lead', 'lead-1', 'notes', 1, 10);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['results']);
        $item = $result['results'][0];
        $this->assertSame('note', $item['type']);
        $this->assertSame('42', $item['id']);
        $this->assertNull($item['channel']);
        $this->assertSame('agent-b', $item['agent']);
    }//end testNotesOnly()

    /**
     * type=all merges both sources and sorts reverse-chronologically.
     *
     * @return void
     */
    public function testAllMergesAndSortsByTimestampDesc(): void
    {
        $this->timelineService->method('getTimeline')->willReturn(
            [
                'items' => [
                    [
                        'id'       => 'cm-old',
                        'title'    => 'Oud contactmoment',
                        'date'     => '2026-04-01T10:00:00Z',
                        'metadata' => ['channel' => 'email'],
                    ],
                ],
                'total' => 1,
                'page'  => 1,
                'pages' => 1,
            ]
        );

        $this->notesService->method('getNotes')->willReturn(
            [
                [
                    'id'        => 7,
                    'message'   => 'Nieuwe notitie',
                    'authorId'  => 'agent-c',
                    'timestamp' => '2026-04-30T12:00:00+00:00',
                ],
            ]
        );

        $result = $this->service->getActivity('client', 'client-1', 'all', 1, 10);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['results']);
        // Newest first: the note (2026-04-30) precedes the contactmoment (2026-04-01).
        $this->assertSame('note', $result['results'][0]['type']);
        $this->assertSame('contactmoment', $result['results'][1]['type']);
    }//end testAllMergesAndSortsByTimestampDesc()

    /**
     * Pagination metadata reflects the second page of a larger result set.
     *
     * @return void
     */
    public function testPaginationSecondPage(): void
    {
        $items = [];
        for ($i = 0; $i < 25; $i++) {
            $items[] = [
                'id'       => 'cm-'.$i,
                'title'    => 'Item '.$i,
                // Descending dates so order is stable.
                'date'     => sprintf('2026-04-%02dT10:00:00Z', (25 - $i)),
                'metadata' => ['channel' => 'telefoon'],
            ];
        }

        $this->timelineService->method('getTimeline')->willReturn(
            [
                'items' => $items,
                'total' => 25,
                'page'  => 1,
                'pages' => 1,
            ]
        );

        $result = $this->service->getActivity('request', 'req-1', 'contactmomenten', 2, 10);

        $this->assertSame(25, $result['total']);
        $this->assertSame(3, $result['pages']);
        $this->assertSame(2, $result['page']);
        $this->assertCount(10, $result['results']);
    }//end testPaginationSecondPage()

    /**
     * A failure inside the timeline service is swallowed (logged) and yields
     * an empty contactmoment contribution rather than bubbling up.
     *
     * @return void
     */
    public function testTimelineFailureYieldsEmpty(): void
    {
        $this->timelineService->method('getTimeline')
            ->willThrowException(new \RuntimeException('OR down'));

        $result = $this->service->getActivity('client', 'client-1', 'contactmomenten', 1, 10);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['results']);
    }//end testTimelineFailureYieldsEmpty()
}//end class
