<?php

/**
 * Unit tests for FilinqLetterAdapter.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Letter
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
 * @spec openspec/changes/archive/2026-09-30-work-letter-from-filinq-template/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Letter;

use OCA\Pipelinq\Service\Letter\FilinqLetterAdapter;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The adapter asks filinq, and says so by name when filinq is not there.
 *
 * filinq is not installed in the test run, so its two services are stand-ins
 * with the method names and argument order of filinq's own
 * `Service\TemplateService::getTemplatesByNamespace()` /
 * `getTemplate()` and `Service\DocumentService::generateDocument()`
 * (filinq development, lib/Service/TemplateService.php:373,
 * lib/Service/DocumentService.php:168).
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */
class FilinqLetterAdapterTest extends TestCase
{

    /**
     * Calls the stand-in services received.
     *
     * @var \ArrayObject<int, array<int, mixed>>
     */
    private \ArrayObject $calls;


    /**
     * A fresh call log per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = new \ArrayObject();

    }//end setUp()


    /**
     * An adapter over a container that knows filinq's services, or none.
     *
     * @param object|null $templates The TemplateService stand-in, or null for absent.
     * @param object|null $documents The DocumentService stand-in, or null for absent.
     * @param string|null $uid       The acting user, or null.
     *
     * @return FilinqLetterAdapter
     */
    private function adapter(?object $templates, ?object $documents, ?string $uid='ann'): FilinqLetterAdapter
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($templates, $documents): object {
                if ($id === 'OCA\Filinq\Service\TemplateService' && $templates !== null) {
                    return $templates;
                }

                if ($id === 'OCA\Filinq\Service\DocumentService' && $documents !== null) {
                    return $documents;
                }

                throw new RuntimeException('not registered: '.$id);
            }
        );

        $session = $this->createMock(IUserSession::class);
        $user    = null;
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
        }

        $session->method('getUser')->willReturn($user);

        return new FilinqLetterAdapter($container, $session, $this->createMock(LoggerInterface::class));

    }//end adapter()


    /**
     * A TemplateService stand-in that records the namespace it was asked for.
     *
     * @param array<int, array<string, mixed>> $rows The templates it holds.
     *
     * @return object
     */
    private function templates(array $rows): object
    {
        return new class($rows, $this->calls) {


            /**
             * @param array<int, array<string, mixed>> $rows  Templates.
             * @param \ArrayObject                    $calls Call log.
             */
            public function __construct(private array $rows, private \ArrayObject $calls)
            {
            }


            /**
             * @param string $namespace The namespace.
             *
             * @return array<int, array<string, mixed>>
             */
            public function getTemplatesByNamespace(string $namespace): array
            {
                $this->calls[] = ['getTemplatesByNamespace', $namespace];
                return array_values(array_filter($this->rows, static fn(array $r): bool => ($r['namespace'] ?? '') === $namespace));
            }


            /**
             * @param string $id The id.
             *
             * @return array<string, mixed>
             */
            public function getTemplate(string $id): array
            {
                foreach ($this->rows as $row) {
                    if ($row['id'] === $id) {
                        return $row;
                    }
                }

                throw new RuntimeException('Template not found', 404);
            }


        };

    }//end templates()


    /**
     * A DocumentService stand-in that records its arguments and answers $result.
     *
     * @param array<string, mixed> $result What generateDocument() returns.
     *
     * @return object
     */
    private function documents(array $result): object
    {
        return new class($result, $this->calls) {


            /**
             * @param array<string, mixed>          $result Answer.
             * @param \ArrayObject          $calls  Call log.
             */
            public function __construct(private array $result, private \ArrayObject $calls)
            {
            }


            /**
             * @param string                        $templateId Template.
             * @param array<int, array<string, mixed>> $dataRefs   Refs.
             * @param array<string, mixed>          $options    Options.
             *
             * @return array<string, mixed>
             */
            public function generateDocument(string $templateId, array $dataRefs, array $options=[]): array
            {
                $this->calls[] = ['generateDocument', $templateId, $dataRefs, $options];
                return $this->result;
            }


        };

    }//end documents()


    /**
     * Without filinq every call names the absence, and nothing is faked.
     *
     * @return void
     */
    public function testFilinqAbsentIsNamedNotFaked(): void
    {
        $adapter = $this->adapter(templates: null, documents: null);

        $this->assertStringStartsWith('filinq_unavailable', (string) $adapter->unavailableReason());

        try {
            $adapter->render('tpl-1', [], 'Letter');
            $this->fail('render() without filinq must throw');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('filinq_unavailable', $e->getMessage());
        }

        $this->expectExceptionMessageMatches('/^filinq_unavailable/');
        $adapter->listTemplates();

    }//end testFilinqAbsentIsNamedNotFaked()


    /**
     * Only half of filinq resolving is still absent.
     *
     * @return void
     */
    public function testHalfOfFilinqIsAbsent(): void
    {
        $adapter = $this->adapter(templates: $this->templates([]), documents: null);

        $this->assertNotNull($adapter->unavailableReason());

    }//end testHalfOfFilinqIsAbsent()


    /**
     * The dialog lists pipelinq's templates by name, and no other app's.
     *
     * @return void
     */
    public function testListsPipelinqTemplatesByName(): void
    {
        $adapter = $this->adapter(
            templates: $this->templates(
                [
                    ['id' => 't-2', 'name' => 'Welcome letter', 'namespace' => 'pipelinq'],
                    ['id' => 't-1', 'name' => 'Appointment letter', 'namespace' => 'pipelinq', 'description' => 'Invites to a visit'],
                    ['id' => 't-9', 'name' => 'Decision', 'namespace' => 'dossiq'],
                    ['name' => 'No id', 'namespace' => 'pipelinq'],
                ]
            ),
            documents: $this->documents([])
        );

        $this->assertNull($adapter->unavailableReason());
        $this->assertSame(
            [
                ['id' => 't-1', 'name' => 'Appointment letter', 'description' => 'Invites to a visit'],
                ['id' => 't-2', 'name' => 'Welcome letter', 'description' => ''],
            ],
            $adapter->listTemplates()
        );
        $this->assertSame([['getTemplatesByNamespace', 'pipelinq']], $this->calls->getArrayCopy());

    }//end testListsPipelinqTemplatesByName()


    /**
     * A template of another app, or an unknown id, is not a pipelinq letter.
     *
     * @return void
     */
    public function testFindTemplateRefusesAnotherAppsTemplate(): void
    {
        $adapter = $this->adapter(
            templates: $this->templates(
                [
                    ['id' => 't-1', 'name' => 'Appointment letter', 'namespace' => 'pipelinq'],
                    ['id' => 't-9', 'name' => 'Decision', 'namespace' => 'dossiq'],
                ]
            ),
            documents: $this->documents([])
        );

        $this->assertSame('Appointment letter', $adapter->findTemplate('t-1')['name'] ?? null);
        $this->assertNull($adapter->findTemplate('t-9'));
        $this->assertNull($adapter->findTemplate('nope'));

    }//end testFindTemplateRefusesAnotherAppsTemplate()


    /**
     * A render hands filinq references and asks for a PDF in Files and back.
     *
     * @return void
     */
    public function testRenderPassesReferencesAndAsksForBoth(): void
    {
        $adapter = $this->adapter(
            templates: $this->templates([]),
            documents: $this->documents(
                [
                    'content'  => '%PDF-1.7 letter',
                    'format'   => 'pdf',
                    'warnings' => ['Variable "client.salutation" is empty'],
                    'output'   => ['mode' => 'both', 'fileId' => 4711, 'path' => '/DocuDesk/Letter.pdf'],
                ]
            )
        );

        $refs   = [
            ['register' => '7', 'schema' => 'client', 'id' => 'c-1'],
            ['register' => '7', 'schema' => 'ticket', 'id' => 't-1'],
        ];
        $letter = $adapter->render('tpl-1', $refs, 'Appointment letter Bakkerij 2026-09-30');

        $this->assertSame(
            [
                'content'  => '%PDF-1.7 letter',
                'fileId'   => 4711,
                'path'     => '/DocuDesk/Letter.pdf',
                'warnings' => ['Variable "client.salutation" is empty'],
            ],
            $letter
        );
        $this->assertSame(
            [
                'generateDocument',
                'tpl-1',
                $refs,
                [
                    'format'   => 'pdf',
                    'userId'   => 'ann',
                    'filename' => 'Appointment letter Bakkerij 2026-09-30',
                    'output'   => ['mode' => 'both'],
                ],
            ],
            $this->calls[0]
        );

    }//end testRenderPassesReferencesAndAsksForBoth()


    /**
     * No acting user, no letter: filinq files it in somebody's Files.
     *
     * @return void
     */
    public function testRenderWithoutAUserIsRefused(): void
    {
        $adapter = $this->adapter(templates: $this->templates([]), documents: $this->documents(['content' => 'x']), uid: null);

        $this->expectExceptionMessageMatches('/^user_required/');
        $adapter->render('tpl-1', [], 'Letter');

    }//end testRenderWithoutAUserIsRefused()


    /**
     * An empty answer from filinq is a failure, not an empty PDF.
     *
     * @return void
     */
    public function testAnEmptyDocumentIsAFailure(): void
    {
        $adapter = $this->adapter(templates: $this->templates([]), documents: $this->documents(['content' => '']));

        $this->expectExceptionMessageMatches('/^filinq_render_failed/');
        $adapter->render('tpl-1', [], 'Letter');

    }//end testAnEmptyDocumentIsAFailure()


}//end class
