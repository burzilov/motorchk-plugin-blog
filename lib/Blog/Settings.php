<?php

namespace Blog;

use PluginContext;

/**
 * Необязательный plugin-data/blog/settings.json (правится вручную, интерфейса нет):
 *   per_page      — записей на странице списка, 1…50 (10)
 *   template      — шаблон активной темы для страниц блога ("default")
 *   menu_location — меню темы для страниц блога (по умолчанию — основное меню ядра)
 *   styles        — подключать assets/blog.css (true); false — оформление целиком в теме
 * Неверные значения заменяются значениями по умолчанию.
 */
final class Settings
{
    public const DEFAULTS = ['per_page' => 10, 'template' => 'default', 'menu_location' => '', 'styles' => true];

    /** @return array{per_page: int, template: string, menu_location: string, styles: bool} */
    public static function load(PluginContext $plugin): array
    {
        $raw = $plugin->readJson('settings.json', []);
        $raw = is_array($raw) ? $raw : [];
        $out = self::DEFAULTS;
        if (is_int($raw['per_page'] ?? null) && $raw['per_page'] >= 1 && $raw['per_page'] <= 50) {
            $out['per_page'] = $raw['per_page'];
        }
        foreach (['template', 'menu_location'] as $key) {
            if (is_string($raw[$key] ?? null) && preg_match('/^[a-z0-9_-]{1,64}$/', $raw[$key])) {
                $out[$key] = $raw[$key];
            }
        }
        if (is_bool($raw['styles'] ?? null)) {
            $out['styles'] = $raw['styles'];
        }

        return $out;
    }
}
