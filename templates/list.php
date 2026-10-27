<?php
/** @var PluginContext $plugin */
/** @var callable(string, array=): string $t строка на языке страницы */
/** @var string $heading */
/** @var list<array<string, mixed>> $posts */

$e = [PluginContext::class, 'e'];
?>
<div class="blog blog--list">
    <h1 class="blog-title"><?= $e($heading) ?></h1>
    <?php if ($isFiltered): ?>
        <p class="blog-back"><a href="<?= $e($listUrl) ?>"><?= $e($t('all_posts')) ?></a></p>
    <?php endif; ?>

    <?php if ($posts === []): ?>
        <p class="blog-empty"><?= $e($t('empty')) ?></p>
    <?php endif; ?>

    <?php foreach ($posts as $post): ?>
        <article class="blog-card">
            <h2 class="blog-card__title"><a href="<?= $e($post['url']) ?>"><?= $e($post['title']) ?></a></h2>
            <?= $plugin->partial('_meta', ['post' => $post, 't' => $t]) ?>
            <?php if ($post['excerpt'] !== ''): ?>
                <p class="blog-card__excerpt"><?= nl2br($e($post['excerpt'])) ?></p>
            <?php endif; ?>
            <p class="blog-card__more"><a href="<?= $e($post['url']) ?>"><?= $e($t('read_more')) ?></a></p>
        </article>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
        <nav class="blog-pagination" aria-label="<?= $e($t('pagination')) ?>">
            <?php if ($newerUrl !== null): ?><a class="blog-pagination__newer" href="<?= $e($newerUrl) ?>" rel="prev"><?= $e($t('newer')) ?></a><?php endif; ?>
            <span class="blog-pagination__info"><?= $e($t('page_of', ['page' => $page, 'pages' => $pages])) ?></span>
            <?php if ($olderUrl !== null): ?><a class="blog-pagination__older" href="<?= $e($olderUrl) ?>" rel="next"><?= $e($t('older')) ?></a><?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
