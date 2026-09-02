<?php
require_once __DIR__ . '/_helpers.php';
$data = ml_theme_data($bms_theme_data ?? []);
$items = is_array($data['items'] ?? null) ? $data['items'] : [];
$conversation = !empty($data['conversation']);
$csrf = (string)($data['csrf'] ?? '');
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
ml_open_document($data, [
    'fallback_title' => $conversation ? 'Conversation' : 'Following',
    'og_type' => 'website',
    'body_class' => 'following-template',
    'main_class' => 'site-main stream-shell timeline ledger-stream-shell following-shell ledger-following-shell',
]);
?>
        <?= (string)($data['notice_html'] ?? '') ?>

        <?php if ($conversation): ?>
          <nav class="following-conversation-nav" aria-label="Following navigation">
            <a class="stream-meta-pill following-back" href="<?= $h((string)($data['following_url'] ?? '')) ?>">Back to Following</a>
          </nav>
        <?php endif; ?>

        <?php if ($conversation && empty($data['conversation_found'])): ?>
          <section class="following-empty stream-state-card">
            <h2>Conversation not found</h2>
            <p>The requested remote object is unavailable, blocked, or outside an accepted Following relationship.</p>
          </section>
        <?php elseif (!$items): ?>
          <section class="following-empty stream-state-card">
            <h2>No remote posts are cached yet</h2>
            <p>Posts delivered by actors you follow will appear here after their signed activities pass Bonumark’s federation boundary.</p>
          </section>
        <?php else: ?>
          <section class="following-feed" aria-label="<?= $conversation ? 'Remote conversation' : 'Following timeline' ?>">
            <?php foreach ($items as $item):
              $deleted = (string)($item['lifecycle_state'] ?? '') === 'deleted';
              $like = is_array($item['like'] ?? null) ? $item['like'] : [];
              $announce = is_array($item['announce'] ?? null) ? $item['announce'] : [];
              $media = is_array($item['media'] ?? null) ? $item['media'] : [];
              $replyFieldId = 'following_reply_body_' . substr(hash('sha256', (string)($item['object_uri'] ?? '')), 0, 12);
            ?>
              <article class="following-card stream-card ledger-stream-card<?= $deleted ? ' is-deleted' : '' ?>">
                <div class="following-card-inner stream-card-inner">
                  <div class="following-avatar stream-card-avatar" aria-hidden="true">
                    <?php if ((string)($item['actor_avatar_url'] ?? '') !== ''): ?>
                      <img class="stream-author-image" src="<?= $h((string)$item['actor_avatar_url']) ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                    <?php else: ?>
                      <span class="stream-author-initials"><?= $h(strtoupper(bms_text_substr((string)($item['actor_name'] ?? 'R'), 0, 1))) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="following-card-main stream-card-main">
                    <header class="following-card-header stream-card-headerline">
                      <a class="following-actor stream-card-author" href="<?= $h((string)($item['actor_uri'] ?? '')) ?>" rel="nofollow noopener noreferrer">
                        <?= $h((string)($item['actor_name'] ?? 'Remote actor')) ?>
                      </a>
                      <?php if ((string)($item['actor_handle'] ?? '') !== ''): ?><span class="following-handle"><?= $h((string)$item['actor_handle']) ?></span><?php endif; ?>
                      <span class="stream-card-separator" aria-hidden="true">&middot;</span>
                      <a class="following-time stream-card-datetime stream-card-permalink stream-permalink" href="<?= $h((string)($item['permalink'] ?? '')) ?>" rel="nofollow noopener noreferrer">
                        <time<?= (string)($item['published_datetime'] ?? '') !== '' ? ' datetime="' . $h((string)$item['published_datetime']) . '"' : '' ?>><?= $h((string)($item['published_label'] ?? 'Time unavailable')) ?></time>
                      </a>
                    </header>

                    <?php if ($deleted): ?>
                      <div class="following-tombstone"><strong>Deleted remote post</strong><p>This object remains tombstoned and cannot be revived by a stale Create or Update.</p></div>
                    <?php else: ?>
                      <?php if (!empty($item['sensitive']) && (string)($item['summary'] ?? '') !== ''): ?>
                        <details class="following-sensitive">
                          <summary><?= $h((string)$item['summary']) ?></summary>
                          <div class="following-content stream-card-content"><?= (string)($item['content_html'] ?? '') ?></div>
                        </details>
                      <?php else: ?>
                        <?php if ((string)($item['summary'] ?? '') !== ''): ?><p class="following-summary"><?= $h((string)$item['summary']) ?></p><?php endif; ?>
                        <div class="following-content stream-card-content"><?= (string)($item['content_html'] ?? '') ?></div>
                      <?php endif; ?>

                      <?php if ($media): ?>
                        <div class="following-media<?= count($media) > 1 ? ' is-gallery' : '' ?>">
                          <?php foreach ($media as $attachment): ?>
                            <?php if ((string)($attachment['kind'] ?? '') === 'image'): ?>
                              <a href="<?= $h((string)$attachment['url']) ?>" rel="nofollow noopener noreferrer">
                                <img src="<?= $h((string)$attachment['url']) ?>" alt="<?= $h((string)($attachment['alt_text'] ?? '')) ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer"<?= (int)($attachment['width'] ?? 0) > 0 ? ' width="' . (int)$attachment['width'] . '"' : '' ?><?= (int)($attachment['height'] ?? 0) > 0 ? ' height="' . (int)$attachment['height'] . '"' : '' ?>>
                              </a>
                            <?php elseif ((string)($attachment['kind'] ?? '') === 'video'): ?>
                              <video controls preload="metadata" playsinline referrerpolicy="no-referrer"><source src="<?= $h((string)$attachment['url']) ?>" type="<?= $h((string)($attachment['media_type'] ?? '')) ?>"></video>
                              <?php if ((string)($attachment['alt_text'] ?? '') !== ''): ?><p class="following-media-alt"><?= $h((string)$attachment['alt_text']) ?></p><?php endif; ?>
                            <?php else: ?>
                              <audio controls preload="metadata"><source src="<?= $h((string)$attachment['url']) ?>" type="<?= $h((string)($attachment['media_type'] ?? '')) ?>"></audio>
                              <?php if ((string)($attachment['alt_text'] ?? '') !== ''): ?><p class="following-media-alt"><?= $h((string)$attachment['alt_text']) ?></p><?php endif; ?>
                            <?php endif; ?>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>

                    <div class="following-meta stream-card-meta">
                      <div class="stream-card-tags"></div>
                      <div class="following-actions stream-card-actions">
                        <a class="stream-meta-pill" href="<?= $h((string)($item['conversation_url'] ?? '')) ?>">Conversation</a>
                        <a class="stream-meta-pill" href="<?= $h((string)($item['permalink'] ?? '')) ?>" rel="nofollow noopener noreferrer">Remote post</a>
                        <?php if (!$deleted): ?>
                          <details class="following-reply-control">
                            <summary class="stream-meta-pill">Reply</summary>
                            <form method="post" class="following-reply-form comment-form">
                              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                              <input type="hidden" name="following_action" value="reply">
                              <input type="hidden" name="object_uri" value="<?= $h((string)$item['object_uri']) ?>">
                              <label for="<?= $h($replyFieldId) ?>">Add a reply</label>
                              <textarea id="<?= $h($replyFieldId) ?>" name="reply_body" rows="4" maxlength="2097152" required></textarea>
                              <div class="comment-form-actions">
                                <button type="submit">Create Reply Draft</button>
                              </div>
                            </form>
                          </details>

                          <form method="post" class="following-action-form">
                            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                            <input type="hidden" name="object_uri" value="<?= $h((string)$item['object_uri']) ?>">
                            <input type="hidden" name="following_action" value="<?= !empty($like['active']) ? 'unlike' : 'like' ?>">
                            <?php if (!empty($like['active'])): ?><input type="hidden" name="interaction_id" value="<?= (int)($like['interaction_id'] ?? 0) ?>"><?php endif; ?>
                            <button type="submit" class="stream-meta-pill<?= !empty($like['active']) ? ' is-active' : '' ?>"><?= !empty($like['active']) ? 'Unlike' : 'Like' ?></button>
                          </form>

                          <form method="post" class="following-action-form">
                            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                            <input type="hidden" name="object_uri" value="<?= $h((string)$item['object_uri']) ?>">
                            <input type="hidden" name="following_action" value="<?= !empty($announce['active']) ? 'unboost' : 'boost' ?>">
                            <?php if (!empty($announce['active'])): ?><input type="hidden" name="interaction_id" value="<?= (int)($announce['interaction_id'] ?? 0) ?>"><?php endif; ?>
                            <button type="submit" class="stream-meta-pill<?= !empty($announce['active']) ? ' is-active' : '' ?>"><?= !empty($announce['active']) ? 'Unboost' : 'Boost' ?></button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php if ((string)($like['error'] ?? '') !== ''): ?><p class="following-action-error">Like delivery: <?= $h((string)$like['error']) ?></p><?php endif; ?>
                    <?php if ((string)($announce['error'] ?? '') !== ''): ?><p class="following-action-error">Boost delivery: <?= $h((string)$announce['error']) ?></p><?php endif; ?>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </section>
        <?php endif; ?>
<?php ml_close_document($data); ?>
