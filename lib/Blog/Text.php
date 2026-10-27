<?php

namespace Blog;

/**
 * Строки интерфейса блога. Публичные — на ru и en; любую можно переопределить в словаре
 * плагина i18n ключом «blog.<ключ>» (например blog.read_more). Строки админки — на русском.
 * Параметры подставляются как {name}.
 */
final class Text
{
    public const FALLBACK = 'ru';

    private const STRINGS = [
        'ru' => [
            // сайт
            'title' => 'Блог',
            'category_title' => 'Рубрика: {name}',
            'tag_title' => 'Тег: {name}',
            'empty' => 'Записей пока нет.',
            'read_more' => 'Читать далее',
            'category' => 'Рубрика',
            'tags' => 'Теги',
            'all_posts' => 'Все записи',
            'newer' => '← Новее',
            'older' => 'Старше →',
            'page_of' => 'Страница {page} из {pages}',
            'not_found' => 'Запись не найдена',
            'not_found_text' => 'Такой страницы в блоге нет.',
            'pagination' => 'Страницы блога',
            // админка
            'a.posts' => 'Записи',
            'a.categories' => 'Рубрики',
            'a.tags' => 'Теги',
            'a.new_post' => 'Новая запись',
            'a.edit_post' => 'Редактирование записи',
            'a.no_posts' => 'Записей пока нет.',
            'a.no_terms' => 'Пока пусто.',
            'a.col_title' => 'Заголовок',
            'a.col_date' => 'Дата',
            'a.col_status' => 'Статус',
            'a.col_category' => 'Рубрика',
            'a.col_slug' => 'Адрес',
            'a.col_name' => 'Название',
            'a.col_posts' => 'Записей',
            'a.published' => 'Опубликована',
            'a.draft' => 'Черновик',
            'a.edit' => 'Изменить',
            'a.delete' => 'Удалить',
            'a.view' => 'Открыть на сайте',
            'a.save' => 'Сохранить',
            'a.create' => 'Создать',
            'a.cancel' => 'Отмена',
            'a.back' => '← К списку',
            'a.saved' => 'Сохранено.',
            'a.deleted' => 'Удалено.',
            'a.not_saved' => 'Не сохранено:',
            'a.confirm_delete' => 'Удалить безвозвратно?',
            'a.field_slug' => 'Адрес (slug)',
            'a.field_slug_help' => 'Латиница в нижнем регистре, цифры и дефис. Пусто — из заголовка.',
            'a.field_date' => 'Дата',
            'a.field_published' => 'Опубликовать',
            'a.field_category' => 'Рубрика',
            'a.field_no_category' => '— без рубрики —',
            'a.field_tags' => 'Теги',
            'a.field_title' => 'Заголовок',
            'a.field_excerpt' => 'Анонс',
            'a.field_excerpt_help' => 'Короткий текст для списка записей и описания страницы.',
            'a.field_body' => 'Текст (Markdown)',
            'a.field_name' => 'Название',
            'a.lang_help' => 'Языки сайта из плагина i18n. Обязателен только основной ({locale}); незаполненный перевод — показывается текст на основном языке.',
            'a.new_category' => 'Новая рубрика',
            'a.edit_category' => 'Рубрика',
            'a.new_tag' => 'Новый тег',
            'a.edit_tag' => 'Тег',
            'a.no_tags_hint' => 'Тегов пока нет — их можно создать на вкладке «Теги».',
            'a.file_error' => 'Файл plugin-data/blog/{file} повреждён ({reason}). Изменения не сохраняются, пока файл не исправлен или не удалён.',
            'a.not_found' => 'Не найдено',
            // ошибки
            'e.slug' => 'Адрес должен состоять из латиницы в нижнем регистре, цифр и дефисов (до {max} символов).',
            'e.slug_reserved' => 'Адрес «{slug}» зарезервирован.',
            'e.slug_taken' => 'Адрес «{slug}» уже занят.',
            'e.title_required' => 'Заполните заголовок на основном языке ({locale}).',
            'e.name_required' => 'Заполните название на основном языке ({locale}).',
            'e.too_long' => 'Поле «{field}» ({locale}) длиннее {max} символов.',
            'e.date' => 'Дата должна быть в формате ГГГГ-ММ-ДД.',
            'e.category' => 'Выбранной рубрики нет.',
            'e.tag' => 'Выбранного тега нет.',
            'e.file' => 'Файл plugin-data/blog/{file} повреждён — сохранение отменено.',
            'e.write' => 'Не удалось записать файл plugin-data/blog/{file}.',
        ],
        'en' => [
            'title' => 'Blog',
            'category_title' => 'Category: {name}',
            'tag_title' => 'Tag: {name}',
            'empty' => 'No posts yet.',
            'read_more' => 'Read more',
            'category' => 'Category',
            'tags' => 'Tags',
            'all_posts' => 'All posts',
            'newer' => '← Newer',
            'older' => 'Older →',
            'page_of' => 'Page {page} of {pages}',
            'not_found' => 'Post not found',
            'not_found_text' => 'There is no such page in the blog.',
            'pagination' => 'Blog pages',
        ],
    ];

    /** @var (callable(string, string, string): ?string)|null переопределение из словаря i18n */
    private $override;

    public function __construct(?callable $override = null)
    {
        $this->override = $override;
    }

    /** Строка на языке $locale: словарь i18n → встроенная на этом языке → встроенная на русском → ключ. */
    public function get(string $key, string $locale = self::FALLBACK, array $params = []): string
    {
        $builtin = self::STRINGS[$locale][$key] ?? self::STRINGS[self::FALLBACK][$key] ?? $key;
        $text = $builtin;
        if ($this->override !== null && !str_starts_with($key, 'a.') && !str_starts_with($key, 'e.')) {
            $value = ($this->override)('blog.' . $key, $builtin, $locale);
            if (is_string($value) && $value !== '' && $value !== 'blog.' . $key) {
                $text = $value;
            }
        }
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }

    /** Строка админки (русский). */
    public function admin(string $key, array $params = []): string
    {
        return $this->get($key, self::FALLBACK, $params);
    }

    /** @return list<string> ключи публичных строк (для README и тестов) */
    public static function publicKeys(): array
    {
        return array_keys(self::STRINGS['en']);
    }
}
