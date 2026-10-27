<?php

namespace Blog;

use PluginContext;
use PluginDataException;

/**
 * Данные блога в plugin-data/blog/ (только через API PluginContext):
 *   posts.json      — { "<slug>": запись }
 *   categories.json — { "<slug>": { "name": { "<язык>": "…" } } }
 *   tags.json       — то же для тегов
 * Повреждённый файл не перезаписывается: чтение бросает PluginDataException, запись блокируется.
 */
final class Repository
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    public const SLUG_MAX = 80;
    public const RESERVED_POST_SLUGS = ['category', 'tag', 'page', 'new'];
    public const RESERVED_TERM_SLUGS = ['new'];
    public const TITLE_MAX = 200;
    public const EXCERPT_MAX = 1000;
    public const BODY_MAX = 200000;
    public const NAME_MAX = 100;
    public const KINDS = ['posts', 'categories', 'tags'];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $cache = [];

    public function __construct(private PluginContext $plugin, private Text $text)
    {
    }

    public static function validSlug(string $slug): bool
    {
        return strlen($slug) <= self::SLUG_MAX && preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    // ---------- чтение ----------

    /**
     * @return array<string, array<string, mixed>>
     * @throws PluginDataException
     */
    public function all(string $kind): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Неизвестный тип данных: ' . $kind);
        }
        if (isset($this->cache[$kind])) {
            return $this->cache[$kind];
        }
        $raw = $this->plugin->readJsonStrict($kind . '.json', []) ?? [];
        $out = [];
        foreach ($raw as $slug => $item) {
            if (!is_string($slug) || !self::validSlug($slug) || !is_array($item)) {
                continue;
            }
            $out[$slug] = $kind === 'posts' ? self::normalizePost($slug, $item) : self::normalizeTerm($slug, $item);
        }

        return $this->cache[$kind] = $out;
    }

    /** Ошибка чтения файла (для предупреждения в админке) или null. */
    public function fileError(): ?string
    {
        foreach (self::KINDS as $kind) {
            try {
                $this->all($kind);
            } catch (PluginDataException $e) {
                return $e->getMessage();
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function post(string $slug): ?array
    {
        return self::validSlug($slug) ? ($this->all('posts')[$slug] ?? null) : null;
    }

    /** @return array<string, mixed>|null */
    public function term(string $kind, string $slug): ?array
    {
        return self::validSlug($slug) ? ($this->all($kind)[$slug] ?? null) : null;
    }

    /**
     * Записи по убыванию даты (затем по адресу).
     *
     * @return list<array<string, mixed>>
     */
    public function posts(bool $onlyPublished = false, ?string $category = null, ?string $tag = null): array
    {
        $posts = array_values(array_filter(
            $this->all('posts'),
            static fn(array $p): bool => (!$onlyPublished || $p['published'])
                && ($category === null || $p['category'] === $category)
                && ($tag === null || in_array($tag, $p['tags'], true)),
        ));
        usort($posts, static fn(array $a, array $b): int => [$b['date'], $a['slug']] <=> [$a['date'], $b['slug']]);

        return $posts;
    }

    /** @return array<string, int> slug → число записей */
    public function counts(string $kind): array
    {
        $out = [];
        foreach ($this->all('posts') as $post) {
            $slugs = $kind === 'categories' ? ($post['category'] !== null ? [$post['category']] : []) : $post['tags'];
            foreach ($slugs as $slug) {
                $out[$slug] = ($out[$slug] ?? 0) + 1;
            }
        }

        return $out;
    }

    // ---------- запись ----------

    /**
     * Создать ($oldSlug = null) или обновить запись. Возвращает [ошибки, запись].
     *
     * @param array<string, mixed> $input поля формы
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    public function savePost(?string $oldSlug, array $input): array
    {
        $errors = [];
        $locales = $this->plugin->locales();
        $default = $locales[0];
        $posts = $this->all('posts');
        $existing = $oldSlug !== null ? ($posts[$oldSlug] ?? null) : null;

        $post = [
            'title' => $this->localizedInput($input['title'] ?? null, $existing['title'] ?? [], self::TITLE_MAX, 'a.field_title', $errors, true),
            'excerpt' => $this->localizedInput($input['excerpt'] ?? null, $existing['excerpt'] ?? [], self::EXCERPT_MAX, 'a.field_excerpt', $errors, false, true),
            'body' => $this->localizedInput($input['body'] ?? null, $existing['body'] ?? [], self::BODY_MAX, 'a.field_body', $errors, false, true),
        ];
        if (($post['title'][$default] ?? '') === '') {
            $errors[] = $this->text->admin('e.title_required', ['locale' => $default]);
        }

        $date = trim(self::str($input['date'] ?? ''));
        if ($date === '') {
            $date = date('Y-m-d');
        }
        if (!self::validDate($date)) {
            $errors[] = $this->text->admin('e.date');
        }

        $category = trim(self::str($input['category'] ?? ''));
        if ($category !== '' && !isset($this->all('categories')[$category])) {
            $errors[] = $this->text->admin('e.category');
        }

        $tags = [];
        $knownTags = $this->all('tags');
        foreach ((array) ($input['tags'] ?? []) as $tag) {
            $tag = self::str($tag);
            if (!isset($knownTags[$tag])) {
                $errors[] = $this->text->admin('e.tag');
                break;
            }
            $tags[$tag] = true;
        }

        $slug = trim(self::str($input['slug'] ?? ''));
        if ($slug === '' && ($post['title'][$default] ?? '') !== '') {
            $slug = $this->uniqueSlug(self::slugify($post['title'][$default]), $posts, $oldSlug, self::RESERVED_POST_SLUGS);
        }
        $this->checkSlug($slug, $posts, $oldSlug, self::RESERVED_POST_SLUGS, $errors);

        $post = ['slug' => $slug] + $post + [
            'category' => $category !== '' ? $category : null,
            'tags' => array_keys($tags),
            'date' => $date,
            'published' => !empty($input['published']),
            'created' => $existing['created'] ?? date('c'),
            'updated' => date('c'),
        ];
        if ($errors !== []) {
            return [array_values(array_unique($errors)), $post];
        }

        if ($oldSlug !== null) {
            unset($posts[$oldSlug]);
        }
        $posts[$slug] = $post;
        $error = $this->write('posts', $posts);

        return [$error !== null ? [$error] : [], $post];
    }

    public function deletePost(string $slug): ?string
    {
        $posts = $this->all('posts');
        if (!isset($posts[$slug])) {
            return null;
        }
        unset($posts[$slug]);

        return $this->write('posts', $posts);
    }

    /**
     * Создать или обновить рубрику/тег. Смена адреса переносится в записи.
     *
     * @param array<string, mixed> $input
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    public function saveTerm(string $kind, ?string $oldSlug, array $input): array
    {
        $errors = [];
        $default = $this->plugin->locales()[0];
        $terms = $this->all($kind);
        $posts = $this->all('posts');
        $existing = $oldSlug !== null ? ($terms[$oldSlug] ?? null) : null;

        $name = $this->localizedInput($input['name'] ?? null, $existing['name'] ?? [], self::NAME_MAX, 'a.field_name', $errors, true);
        if (($name[$default] ?? '') === '') {
            $errors[] = $this->text->admin('e.name_required', ['locale' => $default]);
        }
        $slug = trim(self::str($input['slug'] ?? ''));
        if ($slug === '' && ($name[$default] ?? '') !== '') {
            $slug = $this->uniqueSlug(self::slugify($name[$default]), $terms, $oldSlug, self::RESERVED_TERM_SLUGS);
        }
        $this->checkSlug($slug, $terms, $oldSlug, self::RESERVED_TERM_SLUGS, $errors);
        $term = ['slug' => $slug, 'name' => $name];
        if ($errors !== []) {
            return [array_values(array_unique($errors)), $term];
        }

        if ($oldSlug !== null) {
            unset($terms[$oldSlug]);
        }
        $terms[$slug] = $term;
        if ($oldSlug !== null && $oldSlug !== $slug) {
            $posts = $this->replaceTerm($kind, $posts, $oldSlug, $slug);
        }
        $error = $this->write($kind, $terms) ?? ($oldSlug !== null && $oldSlug !== $slug ? $this->write('posts', $posts) : null);

        return [$error !== null ? [$error] : [], $term];
    }

    /** Удалить рубрику/тег; из записей ссылка убирается. */
    public function deleteTerm(string $kind, string $slug): ?string
    {
        $terms = $this->all($kind);
        if (!isset($terms[$slug])) {
            return null;
        }
        $posts = $this->all('posts');
        unset($terms[$slug]);

        return $this->write('posts', $this->replaceTerm($kind, $posts, $slug, null)) ?? $this->write($kind, $terms);
    }

    // ---------- служебное ----------

    /**
     * @param array<string, array<string, mixed>> $posts
     * @return array<string, array<string, mixed>>
     */
    private function replaceTerm(string $kind, array $posts, string $from, ?string $to): array
    {
        foreach ($posts as &$post) {
            if ($kind === 'categories') {
                if ($post['category'] === $from) {
                    $post['category'] = $to;
                }
                continue;
            }
            if (in_array($from, $post['tags'], true)) {
                $tags = array_values(array_diff($post['tags'], [$from]));
                if ($to !== null && !in_array($to, $tags, true)) {
                    $tags[] = $to;
                }
                $post['tags'] = $tags;
            }
        }
        unset($post);

        return $posts;
    }

    /** @param array<string, array<string, mixed>> $data */
    private function write(string $kind, array $data): ?string
    {
        try {
            $this->plugin->writeJson($kind . '.json', $data === [] ? new \stdClass() : $data);
        } catch (\Throwable) {
            return $this->text->admin('e.write', ['file' => $kind . '.json']);
        }
        $this->cache[$kind] = $data;

        return null;
    }

    /**
     * Значения поля по языкам: присланные языки сайта поверх сохранённых (языки, убранные
     * из i18n, не теряются). Пустая строка удаляет перевод.
     *
     * @param array<string, string> $existing
     * @param list<string> $errors
     * @return array<string, string>
     */
    private function localizedInput(mixed $input, array $existing, int $max, string $label, array &$errors, bool $singleLine, bool $keepNewlines = false): array
    {
        $out = $existing;
        $input = is_array($input) ? $input : [];
        foreach ($this->plugin->locales() as $locale) {
            if (!array_key_exists($locale, $input)) {
                continue;
            }
            $value = self::str($input[$locale]);
            $value = str_replace(["\r\n", "\r"], "\n", $value);
            $value = $singleLine ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : ($keepNewlines ? rtrim($value) : trim($value));
            if (mb_strlen($value, 'UTF-8') > $max) {
                $errors[] = $this->text->admin('e.too_long', ['field' => $this->text->admin($label), 'locale' => $locale, 'max' => $max]);
                $value = mb_substr($value, 0, $max, 'UTF-8');
            }
            if ($value === '') {
                unset($out[$locale]);
            } else {
                $out[$locale] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $items
     * @param list<string> $reserved
     * @param list<string> $errors
     */
    private function checkSlug(string $slug, array $items, ?string $oldSlug, array $reserved, array &$errors): void
    {
        if (!self::validSlug($slug)) {
            $errors[] = $this->text->admin('e.slug', ['max' => self::SLUG_MAX]);
        } elseif (in_array($slug, $reserved, true)) {
            $errors[] = $this->text->admin('e.slug_reserved', ['slug' => $slug]);
        } elseif ($slug !== $oldSlug && isset($items[$slug])) {
            $errors[] = $this->text->admin('e.slug_taken', ['slug' => $slug]);
        }
    }

    /**
     * @param array<string, mixed> $items
     * @param list<string> $reserved
     */
    private function uniqueSlug(string $base, array $items, ?string $oldSlug, array $reserved): string
    {
        $base = substr($base, 0, self::SLUG_MAX - 4);
        $base = trim($base, '-') ?: 'post';
        $slug = $base;
        for ($i = 2; ($slug !== $oldSlug && isset($items[$slug])) || in_array($slug, $reserved, true); $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    private static function slugify(string $text): string
    {
        if (class_exists('Slugifier')) {
            return \Slugifier::slugify($text);
        }
        $text = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $text) ?? '');

        return trim($text, '-') ?: 'post';
    }

    private static function validDate(string $date): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function normalizePost(string $slug, array $raw): array
    {
        $date = self::str($raw['date'] ?? '');

        return [
            'slug' => $slug,
            'title' => self::localizedMap($raw['title'] ?? []),
            'excerpt' => self::localizedMap($raw['excerpt'] ?? []),
            'body' => self::localizedMap($raw['body'] ?? []),
            'category' => is_string($raw['category'] ?? null) && self::validSlug($raw['category']) ? $raw['category'] : null,
            'tags' => array_values(array_unique(array_filter((array) ($raw['tags'] ?? []), static fn($t): bool => is_string($t) && self::validSlug($t)))),
            'date' => self::validDate($date) ? $date : '1970-01-01',
            'published' => ($raw['published'] ?? false) === true,
            'created' => self::str($raw['created'] ?? ''),
            'updated' => self::str($raw['updated'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function normalizeTerm(string $slug, array $raw): array
    {
        return ['slug' => $slug, 'name' => self::localizedMap($raw['name'] ?? [])];
    }

    /** @return array<string, string> */
    private static function localizedMap(mixed $raw): array
    {
        if (is_string($raw)) {
            return $raw !== '' ? [\SiteLocale::default() => $raw] : [];
        }
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $locale => $value) {
            if (is_string($locale) && preg_match(\SiteLocale::PATTERN, $locale) && is_string($value) && $value !== '') {
                $out[$locale] = $value;
            }
        }

        return $out;
    }
}
