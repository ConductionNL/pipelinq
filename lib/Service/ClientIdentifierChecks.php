<?php

/**
 * Pipelinq ClientIdentifierChecks.
 *
 * The register checks of a client's numbers, stamped with when they ran: a
 * KvK number against the KvK register, a VAT number against VIES. Each check
 * answers valid, invalid, unavailable or unchecked, the time it ran and the
 * source it asked.
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

use DateTimeImmutable;
use DateTimeZone;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * KvK and VAT checks with their outcome, time and source.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */
class ClientIdentifierChecks {

	/**
	 * Not checked yet, or not checkable here.
	 *
	 * @var string
	 */
	public const STATUS_UNCHECKED = 'unchecked';

	/**
	 * The register knows the number.
	 *
	 * @var string
	 */
	public const STATUS_VALID = 'valid';

	/**
	 * The register does not know the number.
	 *
	 * @var string
	 */
	public const STATUS_INVALID = 'invalid';

	/**
	 * The register could not be reached.
	 *
	 * @var string
	 */
	public const STATUS_UNAVAILABLE = 'unavailable';

	/**
	 * The source a KvK check names.
	 *
	 * @var string
	 */
	public const SOURCE_KVK = 'KvK';

	/**
	 * Constructor.
	 *
	 * @param ClientIdentifierFormat $format The normalisation of a number.
	 * @param KvkCompanyLookup       $kvk    The KvK register.
	 * @param ViesVatLookup          $vies   VIES, through shillinq.
	 * @param ITimeFactory           $time   The clock for checkedAt.
	 */
	public function __construct(
		private readonly ClientIdentifierFormat $format,
		private readonly KvkCompanyLookup $kvk,
		private readonly ViesVatLookup $vies,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Check a KvK number against the KvK register.
	 *
	 * @param string $value The KvK number.
	 *
	 * @return array{checkStatus: string, checkedAt: string, checkSource: string} The outcome.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.2
	 */
	public function checkKvk(string $value): array {
		$number = $this->format->normalise(scheme: 'kvk', value: $value);
		return [
			'checkStatus' => $this->kvk->company(number: $number)['checkStatus'],
			'checkedAt' => $this->now(),
			'checkSource' => self::SOURCE_KVK,
		];
	}//end checkKvk()

	/**
	 * What the KvK register says about a KvK number, as client fields.
	 *
	 * @param string $value The KvK number.
	 *
	 * @return array{checkStatus: string, facts: array<string, mixed>|null} The outcome, and the facts when found.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function kvkFacts(string $value): array {
		$answer = $this->kvk->company(number: $this->format->normalise(scheme: 'kvk', value: $value));
		if ($answer['company'] === null) {
			return ['checkStatus' => $answer['checkStatus'], 'facts' => null];
		}

		return ['checkStatus' => $answer['checkStatus'], 'facts' => $this->kvk->facts(company: $answer['company'])];
	}//end kvkFacts()

	/**
	 * Check a VAT number through shillinq's VIES service; unchecked without shillinq.
	 *
	 * @param string $value The VAT number.
	 *
	 * @return array{checkStatus: string, checkedAt: string, checkSource: string, validUntil?: string} The outcome.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.3
	 */
	public function checkVat(string $value): array {
		$outcome = $this->vies->check(number: $this->format->normalise(scheme: 'vat', value: $value));
		return array_merge(['checkStatus' => $outcome['checkStatus'], 'checkedAt' => $this->now()], $outcome);
	}//end checkVat()

	/**
	 * Check a number of a checkable scheme.
	 *
	 * @param string $scheme The scheme: kvk or vat.
	 * @param string $value  The number.
	 *
	 * @return array{checkStatus: string, checkedAt: string, checkSource: string, validUntil?: string} The outcome.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.4
	 */
	public function checkScheme(string $scheme, string $value): array {
		if ($scheme === 'kvk') {
			return $this->checkKvk(value: $value);
		}

		return $this->checkVat(value: $value);
	}//end checkScheme()

	/**
	 * Now, in UTC, as an ISO-8601 date-time.
	 *
	 * @return string The time.
	 */
	private function now(): string {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'))->format('c');
	}//end now()
}//end class
