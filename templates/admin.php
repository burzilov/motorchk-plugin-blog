<?php
/** @var PluginContext $plugin */
/** @var Blog\Text $text */
/** @var string $section posts | post | categories | tags | not_found */
/** @var list<string> $locales языки сайта, первым — основной */
/** @var array<string, string> $localeNames */

$e = [PluginContext::class, 'e'];
$a = static fn(string $key, array $p = []): string => $text->admin($key, $p);
$tabs = ['posts' => ['/', 'a.posts'], 'categories' => ['/categories', 'a.categories'], 'tags' => ['/tags', 'a.tags']];
$activeTab = $section === 'post' ? 'posts' : $section;
$multi = count($locales) > 1;
$label = static fn(string $field, string $locale) => $a($field) . ($multi ? ' · ' . ($localeNames[$locale] ?? $locale) : '');
?>
<div class="blog-admin">
    <nav class="blog-tabs" aria-label="<?= $e($text->get('title')) ?>">
        <?php foreach ($tabs as $key => [$path, $title]): ?>
            <a class="blog-tab<?= $key === $activeTab ? ' is-active' : '' ?>" href="<?= $e($plugin->adminUrl($path)) ?>"<?= $key === $activeTab ? ' aria-current="page"' : '' ?>><?= $e($a($title)) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if (!empty($fileError)): ?>
        <p class="blog-alert blog-alert--error"><?= $e($fileError) ?></p>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="blog-alert blog-alert--error" role="alert">
            <b><?= $e($a('a.not_saved')) ?></b>
            <ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php if (!empty($notice)): ?>
        <p class="blog-alert blog-alert--ok" role="status"><?= $e($notice) ?></p>
    <?php endif; ?>

<?php if ($section === 'not_found'): ?>
    <h1 class="blog-h1"><?= $e($a('a.not_found')) ?></h1>
    <p><a href="<?= $e($plugin->adminUrl('/')) ?>"><?= $e($a('a.back')) ?></a></p>

