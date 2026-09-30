<?php

/**
 * Unit tests for LetterController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/archive/2026-09-30-work-letter-from-filinq-template/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Controller\LetterController;
use OCA\Pipelinq\Service\Letter\FilinqLetterAdapter;
use OCA\Pipelinq\Service\TicketService;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Templates, the read guard, the render, the contact moment, and absence.
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 * @spec openspec/specs/client-letters/spec.md#requirement-a-letter-is-logged-on-the-client-req-wlt-002
 * @spec openspec/specs/client-letters/spec.md#requirement-without-filinq-no-letter-is-offered-or-faked-req-wlt-003
 */
class LetterControllerTest extends TestCase
{

    private const CLIENT = '6f1c1d2e-3b4a-4c5d-8e9f-0a1b2c3d4e5f';

    private const CONTACT = '1b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e';

    private const TICKET = '7a2b3c4d-5e6f-4a1b-9c2d-3e4f5a6b7c8d';

    /**
     * The adapter double.
     *
     * @var FilinqLetterAdapter&MockObject
     */
    private FilinqLetterAdapter $letters;

    /**
     * The ticket service double.
     *
     * @var TicketService&MockObject
     */
    private TicketService $tickets;

    /**
     * Objects the caller can read, by id.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $readable = [];

    /**
     * Whether the caller sits in a privileged CRM group.
     *
     * @var boolean
     */
    private bool $privileged = true;


