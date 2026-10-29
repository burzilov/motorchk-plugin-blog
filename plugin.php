<?php

/**
 * Блог: записи с рубриками и тегами.
 *   сайт:    /blog/, /blog/{slug}, /blog/category/{slug}, /blog/tag/{slug}
 *   админка: /admin-panel/plugins/blog/ — записи, /categories — рубрики, /tags — теги
 * Языки берутся из плагина i18n (если установлен), без него — один язык сайта.
 * Данные — plugin-data/blog/{posts,categories,tags}.json.
 */

use Blog\Admin;
use Blog\Repository;
use Blog\Site;
use Blog\Text;

return static function (PluginContext $plugin): void {
    // Строки сайта можно переопределить в словаре i18n (ключи blog.*). Служба берётся лениво — в обработчике.
    $text = static function () use ($plugin): Text {
        $i18n = $plugin->service('i18n');
        $override = $i18n !== null && method_exists($i18n, 't')
            ? static fn(string $key, string $fallback, string $locale): ?string => $i18n->t($key, $fallback, $locale)
            : null;

        return new Text($override);
    };
    $site = static function () use ($plugin, $text): Site {
        $t = $text();

        return new Site($plugin, new Repository($plugin, $t), $t);
    };
    $admin = static function () use ($plugin, $text): Admin {
        $t = $text();

        return new Admin($plugin, new Repository($plugin, $t), $t);
    };

    $plugin->onInstall(static function (?string $from, string $to, PluginContext $ctx): void {
        foreach (Repository::KINDS as $kind) {
            if ($ctx->readFile($kind . '.json') === null) {
                $ctx->writeJson($kind . '.json', new stdClass());
            }
        }
    });

    $plugin->filter('sitemap', static function (array $entries, array $context) use ($plugin, $text): array {
        $locales = $context['locales'];
        $default = $context['default_locale'];
        $index = [];
        foreach ($locales as $locale) {
            $index[$locale] = rtrim($plugin->publicUrl('/', $locale), '/') . '/';
        }
        foreach ($index as $url) {
            $entries[] = ['loc' => $url, 'alternates' => $index];
        }

        $repo = new Repository($plugin, $text());
        foreach ($repo->posts(true) as $post) {
            $alternates = [];
            foreach ($locales as $locale) {
                if ($locale === $default || ($post['title'][$locale] ?? '') !== '') {
                    $alternates[$locale] = $plugin->publicUrl('/' . $post['slug'], $locale);
                }
            }
            foreach ($alternates as $url) {
                $entries[] = ['loc' => $url, 'lastmod' => $post['updated'], 'alternates' => $alternates];
            }
        }
        return $entries;
    });

    // ---------- сайт ----------
    $plugin->publicRoute('GET', '/', static fn(PluginRequest $r) => $site()->index($r));
    $plugin->publicRoute('GET', '/category/{slug}', static fn(PluginRequest $r) => $site()->category($r));
    $plugin->publicRoute('GET', '/tag/{slug}', static fn(PluginRequest $r) => $site()->tag($r));
    $plugin->publicRoute('GET', '/{slug}', static fn(PluginRequest $r) => $site()->post($r));

    // ---------- админка ----------
    $plugin->adminRoute('GET', '/', static fn(PluginRequest $r) => $admin()->posts($r));
    $plugin->adminRoute('GET', '/posts/new', static fn(PluginRequest $r) => $admin()->newPost($r));
    $plugin->adminRoute('POST', '/posts', static fn(PluginRequest $r) => $admin()->savePost($r));
    $plugin->adminRoute('GET', '/posts/{slug}', static fn(PluginRequest $r) => $admin()->editPost($r));
    $plugin->adminRoute('POST', '/posts/{slug}', static fn(PluginRequest $r) => $admin()->savePost($r));
    $plugin->adminRoute('POST', '/posts/{slug}/delete', static fn(PluginRequest $r) => $admin()->deletePost($r));
    foreach (['categories', 'tags'] as $kind) {
        $plugin->adminRoute('GET', '/' . $kind, static fn(PluginRequest $r) => $admin()->terms($kind, $r));
        $plugin->adminRoute('POST', '/' . $kind, static fn(PluginRequest $r) => $admin()->saveTerm($kind, $r));
        $plugin->adminRoute('GET', '/' . $kind . '/{slug}', static fn(PluginRequest $r) => $admin()->editTerm($kind, $r));
        $plugin->adminRoute('POST', '/' . $kind . '/{slug}', static fn(PluginRequest $r) => $admin()->saveTerm($kind, $r));
        $plugin->adminRoute('POST', '/' . $kind . '/{slug}/delete', static fn(PluginRequest $r) => $admin()->deleteTerm($kind, $r));
    }
};
