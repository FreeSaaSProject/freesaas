<?php
declare(strict_types=1);
/** @var array<string,mixed> $client */
/** @var string $scope */
/** @var string $redirect_uri */
/** @var string $state */
/** @var string $response_type */
/** @var string $client_id */
/** @var string $code_challenge */
/** @var string $code_challenge_method */
/** @var string $scope_raw */
?>
<section class="auth-card">
  <h1><?= te('llm.oauth.authorize.title') ?></h1>
  <p class="lede"><?= te('llm.oauth.authorize.lede', [
      'app' => (string)($client['name'] ?? ''),
      'user' => Auth::displayName(),
  ]) ?></p>
  <p><strong><?= te('llm.oauth.authorize.scopes') ?>:</strong> <code><?= e($scope) ?></code></p>
  <ul class="muted">
    <?php if (strpos($scope, 'ops.read') !== false): ?>
      <li><?= te('llm.oauth.scope.read') ?></li>
    <?php endif; ?>
    <?php if (strpos($scope, 'ops.write') !== false): ?>
      <li><?= te('llm.oauth.scope.write') ?></li>
    <?php endif; ?>
  </ul>
  <p class="muted"><small><?= te('llm.oauth.authorize.redirect') ?>: <?= e($redirect_uri) ?></small></p>

  <form method="post" class="stack" data-nav-no-ret>
    <input type="hidden" name="response_type" value="<?= e($response_type) ?>">
    <input type="hidden" name="client_id" value="<?= e($client_id) ?>">
    <input type="hidden" name="redirect_uri" value="<?= e($redirect_uri) ?>">
    <input type="hidden" name="scope" value="<?= e($scope_raw) ?>">
    <input type="hidden" name="state" value="<?= e($state) ?>">
    <input type="hidden" name="code_challenge" value="<?= e($code_challenge) ?>">
    <input type="hidden" name="code_challenge_method" value="<?= e($code_challenge_method) ?>">
    <p class="btn-row">
      <button type="submit" name="action" value="allow" class="btn"><?= te('llm.oauth.authorize.allow') ?></button>
      <button type="submit" name="action" value="deny" class="btn"><?= te('llm.oauth.authorize.deny') ?></button>
    </p>
  </form>
</section>
