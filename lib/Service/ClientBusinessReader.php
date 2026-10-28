<?php

/**
 * Pipelinq ClientBusinessReader.
 *
 * What a client does business on, in sentences for the client page's side
 * card (boards PqKlant "Projecten en facturering" and PqOrganisatie
 * "Verkoop, contracten en facturering"): the open leads, the running
 * contracts, the projects in planninq and the invoices in shillinq, with a
 * link to shillinq. Read only and soft: an app that is not there leaves
 * its sentence out.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/client-side-cards-appointments-business-knowledge/specs/client-detail/spec.md#requirement-the-client-page-says-what-the-client-does-business-on
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The client's leads, contracts, projects and invoices as sentences.
 *
 * @spec openspec/changes/client-side-cards-appointments-business-knowledge/specs/client-detail/spec.md#requirement-the-client-page-says-what-the-client-does-business-on
 */
class ClientBusinessReader {

	private const OBJECT_SERVICE = 'OCA\OpenRegister\Service\ObjectService';

	private const OPEN_INVOICE_STATES = ['draft', 'sent', 'overdue', 'partially_paid'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig            $appConfig    The app configuration.
	 * @param ContainerInterface    $container    The container, for OpenRegister.
	 * @param ShillinqInvoiceReader $invoices     The invoices in shillinq.
	 * @param IL10N                 $l10n         The translator.
	 * @param IURLGenerator         $urlGenerator The URL generator.
	 * @param LoggerInterface       $logger       The logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly ShillinqInvoiceReader $invoices,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The sentences for one client.
	 *
	 * @param string $clientId The client UUID.
	 *
	 * @return array<string, mixed> `status`, and on 200 `leadsLine`, `contractsLine`, `projectsLine`, `invoicesLine` and `shillinqUrl`.
	 *
	 * @spec openspec/changes/client-side-cards-appointments-business-knowledge/specs/client-detail/spec.md#requirement-the-client-page-says-what-the-client-does-business-on
	 */
	public function businessOf(string $clientId): array {
		$client = $this->client(clientId: $clientId);
		if (isset($client['status']) === true) {
			return $client;
		}

		$register  = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$person    = (($client['type'] ?? '') === 'person');
		$leads     = $this->rows(
			register: $register,
			schema: $this->schema(key: 'lead_schema', default: 'lead'),
			filters: ['client' => $clientId, 'status' => 'open']
		);
		$contracts = $this->rows(
			register: $register,
			schema: $this->schema(key: 'contract_schema', default: 'salesContract'),
			filters: ['clientRef' => $clientId]
		);

		return [
			'status'        => 200,
			'leadsLine'     => $this->leadsLine(leads: $leads),
			'contractsLine' => $this->contractsLine(contracts: $contracts),
			'projectsLine'  => $this->projectsLine(projects: $this->rows(register: 'planninq', schema: 'project', filters: ['client' => $clientId])),
			'invoicesLine'  => $this->invoicesLine(customerRef: (string)($client['shillinqOrganisationRef'] ?? ''), person: $person),
			'shillinqUrl'   => $this->shillinqUrl(),
		];
	}//end businessOf()

	/**
	 * "1 open lead: evenementenpakket, € 12.500", the largest first.
	 *
	 * @param array<int, array<string, mixed>> $leads The open leads.
	 *
	 * @return string The sentence, or '' without open leads.
	 */
	private function leadsLine(array $leads): string {
		if ($leads === []) {
			return '';
		}

		usort($leads, fn (array $left, array $right): int => ((float)($right['value'] ?? 0) <=> (float)($left['value'] ?? 0)));
		$largest = $leads[0];

		return $this->l10n->n(
			'%n open lead: %s, %s',
			'%n open leads, the largest: %s, %s',
			count($leads),
			[(string)($largest['title'] ?? ''), $this->money(amount: (float)($largest['value'] ?? 0))]
		);
	}//end leadsLine()

	/**
	 * "Huurovereenkomst zaal, renews 1 Jan 2027" for each running contract.
	 *
	 * @param array<int, array<string, mixed>> $contracts The client's contracts.
	 *
	 * @return string The sentences, or ''.
	 */
	private function contractsLine(array $contracts): string {
		$lines = [];
		foreach ($contracts as $contract) {
			if (in_array(($contract['status'] ?? ''), ['active', 'expiring'], true) === false) {
				continue;
			}

			$end = (string)($contract['endDate'] ?? '');
			if ($end === '') {
				$lines[] = (string)($contract['title'] ?? '');
				continue;
			}

			$date = (string)$this->l10n->l('date', new \DateTime($end), ['width' => 'medium']);
			$text = $this->l10n->t('%s, ends %s', [(string)($contract['title'] ?? ''), $date]);
			if (($contract['autoRenew'] ?? false) === true) {
				$text = $this->l10n->t('%s, renews %s', [(string)($contract['title'] ?? ''), $date]);
			}

			$lines[] = $text;
		}//end foreach

		return implode('. ', array_filter($lines));
	}//end contractsLine()

	/**
	 * "1 project: straatfeest Parkstraat 2026, planned."
	 *
	 * @param array<int, array<string, mixed>> $projects The client's projects.
	 *
	 * @return string The sentence.
	 */
	private function projectsLine(array $projects): string {
		if ($projects === []) {
			return $this->l10n->t('No projects.');
		}

		$first  = $projects[0];
		$labels = [
			'planned'   => $this->l10n->t('planned'),
			'active'    => $this->l10n->t('active'),
			'on_hold'   => $this->l10n->t('on hold'),
			'completed' => $this->l10n->t('completed'),
			'cancelled' => $this->l10n->t('cancelled'),
		];
		$status = (string)($first['status'] ?? '');

		return $this->l10n->n(
			'%n project: %s, %s.',
			'%n projects, the first: %s, %s.',
			count($projects),
			[(string)($first['title'] ?? ''), ($labels[$status] ?? $status)]
		);
	}//end projectsLine()

	/**
	 * "No invoices for this resident." or "2 invoices, 1 open."; '' without shillinq.
	 *
	 * @param string $customerRef The client's customer in shillinq.
	 * @param bool   $person      Whether the client is a resident.
	 *
	 * @return string The sentence.
	 */
	private function invoicesLine(string $customerRef, bool $person): string {
		if ($this->invoices->isAvailable() === false) {
			return '';
		}

		$invoices = [];
		if ($customerRef !== '') {
			$invoices = $this->invoices->invoicesFor(customerRef: $customerRef);
		}

		if ($invoices === []) {
			if ($person === true) {
				return $this->l10n->t('No invoices for this resident.');
			}

			return $this->l10n->t('No invoices for this organisation.');
		}

		$open = count(
			array_filter(
				$invoices,
				fn (array $invoice): bool => in_array(($invoice['lifecycleState'] ?? ''), self::OPEN_INVOICE_STATES, true)
			)
		);

		return $this->l10n->n('%n invoice, %s open.', '%n invoices, %s open.', count($invoices), [(string)$open]);
	}//end invoicesLine()

	/**
	 * Where shillinq opens, or '' without it.
	 *
	 * @return string The URL.
	 */
	private function shillinqUrl(): string {
		if ($this->invoices->isAvailable() === false) {
			return '';
		}

		return $this->urlGenerator->getAbsoluteURL('/index.php/apps/shillinq/');
	}//end shillinqUrl()

	/**
	 * An amount in euro, as the user's locale writes it.
	 *
	 * @param float $amount The amount.
	 *
	 * @return string The text.
	 */
	private function money(float $amount): string {
		return '€ '.number_format($amount, 0, ',', '.');
	}//end money()

	/**
	 * The schema configured under a key.
	 *
	 * @param string $key     The app config key.
	 * @param string $default The slug when none is configured.
	 *
	 * @return string The schema.
	 */
	private function schema(string $key, string $default): string {
		$schema = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($schema === '') {
			return $default;
		}

		return $schema;
	}//end schema()

	/**
	 * The rows of a schema that match the filters, as the reader may see them.
	 *
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param array<string, mixed> $filters  The filters.
	 *
	 * @return array<int, array<string, mixed>> The rows; [] when the read fails.
	 */
	private function rows(string $register, string $schema, array $filters): array {
		if ($register === '') {
			return [];
		}

		try {
			$found = $this->container->get(self::OBJECT_SERVICE)->findAll(
				config: ['filters' => array_merge(['register' => $register, 'schema' => $schema], $filters), 'limit' => 50]
			);
		} catch (Throwable $e) {
			$this->logger->info('ClientBusinessReader: no rows', ['schema' => $schema, 'exception' => $e->getMessage()]);
			return [];
		}

		return array_map(fn (mixed $row): array => $this->asArray(object: $row), (array)$found);
	}//end rows()

	/**
	 * The client, or the refusal to read it.
	 *
	 * @param string $clientId The client UUID.
	 *
	 * @return array<string, mixed> The client, or `status` and `message`.
	 */
	private function client(string $clientId): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema   = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($clientId === '' || $register === '' || $schema === '') {
			return ['status' => 400, 'message' => 'Missing client'];
		}

		try {
			$object = $this->container->get(self::OBJECT_SERVICE)->find(
				id: $clientId,
				register: $register,
				schema: $schema,
				_rbac: true,
				_multitenancy: true,
			);
		} catch (NotAuthorizedException $e) {
			return ['status' => 403, 'message' => 'Forbidden'];
		} catch (Throwable $e) {
			$this->logger->warning('ClientBusinessReader: reading the client failed', ['clientId' => $clientId, 'exception' => $e->getMessage()]);
			return ['status' => 404, 'message' => 'Client not found'];
		}

		if ($object === null) {
			return ['status' => 404, 'message' => 'Client not found'];
		}

		return $this->asArray(object: $object);
	}//end client()

	/**
	 * An object as an array.
	 *
	 * @param mixed $object The object.
	 *
	 * @return array<string, mixed> The array.
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return (array)$object;
	}//end asArray()
}//end class
