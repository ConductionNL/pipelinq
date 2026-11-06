<?php
/**
 * Unit tests for RecordDocumentController.
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
 * @spec openspec/changes/archive/2026-10-30-record-document-pdf/tasks.md#task-1-the-document-endpoints
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\RecordDocumentController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\CallerObjectReader;
use OCA\Pipelinq\Service\Letter\FilinqLetterAdapter;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The configured map, the access check before the render, the download, and absence.
 *
 * @spec openspec/specs/record-documents/spec.md#requirement-a-configured-record-type-has-a-document
 * @spec openspec/specs/record-documents/spec.md#requirement-the-caller-may-act-on-the-record-before-anything-is-made
 * @spec openspec/specs/record-documents/spec.md#requirement-the-document-is-offered-only-when-it-can-be-made
 */
class RecordDocumentControllerTest extends TestCase
{

    private const QUOTE = '8cd55831-b6ca-4eec-8c4b-0156cc29a501';

    /**
     * The filinq adapter double.
     *
     * @var FilinqLetterAdapter&MockObject
     */
    private FilinqLetterAdapter $documents;

    /**
     * The reader double.
     *
     * @var CallerObjectReader&MockObject
     */
    private CallerObjectReader $reader;

    /**
     * The access policy double.
     *
     * @var ObjectOwnerAccessPolicy&MockObject
     */
    private ObjectOwnerAccessPolicy $policy;

    /**
     * The app config double.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The configured `record_document_templates` value.
     *
     * @var string
     */
    private string $map = '{"salesQuote":{"template":"tpl-quote","ownerField":"assignee"}}';


    /**
     * Wire the doubles.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->documents = $this->createMock(originalClassName: FilinqLetterAdapter::class);
        $this->reader    = $this->createMock(originalClassName: CallerObjectReader::class);
        $this->policy    = $this->createMock(originalClassName: ObjectOwnerAccessPolicy::class);
        $this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $this->appConfig->method('getValueString')->willReturnCallback($this->configValue(...));
        $this->documents->method('unavailableReason')->willReturn(null);
        $this->documents->method('findTemplate')->willReturnCallback(self::template(...));

    }//end setUp()


    /**
     * The app config double's answer: the template map, else the default.
     *
     * @param string $app     The app id.
     * @param string $key     The key.
     * @param string $default The default.
     *
     * @return string The value.
     */
    private function configValue(string $app, string $key, string $default=''): string
    {
        if ($key === 'record_document_templates') {
            return $this->map;
        }

        return $default;

    }//end configValue()


    /**
     * The filinq double's template: only `tpl-quote` is pipelinq's.
     *
     * @param string $templateId The template id.
     *
     * @return array<string, string>|null The template.
     */
    private static function template(string $templateId): ?array
    {
        if ($templateId === 'tpl-quote') {
            return ['id' => 'tpl-quote', 'name' => 'Offerte', 'description' => ''];
        }

        if ($templateId === 'offerte') {
            return ['id' => '4f1c0d6e-9b2a-4c3d-8e5f-6a7b8c9d0e1f', 'name' => 'Offerte', 'description' => ''];
        }

        return null;

    }//end template()


    /**
     * The controller, signed in as pieter.
     *
     * @param boolean $signedIn Whether a user is signed in.
     *
     * @return RecordDocumentController
     */
    private function controller(bool $signedIn=true): RecordDocumentController
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('pieter');
        $session = $this->createMock(originalClassName: IUserSession::class);
        $session->method('getUser')->willReturn(null);
        if ($signedIn === true) {
            $session = $this->createMock(originalClassName: IUserSession::class);
            $session->method('getUser')->willReturn($user);
        }

