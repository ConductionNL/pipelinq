<?php

/**
 * Unit tests for CampaignEventCreator.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Service\Event
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Event;

use DateTimeZone;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCA\Pipelinq\Service\Event\CampaignEventCreator;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The campaign page's Create event: what is checked, how times are read and what is written.
 *
 * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
 */
#[CoversClass(CampaignEventCreator::class)]
class CampaignEventCreatorTest extends TestCase
{

    /**
     * The webinar of the spec's scenario, as the dialog sends it.
     *
     * @var array<string, mixed>
     */
    private const WEBINAR = [
        'title'    => 'Webinar AI voor gemeenten',
        'startAt'  => '2026-11-12T14:00',
        'endAt'    => '2026-11-12T15:00',
        'online'   => true,
        'joinUrl'  => 'https://meet.example.nl/webinar-ai',
        'capacity' => '100',
        'openNow'  => true,
    ];

    /**
     * The objects saved, with their options.
     *
     * @var list<array{object: array<string, mixed>, options: ObjectSaveOptions, rbac: bool}>
     */
    private array $saved = [];

    /**
     * A creator over a recording object service, in the Amsterdam time zone.
     *
     * @param array<string, mixed>|null $campaign The campaign the caller reads, null when they cannot.
     *
     * @return CampaignEventCreator
     */
    private function creator(?array $campaign=['id' => 'camp-1']): CampaignEventCreator
    {
        $objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
        $objects->method('saveObject')->willReturnCallback(
            function (array $object, ObjectSaveOptions $options, bool $_rbac=true, bool $_multitenancy=true): ObjectEntityInterface {
                $this->saved[] = ['object' => $object, 'options' => $options, 'rbac' => $_rbac];
                $entity        = $this->createMock(originalClassName: ObjectEntityInterface::class);
                $entity->method('getUuid')->willReturn('ev-new');
                $entity->method('getObject')->willReturn($object);
                return $entity;
            }
        );

        $reader = $this->createMock(originalClassName: CallerObjectReaderInterface::class);
        $reader->method('register')->willReturn('pipelinq');
        $reader->method('read')->willReturnCallback(
            static fn (string $id, string $schemaKey): ?array => ($schemaKey === 'campaign_schema' && $id === 'camp-1') ? $campaign : null
        );

        $config = $this->createMock(originalClassName: IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default=''): string => ($key === 'campaignEvent_schema') ? 'campaignEvent' : $default
        );

