<?php

namespace Blog;

use PluginContext;
use PluginDataException;
use PluginRequest;

/**
 * Админка: /admin-panel/plugins/blog/… — записи, рубрики, теги (создание, правка, удаление).
 * Формы — обычный POST с CSRF (проверяет ядро); успех → 302, ошибки → 422 с заполненной формой.
 */
final class Admin
{
    private const TERM_PATHS = ['categories' => '/categories', 'tags' => '/tags'];

    public function __construct(private PluginContext $plugin, private Repository $repo, private Text $text)
    {
    }

    // ---------- записи ----------

    public function posts(PluginRequest $req): void
    {
        $error = $this->repo->fileError();
        $this->page('posts', [
            'posts' => $error === null ? $this->repo->posts() : [],
            'categories' => $this->safeAll('categories'),
            'notice' => $this->notice($req),
            'fileError' => $error,
        ]);
    }

    public function newPost(PluginRequest $req): void
    {
        $this->editor(null, ['slug' => '', 'title' => [], 'excerpt' => [], 'body' => [], 'category' => null, 'tags' => [], 'date' => date('Y-m-d'), 'published' => false], [], $this->notice($req));
    }

    public function editPost(PluginRequest $req): void
    {
        $slug = (string) $req->param('slug');
        $post = $this->safe(fn() => $this->repo->post($slug));
        if ($post === null) {
            $this->notFound();
            return;
        }
        $this->editor($slug, $post, [], $this->notice($req));
    }

    public function savePost(PluginRequest $req): void
    {
        $oldSlug = $req->param('slug');
        if (($error = $this->blocked()) !== null) {
            $this->editor($oldSlug, $this->formPost($req), [$error], null, 409);
            return;
        }
        if ($oldSlug !== null && $this->repo->post($oldSlug) === null) {
            $this->notFound();
            return;
        }
        [$errors, $post] = $this->repo->savePost($oldSlug, $req->allInput());
        if ($errors !== []) {
            $this->editor($oldSlug, $post, $errors, null, 422);
            return;
        }
        $this->plugin->redirect($this->plugin->adminUrl('/posts/' . $post['slug']) . '?saved=1');
    }

    public function deletePost(PluginRequest $req): void
    {
        $slug = (string) $req->param('slug');
        if (($error = $this->blocked() ?? $this->repo->deletePost($slug)) !== null) {
            $this->page('posts', ['posts' => [], 'categories' => [], 'notice' => null, 'fileError' => $error], 409);
            return;
        }
        $this->plugin->redirect($this->plugin->adminUrl('/') . '?deleted=1');
    }

    // ---------- рубрики и теги ----------

    public function terms(string $kind, PluginRequest $req, ?array $form = null, array $errors = [], int $status = 200): void
    {
        $error = $this->repo->fileError();
        $this->page($kind, [
            'kind' => $kind,
            'terms' => $error === null ? $this->repo->all($kind) : [],
            'counts' => $error === null ? $this->repo->counts($kind) : [],
            'form' => $form ?? ['slug' => '', 'name' => []],
            'editing' => null,
            'errors' => $errors,
            'notice' => $this->notice($req),
            'fileError' => $error,
        ], $status);
    }

    public function editTerm(string $kind, PluginRequest $req, ?array $form = null, array $errors = [], int $status = 200): void
    {
        $slug = (string) $req->param('slug');
        $term = $this->safe(fn() => $this->repo->term($kind, $slug));
        if ($term === null) {
            $this->notFound();
            return;
        }
        $this->page($kind, [
            'kind' => $kind,
            'terms' => [],
            'counts' => $this->safe(fn() => $this->repo->counts($kind)) ?? [],
            'form' => $form ?? $term,
            'editing' => $slug,
            'errors' => $errors,
            'notice' => $this->notice($req),
            'fileError' => $this->repo->fileError(),
        ], $status);
    }

    public function saveTerm(string $kind, PluginRequest $req): void
    {
        $oldSlug = $req->param('slug');
        if (($error = $this->blocked()) !== null) {
            $this->terms($kind, $req, null, [$error], 409);
            return;
        }
        if ($oldSlug !== null && $this->repo->term($kind, $oldSlug) === null) {
            $this->notFound();
            return;
        }
        $show = fn(array $f, array $errors, int $status) => $oldSlug !== null ? $this->editTerm($kind, $req, $f, $errors, $status) : $this->terms($kind, $req, $f, $errors, $status);
        [$errors, $term] = $this->repo->saveTerm($kind, $oldSlug, $req->allInput());
        if ($errors !== []) {
            $show($term, $errors, 422);
            return;
        }
        $this->plugin->redirect($this->plugin->adminUrl(self::TERM_PATHS[$kind]) . '?saved=1');
    }

