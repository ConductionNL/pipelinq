<?php

/**
 * Pipelinq RecordDocumentController.
 *
 * A record's document as a PDF, made by filinq from the template configured
 * for its schema (record-document-pdf). Which schemas have a document is
 * configuration (`record_document_templates`), so this controller knows no
 * record type: a quote, a contract or an invitation are all the same call.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/record-document-pdf/specs/record-documents/spec.md#requirement-a-configured-record-type-has-a-document
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\CallerObjectReader;
use OCA\Pipelinq\Service\Letter\FilinqLetterAdapter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * A configured record's document, and whether it can be made.
 *
 * @spec openspec/changes/record-document-pdf/specs/record-documents/spec.md#requirement-a-configured-record-type-has-a-document
 */
class RecordDocumentController extends Controller
{

    /**
     * The app config key holding the map from a schema slug to its template.
     */
    public const TEMPLATES_KEY = 'record_document_templates';


    /**
     * Constructor.
     *
     * @param IRequest                $request      The request.
     * @param FilinqLetterAdapter     $documents    filinq's side: templates and the render.
     * @param IUserSession            $userSession  The acting user.
     * @param CallerObjectReader      $reader       Reads the record as the caller.
     * @param ObjectOwnerAccessPolicy $accessPolicy Who may act on a record.
     * @param IAppConfig              $appConfig    The configured templates.
     */
    public function __construct(
        IRequest $request,
        private readonly FilinqLetterAdapter $documents,
        private readonly IUserSession $userSession,
        private readonly CallerObjectReader $reader,
        private readonly ObjectOwnerAccessPolicy $accessPolicy,
        private readonly IAppConfig $appConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()


    /**
     * GET /api/records/{schema}/document-template: whether this schema's records have a document.
     *
     * Always 200 for a signed-in user, so a header action's `visibleWhen` can
     * read `available`; when no document can be made the answer says why.
     *
     * @param string $schema The schema slug.
     *
     * @return JSONResponse `{available, reason?, template?}`.
     *
     * @spec openspec/changes/record-document-pdf/specs/record-documents/spec.md#requirement-the-document-is-offered-only-when-it-can-be-made
     */
    #[NoAdminRequired]
    public function template(string $schema): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $config = $this->configFor(schema: $schema);
        if ($config === null) {
            return new JSONResponse(['available' => false, 'reason' => 'no_template_configured']);
        }

        $reason = $this->documents->unavailableReason();
        if ($reason !== null) {
            return new JSONResponse(['available' => false, 'reason' => $reason]);
        }

        try {
            $template = $this->documents->findTemplate(templateId: $config['template']);
        } catch (RuntimeException $e) {
            return new JSONResponse(['available' => false, 'reason' => $e->getMessage()]);
        }

        if ($template === null) {
            return new JSONResponse(['available' => false, 'reason' => 'template_not_found']);
        }

        return new JSONResponse(['available' => true, 'template' => $template]);

    }//end template()


