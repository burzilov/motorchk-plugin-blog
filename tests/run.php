<?php

/**
 * Тесты плагина blog (без PHPUnit):
 *   php tests/run.php [путь/к/core]
 * Путь к ядру: аргумент, переменная MOTORCHK_CORE или ../motorchk/public_html/core рядом с репозиторием плагинов.
 *
 * Создаёт временный сайт (skeleton ядра ≥ 0.5 + этот плагин) в sys_get_temp_dir() и проверяет
 * установку, редактор записей/рубрик/тегов (валидация, CSRF, переименование, удаление),
 * публичные страницы (список, запись, рубрика, тег, страницы, 404, экранирование),
 * повреждённые файлы данных и мультиязычность с плагином i18n (если он лежит рядом: ../i18n).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pluginRoot = dirname(__DIR__);
$corePath = $argv[1] ?? (getenv('MOTORCHK_CORE') ?: dirname($pluginRoot, 2) . '/motorchk/public_html/core');
$corePath = realpath($corePath) ?: $corePath;
if (!is_file($corePath . '/includes/SiteLocale.php')) {
    fwrite(STDERR, "Не найдено ядро motorchk ≥ 0.5 в {$corePath}. Укажите путь: php tests/run.php /path/to/core\n");
    exit(2);
}
require_once $corePath . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($corePath): void {
    $file = $corePath . '/includes/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

ini_set('error_log', sys_get_temp_dir() . '/blog-plugin-test-' . getmypid() . '.log');
ob_start();
Env::set('APP_SECRET', 'blog-plugin-test-secret');
$failures = 0;
$passed = 0;

function out(string $text): void
{
    fwrite(STDOUT, $text);
}

function check(bool $ok, string $name, string $details = ''): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        out("  ok   {$name}\n");
        return;
    }
    $failures++;
    out("  FAIL {$name}" . ($details !== '' ? " — {$details}" : '') . "\n");
}

function t_copy(string $from, string $to, array $skip = []): void
{
    if (is_dir($from)) {
        @mkdir($to, 0775, true);
        foreach (scandir($from) ?: [] as $item) {
            if ($item !== '.' && $item !== '..' && !in_array($item, $skip, true)) {
                t_copy($from . '/' . $item, $to . '/' . $item);
            }
        }
        return;
    }
    copy($from, $to);
}

function t_rm(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            t_rm($path . '/' . $item);
        }
    }
    rmdir($path);
}

/** @return array{0: int, 1: string} */
function t_capture(callable $fn): array
{
    http_response_code(200);
    ob_start();
    try {
        $fn();
    } finally {
        $body = (string) ob_get_clean();
    }

    return [http_response_code(), $body];
}

$i18nSource = getenv('MOTORCHK_I18N') ?: dirname($pluginRoot) . '/i18n';
$hasI18n = is_file($i18nSource . '/plugin.json');

/** Временный сайт; $withI18n — положить рядом плагин i18n. @return array<string, mixed> config */
function t_site(string $name, bool $withI18n): array
{
    global $corePath, $pluginRoot, $i18nSource;
    $site = sys_get_temp_dir() . '/blog-plugin-test-' . $name . '-' . getmypid();
    t_rm($site);
    mkdir($site, 0775, true);
    foreach (['content', 'themes', 'config'] as $dir) {
        t_copy($corePath . '/skeleton/' . $dir, $site . '/' . $dir);
    }
    @mkdir($site . '/cache', 0775, true);
    file_put_contents($site . '/cache/update-check.json', json_encode(['checked_at' => time() + 3600, 'current' => Version::current(), 'update' => null]));
    t_copy($pluginRoot, $site . '/plugins/blog', ['.git', '.github', 'tests', 'dist', 'release.sh']);
    if ($withI18n) {
        t_copy($i18nSource, $site . '/plugins/i18n', ['.git', '.github', 'tests', 'dist', 'release.sh']);
    }

    return [
        'site_path' => $site, 'core_path' => $corePath, 'base_path' => $site,
        'content_path' => $site . '/content', 'cache_path' => $site . '/cache', 'themes_path' => $site . '/themes',
        'forms_path' => $site . '/content/forms', 'submissions_path' => $site . '/submissions',
        'admin_templates_path' => $corePath . '/admin-panel/templates', 'menu_file' => $site . '/content/_menus.yaml',
        'skeleton_path' => $corePath . '/skeleton', 'plugins_path' => $site . '/plugins',
        'plugin_data_path' => $site . '/plugin-data', 'installed' => true,
    ];
}

