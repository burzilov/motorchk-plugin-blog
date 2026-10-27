<?php
/** @var callable(string, array=): string $t */

$e = [PluginContext::class, 'e'];
?>
<div class="blog blog--not-found">
    <h1 class="blog-title"><?= $e($t('not_found')) ?></h1>
    <p><?= $e($t('not_found_text')) ?></p>
    <p class="blog-back"><a href="<?= $e($listUrl) ?>"><?= $e($t('all_posts')) ?></a></p>
</div>
