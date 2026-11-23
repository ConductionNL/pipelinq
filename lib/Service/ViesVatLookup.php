<?php

/**
 * Pipelinq ViesVatLookup.
 *
 * Asks VIES about one EU VAT number through shillinq's VIES service when
 * shillinq is installed. shillinq owns the VIES client; pipelinq resolves its
 * contract lazily and answers "not checked" without it, so pipelinq installs
 * and runs with or without shillinq.
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

use Throwable;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One VAT number checked in VIES, through shillinq.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */
class ViesVatLookup {

	/**
	 * shillinq's VIES contract, resolved lazily.
	 *
	 * @var string
	 */
	public const VIES_CLASS = 'OCA\\Shillinq\\Kernel\\Contracts\\ViesServiceInterface';

	/**
	 * The source a VAT check names.
	 *
	 * @var string
	 */
	public const SOURCE_VIES = 'VIES';

	/**
	 * The source a VAT check names when shillinq is absent.
	 *
	 * @var string
	 */
	public const SOURCE_NO_SHILLINQ = 'shillinq not installed';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container  Resolves shillinq's VIES service lazily.
	 * @param IAppManager        $appManager Whether shillinq is enabled.
	 * @param LoggerInterface    $logger     The logger.
	 * @param string             $viesClass  shillinq's VIES contract; a test names a double.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly string $viesClass = self::VIES_CLASS,
	) {
	}//end __construct()

	/**
	 * Check a normalised VAT number; unchecked without shillinq.
	 *
	 * @param string $number The VAT number, normalised.
	 *
	 * @return array{checkStatus: string, checkSource: string, validUntil?: string} The outcome.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.3
	 */
	public function check(string $number): array {
		$vies = $this->vies();
		if ($vies === null) {
			return ['checkStatus' => ClientIdentifierChecks::STATUS_UNCHECKED, 'checkSource' => self::SOURCE_NO_SHILLINQ];
		}

		try {
			$result = $vies->validate(administrationId: '', vatId: $number);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: the VIES check failed', ['exception' => $e->getMessage()]);
			return ['checkStatus' => ClientIdentifierChecks::STATUS_UNAVAILABLE, 'checkSource' => self::SOURCE_VIES];
		}

		$outcome = ['checkStatus' => self::statusOf(result: (array)$result), 'checkSource' => self::SOURCE_VIES];
		$validUntil = trim((string)($result['validUntil'] ?? ''));
		if ($outcome['checkStatus'] === ClientIdentifierChecks::STATUS_VALID && $validUntil !== '') {
			$outcome['validUntil'] = $validUntil;
		}

		return $outcome;
	}//end check()

	/**
	 * The check status a VIES answer means.
	 *
	 * @param array<string, mixed> $result The answer.
	 *
	 * @return string The status: unavailable on an outage, valid, or invalid.
	 */
	private static function statusOf(array $result): string {
		if (($result['outage'] ?? false) === true) {
			return ClientIdentifierChecks::STATUS_UNAVAILABLE;
		}

		if (($result['valid'] ?? false) === true) {
			return ClientIdentifierChecks::STATUS_VALID;
		}

		return ClientIdentifierChecks::STATUS_INVALID;
	}//end statusOf()

	/**
	 * shillinq's VIES service, or null when shillinq is not installed.
	 *
	 * @return object|null The service.
	 */
	private function vies(): ?object {
		if ($this->appManager->isEnabledForAnyone('shillinq') === false) {
			return null;
		}

		if (interface_exists($this->viesClass) === false && class_exists($this->viesClass) === false) {
			return null;
		}

		try {
			$service = $this->container->get($this->viesClass);
		} catch (Throwable $e) {
			$this->logger->debug('Pipelinq: shillinq VIES service not resolvable', ['exception' => $e->getMessage()]);
			return null;
		}

		if (is_object($service) === false || method_exists($service, 'validate') === false) {
			return null;
		}

		return $service;
	}//end vies()
}//end class