$_SESSION = [];
$_GET = $_POST = $_FILES = $_COOKIE = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'example.test';
unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);

$config = t_site('plain', false);
$data = $config['plugin_data_path'] . '/blog';

/** Публичный запрос (без входа в админку): [status, body] */
$public = static function (string $uri, array $get = [], bool $asAdmin = false) use (&$config): array {
    $session = $_SESSION;
    $_SESSION = $asAdmin ? ['admin_authenticated' => true, 'csrf_token' => 'tok'] : [];
    $_GET = $get;
    $_COOKIE = [];
    try {
        return t_capture(fn() => (new Router($config))->dispatchPublic($uri));
    } finally {
        $_GET = [];
        $_SESSION = $session;
    }
};
/** Запрос в админку блога: [status, body]; $csrf = false — без токена */
$call = static function (string $method, string $path, array $post = [], bool $csrf = true, array $get = []) use (&$config): array {
    $_SESSION = ['admin_authenticated' => true, 'csrf_token' => 'tok'];
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $method === 'POST' ? $post + ($csrf ? ['_csrf' => 'tok'] : []) : [];
    $_GET = $get;
    try {
        return t_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/plugins/blog' . ($path === '/' ? '' : $path)));
    } finally {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = $_GET = [];
        $_SESSION = [];
    }
};
$json = static function (string $file) use (&$data): mixed {
    return json_decode((string) @file_get_contents($data . '/' . $file), true);
};

out('blog plugin tests (core v' . Version::current() . ', plugin v' . json_decode((string) file_get_contents($pluginRoot . '/plugin.json'), true)['version'] . ($hasI18n ? ', i18n рядом' : ', без i18n') . ")\n");

// ---------------------------------------------------------------- установка
out("\n[манифест и установка]\n");
$manifest = json_decode((string) file_get_contents($pluginRoot . '/plugin.json'), true);
check(($manifest['license'] ?? null) === 'MIT' && ($manifest['repository'] ?? null) === 'burzilov/motorchk-plugin-blog' && is_file($pluginRoot . '/LICENSE') && str_contains((string) file_get_contents($pluginRoot . '/LICENSE'), 'Copyright (c) 2026 Ivan Burzilov'), 'license MIT, repository, файл LICENSE');
$changelog = (string) file_get_contents($pluginRoot . '/CHANGELOG.md');
check(preg_match('/^## ' . preg_quote($manifest['version'], '/') . ' — \d{4}-\d{2}-\d{2}$/m', $changelog) === 1, 'CHANGELOG: первая запись совпадает с version');
$manager = new PluginManager($config);
$info = $manager->discover()['blog'] ?? null;
check($info !== null && $info['status'] === 'ok', 'манифест валиден, совместим с ядром', json_encode($info['errors'] ?? null, JSON_UNESCAPED_UNICODE));
check(($manager->adminMenu()[0]['label'] ?? null) === 'Блог', 'пункт меню «Блог»');
$ctx = $manager->boot('blog');
check($ctx instanceof PluginContext && $ctx->prefix() === 'blog', 'плагин загружается, префикс /blog');
check($json('posts.json') === [] && $json('categories.json') === [] && $json('tags.json') === [], 'install: пустые posts/categories/tags.json');