    public function deleteTerm(string $kind, PluginRequest $req): void
    {
        $slug = (string) $req->param('slug');
        if (($error = $this->blocked() ?? $this->repo->deleteTerm($kind, $slug)) !== null) {
            $this->terms($kind, $req, null, [$error], 409);
            return;
        }
        $this->plugin->redirect($this->plugin->adminUrl(self::TERM_PATHS[$kind]) . '?deleted=1');
    }

    // ---------- служебное ----------

    /** @param array<string, mixed> $post */
    private function editor(?string $slug, array $post, array $errors, ?string $notice, int $status = 200): void
    {
        $this->page('post', [
            'editing' => $slug,
            'post' => $post,
            'categories' => $this->safeAll('categories'),
            'tags' => $this->safeAll('tags'),
            'errors' => $errors,
            'notice' => $notice,
            'fileError' => $this->repo->fileError(),
        ], $status);
    }

    /** @return array<string, mixed> поля формы записи из запроса (для повторного показа) */
    private function formPost(PluginRequest $req): array
    {
        $map = static fn(mixed $v): array => array_filter(is_array($v) ? $v : [], 'is_string');

        return [
            'slug' => (string) $req->input('slug', ''),
            'title' => $map($req->input('title')),
            'excerpt' => $map($req->input('excerpt')),
            'body' => $map($req->input('body')),
            'category' => is_string($req->input('category')) ? $req->input('category') : null,
            'tags' => array_filter((array) $req->input('tags', []), 'is_string'),
            'date' => (string) $req->input('date', ''),
            'published' => (bool) $req->input('published', false),
        ];
    }

    private function page(string $section, array $data, int $status = 200): void
    {
        $titles = ['posts' => 'a.posts', 'post' => 'a.posts', 'categories' => 'a.categories', 'tags' => 'a.tags'];
        $locales = $this->plugin->locales();
        $this->plugin->render('admin', $data + [
            'section' => $section,
            'text' => $this->text,
            'locales' => $locales,
            'localeNames' => $this->localeNames($locales),
            'errors' => [],
            'notice' => null,
            'fileError' => null,
        ], [
            'title' => $this->text->admin($titles[$section]) . ' — ' . $this->text->get('title'),
            'styles' => [$this->plugin->assetUrl('admin.css')],
            'status' => $status,
        ]);
    }

    /**
     * @param list<string> $locales
     * @return array<string, string>
     */
    private function localeNames(array $locales): array
    {
        $names = [];
        $svc = $this->plugin->service('i18n');
        if ($svc !== null && method_exists($svc, 'names')) {
            $names = (array) $svc->names();
        }
        $out = [];
        foreach ($locales as $locale) {
            $out[$locale] = is_string($names[$locale] ?? null) && $names[$locale] !== '' ? $names[$locale] : $locale;
        }

        return $out;
    }

    private function notice(PluginRequest $req): ?string
    {
        return match (true) {
            $req->query('saved') !== null => $this->text->admin('a.saved'),
            $req->query('deleted') !== null => $this->text->admin('a.deleted'),
            default => null,
        };
    }

    /** Сообщение, если какой-то файл данных повреждён (запись запрещена). */
    private function blocked(): ?string
    {
        foreach (Repository::KINDS as $kind) {
            try {
                $this->repo->all($kind);
            } catch (PluginDataException $e) {
                return $this->text->admin('e.file', ['file' => $e->relativePath()]);
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    private function safeAll(string $kind): array
    {
        return $this->safe(fn() => $this->repo->all($kind)) ?? [];
    }

    private function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (PluginDataException) {
            return null;
        }
    }

    private function notFound(): void
    {
        $this->plugin->render('admin', [
            'section' => 'not_found',
            'text' => $this->text,
            'locales' => [],
            'localeNames' => [],
            'errors' => [],
            'notice' => null,
            'fileError' => null,
        ], ['title' => $this->text->admin('a.not_found'), 'styles' => [$this->plugin->assetUrl('admin.css')], 'status' => 404]);
    }
}
