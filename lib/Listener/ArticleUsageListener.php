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
		if ($this->isUsageQuestion(event: $event) === false) {
			return;
		}

		$names = $this->namesOf(event: $event);
		if ($names === []) {
			return;
		}

		$this->answer(event: $event, names: $names);
	}//end handle()

	/**
	 * Add every usage to the question; a failure is logged, never thrown into portaliq.
	 *
	 * @param object             $event The usage question.
	 * @param array<int, string> $names The article's identities.
	 *
	 * @return void
	 */
	private function answer(object $event, array $names): void {
		try {
			foreach ($this->usages(names: $names) as $usage) {
				$event->addUsage($usage['id'], $usage['title'], $usage['kind'], AppIdentity::APP_ID, $usage['url']);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: article usages could not be listed', ['reason' => $e->getMessage()]);
		}
	}//end answer()

	/**
	 * Whether the event is portaliq's usage question, with the methods it is answered through.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return bool True when the event can be answered.
	 */
	private function isUsageQuestion(Event $event): bool {
		return in_array(self::EVENT, array_merge([$event::class], array_values(class_parents($event))), true) === true
			&& method_exists($event, 'addUsage') === true
			&& method_exists($event, 'getArticleId') === true;
	}//end isUsageQuestion()

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
		$templates = array_values(
			array_filter(
				$this->store->findAll(schemaSlug: $this->slug(configKey: 'campaignTemplate_schema', default: 'campaignTemplate')),
				fn (array $template): bool => $this->namesAny(values: ($template['articleIds'] ?? []), names: $names)
			)
		);

		$usages = array_map(fn (array $template): array => $this->usage(object: $template, kind: 'campaignTemplate', route: '/templates'), $templates);
		$usages = array_merge($usages, $this->blastUsages(templates: $templates));
		foreach ($this->store->findAll(schemaSlug: $this->slug(configKey: 'social_post_schema', default: 'socialPost')) as $post) {
			if ($this->namesAny(values: [($post['articleId'] ?? null)], names: $names) === true) {
				$usages[] = $this->usage(object: $post, kind: 'socialPost', route: '/social-posts/{id}');
			}
		}

		return $usages;
	}//end usages()

	/**
	 * The usages of every blast built on one of the given templates.
	 *
	 * @param array<int, array<string, mixed>> $templates The templates that name the article.
	 *
	 * @return array<int, array{id: string, title: string, kind: string, url: string}> The usages.
	 */
	private function blastUsages(array $templates): array {
		$templateIds = [];
		foreach ($templates as $template) {
			$templateIds = array_merge($templateIds, $this->identitiesOf(object: $template));
		}

		if ($templateIds === []) {
			return [];
		}

		$usages = [];
		foreach ($this->store->findAll(schemaSlug: $this->slug(configKey: 'blast_schema', default: 'blast')) as $blast) {
			if (in_array((string)($blast['templateId'] ?? ''), $templateIds, true) === true) {
				$usages[] = $this->usage(object: $blast, kind: 'blast', route: '/blasts/{id}/monitor');
			}
		}

		return $usages;
	}//end blastUsages()

	/**
	 * One usage row for a pipelinq object.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string               $kind   Its kind.
	 * @param string               $route  Its page's route, `{id}` standing for its encoded id.
	 *
	 * @return array{id: string, title: string, kind: string, url: string} The usage.
	 */
	private function usage(array $object, string $kind, string $route): array {
		$id = $this->store->idOf(payload: $object);
		return [
			'id' => $id,
			'title' => $this->titleOf(object: $object, fallback: $id),
			'kind' => $kind,
			'url' => $this->link(path: str_replace('{id}', rawurlencode($id), $route)),
		];
	}//end usage()

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
		$sources = [$object, $this->selfOf(object: $object)];
		$out     = [];
		foreach (['uuid', 'id', 'slug'] as $key) {
			foreach ($sources as $source) {
				$value = ($source[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					$out[] = (string)$value;
				}
			}
		}

		return array_values(array_unique($out));
	}//end identitiesOf()

	/**
	 * An object's `@self` block, or an empty array when it has none.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed> The block.
	 */
	private function selfOf(array $object): array {
		$self = ($object['@self'] ?? []);
		if (is_array($self) === false) {
			return [];
		}

		return $self;
	}//end selfOf()

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