// ---------------------------------------------------------------- пустой блог
out("\n[пустой блог]\n");
[$st, $body] = $public('/blog/');
check($st === 200 && str_contains($body, 'Записей пока нет.') && str_contains($body, '<h1 class="blog-title">Блог</h1>'), '/blog/ — пустой список в шаблоне темы');
check(str_contains($body, '/plugin-assets/blog/blog.css') && str_contains($body, '</html>'), 'стили блога подключены, страница внутри темы');
[$st, $body] = $public('/blog/nope');
check($st === 404 && str_contains($body, 'Запись не найдена'), 'неизвестная запись — 404 в шаблоне темы');
check($public('/blog/Bad_Slug')[0] === 404 && $public('/blog/category/nope')[0] === 404 && $public('/blog/tag/nope')[0] === 404, 'неверный адрес, нет рубрики/тега — 404');

// ---------------------------------------------------------------- админка: рубрики и теги
out("\n[админка: рубрики и теги]\n");
[$st, $body] = $call('GET', '/');
check($st === 200 && str_contains($body, 'Записей пока нет.') && str_contains($body, '/admin-panel/plugins/blog/posts/new'), 'список записей');
[$st] = $call('POST', '/categories', ['name' => ['ru' => 'Новости'], 'slug' => 'news'], false);
check($st === 403 && $json('categories.json') === [], 'POST без CSRF — 403, ничего не записано');
[$st] = $call('POST', '/categories', ['name' => ['ru' => 'Новости'], 'slug' => 'news']);
check($st === 302 && ($json('categories.json')['news']['name']['ru'] ?? null) === 'Новости', 'создание рубрики');
[$st] = $call('POST', '/categories', ['name' => ['ru' => 'Обзоры техники'], 'slug' => '']);
check($st === 302 && isset($json('categories.json')['obzory-tehniki']), 'пустой адрес — из названия (транслит)');
[$st, $body] = $call('POST', '/categories', ['name' => ['ru' => 'Дубль'], 'slug' => 'news']);
check($st === 422 && str_contains($body, 'уже занят') && str_contains($body, 'value="Дубль"'), 'занятый адрес — 422, форма заполнена');
[$st, $body] = $call('POST', '/categories', ['name' => ['ru' => ''], 'slug' => '../x']);
check($st === 422 && str_contains($body, 'Заполните название') && str_contains($body, 'Адрес должен состоять'), 'пустое название и неверный адрес — 422');
[$st, $body] = $call('POST', '/tags', ['name' => ['ru' => 'Новый'], 'slug' => 'new']);
check($st === 422 && str_contains($body, 'зарезервирован'), 'зарезервированный адрес new — 422');
foreach (['php' => 'PHP', 'tips' => 'Советы', 'old' => 'Старое'] as $slug => $name) {
    $call('POST', '/tags', ['name' => ['ru' => $name], 'slug' => $slug]);
}
check(array_keys($json('tags.json')) === ['php', 'tips', 'old'], 'создание тегов');
[$st, $body] = $call('GET', '/tags/php');
check($st === 200 && str_contains($body, 'value="PHP"') && str_contains($body, '/admin-panel/plugins/blog/tags/php'), 'форма правки тега');
check($call('GET', '/tags/zzz')[0] === 404 && $call('POST', '/tags/zzz', ['name' => ['ru' => 'x']])[0] === 404, 'нет тега — 404');

