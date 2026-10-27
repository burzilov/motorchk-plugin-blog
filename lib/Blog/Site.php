<?php

namespace Blog;

use PluginContext;
use PluginDataException;
use PluginRequest;

/**
 * Публичная часть: /blog/, /blog/{slug}, /blog/category/{slug}, /blog/tag/{slug} (?page=N).
 * Страницы выводятся внутри шаблона активной темы (renderPublic → $blocks['content']).
 */
final class Site
{
    public function __construct(private PluginContext $plugin, private Repository $repo, private Text $text)
    {
    }

    public function index(PluginRequest $req): void
    {
        $this->listing($req, null, null);
    }

    public function category(PluginRequest $req): void
    {
        $slug = (string) $req->param('slug');
        $term = $this->safe(fn() => $this->repo->term('categories', $slug));
        if ($term === null) {
            $this->notFound();
            return;
        }
        $this->listing($req, 'categories', $term);
    }

    public function tag(PluginRequest $req): void
    {
        $slug = (string) $req->param('slug');
        $term = $this->safe(fn() => $this->repo->term('tags', $slug));
        if ($term === null) {
            $this->notFound();
            return;
        }
        $this->listing($req, 'tags', $term);
    }

    public function post(PluginRequest $req): void
    {
        $slug = (string) $req->param('slug');
        $post = $this->safe(fn() => $this->repo->post($slug));
        if ($post === null || (!$post['published'] && !$this->plugin->isAdmin())) {
            $this->notFound();
            return;
        }
        $locale = $this->plugin->locale();
        $view = $this->view($post, $locale);
        $view['body_html'] = Markdown::toHtml((string) ($this->plugin->localized($post['body'], $locale) ?? ''));
        $settings = Settings::load($this->plugin);
        $this->plugin->renderPublic('post', [
            't' => fn(string $key, array $p = []): string => $this->text->get($key, $locale, $p),
            'post' => $view,
            'listUrl' => $this->plugin->publicUrl('/'),
        ], $this->options($settings, $view['title'], $view['excerpt'], $this->alternates($post, '/' . $post['slug']), [
            'type' => 'post', 'post' => $view,
        ]));
    }

    /** @param array<string, mixed>|null $term */
    private function listing(PluginRequest $req, ?string $kind, ?array $term): void
    {
        $locale = $this->plugin->locale();
        $settings = Settings::load($this->plugin);
        $pageParam = $req->query('page', '1');
        if (!is_string($pageParam) || !preg_match('/^[1-9][0-9]{0,5}$/', $pageParam)) {
            $this->notFound();
            return;
        }
        $page = (int) $pageParam;
        $posts = $this->safe(fn() => $this->repo->posts(true, $kind === 'categories' ? $term['slug'] : null, $kind === 'tags' ? $term['slug'] : null)) ?? [];
        $pages = max(1, (int) ceil(count($posts) / $settings['per_page']));
        if ($page > $pages) {
            $this->notFound();
            return;
        }
        $base = match ($kind) {
            'categories' => '/category/' . $term['slug'],
            'tags' => '/tag/' . $term['slug'],
            default => '/',
        };
        $pageUrl = fn(int $n): string => $this->plugin->publicUrl($base) . ($n > 1 ? '?page=' . $n : '');
        $items = array_map(fn(array $p): array => $this->view($p, $locale), array_slice($posts, ($page - 1) * $settings['per_page'], $settings['per_page']));

        $termName = $term !== null ? (string) ($this->plugin->localized($term['name'], $locale) ?? $term['slug']) : '';
        $heading = match ($kind) {
            'categories' => $this->text->get('category_title', $locale, ['name' => $termName]),
            'tags' => $this->text->get('tag_title', $locale, ['name' => $termName]),
            default => $this->text->get('title', $locale),
        };
        $title = $page > 1 ? $heading . ' — ' . $this->text->get('page_of', $locale, ['page' => $page, 'pages' => $pages]) : $heading;
        $this->plugin->renderPublic('list', [
            't' => fn(string $key, array $p = []): string => $this->text->get($key, $locale, $p),
            'heading' => $heading,
            'isFiltered' => $kind !== null,
            'posts' => $items,
            'page' => $page,
            'pages' => $pages,
            'newerUrl' => $page > 1 ? $pageUrl($page - 1) : null,
            'olderUrl' => $page < $pages ? $pageUrl($page + 1) : null,
            'listUrl' => $this->plugin->publicUrl('/'),
        ], $this->options($settings, $title, '', true, [
            'type' => $kind === null ? 'list' : ($kind === 'categories' ? 'category' : 'tag'),
            'term' => $term !== null ? ['slug' => $term['slug'], 'name' => $termName] : null,
            'page' => $page,
            'pages' => $pages,
        ]));
    }