<?php elseif ($section === 'posts'): ?>
    <div class="blog-head">
        <h1 class="blog-h1"><?= $e($a('a.posts')) ?></h1>
        <a class="blog-btn" href="<?= $e($plugin->adminUrl('/posts/new')) ?>"><?= $e($a('a.new_post')) ?></a>
    </div>
    <?php if ($posts === []): ?>
        <p class="blog-muted"><?= $e($a('a.no_posts')) ?></p>
    <?php else: ?>
        <table class="blog-table">
            <thead><tr><th><?= $e($a('a.col_title')) ?></th><th><?= $e($a('a.col_date')) ?></th><th><?= $e($a('a.col_category')) ?></th><th><?= $e($a('a.col_status')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($posts as $post): ?>
                <tr>
                    <td>
                        <a href="<?= $e($plugin->adminUrl('/posts/' . $post['slug'])) ?>"><?= $e($plugin->localized($post['title'], $locales[0]) ?? $post['slug']) ?></a>
                        <div class="blog-muted"><code>/<?= $e($plugin->prefix()) ?>/<?= $e($post['slug']) ?></code><?php if ($multi): ?> · <?= $e(implode(', ', array_keys($post['title']))) ?><?php endif; ?></div>
                    </td>
                    <td><?= $e($post['date']) ?></td>
                    <td><?= $e($post['category'] !== null ? ($plugin->localized($categories[$post['category']]['name'] ?? [], $locales[0]) ?? $post['category']) : '—') ?></td>
                    <td><?= $e($a($post['published'] ? 'a.published' : 'a.draft')) ?></td>
                    <td class="blog-actions">
                        <a href="<?= $e($plugin->adminUrl('/posts/' . $post['slug'])) ?>"><?= $e($a('a.edit')) ?></a>
                        <form method="post" action="<?= $e($plugin->adminUrl('/posts/' . $post['slug'] . '/delete')) ?>" onsubmit="return confirm(<?= $e(json_encode($a('a.confirm_delete'), JSON_UNESCAPED_UNICODE)) ?>)">
                            <?= $plugin->csrfField() ?>
                            <button type="submit" class="blog-link-danger"><?= $e($a('a.delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php elseif ($section === 'post'): ?>
    <?php $action = $editing === null ? '/posts' : '/posts/' . $editing; ?>
    <div class="blog-head">
        <h1 class="blog-h1"><?= $e($a($editing === null ? 'a.new_post' : 'a.edit_post')) ?></h1>
        <a href="<?= $e($plugin->adminUrl('/')) ?>"><?= $e($a('a.back')) ?></a>
    </div>
    <form method="post" action="<?= $e($plugin->adminUrl($action)) ?>" class="blog-form">
        <?= $plugin->csrfField() ?>
        <?php if ($multi): ?><p class="blog-muted"><?= $e($a('a.lang_help', ['locale' => $locales[0]])) ?></p><?php endif; ?>
        <?php foreach ($locales as $i => $locale): ?>
            <fieldset class="blog-lang" lang="<?= $e($locale) ?>">
                <?php if ($multi): ?><legend><?= $e($localeNames[$locale] ?? $locale) ?></legend><?php endif; ?>
                <label class="blog-field"><span><?= $e($label('a.field_title', $locale)) ?></span>
                    <input type="text" name="title[<?= $e($locale) ?>]" value="<?= $e($post['title'][$locale] ?? '') ?>" maxlength="<?= Blog\Repository::TITLE_MAX ?>"<?= $i === 0 ? ' required' : '' ?>></label>
                <label class="blog-field"><span><?= $e($label('a.field_excerpt', $locale)) ?></span>
                    <textarea name="excerpt[<?= $e($locale) ?>]" rows="2" maxlength="<?= Blog\Repository::EXCERPT_MAX ?>"><?= $e($post['excerpt'][$locale] ?? '') ?></textarea>
                    <small class="blog-muted"><?= $e($a('a.field_excerpt_help')) ?></small></label>
                <label class="blog-field"><span><?= $e($label('a.field_body', $locale)) ?></span>
                    <textarea name="body[<?= $e($locale) ?>]" rows="16" class="blog-body"><?= $e($post['body'][$locale] ?? '') ?></textarea></label>
            </fieldset>
        <?php endforeach; ?>
        <div class="blog-grid">
            <label class="blog-field"><span><?= $e($a('a.field_slug')) ?></span>
                <input type="text" name="slug" value="<?= $e($post['slug']) ?>" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="<?= Blog\Repository::SLUG_MAX ?>">
                <small class="blog-muted"><?= $e($a('a.field_slug_help')) ?></small></label>
            <label class="blog-field"><span><?= $e($a('a.field_date')) ?></span>
                <input type="date" name="date" value="<?= $e($post['date']) ?>"></label>
            <label class="blog-field"><span><?= $e($a('a.field_category')) ?></span>
                <select name="category">
                    <option value=""><?= $e($a('a.field_no_category')) ?></option>
                    <?php foreach ($categories as $slug => $cat): ?>
                        <option value="<?= $e($slug) ?>"<?= $post['category'] === $slug ? ' selected' : '' ?>><?= $e($plugin->localized($cat['name'], $locales[0]) ?? $slug) ?></option>
                    <?php endforeach; ?>
                </select></label>
        </div>
        <fieldset class="blog-tags">
            <legend><?= $e($a('a.field_tags')) ?></legend>
            <?php if ($tags === []): ?>
                <p class="blog-muted"><?= $e($a('a.no_tags_hint')) ?></p>
            <?php endif; ?>
            <?php foreach ($tags as $slug => $tag): ?>
                <label class="blog-check"><input type="checkbox" name="tags[]" value="<?= $e($slug) ?>"<?= in_array($slug, $post['tags'], true) ? ' checked' : '' ?>> <?= $e($plugin->localized($tag['name'], $locales[0]) ?? $slug) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <label class="blog-check"><input type="checkbox" name="published" value="1"<?= !empty($post['published']) ? ' checked' : '' ?>> <?= $e($a('a.field_published')) ?></label>
        <div class="blog-buttons">
            <button type="submit" class="blog-btn"><?= $e($a($editing === null ? 'a.create' : 'a.save')) ?></button>
            <?php if ($editing !== null): ?>
                <a class="blog-btn blog-btn--ghost" href="<?= $e($plugin->publicUrl('/' . $editing)) ?>" target="_blank" rel="noopener"><?= $e($a('a.view')) ?></a>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($editing !== null): ?>
        <form method="post" action="<?= $e($plugin->adminUrl('/posts/' . $editing . '/delete')) ?>" class="blog-delete" onsubmit="return confirm(<?= $e(json_encode($a('a.confirm_delete'), JSON_UNESCAPED_UNICODE)) ?>)">
            <?= $plugin->csrfField() ?>
            <button type="submit" class="blog-btn blog-btn--danger"><?= $e($a('a.delete')) ?></button>
        </form>
    <?php endif; ?>

<?php else: /* categories | tags */ ?>
    <?php
    $base = '/' . $kind;
    $isCat = $kind === 'categories';
    $action = $editing === null ? $base : $base . '/' . $editing;
    ?>
    <?php if ($editing === null): ?>
        <h1 class="blog-h1"><?= $e($a($isCat ? 'a.categories' : 'a.tags')) ?></h1>
        <?php if ($terms === []): ?>
            <p class="blog-muted"><?= $e($a('a.no_terms')) ?></p>
        <?php else: ?>
            <table class="blog-table">
                <thead><tr><th><?= $e($a('a.col_name')) ?></th><th><?= $e($a('a.col_slug')) ?></th><th><?= $e($a('a.col_posts')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($terms as $slug => $term): ?>
                    <tr>
                        <td><a href="<?= $e($plugin->adminUrl($base . '/' . $slug)) ?>"><?= $e($plugin->localized($term['name'], $locales[0]) ?? $slug) ?></a></td>
                        <td><code><?= $e($slug) ?></code></td>
                        <td><?= (int) ($counts[$slug] ?? 0) ?></td>
                        <td class="blog-actions">
                            <a href="<?= $e($plugin->adminUrl($base . '/' . $slug)) ?>"><?= $e($a('a.edit')) ?></a>
                            <form method="post" action="<?= $e($plugin->adminUrl($base . '/' . $slug . '/delete')) ?>" onsubmit="return confirm(<?= $e(json_encode($a('a.confirm_delete'), JSON_UNESCAPED_UNICODE)) ?>)">
                                <?= $plugin->csrfField() ?>
                                <button type="submit" class="blog-link-danger"><?= $e($a('a.delete')) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <h2 class="blog-h2"><?= $e($a($isCat ? 'a.new_category' : 'a.new_tag')) ?></h2>
    <?php else: ?>
        <div class="blog-head">
            <h1 class="blog-h1"><?= $e($a($isCat ? 'a.edit_category' : 'a.edit_tag')) ?>: <?= $e($editing) ?></h1>
            <a href="<?= $e($plugin->adminUrl($base)) ?>"><?= $e($a('a.back')) ?></a>
        </div>
    <?php endif; ?>
    <form method="post" action="<?= $e($plugin->adminUrl($action)) ?>" class="blog-form blog-form--term">
        <?= $plugin->csrfField() ?>
        <?php foreach ($locales as $i => $locale): ?>
            <label class="blog-field"><span><?= $e($label('a.field_name', $locale)) ?></span>
                <input type="text" name="name[<?= $e($locale) ?>]" value="<?= $e($form['name'][$locale] ?? '') ?>" maxlength="<?= Blog\Repository::NAME_MAX ?>"<?= $i === 0 ? ' required' : '' ?>></label>
        <?php endforeach; ?>
        <label class="blog-field"><span><?= $e($a('a.field_slug')) ?></span>
            <input type="text" name="slug" value="<?= $e($form['slug']) ?>" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="<?= Blog\Repository::SLUG_MAX ?>">
            <small class="blog-muted"><?= $e($a('a.field_slug_help')) ?></small></label>
        <div class="blog-buttons">
            <button type="submit" class="blog-btn"><?= $e($a($editing === null ? 'a.create' : 'a.save')) ?></button>
            <?php if ($editing !== null): ?>
                <a class="blog-btn blog-btn--ghost" href="<?= $e($plugin->publicUrl(($isCat ? '/category/' : '/tag/') . $editing)) ?>" target="_blank" rel="noopener"><?= $e($a('a.view')) ?></a>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>
</div>