// ---------------------------------------------------------------- админка: записи
out("\n[админка: записи]\n");
[$st, $body] = $call('GET', '/posts/new');
check($st === 200 && str_contains($body, 'name="title[ru]"') && !str_contains($body, 'name="title[en]"') && str_contains($body, 'value="php"') && str_contains($body, 'value="news"'), 'редактор: без i18n — одно поле на язык, теги и рубрики в форме');
$md = "Первый абзац.\n\n## Подзаголовок\n\n- пункт\n";
[$st] = $call('POST', '/posts', ['title' => ['ru' => 'Привет, мир <script>'], 'excerpt' => ['ru' => "Анонс <b>жирный</b>\nвторая строка"], 'body' => ['ru' => $md], 'slug' => '', 'date' => '2026-10-01', 'category' => 'news', 'tags' => ['php', 'tips'], 'published' => '1']);
$posts = $json('posts.json');
check($st === 302 && isset($posts['privet-mir-script']) && $posts['privet-mir-script']['tags'] === ['php', 'tips'] && $posts['privet-mir-script']['category'] === 'news' && $posts['privet-mir-script']['published'] === true, 'создание записи: адрес из заголовка, рубрика, теги');
[$st, $body] = $call('POST', '/posts', ['title' => ['ru' => ''], 'slug' => 'category', 'date' => '2026-02-30', 'category' => 'zzz', 'tags' => ['zzz']]);
check($st === 422 && str_contains($body, 'Заполните заголовок') && str_contains($body, 'зарезервирован') && str_contains($body, 'ГГГГ-ММ-ДД') && str_contains($body, 'рубрики нет') && str_contains($body, 'тега нет'), 'валидация записи: заголовок, адрес, дата, рубрика, тег');
[$st, $body] = $call('POST', '/posts', ['title' => ['ru' => str_repeat('я', 201)], 'slug' => 'long']);
check($st === 422 && str_contains($body, 'длиннее 200'), 'слишком длинный заголовок — 422');
check(count($json('posts.json')) === 1, 'после ошибок записей не прибавилось');
[$st] = $call('POST', '/posts', ['title' => ['ru' => 'Черновик'], 'slug' => 'draft', 'date' => '2026-10-05', 'tags' => ['old']]);
check($st === 302 && $json('posts.json')['draft']['published'] === false, 'черновик (без «Опубликовать»)');
for ($i = 1; $i <= 11; $i++) {
    $call('POST', '/posts', ['title' => ['ru' => 'Запись ' . $i], 'slug' => 'post-' . $i, 'date' => sprintf('2026-09-%02d', $i), 'published' => '1', 'tags' => $i % 2 ? ['php'] : []]);
}
check(count($json('posts.json')) === 13, 'ещё 11 записей');
[$st, $body] = $call('GET', '/');
check($st === 200 && str_contains($body, 'Привет, мир &lt;script&gt;') && !str_contains($body, 'мир <script>') && str_contains($body, 'Черновик') && str_contains($body, 'Новости'), 'список в админке: экранирование, черновики, рубрика');
[$st, $body] = $call('GET', '/posts/privet-mir-script');
check($st === 200 && str_contains($body, '## Подзаголовок') && str_contains($body, 'value="2026-10-01"') && preg_match('/value="php" checked/', $body) === 1, 'редактор существующей записи');
check($call('GET', '/posts/zzz')[0] === 404 && $call('GET', '/posts/..%2Fx')[0] !== 200, 'нет записи / путь с .. — не открывается');

