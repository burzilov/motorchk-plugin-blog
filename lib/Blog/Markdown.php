<?php

namespace Blog;

/**
 * Текст записи (Markdown) → HTML тем же рендерером, что и страницы ядра (MarkdownRenderer,
 * league/commonmark). Как и в страницах ядра, HTML внутри Markdown разрешён: текст пишет администратор.
 */
final class Markdown
{
    private static ?\MarkdownRenderer $renderer = null;

    public static function toHtml(string $markdown): string
    {
        if ($markdown === '') {
            return '';
        }
        if (!class_exists('MarkdownRenderer')) {
            return '<p>' . nl2br(htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        self::$renderer ??= new \MarkdownRenderer();

        return (string) (self::$renderer->renderBlocks(['content' => $markdown])['content'] ?? '');
    }
}
