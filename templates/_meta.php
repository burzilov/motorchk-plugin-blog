<?php
/** @var array<string, mixed> $post запись для шаблона (Blog\Site::view) */
/** @var callable(string, array=): string $t */

$e = [PluginContext::class, 'e'];
?>
<p class="blog-meta">
    <time class="blog-meta__date" datetime="<?= $e($post['date']) ?>"><?= $e($post['date_label']) ?></time>
    <?php if ($post['category'] !== null): ?>
        <span class="blog-meta__category"><span class="blog-meta__label"><?= $e($t('category')) ?>:</span> <a href="<?= $e($post['category']['url']) ?>"><?= $e($post['category']['name']) ?></a></span>
    <?php endif; ?>
    <?php if ($post['tags'] !== []): ?>
        <span class="blog-meta__tags"><span class="blog-meta__label"><?= $e($t('tags')) ?>:</span>
            <?php foreach ($post['tags'] as $i => $tag): ?><?= $i > 0 ? ', ' : '' ?><a class="blog-tag" href="<?= $e($tag['url']) ?>" rel="tag"><?= $e($tag['name']) ?></a><?php endforeach; ?>
        </span>
    <?php endif; ?>
</p>