// ---------------------------------------------------------------- сайт
out("\n[сайт]\n");
[$st, $body] = $public('/blog/');
check($st === 200 && str_contains($body, 'Привет, мир &lt;script&gt;') && !str_contains($body, '<script>Привет') && !str_contains($body, 'Черновик'), 'список: опубликованные, экранирование, без черновиков');
check(str_contains($body, 'Анонс &lt;b&gt;жирный&lt;/b&gt;<br />') && str_contains($body, 'href="/blog/privet-mir-script"') && str_contains($body, 'Читать далее'), 'анонс экранирован, ссылка на запись');
check(strpos($body, 'privet-mir-script') < strpos($body, 'post-11') && str_contains($body, 'Страница 1 из 2') && str_contains($body, 'href="/blog?page=2"') && !str_contains($body, 'post-2"'), 'по убыванию даты, 10 на странице, ссылка на следующую');
[$st, $body] = $public('/blog/', ['page' => '2']);
check($st === 200 && str_contains($body, 'post-2"') && str_contains($body, 'post-1"') && str_contains($body, 'href="/blog" rel="prev"') && str_contains($body, 'Страница 2 из 2'), 'вторая страница');
check($public('/blog/', ['page' => '3'])[0] === 404 && $public('/blog/', ['page' => 'x'])[0] === 404 && $public('/blog/', ['page' => ['1']])[0] === 404, 'нет такой страницы / неверный page — 404');
[$st, $body] = $public('/blog/privet-mir-script');
check($st === 200 && str_contains($body, '<h2>Подзаголовок</h2>') && str_contains($body, '<li>пункт</li>') && str_contains($body, '<title>Привет, мир &lt;script&gt;</title>'), 'страница записи: Markdown → HTML, заголовок в <title>');
check(str_contains($body, 'datetime="2026-10-01"') && str_contains($body, '01.10.2026') && str_contains($body, 'href="/blog/category/news"') && str_contains($body, 'href="/blog/tag/php"') && str_contains($body, 'rel="tag"'), 'дата, рубрика, теги со ссылками');
check(str_contains($body, 'name="description" content="Анонс &lt;b&gt;жирный&lt;/b&gt; вторая строка"') || str_contains($body, 'name="description" content="Анонс &lt;b&gt;жирный&lt;/b&gt;'), 'анонс — meta description');
check($public('/blog/draft')[0] === 404 && $public('/blog/draft', [], true)[0] === 200, 'черновик: 404 для гостей, виден администратору');
[$seoStatus, $seo] = $public('/sitemap.xml');
check($seoStatus === 200 && str_contains($seo, '<loc>http://example.test/blog/</loc>') && str_contains($seo, '<loc>http://example.test/blog/privet-mir-script</loc>') && !str_contains($seo, '/blog/draft</loc>'), 'sitemap: блог и опубликованная запись, без черновика');
check(str_contains($seo, '<lastmod>'), 'sitemap: дата изменения записи');
[$st, $body] = $public('/blog/category/news');
check($st === 200 && str_contains($body, 'Рубрика: Новости') && str_contains($body, 'privet-mir-script') && !str_contains($body, 'post-1"') && str_contains($body, 'Все записи'), 'страница рубрики');
[$st, $body] = $public('/blog/tag/php');
check($st === 200 && str_contains($body, 'Тег: PHP') && str_contains($body, 'post-11"') && !str_contains($body, 'post-10"') && !str_contains($body, 'blog-pagination'), 'страница тега: только записи с тегом, без пагинации');
[$st, $body] = $public('/blog/tag/old');
check($st === 200 && str_contains($body, 'Записей пока нет.'), 'тег только у черновика — пусто');

// ---------------------------------------------------------------- переименование и удаление
out("\n[переименование и удаление]\n");
[$st] = $call('POST', '/categories/news', ['name' => ['ru' => 'Новости сайта'], 'slug' => 'site-news']);
$posts = $json('posts.json');
check($st === 302 && !isset($json('categories.json')['news']) && $posts['privet-mir-script']['category'] === 'site-news', 'смена адреса рубрики переносится в записи');
check($public('/blog/category/news')[0] === 404 && str_contains($public('/blog/category/site-news')[1], 'Новости сайта'), 'новый адрес рубрики на сайте');
[$st] = $call('POST', '/tags/tips', ['name' => ['ru' => 'Советы'], 'slug' => 'php']);
check($st === 422, 'переименование тега в занятый адрес — 422');
[$st] = $call('POST', '/tags/php/delete');
$posts = $json('posts.json');
check($st === 302 && !isset($json('tags.json')['php']) && $posts['privet-mir-script']['tags'] === ['tips'] && $posts['post-1']['tags'] === [], 'удаление тега убирает его из записей');
[$st] = $call('POST', '/categories/site-news/delete');
check($st === 302 && $json('posts.json')['privet-mir-script']['category'] === null && $json('categories.json')['obzory-tehniki'] !== null, 'удаление рубрики: у записей рубрика снята');
[$st] = $call('POST', '/posts/privet-mir-script', ['title' => ['ru' => 'Привет'], 'body' => ['ru' => 'Новый текст'], 'slug' => 'hello', 'date' => '2026-10-02', 'published' => '1']);
$posts = $json('posts.json');
check($st === 302 && !isset($posts['privet-mir-script']) && $posts['hello']['title']['ru'] === 'Привет' && $posts['hello']['created'] !== '', 'правка записи со сменой адреса');
check($public('/blog/privet-mir-script')[0] === 404 && str_contains($public('/blog/hello')[1], 'Новый текст'), 'новый адрес записи на сайте');
[$st] = $call('POST', '/posts/post-3', ['title' => ['ru' => 'x'], 'slug' => 'hello']);
check($st === 422, 'смена адреса на занятый — 422');
[$st] = $call('POST', '/posts/hello/delete');
check($st === 302 && !isset($json('posts.json')['hello']) && $public('/blog/hello')[0] === 404, 'удаление записи');
check($call('POST', '/posts/hello/delete', [], false)[0] === 403, 'удаление без CSRF — 403');
[$st, $body] = $call('GET', '/', [], true, ['deleted' => '1']);
check($st === 200 && str_contains($body, 'Удалено.'), 'сообщение после удаления');