    /**
     * Fresh doubles per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->letters  = $this->createMock(FilinqLetterAdapter::class);
        $this->tickets  = $this->createMock(TicketService::class);
        $this->readable = [
            self::CLIENT  => ['name' => 'Bakkerij de Jong', 'type' => 'organization'],
            self::CONTACT => ['name' => 'Anna de Jong', 'client' => self::CLIENT],
            self::TICKET  => ['title' => 'Oven repair', 'client' => self::CLIENT, 'ticketType' => 'request'],
        ];

    }//end setUp()


    /**
     * A controller whose request carries $params and whose ObjectService reads $this->readable.
     *
     * @param array<string, string> $params Request parameters.
     * @param string|null           $uid    The acting user, or null.
     *
     * @return LetterController
     */
    private function controller(array $params=[], ?string $uid='ann'): LetterController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn(string $key, $default=null) => ($params[$key] ?? $default)
        );

        $session = $this->createMock(IUserSession::class);
        $user    = null;
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
        }

        $session->method('getUser')->willReturn($user);

        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            static fn(string $app, string $key, string $default=''): string => match ($key) {
                'register' => '7',
                'client_schema' => '11',
                'contact_schema' => '12',
                'ticket_schema' => '13',
                default => $default,
            }
        );

        $readable      = $this->readable;
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            static function (int|string $id) use ($readable): ?ObjectEntity {
                if (isset($readable[$id]) === false) {
                    return null;
                }

                $entity = new ObjectEntity();
                $entity->setUuid((string) $id);
                $entity->setObject($readable[$id]);
                return $entity;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        // The REAL policy, so a guard that stops calling it cannot stay green.
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isInGroup')->willReturn($this->privileged);

        return new LetterController(
            $request,
            $this->letters,
            $this->tickets,
            $session,
            $config,
            $container,
            new ObjectOwnerAccessPolicy($groups, $config),
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()


    /**
     * filinq is there and holds the Appointment letter template.
     *
     * @return void
     */
    private function filinqPresent(): void
    {
        $this->letters->method('unavailableReason')->willReturn(null);
        $this->letters->method('findTemplate')->willReturnCallback(
            static fn(string $id): ?array => $id === 'tpl-1' ? ['id' => 'tpl-1', 'name' => 'Appointment letter', 'description' => ''] : null
        );

    }//end filinqPresent()


    /**
     * Without filinq the template endpoint says unavailable and why, so the action hides.
     *
     * @return void
     */
    public function testTemplatesWithoutFilinqSayUnavailable(): void
    {
        $this->letters->method('unavailableReason')->willReturn('filinq_unavailable: not installed');
        $this->letters->expects($this->never())->method('listTemplates');

        $response = $this->controller()->templates();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['available' => false, 'reason' => 'filinq_unavailable: not installed', 'templates' => []], $response->getData());

    }//end testTemplatesWithoutFilinqSayUnavailable()


    /**
     * With filinq the template endpoint lists pipelinq's templates.
     *
     * @return void
     */
    public function testTemplatesWithFilinqList(): void
    {
        $this->letters->method('unavailableReason')->willReturn(null);
        $this->letters->method('listTemplates')->willReturn([['id' => 'tpl-1', 'name' => 'Appointment letter', 'description' => '']]);

        $data = $this->controller()->templates()->getData();

        $this->assertTrue($data['available']);
        $this->assertSame('tpl-1', $data['templates'][0]['id']);

    }//end testTemplatesWithFilinqList()


    /**
     * A direct POST without filinq answers 503 naming filinq, and no document at all.
     *
     * @return void
     */
    public function testCreateWithoutFilinqIs503(): void
    {
        $this->letters->method('unavailableReason')->willReturn('filinq_unavailable: not installed');
        $this->letters->expects($this->never())->method('render');
        $this->tickets->expects($this->never())->method('save');

        $response = $this->controller(['templateId' => 'tpl-1'])->create(self::CLIENT);

        $this->assertSame(503, $response->getStatus());
        $this->assertStringContainsString('filinq', $response->getData()['message']);
        $this->assertArrayNotHasKey('content', $response->getData());

    }//end testCreateWithoutFilinqIs503()


    /**
     * A client the caller may not read is 404, and filinq is never asked.
     *
     * @return void
     */
    public function testUnreadableClientIs404(): void
    {
        $this->filinqPresent();
        unset($this->readable[self::CLIENT]);
        $this->letters->expects($this->never())->method('render');

        $response = $this->controller(['templateId' => 'tpl-1'])->create(self::CLIENT);

        $this->assertSame(404, $response->getStatus());

    }//end testUnreadableClientIs404()


    /**
     * Existence is not authorization: a caller outside the CRM groups who does
     * not own the client gets 404, and filinq is never asked.
     *
     * @return void
     */
    public function testClientTheCallerMayNotAccessIs404(): void
    {
        $this->filinqPresent();
        $this->privileged = false;
        $this->letters->expects($this->never())->method('render');
        $this->tickets->expects($this->never())->method('save');

        $response = $this->controller(['templateId' => 'tpl-1'])->create(self::CLIENT);

        $this->assertSame(404, $response->getStatus());

    }//end testClientTheCallerMayNotAccessIs404()


    /**
     * The owner of a client may make a letter for it without a CRM group.
     *
     * @return void
     */
    public function testOwnerOfTheClientMayMakeALetter(): void
    {
        $this->filinqPresent();
        $this->privileged = false;
        $this->readable[self::CLIENT]['ownerId'] = 'ann';
        $this->letters->method('render')->willReturn(['content' => '%PDF', 'fileId' => 5, 'path' => '/Letters/a.pdf', 'warnings' => []]);

        $response = $this->controller(['templateId' => 'tpl-1'])->create(self::CLIENT);

        $this->assertSame(200, $response->getStatus());

    }//end testOwnerOfTheClientMayMakeALetter()


    /**
     * A ticket of another client is not found here, so the letter cannot land on the wrong timeline.
     *
     * @return void
     */
    public function testTicketOfAnotherClientIs404(): void
    {
        $this->filinqPresent();
        $this->readable[self::TICKET]['client'] = 'someone-else';
        $this->letters->expects($this->never())->method('render');

        $response = $this->controller(['templateId' => 'tpl-1', 'ticketId' => self::TICKET])->create(self::CLIENT);

        $this->assertSame(404, $response->getStatus());

    }//end testTicketOfAnotherClientIs404()


    /**
     * An unknown template, or another app's, is 404.
     *
     * @return void
     */
    public function testUnknownTemplateIs404(): void
    {
        $this->filinqPresent();
        $this->letters->expects($this->never())->method('render');

        $this->assertSame(404, $this->controller(['templateId' => 'tpl-9'])->create(self::CLIENT)->getStatus());
        $this->assertSame(400, $this->controller([])->create(self::CLIENT)->getStatus());

    }//end testUnknownTemplateIs404()


    /**
     * The letter from a ticket: filinq gets the three references, the PDF comes
     * back with filinq's warnings, and the contact moment hangs under the ticket.
     *
     * @return void
     */
    public function testLetterFromATicketIsRenderedAndLogged(): void
    {
        $this->filinqPresent();
        $this->letters->expects($this->once())->method('render')
            ->with(
                'tpl-1',
                [
                    ['register' => '7', 'schema' => 'client', 'id' => self::CLIENT],
                    ['register' => '7', 'schema' => 'contact', 'id' => self::CONTACT],
                    ['register' => '7', 'schema' => 'ticket', 'id' => self::TICKET],
                ],
                $this->stringStartsWith('Appointment letter Bakkerij de Jong ')
            )
            ->willReturn(['content' => '%PDF-1.7 letter', 'fileId' => 4711, 'path' => '/DocuDesk/Appointment letter.pdf', 'warnings' => ['client.salutation is empty']]);

        $saved = null;
        $this->tickets->expects($this->once())->method('save')->willReturnCallback(
            static function (string $ticketType, array $payload) use (&$saved): ObjectEntity {
                $saved  = [$ticketType, $payload];
                $entity = new ObjectEntity();
                $entity->setUuid('cm-1');
                $entity->setObject($payload + ['id' => 'cm-1']);
                return $entity;
            }
        );

        $response = $this->controller(['templateId' => 'tpl-1', 'contactId' => self::CONTACT, 'ticketId' => self::TICKET])->create(self::CLIENT);
        $data     = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('%PDF-1.7 letter', base64_decode($data['content']));
        $this->assertSame('application/pdf', $data['mimeType']);
        $this->assertStringEndsWith('.pdf', $data['filename']);
        $this->assertSame(4711, $data['fileId']);
        $this->assertSame(['client.salutation is empty'], $data['warnings']);
        $this->assertSame('cm-1', $data['contactMomentId']);

        $this->assertSame('interaction', $saved[0]);
        $this->assertSame(self::CLIENT, $saved[1]['client']);
        $this->assertSame(self::CONTACT, $saved[1]['contact']);
        $this->assertSame(self::TICKET, $saved[1]['parentTicket']);
        $this->assertSame('Letter: Appointment letter', $saved[1]['title']);
        $this->assertSame('outbound', $saved[1]['direction']);
        $this->assertSame('brief', $saved[1]['channel']);
        $this->assertStringContainsString('/DocuDesk/Appointment letter.pdf', $saved[1]['description']);

    }//end testLetterFromATicketIsRenderedAndLogged()


    /**
     * A failed render answers 502 and logs nothing.
     *
     * @return void
     */
    public function testFailedRenderSavesNothing(): void
    {
        $this->filinqPresent();
        $this->letters->method('render')->willThrowException(new RuntimeException('filinq_render_failed: Twig syntax error'));
        $this->tickets->expects($this->never())->method('save');

        $response = $this->controller(['templateId' => 'tpl-1'])->create(self::CLIENT);

        $this->assertSame(502, $response->getStatus());
        $this->assertStringContainsString('Twig syntax error', $response->getData()['reason']);

    }//end testFailedRenderSavesNothing()


    /**
     * A contact moment that fails to save does not take the letter away.
     *
     * @return void
     */
    public function testLetterSurvivesAFailedLog(): void
    {
        $this->filinqPresent();
        $this->letters->method('render')->willReturn(['content' => '%PDF', 'fileId' => 1, 'path' => null, 'warnings' => []]);
        $this->tickets->method('save')->willThrowException(new RuntimeException('validation failed'));

        $response = $this->controller(['templateId' => 'tpl-1'])->create(self::CLIENT);

        $this->assertSame(200, $response->getStatus());
        $this->assertNull($response->getData()['contactMomentId']);

    }//end testLetterSurvivesAFailedLog()


    /**
     * The contact moment payload, with `ticketType` forced as TicketService::save()
     * does, validates against the REAL merged `ticket` schema, and a broken one
     * does not (the negative control proves the validator can fail).
     *
     * @return void
     */
    public function testContactMomentPayloadMatchesTheRealTicketSchema(): void
    {
        $payload = LetterController::contactMomentPayload(
            clientId: self::CLIENT,
            contactId: self::CONTACT,
            ticketId: self::TICKET,
            templateName: 'Appointment letter',
            path: '/DocuDesk/Appointment letter.pdf',
            occurredAt: new DateTimeImmutable('2026-09-30T10:15:00+02:00')
        );
        $payload['ticketType'] = TicketService::TYPE_CONTACTMOMENT;

        $validator = new Validator();
        $schema    = json_decode((string) json_encode($this->mergedSchema('ticket')));

        $result = $validator->validate(json_decode((string) json_encode($payload)), $schema);
        $this->assertTrue($result->isValid(), 'The letter contact moment must validate: '.print_r($result->error()?->message(), true));

        $broken = $payload;
        $broken['outcome']    = 'sent';
        $broken['occurredAt'] = '2026-09-30';
        $this->assertFalse($validator->validate(json_decode((string) json_encode($broken)), $schema)->isValid());

        $unknown             = $payload;
        $unknown['letterId'] = 'x';
        $this->assertFalse($validator->validate(json_decode((string) json_encode($unknown)), $schema)->isValid());

    }//end testContactMomentPayloadMatchesTheRealTicketSchema()


    /**
     * The merged JSON schema of one register schema, as OpenRegister builds it:
     * every register file declaring it adds properties; `$ref` and `x-` keys
     * dropped because they name other schemas, not value shapes.
     *
     * @param string $name The schema key.
     *
     * @return array<string, mixed> The schema.
     */
    private function mergedSchema(string $name): array
    {
        $dir   = __DIR__.'/../../../lib/Settings';
        $files = glob($dir.'/register.d/*.json');
        sort($files);
        array_unshift($files, $dir.'/pipelinq_register.json');

        $properties = [];
        $required   = [];
        foreach ($files as $file) {
            $schema = json_decode((string) file_get_contents($file), true)['components']['schemas'][$name] ?? null;
            if (is_array($schema) === false) {
                continue;
            }

            foreach (($schema['properties'] ?? []) as $key => $def) {
                $clean = array_intersect_key((array) $def, array_flip(['type', 'enum', 'format', 'minimum', 'maximum', 'maxLength', 'minLength']));
                $properties[$key] = array_merge(($properties[$key] ?? []), $clean);
            }

            if (isset($schema['required']) === true) {
                $required = $schema['required'];
            }
        }

        $this->assertNotEmpty($properties, 'No register file declares '.$name);

        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];

    }//end mergedSchema()


}//end class
