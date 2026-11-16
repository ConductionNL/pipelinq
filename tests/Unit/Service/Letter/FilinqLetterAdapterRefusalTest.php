<?php

/**
 * What the filinq letter adapter does when filinq refuses a call.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Letter;

use OCA\Pipelinq\Service\Letter\FilinqLetterAdapter;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A refusal from filinq is logged and surfaced as a named failure, never swallowed.
 *
 * @covers \OCA\Pipelinq\Service\Letter\FilinqLetterAdapter
 * @uses   \OCA\Pipelinq\Support\FleetAppId
 */
class FilinqLetterAdapterRefusalTest extends TestCase
{

    /**
     * The logger the adapter reports to.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;


    /**
     * Set up the logger.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);

    }//end setUp()


    /**
     * An adapter whose filinq services are the given objects.
     *
     * @param object      $service The object served for every filinq service.
     * @param object|null $slugs   The slug resolver, or null when none is registered.
     *
     * @return FilinqLetterAdapter
     */
    private function adapter(object $service, ?object $slugs=null): FilinqLetterAdapter
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($service, $slugs): object {
                if ($id === 'OCA\Filinq\Service\TemplateSlugResolver') {
                    if ($slugs === null) {
                        throw new RuntimeException('not registered: '.$id);
                    }

                    return $slugs;
                }

                if ($id === 'OCA\Filinq\Service\TemplateService' || $id === 'OCA\Filinq\Service\DocumentService') {
                    return $service;
                }

                throw new RuntimeException('not registered: '.$id);
            }
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('ann');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        return new FilinqLetterAdapter($container, $session, $this->logger);

    }//end adapter()


    /**
     * A filinq stand-in that refuses every call.
     *
     * @return object
     */
    private function refusing(): object
    {
        return new class {


            public function getTemplatesByNamespace(string $namespace): array
            {
                throw new RuntimeException('listing down');
            }


            public function getTemplate(string $id): array
            {
                throw new RuntimeException('Template not found', 404);
            }


            public function generateDocument(string $templateId, array $dataRefs, array $options=[]): array
            {
                throw new RuntimeException('render down');
            }


            public function generateMergePreview(string $templateId, array $dataRefs, array $options=[]): array
            {
                throw new RuntimeException('preview down');
            }


            public function resolve(string $namespace, string $slug): array
            {
                throw new RuntimeException('resolver down');
            }


        };

    }//end refusing()


    /**
     * Expect one error log with this message.
     *
     * @param string $message The log message.
     *
     * @return void
     */
    private function expectLogged(string $message): void
    {
        $this->logger->expects($this->once())->method('error')->with(
            $message,
            $this->callback(static fn(array $context): bool => ($context['app'] ?? null) === 'pipelinq')
        );

    }//end expectLogged()


    /**
     * A refused template listing is logged and named filinq_failed with the cause.
     *
     * @return void
     */
    public function testARefusedListingIsNamed(): void
    {
        $this->expectLogged(message: 'Pipelinq: filinq refused the letter template listing');

        try {
            $this->adapter(service: $this->refusing())->listTemplates();
            $this->fail('listTemplates() must throw');
        } catch (RuntimeException $e) {
            $this->assertSame('filinq_failed: listing down', $e->getMessage());
            $this->assertSame('listing down', $e->getPrevious()?->getMessage());
        }

    }//end testARefusedListingIsNamed()


    /**
     * A refused render is logged with its template and named filinq_render_failed.
     *
     * @return void
     */
    public function testARefusedRenderIsNamed(): void
    {
        $this->logger->expects($this->once())->method('error')->with(
            'Pipelinq: filinq refused a letter render',
            $this->callback(static fn(array $context): bool => ($context['templateId'] ?? null) === 'tpl-1' && ($context['exception'] ?? null) === 'render down')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('filinq_render_failed: render down');

        $this->adapter(service: $this->refusing())->render('tpl-1', [], 'Letter');

    }//end testARefusedRenderIsNamed()


    /**
     * A refused preview is logged and named filinq_preview_failed.
     *
     * @return void
     */
    public function testARefusedPreviewIsNamed(): void
    {
        $this->expectLogged(message: 'Pipelinq: filinq refused a letter preview');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('filinq_preview_failed: preview down');

        $this->adapter(service: $this->refusing())->preview('tpl-1', []);

    }//end testARefusedPreviewIsNamed()


    /**
     * When the id lookup fails and the slug resolver refuses too, the template is absent.
     *
     * @return void
     */
    public function testARefusingSlugResolverFindsNothing(): void
    {
        $this->logger->expects($this->never())->method('error');

        $this->assertNull($this->adapter(service: $this->refusing(), slugs: $this->refusing())->findTemplate(templateId: 'offerte'));

    }//end testARefusingSlugResolverFindsNothing()


    /**
     * A slug the resolver answers from another app's namespace is absent, so a
     * slug cannot reach another app's template.
     *
     * @return void
     */
    public function testASlugResolvedInAnotherNamespaceFindsNothing(): void
    {
        $slugs = new class {


            public function resolve(string $namespace, string $slug): array
            {
                return ['id' => 'other-1', 'namespace' => 'shillinq', 'name' => 'Factuur'];
            }


        };

        $this->assertNull($this->adapter(service: $this->refusing(), slugs: $slugs)->findTemplate(templateId: 'factuur'));

    }//end testASlugResolvedInAnotherNamespaceFindsNothing()


    /**
     * Listed rows take their id from id, @self.id or uuid; a row without any id
     * is left out, and a row without a name is named by its id.
     *
     * @return void
     */
    public function testListedRowsWithoutAnIdAreLeftOut(): void
    {
        $rows = [
            ['namespace' => 'pipelinq', 'name' => 'Zomer'],
            ['namespace' => 'pipelinq', '@self' => ['id' => 'self-1'], 'name' => 'Brief'],
            ['namespace' => 'pipelinq', 'uuid' => 'uuid-1', 'name' => '  '],
        ];
        $service = new class($rows) {


            public function __construct(private array $rows)
            {
            }


            public function getTemplatesByNamespace(string $namespace): array
            {
                return $this->rows;
            }


        };

        $this->assertSame(
            [
                ['id' => 'self-1', 'name' => 'Brief', 'description' => ''],
                ['id' => 'uuid-1', 'name' => 'uuid-1', 'description' => ''],
            ],
            $this->adapter(service: $service)->listTemplates()
        );

    }//end testListedRowsWithoutAnIdAreLeftOut()


}//end class