// ---------------------------------------------------------------- настройки
out("\n[settings.json]\n");
file_put_contents($data . '/settings.json', json_encode(['per_page' => 3, 'styles' => false, 'template' => '../evil']));
[$st, $body] = $public('/blog/');
check($st === 200 && str_contains($body, 'Страница 1 из 4') && !str_contains($body, 'blog.css'), 'per_page и styles из settings.json; неверный template игнорируется');
unlink($data . '/settings.json');

// ---------------------------------------------------------------- повреждённые файлы
out("\n[повреждённые файлы]\n");
$before = (string) file_get_contents($data . '/posts.json');
file_put_contents($data . '/tags.json', '{broken');
[$st, $body] = $public('/blog/');
check($st === 200, 'сайт открывается при повреждённом tags.json');
[$st, $body] = $call('GET', '/');
check($st === 200 && str_contains($body, 'plugin-data/blog/tags.json повреждён'), 'админка показывает, какой файл повреждён');
[$st, $body] = $call('POST', '/posts', ['title' => ['ru' => 'Новая'], 'slug' => 'new-one']);
check($st === 409 && str_contains($body, 'сохранение отменено') && (string) file_get_contents($data . '/posts.json') === $before && (string) file_get_contents($data . '/tags.json') === '{broken', 'сохранение запрещено, файлы не перезаписаны');
check($call('POST', '/categories/obzory-tehniki/delete')[0] === 409, 'удаление тоже запрещено');
file_put_contents($data . '/tags.json', '{}');