        $zone = $this->createMock(originalClassName: IDateTimeZone::class);
        $zone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));

        return new CampaignEventCreator(objectService: $objects, reader: $reader, appConfig: $config, timeZone: $zone);

    }//end creator()

    /**
     * The spec's webinar is written as an online event of the campaign, in the caller's zone.
     *
     * @return void
     */
    public function testTheWebinarIsWrittenAsTheCampaignsOnlineEvent(): void
    {
        $event = $this->creator()->create(campaignId: 'camp-1', input: self::WEBINAR);

        $this->assertCount(1, $this->saved);
        $written = $this->saved[0];
        $this->assertSame(
            [
                'title'        => 'Webinar AI voor gemeenten',
                'campaign'     => 'camp-1',
                'startAt'      => '2026-11-12T14:00:00+01:00',
                'online'       => true,
                'signupStatus' => 'open',
                'endAt'        => '2026-11-12T15:00:00+01:00',
                'capacity'     => 100,
                'joinUrl'      => 'https://meet.example.nl/webinar-ai',
            ],
            $written['object']
        );
        $this->assertSame('pipelinq', $written['options']->target->register);
        $this->assertSame('campaignEvent', $written['options']->target->schema);
        $this->assertNull($written['options']->uuid);
        $this->assertTrue($written['rbac'], 'the event is written as the caller, so access rules apply');
        $this->assertSame('ev-new', $event['id']);
        $this->assertSame(100, $event['capacity']);

    }//end testTheWebinarIsWrittenAsTheCampaignsOnlineEvent()

    /**
     * A time sent with its zone keeps it; a summer date gets summer time.
     *
     * @return void
     */
    public function testATimeWithAZoneKeepsItAndSummerTimeApplies(): void
    {
        $payload = $this->creator()->payload(
            campaignId: 'camp-1',
            input: ['title' => 'Open dag', 'startAt' => '2026-06-01T10:00', 'endAt' => '2026-06-01T12:00:00+00:00']
        );

        $this->assertSame('2026-06-01T10:00:00+02:00', $payload['startAt']);
        $this->assertSame('2026-06-01T12:00:00+00:00', $payload['endAt']);

    }//end testATimeWithAZoneKeepsItAndSummerTimeApplies()

    /**
     * An event on site takes the venue and drops a join link typed earlier.
     *
     * @return void
     */
    public function testAnEventOnSiteTakesTheVenueAndNoJoinLink(): void
    {
        $payload = $this->creator()->payload(
            campaignId: 'camp-1',
            input: ['title' => 'Informatieavond', 'startAt' => '2026-11-12T19:30', 'online' => false, 'venue' => ' Wijkcentrum De Linde ', 'joinUrl' => 'https://x.example']
        );

        $this->assertSame('Wijkcentrum De Linde', $payload['venue']);
        $this->assertArrayNotHasKey('joinUrl', $payload);
        $this->assertFalse($payload['online']);

    }//end testAnEventOnSiteTakesTheVenueAndNoJoinLink()

    /**
     * Without Open registration now the event stays a draft, and without seats it has no capacity.
     *
     * @return void
     */
    public function testWithoutOpenNowTheEventIsADraftWithoutALimit(): void
    {
        $payload = $this->creator()->payload(
            campaignId: 'camp-1',
            input: ['title' => 'Later', 'startAt' => '2026-12-01T09:00', 'openNow' => false, 'capacity' => '']
        );
        $unlimited = $this->creator()->payload(campaignId: 'camp-1', input: ['title' => 'Later', 'startAt' => '2026-12-01T09:00', 'capacity' => '0']);

        $this->assertSame('draft', $payload['signupStatus']);
        $this->assertArrayNotHasKey('capacity', $payload);
        $this->assertArrayNotHasKey('endAt', $payload);
        $this->assertArrayNotHasKey('capacity', $unlimited, 'zero seats means no limit');

    }//end testWithoutOpenNowTheEventIsADraftWithoutALimit()

    /**
     * Each invalid input is refused with its own reason, and nothing is written.
     *
     * @return void
     */
    public function testInvalidInputIsRefusedAndNothingIsWritten(): void
    {
        $cases = [
            'Give the event a title.' => ['title' => '  ', 'startAt' => '2026-11-12T14:00'],
            'Give the event a start.' => ['title' => 'Webinar'],
            'The start is not a date and time.' => ['title' => 'Webinar', 'startAt' => 'next tuesday-ish 99'],
            'The event must end after it starts.' => ['title' => 'Webinar', 'startAt' => '2026-11-12T14:00', 'endAt' => '2026-11-12T13:00'],
            'The number of seats must be a whole number.' => ['title' => 'Webinar', 'startAt' => '2026-11-12T14:00', 'capacity' => '-3'],
            'The join link must be a web address starting with https://.' => ['title' => 'Webinar', 'startAt' => '2026-11-12T14:00', 'online' => '1', 'joinUrl' => 'javascript:alert(1)'],
        ];

        $creator = $this->creator();
        foreach ($cases as $message => $input) {
            try {
                $creator->create(campaignId: 'camp-1', input: $input);
                $this->fail('accepted: '.$message);
            } catch (InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->assertSame([], $this->saved);

    }//end testInvalidInputIsRefusedAndNothingIsWritten()

    /**
     * A seat count with decimals or text is refused too.
     *
     * @return void
     */
    public function testASeatCountThatIsNotAWholeNumberIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The number of seats must be a whole number.');

        $this->creator()->payload(campaignId: 'camp-1', input: ['title' => 'Webinar', 'startAt' => '2026-11-12T14:00', 'capacity' => '12.5']);

    }//end testASeatCountThatIsNotAWholeNumberIsRefused()

    /**
     * The campaign is looked up as the caller: unknown or unreadable reads as absent.
     *
     * @return void
     */
    public function testTheCampaignIsReadAsTheCaller(): void
    {
        $this->assertTrue($this->creator()->campaignExists(campaignId: 'camp-1'));
        $this->assertFalse($this->creator()->campaignExists(campaignId: 'camp-2'));
        $this->assertFalse($this->creator(campaign: null)->campaignExists(campaignId: 'camp-1'));

    }//end testTheCampaignIsReadAsTheCaller()
}//end class