        return new RecordDocumentController(
            request: $this->createMock(originalClassName: IRequest::class),
            documents: $this->documents,
            userSession: $session,
            reader: $this->reader,
            accessPolicy: $this->policy,
            appConfig: $this->appConfig,
        );

    }//end controller()


    /**
     * A configured schema's record comes back as a PDF download, rendered from its template, not filed.
     *
     * @return void
     */
    public function testDocumentIsTheRenderedPdf(): void
    {
        $this->reader->method('register')->willReturn('pipelinq');
        $quote = ['number' => 'OFF-2026-0042', 'title' => 'Website en hosting', 'assignee' => 'pieter'];
        $this->reader->method('readFromSchema')->with(self::QUOTE, 'salesQuote')->willReturn($quote);
        $this->policy->method('mayAccess')->with('pieter', $this->anything(), 'assignee')->willReturn(true);
        $this->documents->expects($this->once())->method('render')->with(
            'tpl-quote',
            [['register' => 'pipelinq', 'schema' => 'salesQuote', 'id' => self::QUOTE]],
            $this->stringContains(string: 'OFF-2026-0042'),
            'return'
        )->willReturn(['content' => '%PDF-1.7', 'fileId' => null, 'path' => null, 'warnings' => []]);

        $response = $this->controller()->document(schema: 'salesQuote', id: self::QUOTE);

        $this->assertInstanceOf(expected: DataDownloadResponse::class, actual: $response);
        $this->assertSame(expected: '%PDF-1.7', actual: $response->render());

    }//end testDocumentIsTheRenderedPdf()


    /**
     * A schema without a configured template answers 404 and filinq is not called.
     *
     * @return void
     */
    public function testUnconfiguredSchemaHasNoDocument(): void
    {
        $this->documents->expects($this->never())->method('render');
        $this->reader->expects($this->never())->method('readFromSchema');

        $response = $this->controller()->document(schema: 'client', id: self::QUOTE);

        $this->assertInstanceOf(expected: JSONResponse::class, actual: $response);
        $this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());

    }//end testUnconfiguredSchemaHasNoDocument()


    /**
     * Someone who may not act on the record gets 404 before filinq is called.
     *
     * @return void
     */
    public function testNoDocumentForSomeoneElsesRecord(): void
    {
        $this->reader->method('readFromSchema')->willReturn(['assignee' => 'lotte']);
        $this->policy->method('mayAccess')->willReturn(false);
        $this->documents->expects($this->never())->method('render');

        $response = $this->controller()->document(schema: 'salesQuote', id: self::QUOTE);

        $this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());

    }//end testNoDocumentForSomeoneElsesRecord()


    /**
     * A record the caller cannot read answers 404.
     *
     * @return void
     */
    public function testNoDocumentForAnUnreadableRecord(): void
    {
        $this->reader->method('readFromSchema')->willReturn(null);
        $this->documents->expects($this->never())->method('render');

        $response = $this->controller()->document(schema: 'salesQuote', id: self::QUOTE);
        $this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());

    }//end testNoDocumentForAnUnreadableRecord()


    /**
     * A filinq failure answers 502 with the reason.
     *
     * @return void
     */
    public function testFilinqFailureIsSaid(): void
    {
        $this->reader->method('readFromSchema')->willReturn(['assignee' => 'pieter']);
        $this->policy->method('mayAccess')->willReturn(true);
        $this->documents->method('render')->willThrowException(new RuntimeException('filinq_render_failed: boom'));

        $response = $this->controller()->document(schema: 'salesQuote', id: self::QUOTE);

        $this->assertSame(expected: Http::STATUS_BAD_GATEWAY, actual: $response->getStatus());

    }//end testFilinqFailureIsSaid()


    /**
     * Without a user the endpoints answer 401.
     *
     * @return void
     */
    public function testSignedOutIsRefused(): void
    {
        $document = $this->controller(signedIn: false)->document(schema: 'salesQuote', id: self::QUOTE);
        $template = $this->controller(signedIn: false)->template(schema: 'salesQuote');
        $this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $document->getStatus());
        $this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $template->getStatus());

    }//end testSignedOutIsRefused()


    /**
     * The availability says whether a document can be made, and names the template.
     *
     * @return void
     */
    public function testTemplateAvailability(): void
    {
        $expected = ['available' => true, 'template' => ['id' => 'tpl-quote', 'name' => 'Offerte', 'description' => '']];
        $this->assertSame(expected: $expected, actual: $this->controller()->template(schema: 'salesQuote')->getData());
        $this->assertFalse(condition: $this->controller()->template(schema: 'client')->getData()['available']);

        $this->map = '{"salesQuote":{"template":"tpl-gone"}}';
        $this->assertFalse(condition: $this->controller()->template(schema: 'salesQuote')->getData()['available']);

    }//end testTemplateAvailability()



    /**
     * A template named by slug renders by the id it resolves to, with the configured lists of the record.
     *
     * @spec openspec/specs/record-documents/spec.md#requirement-a-configured-record-type-has-a-document
     *
     * @return void
     */
    public function testSlugTemplateRendersWithTheRecordsLists(): void
    {
        $lists     = [['schema' => 'salesQuoteLine', 'field' => 'quote', 'as' => 'lines', 'order' => ['sortOrder' => 'ASC']]];
        $this->map = (string) json_encode(value: ['salesQuote' => ['template' => 'offerte', 'ownerField' => 'assignee', 'lists' => $lists]]);
        $this->reader->method('register')->willReturn('pipelinq');
        $this->reader->method('readFromSchema')->willReturn(['number' => 'OFF-2026-0042', 'assignee' => 'pieter']);
        $this->policy->method('mayAccess')->willReturn(true);
        $listRefs = [
            [
                'register' => 'pipelinq',
                'schema'   => 'salesQuoteLine',
                'filter'   => ['quote' => self::QUOTE],
                'as'       => 'lines',
                'order'    => ['sortOrder' => 'ASC'],
            ],
        ];
        $this->documents->expects($this->once())->method('render')->with(
            '4f1c0d6e-9b2a-4c3d-8e5f-6a7b8c9d0e1f',
            [['register' => 'pipelinq', 'schema' => 'salesQuote', 'id' => self::QUOTE]],
            $this->stringContains(string: 'OFF-2026-0042'),
            'return',
            $listRefs
        )->willReturn(['content' => '%PDF-1.7', 'fileId' => null, 'path' => null, 'warnings' => []]);

        $response = $this->controller()->document(schema: 'salesQuote', id: self::QUOTE);

        $this->assertInstanceOf(expected: DataDownloadResponse::class, actual: $response);

    }//end testSlugTemplateRendersWithTheRecordsLists()


}//end class