    /**
     * GET /api/records/{schema}/{id}/document: the record's document as a PDF download.
     *
     * The record is read as the caller, and the caller must be its owner or in
     * a privileged group, before filinq is asked for anything. A schema without
     * a configured template, a record the caller cannot read or act on, and a
     * template outside pipelinq's namespace all answer 404. Nothing is filed
     * and nothing is logged.
     *
     * @param string $schema The schema slug.
     * @param string $id     The record id.
     *
     * @return DataDownloadResponse|JSONResponse The PDF, or an error.
     *
     * @spec openspec/changes/record-document-pdf/specs/record-documents/spec.md#requirement-a-configured-record-type-has-a-document
     * @spec openspec/changes/record-document-pdf/specs/record-documents/spec.md#requirement-the-caller-may-act-on-the-record-before-anything-is-made
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function document(string $schema, string $id): DataDownloadResponse|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $config = $this->configFor(schema: $schema);
        if ($config === null) {
            return new JSONResponse(['message' => 'This record has no document'], Http::STATUS_NOT_FOUND);
        }

        // Existence is not authorization: OpenRegister answers a read whatever
        // `_rbac` says, so this app decides who may act on the record.
        $record = $this->reader->readFromSchema(id: $id, schema: $schema);
        if ($record === null
            || $this->accessPolicy->mayAccess(uid: $user->getUID(), object: $record, ownerField: $config['ownerField']) === false
        ) {
            return new JSONResponse(['message' => 'Record not found'], Http::STATUS_NOT_FOUND);
        }

        $reason = $this->documents->unavailableReason();
        if ($reason !== null) {
            return new JSONResponse(
                ['message' => 'filinq is not available, so no document can be made.', 'reason' => $reason],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        $template = $this->documents->findTemplate(templateId: $config['template']);
        if ($template === null) {
            return new JSONResponse(['message' => 'Template not found'], Http::STATUS_NOT_FOUND);
        }

        $filename = $this->filename(templateName: $template['name'], record: $record);
        try {
            $register = $this->reader->register();
            $document = $this->documents->render(
                templateId: $template['id'],
                dataRefs: [['register' => $register, 'schema' => $schema, 'id' => $id]],
                filename: $filename,
                outputMode: 'return',
                listRefs: $this->listRefs(lists: $config['lists'], register: $register, id: $id)
            );
        } catch (RuntimeException $e) {
            return new JSONResponse(['message' => 'filinq could not make the document.', 'reason' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
        }

        return new DataDownloadResponse($document['content'], $filename.'.pdf', 'application/pdf');

    }//end document()


    /**
     * The configured template and owner field for a schema, or null when it has none.
     *
     * @param string $schema The schema slug.
     *
     * @return array{template: string, ownerField: string, lists: list<array<string, mixed>>}|null The configuration.
     */
    private function configFor(string $schema): ?array
    {
        $map = json_decode($this->appConfig->getValueString(Application::APP_ID, self::TEMPLATES_KEY, ''), true);
        if (is_array($map) === false || isset($map[$schema]) === false || is_array($map[$schema]) === false) {
            return null;
        }

        $template = trim((string) ($map[$schema]['template'] ?? ''));
        if ($template === '') {
            return null;
        }

        $ownerField = trim((string) ($map[$schema]['ownerField'] ?? ''));
        if ($ownerField === '') {
            $ownerField = 'ownerId';
        }

        $lists = [];
        if (is_array($map[$schema]['lists'] ?? null) === true) {
            $lists = array_values(array_filter($map[$schema]['lists'], 'is_array'));
        }

        return ['template' => $template, 'ownerField' => $ownerField, 'lists' => $lists];

    }//end configFor()


    /**
     * filinq's list references for the record's configured lists: each list's
     * schema filtered on its `field` holding the record's id, under its `as`
     * key (a quote's lines as `lines`). An entry without schema, field or as
     * is left out.
     *
     * @param list<array<string, mixed>> $lists    The configured lists (`schema`, `field`, `as`, `order?`).
     * @param string                     $register The register.
     * @param string                     $id       The record id.
     *
     * @return list<array<string, mixed>> The list references.
     *
     * @spec openspec/changes/record-document-pdf/specs/record-documents/spec.md#requirement-a-configured-record-type-has-a-document
     */
    private function listRefs(array $lists, string $register, string $id): array
    {
        $refs = [];
        foreach ($lists as $list) {
            $schema = trim((string) ($list['schema'] ?? ''));
            $field  = trim((string) ($list['field'] ?? ''));
            $as     = trim((string) ($list['as'] ?? ''));
            if ($schema === '' || $field === '' || $as === '') {
                continue;
            }

            $ref = ['register' => $register, 'schema' => $schema, 'filter' => [$field => $id], 'as' => $as];
            if (is_array($list['order'] ?? null) === true) {
                $ref['order'] = $list['order'];
            }

            $refs[] = $ref;
        }

        return $refs;

    }//end listRefs()


    /**
     * A file name for the document: the template, the record's number and title, safe for a download.
     *
     * @param string               $templateName The template's name.
     * @param array<string, mixed> $record       The record.
     *
     * @return string The name, without extension.
     */
    private function filename(string $templateName, array $record): string
    {
        $parts = [$templateName];
        foreach (['number', 'reference', 'title', 'name'] as $field) {
            if (is_scalar($record[$field] ?? null) === true && trim((string) $record[$field]) !== '') {
                $parts[] = trim((string) $record[$field]);
            }
        }

        $name = (string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', implode(' ', $parts));

        return trim((string) preg_replace('/\s+/u', ' ', $name));

    }//end filename()


}//end class
