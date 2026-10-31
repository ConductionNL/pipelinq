<?php

/**
 * Pipelinq StoreInstallLedger.
 *
 * What this instance installed from the store, and when the registry last
 * answered. The store page needs both: a card says Installed or Update, and
 * the Updates card says when the registry was last checked. The registry
 * itself cannot know either, so the ledger lives in pipelinq's app config:
 * `store_installed` holds `{slug: {version, installedAt}}` as JSON and
 * `store_checked_at` an ISO date-time.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Store
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/pipelinq-store-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Store;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * The installed store items and the last registry check.
 */
class StoreInstallLedger {

	/**
	 * App config key of the installed items.
	 */
	private const INSTALLED_KEY = 'store_installed';

	/**
	 * App config key of the last registry answer.
	 */
	private const CHECKED_KEY = 'store_checked_at';

	/**
	 * Where an installed item of a kind is used, as a path in the app.
	 *
	 * @var array<string, string>
	 */
	private const OPEN_PATHS = [
		'pipeline'          => 'pipelines',
		'queue-routing'     => 'queue',
		'catalogue'         => 'products',
		'pos-setup'         => 'pos',
		'loyalty-programme' => 'loyalty/reporting',
		'connector'         => 'integrations',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig    Pipelinq's app config, which holds the ledger.
	 * @param IURLGenerator $urlGenerator Builds the Open link of an installed item.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Record that an item was installed at a version, replacing an earlier record.
	 *
	 * @param string $slug    The store item slug.
	 * @param string $version The version that was installed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pipelinq-store-surface/spec.md
	 */
	public function record(string $slug, string $version): void {
		$installed        = $this->installed();
		$installed[$slug] = [
			'version'     => $version,
			'installedAt' => (new DateTimeImmutable())->format(format: DateTimeInterface::ATOM),
		];
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::INSTALLED_KEY,
			(string) json_encode(value: $installed)
		);
	}//end record()

	/**
	 * Add `installed`, `installedVersion`, `updateAvailable` and, for an
	 * installed item, `openUrl` to each card.
	 *
	 * @param array<int, array<string, mixed>> $cards The registry's cards.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/pipelinq-store-surface/spec.md
	 */
	public function annotate(array $cards): array {
		$installed = $this->installed();
		$annotated = [];
		foreach ($cards as $card) {
			$slug   = (string) ($card['slug'] ?? '');
			$record = ($installed[$slug] ?? null);

			$card['installed']       = is_array($record);
			$card['updateAvailable'] = false;
			if (is_array($record) === true) {
				$card['installedVersion'] = (string) ($record['version'] ?? '');
				$card['updateAvailable']  = $this->isNewer(latest: (string) ($card['version'] ?? ''), current: $card['installedVersion']);
				$card['openUrl']          = $this->openUrl(kind: (string) ($card['kind'] ?? ''));
			}

			$annotated[] = $card;
		}

		return $annotated;
	}//end annotate()

	/**
	 * Note that the registry answered.
	 *
	 * @param DateTimeInterface $when When it answered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pipelinq-store-surface/spec.md
	 */
	public function markChecked(DateTimeInterface $when): void {
		$this->appConfig->setValueString(Application::APP_ID, self::CHECKED_KEY, $when->format(format: DateTimeInterface::ATOM));
	}//end markChecked()

	/**
	 * When the registry last answered, as an ISO date-time, or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/pipelinq-store-surface/spec.md
	 */
	public function checkedAt(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::CHECKED_KEY, '');
	}//end checkedAt()

	/**
	 * The installed items; an unreadable ledger reads as none.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function installed(): array {
		$decoded = json_decode(
			json: $this->appConfig->getValueString(Application::APP_ID, self::INSTALLED_KEY, '{}'),
			associative: true
		);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end installed()

	/**
	 * Whether the registry's version is newer than the installed one.
	 *
	 * @param string $latest  The registry's version.
	 * @param string $current The installed version.
	 *
	 * @return bool
	 */
	private function isNewer(string $latest, string $current): bool {
		if ($latest === '' || $current === '') {
			return false;
		}

		return version_compare(version1: $latest, version2: $current, operator: '>');
	}//end isNewer()

	/**
	 * Where an installed item of this kind is used.
	 *
	 * @param string $kind The item kind.
	 *
	 * @return string
	 */
	private function openUrl(string $kind): string {
		return $this->urlGenerator->linkToRoute(
			'pipelinq.dashboard.page',
			['path' => (self::OPEN_PATHS[$kind] ?? '')]
		);
	}//end openUrl()
}//end class
