<?php

/**
 * Pipelinq KvkCompanyLookup.
 *
 * Asks the KvK register about one KvK number, through OpenRegister's KvK
 * integration leaf, and turns its row into client facts: the place of the
 * visiting address, the legal form code and the SBI descriptions. pipelinq
 * keeps no KvK client of its own for this (ADR-012).
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
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * One KvK number looked up in the KvK register.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */
class KvkCompanyLookup {

	/**
	 * The leaf path, relative to the Nextcloud root.
	 *
	 * @var string
	 */
	private const LEAF_PATH = '/apps/openregister/api/integrations/kvk/company?';

	/**
	 * Constructor.
	 *
	 * @param IClientService  $clientService The HTTP client factory for the KvK leaf.
	 * @param IURLGenerator   $urlGenerator  Resolves the KvK leaf URL.
	 * @param LoggerInterface $logger        The logger.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Look a normalised KvK number up through OpenRegister's KvK leaf.
	 *
	 * Found: valid, with the register's row. Not found (404, or an answer
	 * without the number): invalid. Not reachable (a 5xx, or no answer):
	 * unavailable.
	 *
	 * @param string $number The KvK number, normalised.
	 *
	 * @return array{checkStatus: string, company: array<string, mixed>|null} The outcome and the row.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.2
	 */
	public function company(string $number): array {
		$url = $this->urlGenerator->getAbsoluteURL(self::LEAF_PATH . http_build_query(['kvkNumber' => $number]));
		$answer = (new CompanyRegistryHttp(clientService: $this->clientService, logger: $this->logger))->leafAnswer(url: $url);

		if ($answer['status'] === 404) {
			return ['checkStatus' => ClientIdentifierChecks::STATUS_INVALID, 'company' => null];
		}

		if ($answer['status'] !== 200) {
			return ['checkStatus' => ClientIdentifierChecks::STATUS_UNAVAILABLE, 'company' => null];
		}

		foreach (($answer['rows'] ?? []) as $row) {
			if ((string)($row['kvkNummer'] ?? '') === $number) {
				return ['checkStatus' => ClientIdentifierChecks::STATUS_VALID, 'company' => $row];
			}
		}

		return ['checkStatus' => ClientIdentifierChecks::STATUS_INVALID, 'company' => null];
	}//end company()

	/**
	 * What a KvK row says, as client fields.
	 *
	 * `place` from the visiting address, `legalForm` as the client's legal form
	 * code, `industry` the SBI descriptions with the main activity first. A
	 * fact the register does not give is left out.
	 *
	 * @param array<string, mixed> $company The KvK row.
	 *
	 * @return array<string, mixed> The facts.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function facts(array $company): array {
		$address = (array)($company['adres']['binnenlandsAdres'] ?? ($company['adres'] ?? []));
		$legalForm = mb_strtolower(trim((string)($company['rechtsvorm'] ?? '')));
		$facts = [
			'place' => trim((string)($address['plaats'] ?? '')),
			'legalForm' => (OrganisationEnrichmentService::LEGAL_FORMS[$legalForm] ?? ''),
			'industry' => self::sbiDescriptions(company: $company),
		];

		return array_filter($facts, static fn (mixed $fact): bool => $fact !== '' && $fact !== []);
	}//end facts()

	/**
	 * The SBI descriptions of a KvK row, main activity first, without duplicates.
	 *
	 * @param array<string, mixed> $company The KvK row.
	 *
	 * @return array<int, string> The descriptions.
	 */
	private static function sbiDescriptions(array $company): array {
		$activities = ($company['sbiActiviteiten'] ?? ($company['spiActiviteiten'] ?? []));
		if (is_array($activities) === false) {
			return [];
		}

		$activities = array_values(array_filter($activities, static fn (mixed $activity): bool => is_array($activity) === true));
		usort(
			$activities,
			static fn (array $left, array $right): int => self::isMainActivity(activity: $right) <=> self::isMainActivity(activity: $left)
		);

		$descriptions = [];
		foreach ($activities as $activity) {
			$description = trim((string)($activity['sbiOmschrijving'] ?? ''));
			if ($description !== '' && in_array($description, $descriptions, true) === false) {
				$descriptions[] = $description;
			}
		}

		return $descriptions;
	}//end sbiDescriptions()

	/**
	 * Whether an SBI activity is the main one, as a sortable number.
	 *
	 * @param array<string, mixed> $activity The activity.
	 *
	 * @return int 1 for the main activity, else 0.
	 */
	private static function isMainActivity(array $activity): int {
		return (int)(($activity['indHoofdactiviteit'] ?? '') === 'Ja');
	}//end isMainActivity()
}//end class
