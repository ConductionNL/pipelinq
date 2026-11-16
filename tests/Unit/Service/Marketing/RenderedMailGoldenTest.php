<?php

/**
 * Golden test for the mail a blast delivery is rendered into.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\ArticleService;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Marketing\MailTransportService;
use OCA\Pipelinq\Service\Marketing\PhysicalAddressRenderer;
use OCA\Pipelinq\Service\Marketing\RecipientNameReader;
use OCA\Pipelinq\Tests\Unit\Support\FakeSlugResolver;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Renders fixed templates for fixed deliveries and compares every field of
 * the resulting mail with RenderedMailGolden.json. Set
 * RENDERED_MAIL_GOLDEN_RECORD=1 to rewrite the golden file.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\MailTransportService
 * @covers \OCA\Pipelinq\Service\Marketing\Transport\RenderedMail
 * @covers \OCA\Pipelinq\Service\Marketing\Transport\MailAddressing
 * @covers \OCA\Pipelinq\Service\Marketing\Transport\MailContent
 * @uses   \OCA\Pipelinq\Service\ConnectorSourceRegister
 * @uses   \OCA\Pipelinq\Service\ArticleService
 * @uses   \OCA\Pipelinq\Service\Marketing\ListObjectStore
 * @uses   \OCA\Pipelinq\Service\Marketing\PhysicalAddressRenderer
 * @uses   \OCA\Pipelinq\Service\Marketing\RecipientNameReader
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 * @uses \OCA\Pipelinq\Util\ObjectToPlainArray
 * @uses \OCA\Pipelinq\Util\AnyPayloadToArray
 * @uses \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses \OCA\Pipelinq\Util\NullablePayloadToArray
 * @uses \OCA\Pipelinq\Util\OptionalPayloadToArray
 * @uses \OCA\Pipelinq\Util\SerialisedToArray
 */
class RenderedMailGoldenTest extends TestCase {
	use \OCA\Pipelinq\Tests\Unit\Support\PortaliqArticleFixture;


	private const GOLDEN = __DIR__ . '/RenderedMailGolden.json';

	/**
	 * Template and delivery per case.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$template = [
			'senderEmail' => 'news@example.test',
			'senderName'  => 'The Newsroom',
			'replyTo'     => 'reply@example.test',
			'subject'     => 'Hello {{email}}',
			'bodyHtml'    => '<p>Dear {{email}}</p>',
			'bodyText'    => 'Dear {{email}}',
		];
		$delivery = ['uuid' => 'd-1', 'email' => 'user@example.test', 'unsubscribeUrl' => ' https://example.test/u/1 '];

		return [
			'full'                 => [$template, $delivery],
			'no reply-to'          => [array_merge($template, ['replyTo' => '']), $delivery],
			'no sender name'       => [array_diff_key($template, ['senderName' => true]), $delivery],
			'no unsubscribe link'  => [$template, array_merge($delivery, ['unsubscribeUrl' => '  '])],
			'delivery id from id'  => [$template, ['id' => 'd-2', 'email' => 'x@example.test']],
			'delivery id by slug'  => [$template, ['slug' => 'd-3', 'email' => 'y@example.test']],
			'empty template'       => [[], ['uuid' => 'd-4']],
		];
	}//end cases()

	/**
	 * The service under test, over inert collaborators.
	 *
	 * @return MailTransportService
	 */
	private function service(): MailTransportService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => $default
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$logger    = $this->createMock(originalClassName: LoggerInterface::class);

		return new MailTransportService(
			container: $container,
			appConfig: $appConfig,
			mailer: $this->createMock(originalClassName: IMailer::class),
			articleService: $this->portaliqArticleService(),
			connectorRegister: FakeSlugResolver::connectorRegister(),
			logger: $logger,
			addressRenderer: new PhysicalAddressRenderer(),
			recipientNames: new RecipientNameReader(container: $container, appConfig: $appConfig, logger: new NullLogger()),
		);
	}//end service()

	/**
	 * The rendered mail matches the golden.
	 *
	 * @param array<string, mixed> $template The template.
	 * @param array<string, mixed> $delivery The delivery.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheRenderedMailMatchesTheGolden(array $template, array $delivery): void {
		$service = $this->service();
		$mail    = (new ReflectionMethod($service, 'buildRenderedMail'))->invoke($service, $template, $delivery);
		$actual  = [
			'fromEmail'  => $mail->fromEmail,
			'fromName'   => $mail->fromName,
			'replyTo'    => $mail->replyTo,
			'toEmail'    => $mail->toEmail,
			'subject'    => $mail->subject,
			'html'       => $mail->html,
			'text'       => $mail->text,
			'headers'    => $mail->headers,
			'deliveryId' => $mail->deliveryId,
		];
		$name    = (string) $this->dataName();

		if (getenv('RENDERED_MAIL_GOLDEN_RECORD') !== false) {
			$golden = [];
			if (file_exists(self::GOLDEN) === true) {
				$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		$this->assertArrayHasKey(key: $name, array: $golden);
		$this->assertSame(expected: $golden[$name], actual: $actual);
	}//end testTheRenderedMailMatchesTheGolden()
}//end class
