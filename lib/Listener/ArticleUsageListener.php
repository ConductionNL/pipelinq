<?php

/**
 * Pipelinq ArticleUsageListener.
 *
 * Portaliq owns marketing articles and asks, when an article's page opens,
 * where the article is used (marketing-articles-move-to-portaliq, design
 * decision 3). portaliq cannot know pipelinq's schemas, so pipelinq answers
 * for its own objects: every campaign template whose `articleIds` names the
 * article, every blast built on such a template, and every social post whose
 * `articleId` names it. Nothing is written; the answer is derived per read.
 *
 * The event is known by its class name only. pipelinq registers before
 * portaliq's autoloader exists (apps register in alphabetical order), so the
 * registration uses the string and the class check happens here, at dispatch.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
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
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-tells-portaliq-where-an-article-is-used
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adds pipelinq's templates, blasts and social posts to an article's usages.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-tells-portaliq-where-an-article-is-used
 */
class ArticleUsageListener implements IEventListener {

	/**
	 * portaliq's usage question, by name.
	 *
	 * @var string
	 */
	public const EVENT = 'OCA\\Portaliq\\Event\\ArticleUsagesRequestedEvent';

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store  pipelinq's register.
	 * @param IURLGenerator   $urls   Builds the links back into pipelinq.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-tells-portaliq-where-an-article-is-used
	 */
	public function __construct(
		private ListObjectStore $store,
		private IURLGenerator $urls,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer the usage question with pipelinq's own objects.
	 *
	 * @param Event $event portaliq's ArticleUsagesRequestedEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-tells-portaliq-where-an-article-is-used
	 */
	public function handle(Event $event): void {
		if (in_array(self::EVENT, array_merge([$event::class], array_values(class_parents($event))), true) === false
			|| method_exists($event, 'addUsage') === false
			|| method_exists($event, 'getArticleId') === false
		) {
			return;
		}

		$names = $this->namesOf(event: $event);
		if ($names === []) {
			return;
		}

		try {
			foreach ($this->usages(names: $names) as $usage) {
				$event->addUsage($usage['id'], $usage['title'], $usage['kind'], AppIdentity::APP_ID, $usage['url']);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: article usages could not be listed', ['reason' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * Every usage of an article named by any of the given identities.
	 *
	 * @param array<int, string> $names The article's id, slug and old pipelinq id.
	 *
	 * @return array<int, array{id: string, title: string, kind: string, url: string}> The usages.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-tells-portaliq-where-an-article-is-used
	 */
	public function usages(array $names): array {
		$usages      = [];
		$templateIds = [];
		foreach ($this->store->findAll(schemaSlug: $this->slug(configKey: 'campaignTemplate_schema', default: 'campaignTemplate')) as $template) {
			if ($this->namesAny(values: ($template['articleIds'] ?? []), names: $names) === false) {
				continue;
			}

			$id = $this->store->idOf(payload: $template);
			$templateIds = array_merge($templateIds, $this->identitiesOf(object: $template));
			$usages[]    = [
				'id' => $id,
				'title' => $this->titleOf(object: $template, fallback: $id),
				'kind' => 'campaignTemplate',
				'url' => $this->link(path: '/templates'),
			];
		}

		if ($templateIds !== []) {
			foreach ($this->store->findAll(schemaSlug: $this->slug(configKey: 'blast_schema', default: 'blast')) as $blast) {
				if (in_array((string)($blast['templateId'] ?? ''), $templateIds, true) === false) {
					continue;
				}

				$id       = $this->store->idOf(payload: $blast);
				$usages[] = [
					'id' => $id,
					'title' => $this->titleOf(object: $blast, fallback: $id),
					'kind' => 'blast',
					'url' => $this->link(path: '/blasts/'.rawurlencode($id).'/monitor'),
				];
			}
		}

		foreach ($this->store->findAll(schemaSlug: $this->slug(configKey: 'social_post_schema', default: 'socialPost')) as $post) {
			if ($this->namesAny(values: [($post['articleId'] ?? null)], names: $names) === false) {
				continue;
			}

			$id       = $this->store->idOf(payload: $post);
			$usages[] = [
				'id' => $id,
				'title' => $this->titleOf(object: $post, fallback: $id),
				'kind' => 'socialPost',
				'url' => $this->link(path: '/social-posts/'.rawurlencode($id)),
			];
		}

		return $usages;
	}//end usages()

	/**
	 * The identities the article may be named by in pipelinq.
	 *
	 * Besides portaliq's id and slug, a template not yet rewritten by the
	 * move still names the article by its old pipelinq id, which portaliq
	 * carries as `legacyRef = pipelinq:<uuid>`.
	 *
	 * @param object $event The usage question.
	 *
	 * @return array<int, string> Non-empty identities.
	 */
	private function namesOf(object $event): array {
		$names = [(string)$event->getArticleId()];
		if (method_exists($event, 'getSlug') === true) {
			$names[] = (string)$event->getSlug();
		}

		if (method_exists($event, 'getLegacyRef') === true) {
			$legacy = (string)$event->getLegacyRef();
			if (str_starts_with($legacy, 'pipelinq:') === true) {
				$names[] = substr($legacy, strlen('pipelinq:'));
			}
		}

		return array_values(array_unique(array_filter(array_map('trim', $names), static fn (string $name): bool => $name !== '')));
	}//end namesOf()

	/**
	 * Whether a list of references names one of the identities.
	 *
	 * @param mixed              $values The references.
	 * @param array<int, string> $names  The identities.
	 *
	 * @return bool True on a match.
	 */
	private function namesAny(mixed $values, array $names): bool {
		if (is_array($values) === false) {
			return false;
		}

		foreach ($values as $value) {
			if (is_scalar($value) === true && in_array(trim((string)$value), $names, true) === true) {
				return true;
			}
		}

		return false;
	}//end namesAny()

	/**
	 * Every identity an object answers to: uuid, id and slug, at the top level and under `@self`.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<int, string> Non-empty identities.
	 */
	private function identitiesOf(array $object): array {
		$out  = [];
		$self = ($object['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		foreach (['uuid', 'id', 'slug'] as $key) {
			foreach ([$object, $self] as $source) {
				$value = ($source[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					$out[] = (string)$value;
				}
			}
		}

		return array_values(array_unique($out));
	}//end identitiesOf()

	/**
	 * An object's display name.
	 *
	 * @param array<string, mixed> $object   The object.
	 * @param string               $fallback Used when it has no name.
	 *
	 * @return string The name.
	 */
	private function titleOf(array $object, string $fallback): string {
		foreach (['name', 'title', 'subject'] as $key) {
			$value = trim((string)($object[$key] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		$body = trim((string)($object['body'] ?? ''));
		if ($body !== '') {
			return mb_substr($body, 0, 60);
		}

		return $fallback;
	}//end titleOf()

	/**
	 * A link to a pipelinq page.
	 *
	 * @param string $path The page's route.
	 *
	 * @return string The absolute link.
	 */
	private function link(string $path): string {
		return $this->urls->linkToRouteAbsolute(AppIdentity::APP_ID.'.dashboard.page', ['path' => ltrim($path, '/')]);
	}//end link()

	/**
	 * A schema slug, overridable in the app config.
	 *
	 * @param string $configKey The config key.
	 * @param string $default   The default slug.
	 *
	 * @return string The slug.
	 */
	private function slug(string $configKey, string $default): string {
		return $this->store->schemaSlug(configKey: $configKey, default: $default);
	}//end slug()
}//end class