    /**
     * Запись для шаблонов: строки уже на текущем языке (нет перевода — основной язык).
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function view(array $post, string $locale): array
    {
        $categories = $this->safe(fn() => $this->repo->all('categories')) ?? [];
        $tags = $this->safe(fn() => $this->repo->all('tags')) ?? [];
        $category = $post['category'] !== null && isset($categories[$post['category']]) ? [
            'slug' => $post['category'],
            'name' => (string) ($this->plugin->localized($categories[$post['category']]['name'], $locale) ?? $post['category']),
            'url' => $this->plugin->publicUrl('/category/' . $post['category']),
        ] : null;
        $tagViews = [];
        foreach ($post['tags'] as $tag) {
            if (isset($tags[$tag])) {
                $tagViews[] = [
                    'slug' => $tag,
                    'name' => (string) ($this->plugin->localized($tags[$tag]['name'], $locale) ?? $tag),
                    'url' => $this->plugin->publicUrl('/tag/' . $tag),
                ];
            }
        }

        return [
            'slug' => $post['slug'],
            'url' => $this->plugin->publicUrl('/' . $post['slug']),
            'title' => (string) ($this->plugin->localized($post['title'], $locale) ?? $post['slug']),
            'excerpt' => (string) ($this->plugin->localized($post['excerpt'], $locale) ?? ''),
            'date' => $post['date'],
            'date_label' => self::formatDate($post['date'], $locale),
            'published' => $post['published'],
            'category' => $category,
            'tags' => $tagViews,
        ];
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function options(array $settings, string $title, string $description, bool|array $alternates, array $vars): array
    {
        $options = [
            'title' => $title,
            'description' => $description,
            'template' => $settings['template'],
            'alternates' => $alternates,
            'vars' => ['blog' => $vars],
            'styles' => $settings['styles'] ? [$this->plugin->assetUrl('blog.css')] : [],
        ];
        if ($settings['menu_location'] !== '') {
            $options['menu_location'] = $settings['menu_location'];
        }

        return $options;
    }

    /**
     * hreflang записи — только языки, на которые она переведена (есть заголовок).
     *
     * @param array<string, mixed> $post
     * @return array<string, string>|false
     */
    private function alternates(array $post, string $path): array|false
    {
        $out = [];
        foreach ($this->plugin->locales() as $locale) {
            if (($post['title'][$locale] ?? '') !== '') {
                $out[$locale] = $this->plugin->publicUrl($path, $locale);
            }
        }

        return count($out) > 1 ? $out : false;
    }

    private function notFound(): void
    {
        $locale = $this->plugin->locale();
        $settings = Settings::load($this->plugin);
        $options = $this->options($settings, $this->text->get('not_found', $locale), '', false, ['type' => 'not_found']);
        $options['status'] = 404;
        $this->plugin->renderPublic('not-found', [
            't' => fn(string $key, array $p = []): string => $this->text->get($key, $locale, $p),
            'listUrl' => $this->plugin->publicUrl('/'),
        ], $options);
    }

    /** Повреждённый файл данных: сайт работает (пустой блог), ошибку видно в админке и в журнале. */
    private function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (PluginDataException $e) {
            static $logged = [];
            if (!isset($logged[$e->getMessage()])) {
                $logged[$e->getMessage()] = true;
                error_log('[blog] ' . $e->getMessage());
            }

            return null;
        }
    }

    public static function formatDate(string $date, string $locale): string
    {
        $ts = strtotime($date . ' 00:00:00 UTC');
        if ($ts === false) {
            return $date;
        }

        return $locale === 'ru' ? gmdate('d.m.Y', $ts) : gmdate('Y-m-d', $ts);
    }
}
