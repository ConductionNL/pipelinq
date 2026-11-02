<?php

/**
 * Tests that the client page's business card counts only the shillinq
 * invoices the reader may see.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/client-detail/spec.md#requirement-the-client-page-says-what-the-client-does-business-on
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Controller\ClientBusinessController;
use OCA\Pipelinq\Service\ClientBusinessReader;
use OCA\Pipelinq\Service\ShillinqInvoiceReader;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The invoice sentence reads shillinq with the caller's rights.
 */
class ClientBusinessInvoiceRightsTest extends TestCase {

	/**
	 * The fake OpenRegister object service.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Build the endpoint over a fake OpenRegister.
	 *
	 * The fake answers ARInvoice reads the way OpenRegister does: a system
	 * read (`_rbac: false`) returns every invoice of the customer, a read with
	 * the caller's rights returns only the invoices the caller may see, and
	 * the permission handler says whether the caller may read the schema.
	 *
	 * @param bool|null $mayRead What the permission handler answers; null throws.
	 * @param array<int, array<string, mixed>> $visible The invoices the caller's read returns.
	 *
	 * @return ClientBusinessController
	 */
	private function endpoint(?bool $mayRead, array $visible): ClientBusinessController {
		$this->objects = new class ($mayRead, $visible) {
			/**
			 * Every read, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * The fake.
			 *
			 * @param bool|null $mayRead What the permission handler answers.
			 * @param array<int, array<string, mixed>> $visible The caller's invoices.
			 */
			public function __construct(private ?bool $mayRead, private array $visible) {
			}

			/**
			 * Find one object, as ObjectService::find.
			 *
			 * @param int|string $id The id.
			 * @param array|null $_extend Unused.
			 * @param bool $files Unused.
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether multitenancy applies.
			 *
			 * @return array<string, mixed>
			 */
			public function find(
				int|string $id,
				?array $_extend=[],
				bool $files=false,
				mixed $register=null,
				mixed $schema=null,
				bool $_rbac=true,
				bool $_multitenancy=true
			): array {
				return ['type' => 'person', 'shillinqOrganisationRef' => 'deb-1'];
			}

			/**
			 * List objects, as ObjectService::findAll.
			 *
			 * @param array<string, mixed> $config The query.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether multitenancy applies.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$schema = $config['filters']['schema'];
				$this->calls[] = ['schema' => $schema, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				if ($schema !== 'ARInvoice') {
					return [];
				}

				if ($_rbac === false) {
					return [
						['id' => 'i1', 'lifecycleState' => 'overdue'],
						['id' => 'i2', 'lifecycleState' => 'paid'],
						['id' => 'i3', 'lifecycleState' => 'sent'],
					];
				}

				return $this->visible;
			}

			/**
			 * The permission handler, as ObjectService::getPermissionHandler.
			 *
			 * @return object
			 */
			public function getPermissionHandler(): object {
				return new class ($this->mayRead) {
					/**
					 * The fake handler.
					 *
					 * @param bool|null $mayRead What it answers; null throws.
					 */
					public function __construct(private ?bool $mayRead) {
					}

					/**
					 * As PermissionHandler::hasPermission.
					 *
					 * @param object $schema The schema.
					 * @param string $action The action.
					 * @param string|null $userId The user; null is the session.
					 *
					 * @return bool
					 */
					public function hasPermission(object $schema, string $action, ?string $userId = null): bool {
						if ($this->mayRead === null) {
							throw new RuntimeException('no session');
						}

						return $this->mayRead && $schema->slug === 'ARInvoice' && $action === 'read' && $userId === null;
					}
				};
			}
		};

		$registers = new class {
			/**
			 * As RegisterMapper::find.
			 *
			 * @param int|string $id The id or slug.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether multitenancy applies.
			 *
			 * @return object
			 */
			public function find(int|string $id, bool $_rbac = true, bool $_multitenancy = true): object {
				if ($id !== 'shillinq') {
					throw new RuntimeException('no register '.$id);
				}

				return new class {
					/**
					 * As Register::getSchemas.
					 *
					 * @return array<int, int>
					 */
					public function getSchemas(): array {
						return [41, 42];
					}
				};
			}
		};
		$schemas = new class {
			/**
			 * As SchemaMapper::findBySlugInIds.
			 *
			 * @param string $slug The slug.
			 * @param array<int, int> $schemaIds The register's schemas.
			 *
			 * @return object|null
			 */
			public function findBySlugInIds(string $slug, array $schemaIds): ?object {
				if ($schemaIds !== [41, 42]) {
					return null;
				}

				return (object)['slug' => $slug];
			}
		};

		$services = [
			'OCA\OpenRegister\Service\ObjectService' => $this->objects,
			'OCA\OpenRegister\Db\RegisterMapper'     => $registers,
			'OCA\OpenRegister\Db\SchemaMapper'       => $schemas,
		];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $services[$id]);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$invoices = new class ($container, $logger) extends ShillinqInvoiceReader {
			/**
			 * Shillinq is installed.
			 *
			 * @return bool
			 */
			protected function probe(): bool {
				return true;
			}
		};

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$keys = ['register' => 'pipelinq', 'client_schema' => 'client'];
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $keys[$key] ?? $default
		);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []): string => vsprintf($text, $params));
		$l10n->method('n')->willReturnCallback(
			function (string $one, string $many, int $count, array $params = []): string {
				$text = $many;
				if ($count === 1) {
					$text = $one;
				}

				return vsprintf(str_replace('%n', (string)$count, $text), $params);
			}
		);

		$reader = new ClientBusinessReader(
			appConfig: $config,
			container: $container,
			invoices: $invoices,
			l10n: $l10n,
			urlGenerator: $this->createMock(originalClassName: IURLGenerator::class),
			logger: $logger
		);

		return new ClientBusinessController(request: $this->createMock(originalClassName: IRequest::class), reader: $reader);
	}//end endpoint()

	/**
	 * The ARInvoice reads the fake saw.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function invoiceReads(): array {
		return array_values(array_filter($this->objects->calls, fn (array $call): bool => $call['schema'] === 'ARInvoice'));
	}//end invoiceReads()

	/**
	 * A reader who may not read shillinq's invoices gets no invoice sentence
	 * and no invoice is read.
	 *
	 * @return void
	 */
	public function testAReaderWithoutInvoiceRightsGetsNoInvoiceSentence(): void {
		$data = $this->endpoint(mayRead: false, visible: [])->index(id: 'c1')->getData();

		$this->assertSame(expected: '', actual: $data['invoicesLine']);
		$this->assertSame(expected: [], actual: $this->invoiceReads());
	}//end testAReaderWithoutInvoiceRightsGetsNoInvoiceSentence()

	/**
	 * A reader who may read invoices counts the ones their own read returns.
	 *
	 * @return void
	 */
	public function testAReaderWithInvoiceRightsCountsWhatTheyMaySee(): void {
		$visible = [['id' => 'i1', 'lifecycleState' => 'overdue'], ['id' => 'i2', 'lifecycleState' => 'paid']];

		$data = $this->endpoint(mayRead: true, visible: $visible)->index(id: 'c1')->getData();

		$this->assertSame(expected: '2 invoices, 1 open.', actual: $data['invoicesLine']);
		$this->assertSame(expected: [['schema' => 'ARInvoice', '_rbac' => true, '_multitenancy' => true]], actual: $this->invoiceReads());
	}//end testAReaderWithInvoiceRightsCountsWhatTheyMaySee()

	/**
	 * A reader who may read invoices and sees none is told there are none.
	 *
	 * @return void
	 */
	public function testAReaderWithInvoiceRightsAndNoInvoicesReadsNone(): void {
		$data = $this->endpoint(mayRead: true, visible: [])->index(id: 'c1')->getData();

		$this->assertSame(expected: 'No invoices for this resident.', actual: $data['invoicesLine']);
	}//end testAReaderWithInvoiceRightsAndNoInvoicesReadsNone()

	/**
	 * When the right cannot be checked the card leaves the sentence out.
	 *
	 * @return void
	 */
	public function testARightThatCannotBeCheckedLeavesTheSentenceOut(): void {
		$data = $this->endpoint(mayRead: null, visible: [['id' => 'i1', 'lifecycleState' => 'sent']])->index(id: 'c1')->getData();

		$this->assertSame(expected: '', actual: $data['invoicesLine']);
		$this->assertSame(expected: [], actual: $this->invoiceReads());
	}//end testARightThatCannotBeCheckedLeavesTheSentenceOut()
}//end class