// ---------------------------------------------------------------- i18n
out("\n[мультиязычность: i18n]\n");
if (!$hasI18n) {
    out("  skip плагин i18n не найден ({$i18nSource}); укажите MOTORCHK_I18N\n");
} else {
    $config = t_site('i18n', true);
    $data = $config['plugin_data_path'] . '/blog';
    (new PluginManager($config))->boot('i18n');
    (new PluginManager($config))->boot('blog');
    [$st, $body] = $call('GET', '/posts/new');
    check($st === 200 && str_contains($body, 'name="title[ru]"') && str_contains($body, 'name="title[en]"') && str_contains($body, 'name="body[en]"') && str_contains($body, 'English'), 'редактор: поля на каждом языке i18n');
    $call('POST', '/categories', ['name' => ['ru' => 'Новости', 'en' => 'News'], 'slug' => 'news']);
    $call('POST', '/tags', ['name' => ['ru' => 'Советы'], 'slug' => 'tips']);
    [$st] = $call('POST', '/posts', ['title' => ['ru' => 'Привет', 'en' => 'Hello'], 'excerpt' => ['ru' => 'Анонс', 'en' => 'Teaser'], 'body' => ['ru' => 'Русский текст', 'en' => 'English text'], 'slug' => 'hello', 'date' => '2026-10-01', 'category' => 'news', 'tags' => ['tips'], 'published' => '1']);
    $call('POST', '/posts', ['title' => ['ru' => 'Только по-русски'], 'body' => ['ru' => 'Без перевода'], 'slug' => 'ru-only', 'date' => '2026-09-01', 'published' => '1']);
    [$seoStatus, $seo] = $public('/sitemap.xml');
    check($seoStatus === 200 && str_contains($seo, '<loc>http://example.test/blog/hello</loc>') && str_contains($seo, '<loc>http://example.test/en/blog/hello</loc>') && str_contains($seo, 'hreflang="x-default"'), 'sitemap: переведённая запись и hreflang');
    check(str_contains($seo, '<loc>http://example.test/blog/ru-only</loc>') && !str_contains($seo, '<loc>http://example.test/en/blog/ru-only</loc>'), 'sitemap: запись без перевода не создаёт дубликат');
    check($st === 302 && $json('posts.json')['hello']['title'] === ['ru' => 'Привет', 'en' => 'Hello'], 'запись на двух языках');
    [$st, $body] = $public('/blog/');
    check($st === 200 && str_contains($body, 'Привет') && str_contains($body, 'Читать далее') && str_contains($body, 'href="/blog/hello"'), 'основной язык: /blog/');
    [$st, $body] = $public('/en/blog/');
    check($st === 200 && str_contains($body, '<h1 class="blog-title">Blog</h1>') && str_contains($body, 'Hello') && str_contains($body, 'Read more') && str_contains($body, 'href="/en/blog/hello"') && str_contains($body, 'Только по-русски'), '/en/blog/: английские строки и ссылки, непереведённая — на основном языке');
    check(str_contains($body, 'rel="alternate" hreflang="en"') && str_contains($body, 'rel="alternate" hreflang="ru"'), 'список: hreflang на всех языках');
    [$st, $body] = $public('/en/blog/hello');
    check($st === 200 && str_contains($body, 'English text') && str_contains($body, 'href="/en/blog/category/news"') && str_contains($body, '>News<') && str_contains($body, '2026-10-01') && str_contains($body, 'lang="en"'), '/en/blog/hello: перевод, рубрика на en, дата');
    check(str_contains($body, 'rel="alternate" hreflang="ru" href="http://example.test/blog/hello"') && str_contains($body, 'rel="alternate" hreflang="en" href="http://example.test/en/blog/hello"'), 'запись с переводом — hreflang');
    [$st, $body] = $public('/en/blog/ru-only');
    check($st === 200 && str_contains($body, 'Без перевода') && !str_contains($body, 'rel="alternate" hreflang'), 'без перевода — текст основного языка, без hreflang');
    [$st, $body] = $public('/en/blog/category/news');
    check($st === 200 && str_contains($body, 'Category: News'), 'рубрика на en');
    $dict = json_decode((string) file_get_contents($config['plugin_data_path'] . '/i18n/dictionary.json'), true);
    $dict['blog.read_more'] = ['ru' => 'Дальше', 'en' => 'Continue'];
    file_put_contents($config['plugin_data_path'] . '/i18n/dictionary.json', json_encode($dict, JSON_UNESCAPED_UNICODE));
    check(str_contains($public('/en/blog/')[1], 'Continue') && str_contains($public('/blog/')[1], 'Дальше'), 'строки переопределяются словарём i18n (blog.read_more)');
    [$st] = $call('POST', '/posts/hello', ['title' => ['ru' => 'Привет', 'en' => ''], 'body' => ['ru' => 'Русский текст', 'en' => ''], 'slug' => 'hello', 'date' => '2026-10-01', 'published' => '1']);
    check($st === 302 && !isset($json('posts.json')['hello']['title']['en']) && !isset($json('posts.json')['hello']['body']['en']), 'пустое поле перевода удаляет перевод');
    check(!str_contains($public('/sitemap.xml')[1], '<loc>http://example.test/en/blog/hello</loc>'), 'sitemap: удалённый перевод исчезает');
    t_rm($config['site_path']);
}

t_rm(sys_get_temp_dir() . '/blog-plugin-test-plain-' . getmypid());
@unlink(sys_get_temp_dir() . '/blog-plugin-test-' . getmypid() . '.log');
ob_end_clean();
out("\n{$passed} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);
