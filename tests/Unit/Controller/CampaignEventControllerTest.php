<?php

/**
 * Unit tests for CampaignEventController.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Controller\CampaignEventController;
use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCA\Pipelinq\Service\Event\CampaignEventCreator;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The Create event route: who may call it, what it answers, and that a refusal writes nothing.
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
 */
#[CoversClass(CampaignEventController::class)]
#[UsesClass(CampaignEventCreator::class)]
class CampaignEventControllerTest extends TestCase
{

    /**
     * How many writes reached OpenRegister.
     *
     * @var integer
     */
    private int $writes = 0;

    /**
     * The controller over a real creator, with the request carrying $params.
     *
     * @param array<string, mixed> $params      The request parameters.
     * @param bool                 $signedIn    Whether a user is signed in.
     * @param bool                 $writeFails  Whether OpenRegister refuses the write.
     *
     * @return CampaignEventController
     */
    private function controller(array $params, bool $signedIn=true, bool $writeFails=false): CampaignEventController
    {
        $request = $this->createMock(originalClassName: IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
        );

        $objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
        $objects->method('saveObject')->willReturnCallback(
            function (array $object) use ($writeFails): ObjectEntityInterface {
                $this->writes++;
                if ($writeFails === true) {
                    throw new RuntimeException('not allowed');
                }

                $entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
                $entity->method('getUuid')->willReturn('ev-new');
                $entity->method('getObject')->willReturn($object);
                return $entity;
            }
        );

        $reader = $this->createMock(originalClassName: CallerObjectReaderInterface::class);
        $reader->method('register')->willReturn('pipelinq');
        $reader->method('read')->willReturnCallback(
            static fn (string $id, string $schemaKey): ?array => ($id === 'camp-1' && $schemaKey === 'campaign_schema') ? ['id' => 'camp-1'] : null
        );

        $config = $this->createMock(originalClassName: IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default=''): string => ($key === 'campaignEvent_schema') ? 'campaignEvent' : $default
        );

        $zone = $this->createMock(originalClassName: IDateTimeZone::class);
        $zone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));

        $session = $this->createMock(originalClassName: IUserSession::class);
        $session->method('getUser')->willReturn($signedIn === true ? $this->createMock(originalClassName: IUser::class) : null);

        return new CampaignEventController(
            request: $request,
            creator: new CampaignEventCreator(objectService: $objects, reader: $reader, appConfig: $config, timeZone: $zone),
            userSession: $session,
            logger: $this->createMock(originalClassName: LoggerInterface::class)
        );

    }//end controller()

    /**
     * The spec's webinar is created on its campaign and answered with 201.
     *
     * @return void
     */
    public function testTheEventIsCreatedOnTheCampaign(): void
    {
        $response = $this->controller(
            params: ['title' => 'Webinar AI voor gemeenten', 'startAt' => '2026-11-12T14:00', 'endAt' => '2026-11-12T15:00', 'online' => true, 'capacity' => '100', 'openNow' => true]
        )->create(id: 'camp-1');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('ev-new', $data['id']);
        $this->assertSame('camp-1', $data['campaign']);
        $this->assertSame(100, $data['capacity']);
        $this->assertSame('open', $data['signupStatus']);
        $this->assertSame(1, $this->writes);

    }//end testTheEventIsCreatedOnTheCampaign()

    /**
     * A signed-out caller is refused before anything is read or written.
     *
     * @return void
     */
    public function testASignedOutCallerIsRefused(): void
    {
        $response = $this->controller(params: ['title' => 'Webinar', 'startAt' => '2026-11-12T14:00'], signedIn: false)->create(id: 'camp-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(0, $this->writes);

    }//end testASignedOutCallerIsRefused()

    /**
     * A campaign the caller cannot read answers 404 and nothing is written.
     *
     * @return void
     */
    public function testACampaignTheCallerCannotReadIsNotFound(): void
    {
        $response = $this->controller(params: ['title' => 'Webinar', 'startAt' => '2026-11-12T14:00'])->create(id: 'camp-2');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['message' => 'Campaign not found'], $response->getData());
        $this->assertSame(0, $this->writes);

    }//end testACampaignTheCallerCannotReadIsNotFound()

    /**
     * Invalid input answers 400 with the reason, and nothing is written.
     *
     * @return void
     */
    public function testInvalidInputIsABadRequest(): void
    {
        $response = $this->controller(params: ['title' => 'Webinar', 'startAt' => '2026-11-12T14:00', 'capacity' => 'many'])->create(id: 'camp-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['message' => 'The number of seats must be a whole number.'], $response->getData());
        $this->assertSame(0, $this->writes);

    }//end testInvalidInputIsABadRequest()

    /**
     * A write OpenRegister refuses answers 400 without its internal message.
     *
     * @return void
     */
    public function testARefusedWriteIsReportedWithoutItsInternals(): void
    {
        $response = $this->controller(params: ['title' => 'Webinar', 'startAt' => '2026-11-12T14:00'], writeFails: true)->create(id: 'camp-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['message' => 'The event could not be created'], $response->getData());
        $this->assertSame(1, $this->writes);

    }//end testARefusedWriteIsReportedWithoutItsInternals()
}//end class
