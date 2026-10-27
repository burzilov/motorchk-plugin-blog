<?php
/** @var PluginContext $plugin */
/** @var callable(string, array=): string $t */
/** @var array<string, mixed> $post запись; body_html — HTML из Markdown */

$e = [PluginContext::class, 'e'];
?>
<article class="blog blog--post">
    <h1 class="blog-title"><?= $e($post['title']) ?></h1>
    <?= $plugin->partial('_meta', ['post' => $post, 't' => $t]) ?>
    <div class="blog-body">
        <?= $post['body_html'] ?>
    </div>
    <p class="blog-back"><a href="<?= $e($listUrl) ?>"><?= $e($t('all_posts')) ?></a></p>
</article>
