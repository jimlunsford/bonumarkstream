<?php
require_once __DIR__ . '/media.php';

function mp_editor_request_path_for_autosave(): string
{
    $path = parse_url((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    return $path ?: '';
}

function mp_editor_autosave_key(string $mode, string $file = ''): string
{
    $path = mp_editor_request_path_for_autosave();
    if ($mode === 'edit' && $file !== '') {
        return 'bonumark-autosave:' . $path . ':file:' . basename($file);
    }
    return 'bonumark-autosave:' . $path . ':new-stream-post';
}

function mp_editor_autosave_saved_at(?string $path = null): string
{
    if (!$path || !is_file($path)) {
        return '';
    }
    $timestamp = filemtime($path);
    return $timestamp ? date('c', $timestamp) : '';
}

function mp_clear_submitted_autosave(): void
{
    $key = trim((string)($_POST['autosave_key'] ?? ''));
    if ($key !== '' && function_exists('mp_delete_autosave')) {
        mp_delete_autosave($key);
    }
}

function mp_editor_media_payload(array $media): array
{
    $url = function_exists('mp_media_public_url_for_item') ? mp_media_public_url_for_item($media) : '';
    $alt = trim((string)($media['alt_text'] ?? ''));
    $label = trim((string)($media['original_filename'] ?? $media['filename'] ?? 'Media'));
    if ($alt === '') {
        $alt = $label;
    }
    $mime = function_exists('mp_media_mime_type') ? mp_media_mime_type($media) : strtolower((string)($media['mime_type'] ?? ''));
    $kind = function_exists('mp_media_kind_label') ? mp_media_kind_label($media) : 'Media';
    $markdown = function_exists('mp_media_markdown') ? mp_media_markdown($media) : '[' . $label . '](' . $url . ')';

    return [
        'id' => (int)($media['id'] ?? 0),
        'url' => $url,
        'alt' => $alt,
        'caption' => (string)($media['caption'] ?? ''),
        'label' => $label,
        'filename' => (string)($media['filename'] ?? ''),
        'mime' => $mime,
        'kind' => $kind,
        'markdown' => $markdown,
        'size' => function_exists('mp_media_human_size') ? mp_media_human_size((int)($media['file_size'] ?? 0)) : '',
        'width' => (int)($media['width'] ?? 0),
        'height' => (int)($media['height'] ?? 0),
        'search' => strtolower(trim($label . ' ' . $alt . ' ' . (string)($media['caption'] ?? '') . ' ' . (string)($media['filename'] ?? '') . ' ' . $kind . ' ' . $mime)),
    ];
}

function mp_editor_media_item_button(array $media): void
{
    $payload = mp_editor_media_payload($media);
    $isImage = str_starts_with($payload['mime'], 'image/');
    $dimensions = ($payload['width'] > 0 && $payload['height'] > 0) ? ($payload['width'] . '×' . $payload['height'] . ' · ') : '';
    ?>
    <button type="button"
      class="media-picker-item"
      data-insert-media
      data-media-id="<?= (int)$payload['id'] ?>"
      data-media-url="<?= htmlspecialchars($payload['url'], ENT_QUOTES, 'UTF-8') ?>"
      data-media-alt="<?= htmlspecialchars($payload['alt'], ENT_QUOTES, 'UTF-8') ?>"
      data-media-caption="<?= htmlspecialchars($payload['caption'], ENT_QUOTES, 'UTF-8') ?>"
      data-media-label="<?= htmlspecialchars($payload['label'], ENT_QUOTES, 'UTF-8') ?>"
      data-media-mime="<?= htmlspecialchars($payload['mime'], ENT_QUOTES, 'UTF-8') ?>"
      data-media-kind="<?= htmlspecialchars($payload['kind'], ENT_QUOTES, 'UTF-8') ?>"
      data-media-markdown="<?= htmlspecialchars($payload['markdown'], ENT_QUOTES, 'UTF-8') ?>"
      data-media-search-text="<?= htmlspecialchars($payload['search'], ENT_QUOTES, 'UTF-8') ?>">
      <span class="media-picker-thumb">
        <?php if ($isImage): ?>
          <img src="<?= htmlspecialchars($payload['url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($payload['alt'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
        <?php else: ?>
          <span class="media-file-badge"><?= htmlspecialchars($payload['kind'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
      </span>
      <span class="media-picker-item-title"><?= htmlspecialchars($payload['label'], ENT_QUOTES, 'UTF-8') ?></span>
      <span class="media-picker-item-meta"><?= htmlspecialchars($payload['kind'] . ' · ' . $dimensions . $payload['size'], ENT_QUOTES, 'UTF-8') ?></span>
    </button>
    <?php
}

function mp_editor_media_picker_markup(): void
{
    $items = function_exists('mp_media_list') ? mp_media_list(120) : [];
    $accept = function_exists('mp_allowed_media_accept_attribute') ? mp_allowed_media_accept_attribute() : 'image/*,audio/*,video/*,.pdf,.doc,.docx,.txt';
    $allowed = function_exists('mp_allowed_media_extensions_label') ? mp_allowed_media_extensions_label() : 'JPG, PNG, GIF, WebP, MP3, M4A, WAV, OGG, MP4, WebM, MOV, PDF, DOC, DOCX, and TXT';
    $endpoint = mp_admin_url('media-picker.php');
    ?>
    <div class="media-picker" data-media-picker data-media-endpoint="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>" data-media-csrf="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>" hidden aria-hidden="true">
      <div class="media-picker-backdrop" data-close-media-picker></div>
      <section class="media-picker-panel" role="dialog" aria-modal="true" aria-labelledby="media-picker-title" aria-describedby="media-picker-description" tabindex="-1">
        <header class="media-picker-header">
          <div>
            <p class="eyebrow">Media</p>
            <h3 id="media-picker-title">Add Media</h3>
            <p class="meta" id="media-picker-description">Upload a new file or insert something already in the library without leaving the editor.</p>
          </div>
          <button type="button" class="secondary-button" data-close-media-picker aria-label="Close media window">Close</button>
        </header>

        <div class="media-picker-tabs" role="tablist" aria-label="Media tools">
          <button type="button" id="media-tab-upload" class="editor-tab active" role="tab" data-media-tab="upload" aria-selected="true" aria-controls="media-panel-upload">Upload Files</button>
          <button type="button" id="media-tab-library" class="editor-tab" role="tab" data-media-tab="library" aria-selected="false" aria-controls="media-panel-library">Media Library</button>
        </div>

        <div class="media-picker-panel-body">
          <section id="media-panel-upload" class="media-picker-tab-panel active" role="tabpanel" data-media-panel="upload" aria-labelledby="media-tab-upload">
            <div class="media-quick-upload" data-media-upload-form>
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
              <label for="media_quick_file">Media file</label>
              <div class="media-upload-dropzone" data-media-dropzone>
                <input id="media_quick_file" type="file" name="media_file" accept="<?= htmlspecialchars($accept, ENT_QUOTES, 'UTF-8') ?>">
                <strong>Drop a file here or choose one from your computer.</strong>
                <span>Supported formats: <?= htmlspecialchars($allowed, ENT_QUOTES, 'UTF-8') ?>. Maximum size: <?= function_exists('mp_current_media_upload_limit_mb') ? (int)mp_current_media_upload_limit_mb() : 8 ?> MB for your role.</span>
              </div>

              <div class="media-upload-meta-grid">
                <div>
                  <label for="media_quick_alt">Alt text / description</label>
                  <input id="media_quick_alt" type="text" name="alt_text" maxlength="255" placeholder="Describe the media">
                </div>
                <div>
                  <label for="media_quick_caption">Caption</label>
                  <input id="media_quick_caption" type="text" name="caption" maxlength="500" placeholder="Optional caption">
                </div>
              </div>

              <div class="media-upload-actions">
                <button type="button" class="primary-button" data-media-upload-insert>Upload and Insert</button>
                <button type="button" class="secondary-button" data-media-upload-only>Upload Only</button>
              </div>
              <p class="field-help" data-media-upload-status aria-live="polite"></p>
            </div>
          </section>

          <section id="media-panel-library" class="media-picker-tab-panel" role="tabpanel" data-media-panel="library" aria-labelledby="media-tab-library" hidden>
            <div class="media-picker-tools">
              <div>
                <label class="sr-only" for="media_picker_search">Search media</label>
                <input id="media_picker_search" type="search" data-media-search placeholder="Search media">
              </div>
              <div>
                <label for="media_picker_alt">Alt text / link text override</label>
                <input id="media_picker_alt" type="text" data-media-alt-input placeholder="Optional override before insert">
              </div>
              <div>
                <label for="media_picker_caption">Caption override</label>
                <input id="media_picker_caption" type="text" data-media-caption-input placeholder="Optional caption">
              </div>
            </div>

            <div class="media-library-status" data-media-library-status aria-live="polite"></div>
            <h4 class="media-picker-subhead">Recent uploads</h4>
            <div class="media-picker-grid" data-media-library-grid>
              <?php if (!$items): ?>
                <div class="empty-state compact-empty-state" data-media-empty-state>
                  <h3>No media yet.</h3>
                  <p class="meta">Upload a file from this window, then insert it directly into the post.</p>
                </div>
              <?php else: ?>
                <?php foreach ($items as $media): ?>
                  <?php mp_editor_media_item_button($media); ?>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </section>
        </div>
      </section>
    </div>
    <?php
}

function mp_stream_field_value(array $page, string $key, string $default = ''): string
{
    $value = $page[$key] ?? ($page['front_matter'][$key] ?? $default);
    if (is_array($value)) {
        $value = implode(', ', $value);
    }
    return (string)$value;
}


function mp_stream_title_fields(array $page): void
{
    $title = mp_stream_field_value($page, 'title', '');
    ?>
    <section class="editor-title-card" aria-label="Title">
      <label class="sr-only" for="stream_title">Title</label>
      <input class="editor-title-input" type="text" id="stream_title" name="stream_title" value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>" maxlength="180" placeholder="Generated from post text if blank">
      <p class="field-help">Optional. Leave blank to generate an internal title from the post text or attached media without adding a prefix.</p>
    </section>
    <?php
}

function mp_stream_url_fields(array $page, string $section = 'drafts'): void
{
    $slug = mp_stream_field_value($page, 'slug', '');
    $cleanSlug = $slug !== '' ? mp_slugify($slug) : 'generated-on-save';
    $displaySlug = $cleanSlug !== '' ? $cleanSlug : 'generated-on-save';
    $relativeBase = rtrim(mp_url_path('stream'), '/') . '/';
    $absoluteBase = rtrim(mp_site_url('stream'), '/') . '/';
    $relativeUrl = $relativeBase . $displaySlug . '/';
    $absoluteUrl = $absoluteBase . $displaySlug . '/';
    $hasSavedSlug = trim($slug) !== '' && $displaySlug !== 'generated-on-save';
    $isPublished = $section === 'published' && $hasSavedSlug;
    $urlLabel = !$hasSavedSlug ? 'URL Preview' : ($isPublished ? 'Final URL' : 'Draft URL');
    $urlHelp = !$hasSavedSlug
        ? 'Save the draft to create the final URL.'
        : ($isPublished ? 'This is the live public URL for this Stream Post.' : 'This draft preview URL exists for logged-in previewing. The post is not live until published.');
    ?>
    <section class="side-card permalink-card" data-editor-card="post-url" aria-label="Post URL">
      <h3>Post URL</h3>
      <div class="permalink-preview-row">
        <span><?= htmlspecialchars($urlLabel, ENT_QUOTES, 'UTF-8') ?></span>
        <code data-permalink-preview data-permalink-base="<?= htmlspecialchars($relativeBase, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($relativeUrl, ENT_QUOTES, 'UTF-8') ?></code>
      </div>

      <label for="stream_final_url"><?= htmlspecialchars($urlLabel, ENT_QUOTES, 'UTF-8') ?></label>
      <div class="permalink-copy-row">
        <input class="readonly-field permalink-final-url" type="text" id="stream_final_url" readonly value="<?= htmlspecialchars($absoluteUrl, ENT_QUOTES, 'UTF-8') ?>" data-final-url-input data-final-url-base="<?= htmlspecialchars($absoluteBase, ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($hasSavedSlug): ?>
          <button type="button" class="secondary-button compact-button" data-copy-target="stream_final_url">Copy</button>
        <?php endif; ?>
      </div>
      <p class="field-help permalink-status-help" data-permalink-status-help><?= htmlspecialchars($urlHelp, ENT_QUOTES, 'UTF-8') ?></p>

      <label for="stream_slug">Slug</label>
      <input class="editor-slug-input" type="text" id="stream_slug" name="stream_slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" maxlength="180" placeholder="Generated on save" data-original-slug="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" data-is-published="<?= $isPublished ? '1' : '0' ?>">
      <p class="field-help">The slug controls the public Stream Post URL. Leave blank to generate a clean URL from the title, first heading, post text, or attached media.</p>
      <?php if ($isPublished): ?>
        <div class="slug-change-warning" data-slug-change-warning hidden>
          <strong>Changing this slug changes the live URL.</strong>
          <p>Bonumark will update the saved URL after save. Make sure links pointing to the old URL are updated.</p>
          <label class="checkbox-line"><input type="checkbox" name="confirm_slug_change" value="1" data-confirm-slug-change> I understand this changes the live URL.</label>
        </div>
      <?php endif; ?>
    </section>
    <?php
}

function mp_stream_settings_fields(array $page, string $section): void
{
    $status = $section === 'published' ? 'published' : 'draft';
    ?>
    <section class="side-card" data-editor-card="stream-post" aria-label="Stream post settings">
      <h3>Stream Post</h3>
      <input type="hidden" name="stream_content_type" value="stream">
      <input type="hidden" name="stream_category" value="Stream">
      <input type="hidden" name="stream_tags" value="">

      <label for="stream_date">Date</label>
      <input type="date" id="stream_date" name="stream_date" value="<?= htmlspecialchars(mp_stream_field_value($page, 'date', date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?>" required>

      <label for="stream_description">Meta description</label>
      <textarea class="small-textarea" id="stream_description" name="stream_description" maxlength="300"><?= htmlspecialchars(mp_stream_field_value($page, 'description', ''), ENT_QUOTES, 'UTF-8') ?></textarea>
      <p class="field-help">Optional. If empty, Bonumark Stream generates one from the post text or media.</p>

      <label for="stream_seo_title">Search title</label>
      <input type="text" id="stream_seo_title" name="stream_seo_title" value="<?= htmlspecialchars(mp_stream_field_value($page, 'seo_title', ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="180" placeholder="Generated from post text if blank">
      <p class="field-help">Optional. Used for the HTML title, Open Graph title, and RSS title. If blank, Bonumark generates “post text | site title” and keeps it under 65 characters when possible.</p>

      <label for="stream_robots">Search indexing</label>
      <?php $robots = mp_stream_field_value($page, 'robots', ''); ?>
      <select id="stream_robots" name="stream_robots">
        <option value="" <?= $robots === '' ? 'selected' : '' ?>>Use reading setting</option>
        <option value="index,follow" <?= $robots === 'index,follow' ? 'selected' : '' ?>>Index this post</option>
        <option value="noindex,follow" <?= $robots === 'noindex,follow' ? 'selected' : '' ?>>Noindex this post</option>
      </select>
      <p class="field-help">Optional per-post override for individual stream post pages.</p>

      <input type="hidden" name="stream_status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="stream_created_at" value="<?= htmlspecialchars(mp_stream_field_value($page, 'stream_created_at', mp_stream_field_value($page, 'date', date('Y-m-d'))), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="featured_media" value="<?= htmlspecialchars(mp_stream_field_value($page, 'featured_media', ''), ENT_QUOTES, 'UTF-8') ?>">
    </section>
    <?php
}




function mp_stream_revision_fields(array $page): void
{
    $slug = (string)($page['slug'] ?? '');
    $count = 0;
    if ($slug !== '' && function_exists('mp_revision_count_for_slug')) {
        try {
            $count = mp_revision_count_for_slug($slug);
        } catch (Throwable $e) {
            $count = 0;
        }
    }
    $url = mp_admin_url('revisions.php' . ($slug !== '' ? '?slug=' . urlencode($slug) : ''));
    ?>
    <section class="side-card revisions-card" data-editor-card="revisions" aria-label="Revisions">
      <h3>Revisions</h3>
      <div class="source-row"><span>Saved versions</span><strong><?= (int)$count ?></strong></div>
      <p class="field-help">Bonumark Stream keeps database revision snapshots so you have a recovery path when content changes.</p>
      <a class="button-link secondary" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">View Revisions</a>
    </section>
    <?php
}

function mp_stream_media_fields(): void
{
    ?>
    <section class="side-card media-helper-card" data-editor-card="media" aria-label="Media tools">
      <h3>Media</h3>
      <p class="field-help">Add images, audio, video, and downloadable files without leaving the post editor.</p>
      <div class="side-card-actions">
        <button type="button" class="secondary-button full-width-button" data-open-media-picker>Add Media</button>
        <a class="button-link secondary full-width-button" href="<?= htmlspecialchars(mp_admin_url('media.php'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open Library</a>
      </div>
    </section>
    <?php
}


function mp_editor_screen_controls_action(): array
{
    ob_start();
    mp_editor_screen_controls();
    return [
        'html' => ob_get_clean(),
        'class' => 'editor-screen-controls-action',
    ];
}


function mp_editor_screen_controls(): void
{
    ?>
    <section class="editor-screen-controls" data-editor-screen-controls-shell aria-label="Editor screen controls">
      <button type="button" class="secondary-button compact-button screen-controls-toggle" data-screen-controls-toggle aria-expanded="false" aria-controls="editor-screen-controls-panel">Screen Controls</button>
      <div id="editor-screen-controls-panel" class="screen-controls-panel" data-screen-controls hidden>
        <div class="screen-controls-header">
          <div>
            <strong>Editor Screen</strong>
            <span>Choose what stays visible while you write. Preferences are remembered in this browser.</span>
          </div>
          <button type="button" class="secondary-button compact-button" data-screen-controls-close>Close</button>
        </div>

        <div class="screen-controls-grid">
          <fieldset>
            <legend>Sidebar cards</legend>
            <label><input type="checkbox" data-screen-card-toggle="post-url" checked> Post URL</label>
            <label><input type="checkbox" data-screen-card-toggle="stream-post" checked> Stream Post</label>
            <label><input type="checkbox" data-screen-card-toggle="media" checked> Media</label>
            <label><input type="checkbox" data-screen-card-toggle="revisions" checked> Revisions</label>
            <p class="field-help">The Publish card always stays visible.</p>
          </fieldset>

          <fieldset>
            <legend>Editor chrome</legend>
            <label><input type="checkbox" data-screen-toggle="metrics" checked> Show word and character counts</label>
            <label><input type="checkbox" data-screen-toggle="previewTools" checked> Show preview refresh buttons</label>
            <label><input type="checkbox" data-screen-toggle="stickySidebar" checked> Keep sidebar sticky on desktop</label>
          </fieldset>

          <fieldset>
            <legend>Layout</legend>
            <label for="editor_density">Density</label>
            <select id="editor_density" data-screen-density>
              <option value="comfortable">Comfortable</option>
              <option value="compact">Compact</option>
              <option value="spacious">Spacious</option>
            </select>
            <label for="editor_layout">Workspace</label>
            <select id="editor_layout" data-screen-layout>
              <option value="standard">Standard</option>
              <option value="wide">Wide writing area</option>
              <option value="single">Single column</option>
            </select>
          </fieldset>
        </div>

        <div class="screen-controls-actions">
          <button type="button" class="secondary-button compact-button" data-side-cards-open>Open all cards</button>
          <button type="button" class="secondary-button compact-button" data-side-cards-collapse>Collapse all cards</button>
          <button type="button" class="secondary-button compact-button" data-screen-controls-reset>Reset screen</button>
        </div>
      </div>
    </section>
    <?php
}

function mp_publish_sidebar(string $section, string $buttonLabel, string $helperText, array $context = []): void
{
    $isPublished = str_contains($section, 'published');
    $contentType = (string)($context['content_type'] ?? 'stream');
    $isPageContent = $contentType === 'page';
    $statusLabel = $isPublished ? 'Published' : 'Draft';
    $mode = (string)($context['mode'] ?? 'edit');
    $file = (string)($context['file'] ?? '');
    $page = is_array($context['page'] ?? null) ? $context['page'] : [];
    $isNew = $mode === 'new';
    $newDefaultStatus = (string)($context['default_status'] ?? ($isPublished ? 'published' : 'draft'));
    $requiresReview = !empty($context['requires_review']) || (function_exists('mp_current_user_requires_post_review') && mp_current_user_requires_post_review());
    $canPublishThis = function_exists('mp_current_user_can') ? mp_current_user_can('publish_content') : false;
    if (!$isNew && $file !== '' && function_exists('mp_content_subject_for_file') && function_exists('mp_current_user_can')) {
        $canPublishThis = mp_current_user_can('publish_content', mp_content_subject_for_file($section, $file, $page));
    }
    $previewType = $isPageContent ? ($isPublished ? 'page-published' : 'page-draft') : ($isPublished ? 'published' : 'draft');
    ?>
    <section class="side-card publish-card" data-editor-card="publish" aria-label="Publish settings">
      <h3>Publish</h3>
      <div class="status-line"><span>Status</span><strong><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>

      <?php if ($isNew): ?>
        <div class="publish-card-actions publish-card-new-actions" aria-label="New content actions">
          <?php if ($requiresReview): ?>
            <button type="submit" form="stream-editor-form" formnovalidate class="primary-button publish-main-button" data-editor-submit-button name="stream_submit_action" value="submit_review">Submit for Review</button>
            <button type="submit" form="stream-editor-form" formnovalidate class="secondary-button full-width-button" data-editor-submit-button name="stream_submit_action" value="draft">Save Draft</button>
            <p class="field-help publish-card-note">Your account can create drafts. An admin must approve publishing.</p>
          <?php else: ?>
            <button type="submit" form="stream-editor-form" formnovalidate class="primary-button publish-main-button" data-editor-submit-button name="stream_submit_action" value="<?= $newDefaultStatus === 'published' ? 'publish' : 'draft' ?>"><?= htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8') ?></button>
            <?php if ($newDefaultStatus === 'published'): ?>
              <button type="submit" form="stream-editor-form" formnovalidate class="secondary-button full-width-button" data-editor-submit-button name="stream_submit_action" value="draft">Save Draft</button>
            <?php else: ?>
              <button type="submit" form="stream-editor-form" formnovalidate class="secondary-button full-width-button" data-editor-submit-button name="stream_submit_action" value="publish"><?= $isPageContent ? 'Publish Page' : 'Post Now' ?></button>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="publish-card-actions" aria-label="Post actions">
          <?php if ($file !== ''): ?>
            <a class="button-link secondary full-width-button" href="<?= htmlspecialchars(mp_admin_url('preview.php?type=' . urlencode($previewType) . '&file=' . urlencode($file) . '&frame=1'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= $isPageContent ? 'Preview Page' : 'Preview Post' ?></a>
          <?php endif; ?>

          <?php if ($isPublished && $page): ?>
            <a class="button-link secondary full-width-button" href="<?= htmlspecialchars($isPageContent && function_exists('mp_page_url_for_page') ? mp_page_url_for_page($page) : mp_stream_url_for_post($page), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= $isPageContent ? 'View Page' : 'View Post' ?></a>
          <?php endif; ?>

          <button type="submit" form="stream-editor-form" formnovalidate class="primary-button publish-main-button" data-editor-submit-button><?= htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8') ?></button>

          <?php if (!$isPublished): ?>
            <?php if ($canPublishThis): ?>
              <button type="submit" class="secondary-button full-width-button" form="publish-draft-action-form"><?= $isPageContent ? 'Publish Page' : 'Post Now' ?></button>
            <?php else: ?>
              <button type="submit" class="secondary-button full-width-button" form="submit-review-action-form">Submit for Review</button>
            <?php endif; ?>
            <button type="submit" class="secondary-button danger full-width-button" form="trash-post-action-form"><?= $isPageContent ? 'Move Draft Page to Trash' : 'Move Draft to Trash' ?></button>
            <p class="field-help publish-card-note"><?= $canPublishThis ? 'Preview uses the saved draft. Save changes first if you edited the post.' : 'Preview uses the saved draft. Submit for review when it is ready for an admin.' ?></p>
          <?php else: ?>
            <?php if ($canPublishThis): ?>
              <button type="submit" class="secondary-button full-width-button" form="unpublish-post-action-form"><?= $isPageContent ? 'Move Page to Drafts' : 'Move to Drafts' ?></button>
            <?php endif; ?>
            <button type="submit" class="secondary-button danger full-width-button" form="trash-post-action-form"><?= $isPageContent ? 'Move Page to Trash' : 'Move Post to Trash' ?></button>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </section>
    <?php
}

function mp_dual_editor(string $bodyMarkdown): void
{
    $defaultMode = function_exists('mp_default_editor_mode') ? mp_default_editor_mode() : 'visual';
    $initialHtml = function_exists('mp_markdown_to_html')
        ? mp_markdown_to_html($bodyMarkdown, false)
        : '<p>' . htmlspecialchars($bodyMarkdown, ENT_QUOTES, 'UTF-8') . '</p>';
    ?>
    <div class="dual-editor" data-bonumark-editor data-autosave-enabled="<?= mp_autosave_enabled() ? '1' : '0' ?>" data-autosave-url="<?= htmlspecialchars(mp_admin_url('autosave.php'), ENT_QUOTES, 'UTF-8') ?>" data-default-mode="<?= htmlspecialchars($defaultMode, ENT_QUOTES, 'UTF-8') ?>">
      <div class="autosave-banner" data-autosave-banner hidden>
        <div class="autosave-banner-copy">
          <strong data-autosave-banner-title>Autosave recovery available.</strong>
          <span data-autosave-banner-text>You can restore the autosave or keep the saved version.</span>
        </div>
        <div class="autosave-banner-actions">
          <button type="button" class="secondary-button" data-autosave-restore>Restore autosave</button>
          <button type="button" class="secondary-button" data-autosave-discard>Discard</button>
        </div>
      </div>

      <div class="editor-status-strip" aria-label="Editor status">
        <div class="editor-save-state" data-editor-save-state role="status" aria-live="polite" aria-atomic="true">Autosave ready.</div>
        <div class="editor-metrics" data-editor-metrics aria-live="polite">
          <span data-editor-word-count>0 words</span>
          <span data-editor-character-count>0 characters</span>
        </div>
      </div>

      <div class="editor-chrome">
        <div class="editor-command-bar">
          <div class="editor-tabs" role="tablist" aria-label="Editor mode">
            <button type="button" id="editor-tab-visual" class="editor-tab active" role="tab" data-editor-mode="visual" aria-selected="true" aria-controls="editor-panel-visual">Visual</button>
            <button type="button" id="editor-tab-markdown" class="editor-tab" role="tab" data-editor-mode="markdown" aria-selected="false" aria-controls="editor-panel-markdown">Markdown</button>
            <button type="button" id="editor-tab-preview" class="editor-tab" role="tab" data-editor-mode="preview" aria-selected="false" aria-controls="editor-panel-preview">Preview</button>
          </div>
          <div class="editor-utility-actions" aria-label="Editor view tools">
            <button type="button" class="secondary-button compact-button" data-editor-preview-refresh data-screen-preview-tool aria-keyshortcuts="Control+Shift+P Meta+Shift+P">Refresh Preview</button>
            <button type="button" class="secondary-button compact-button" data-editor-focus-toggle aria-pressed="false" aria-keyshortcuts="Control+Shift+F Meta+Shift+F">Focus Mode</button>
            <button type="button" class="secondary-button compact-button" data-shortcuts-toggle aria-expanded="false" aria-controls="editor-shortcuts-panel" aria-keyshortcuts="Control+/ Meta+/">Shortcuts</button>
          </div>
        </div>

        <div class="visual-toolbar" data-editor-toolbar="visual" aria-label="Visual editor toolbar">
          <span class="toolbar-group">
            <button type="button" data-command="formatBlock" data-value="P" title="Paragraph">Paragraph</button>
            <button type="button" data-command="formatBlock" data-value="H2" title="Heading 2">H2</button>
            <button type="button" data-command="formatBlock" data-value="H3" title="Heading 3">H3</button>
          </span>
          <span class="toolbar-group">
            <button type="button" data-command="bold" aria-label="Bold" title="Bold, Ctrl+B" aria-keyshortcuts="Control+B Meta+B"><strong>B</strong></button>
            <button type="button" data-command="italic" aria-label="Italic" title="Italic, Ctrl+I" aria-keyshortcuts="Control+I Meta+I"><em>I</em></button>
            <button type="button" data-command="createLink" title="Insert link, Ctrl+K" aria-keyshortcuts="Control+K Meta+K">Link</button>
            <button type="button" class="media-toolbar-button" data-open-media-picker title="Add media, Ctrl+Shift+M" aria-keyshortcuts="Control+Shift+M Meta+Shift+M">Add Media</button>
          </span>
          <span class="toolbar-group">
            <button type="button" data-command="insertUnorderedList">Bullets</button>
            <button type="button" data-command="insertOrderedList">Numbers</button>
            <button type="button" data-command="formatBlock" data-value="BLOCKQUOTE">Quote</button>
            <button type="button" data-command="formatBlock" data-value="PRE">Code</button>
            <button type="button" data-command="insertHorizontalRule">Rule</button>
          </span>
        </div>

        <div class="visual-toolbar markdown-toolbar" data-editor-toolbar="markdown" hidden aria-label="Markdown editor toolbar">
          <span class="toolbar-group">
            <button type="button" data-markdown-command="heading" data-value="## ">H2</button>
            <button type="button" data-markdown-command="heading" data-value="### ">H3</button>
            <button type="button" data-markdown-command="bold" aria-label="Bold" title="Bold, Ctrl+B" aria-keyshortcuts="Control+B Meta+B"><strong>B</strong></button>
            <button type="button" data-markdown-command="italic" aria-label="Italic" title="Italic, Ctrl+I" aria-keyshortcuts="Control+I Meta+I"><em>I</em></button>
            <button type="button" data-markdown-command="link" title="Insert link, Ctrl+K" aria-keyshortcuts="Control+K Meta+K">Link</button>
            <button type="button" class="media-toolbar-button" data-open-media-picker title="Add media, Ctrl+Shift+M" aria-keyshortcuts="Control+Shift+M Meta+Shift+M">Add Media</button>
          </span>
          <span class="toolbar-group">
            <button type="button" data-markdown-command="unordered-list">Bullets</button>
            <button type="button" data-markdown-command="ordered-list">Numbers</button>
            <button type="button" data-markdown-command="quote">Quote</button>
            <button type="button" data-markdown-command="code">Code</button>
            <button type="button" data-markdown-command="rule">Rule</button>
          </span>
        </div>
      </div>

      <div id="editor-shortcuts-panel" class="editor-shortcuts-panel" data-shortcuts-panel role="dialog" aria-modal="true" aria-labelledby="editor-shortcuts-title" hidden>
        <div class="editor-shortcuts-card" tabindex="-1">
          <div class="editor-shortcuts-header">
            <div>
              <p class="eyebrow">Editor help</p>
              <h3 id="editor-shortcuts-title">Keyboard Shortcuts</h3>
            </div>
            <button type="button" class="secondary-button compact-button" data-shortcuts-close aria-label="Close keyboard shortcuts">Close</button>
          </div>
          <dl class="editor-shortcuts-list">
            <div><dt>Ctrl/Cmd + S</dt><dd>Save the current post.</dd></div>
            <div><dt>Ctrl/Cmd + B</dt><dd>Bold selected text.</dd></div>
            <div><dt>Ctrl/Cmd + I</dt><dd>Italicize selected text.</dd></div>
            <div><dt>Ctrl/Cmd + K</dt><dd>Insert or edit a link.</dd></div>
            <div><dt>Ctrl/Cmd + Shift + M</dt><dd>Open Add Media.</dd></div>
            <div><dt>Ctrl/Cmd + Shift + P</dt><dd>Switch to Preview and refresh it.</dd></div>
            <div><dt>Ctrl/Cmd + Shift + F</dt><dd>Toggle Focus Mode.</dd></div>
            <div><dt>Ctrl/Cmd + /</dt><dd>Open this shortcut guide.</dd></div>
            <div><dt>Esc</dt><dd>Close dialogs or leave Focus Mode.</dd></div>
          </dl>
        </div>
      </div>

      <div id="editor-panel-visual" class="editor-mode-panel active" role="tabpanel" data-editor-panel="visual" aria-labelledby="editor-tab-visual">
        <label class="sr-only" for="visual-editor">Visual editor</label>
        <div id="visual-editor" class="visual-editor" contenteditable="true" spellcheck="true" role="textbox" aria-multiline="true" aria-label="Visual editor"><?= $initialHtml ?></div>
      </div>

      <div id="editor-panel-markdown" class="editor-mode-panel" role="tabpanel" data-editor-panel="markdown" aria-labelledby="editor-tab-markdown" hidden>
        <label class="sr-only" for="body_markdown">Markdown body</label>
        <textarea id="body_markdown" name="body_markdown" spellcheck="true" aria-label="Markdown body"><?= htmlspecialchars($bodyMarkdown, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>

      <div id="editor-panel-preview" class="editor-mode-panel" role="tabpanel" data-editor-panel="preview" aria-labelledby="editor-tab-preview" hidden>
        <div class="editor-preview-header">
          <div>
            <strong>Preview</strong>
            <span>Generated from the current editor content after Bonumark cleans the writing surface.</span>
          </div>
          <div class="editor-preview-actions">
            <button type="button" class="secondary-button compact-button" data-editor-preview-refresh data-screen-preview-tool>Refresh Preview</button>
            <button type="submit" form="stream-editor-form" class="secondary-button compact-button" formaction="<?= htmlspecialchars(mp_admin_url('preview-current.php'), ENT_QUOTES, 'UTF-8') ?>" formmethod="post" formtarget="_blank" data-public-preview-submit>Preview in New Tab</button>
          </div>
        </div>
        <div class="visual-preview" data-editor-preview><?= $initialHtml ?></div>
      </div>
    </div>
    <?php mp_editor_media_picker_markup(); ?>
    <?php
}

function mp_editor_script_tag(): void
{
    $src = htmlspecialchars(mp_asset_url('assets/editor.js'), ENT_QUOTES, 'UTF-8');
    echo '<script src="' . $src . '" defer></script>';
}

function mp_page_field_value(array $page, string $key, string $default = ''): string
{
    $value = $page[$key] ?? ($page['front_matter'][$key] ?? $default);
    if (is_array($value)) {
        return implode(', ', array_map('strval', $value));
    }
    return (string)$value;
}

function mp_page_title_fields(array $page): void
{
    $title = mp_page_field_value($page, 'title', '');
    ?>
    <div class="editor-title-block">
      <label for="page_title">Page Title</label>
      <input type="text" id="page_title" name="page_title" value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>" placeholder="About" maxlength="180" required>
      <p class="field-help">Pages are stable site content, so the title displays publicly.</p>
    </div>
    <?php
}

function mp_page_url_fields(array $page, string $section = 'pages/drafts'): void
{
    $slug = mp_page_field_value($page, 'slug', '');
    $published = $section === 'pages/published';
    $previewSlug = $slug !== '' && !mp_page_slug_needs_generation($slug) ? $slug : 'example';
    $previewUrl = mp_page_url($previewSlug);
    ?>
    <section class="editor-card">
      <h2>Page URL</h2>
      <label for="page_slug">Slug</label>
      <input type="text" id="page_slug" name="page_slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" maxlength="190" placeholder="about" data-page-slug-input data-original-slug="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" data-is-published="<?= $published ? '1' : '0' ?>">
      <p class="field-help">Leave blank to generate this from the page title. Public URL after publishing: <code data-page-permalink-preview data-page-permalink-base="<?= htmlspecialchars(mp_url_path('pages/'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?></code></p>
      <?php if ($published): ?>
        <label class="checkbox-row" data-page-slug-warning><input type="checkbox" name="confirm_slug_change" value="1" data-page-confirm-slug-change> I understand this can change the live page URL.</label>
      <?php endif; ?>
    </section>
    <?php
}

function mp_page_settings_fields(array $page): void
{
    $date = mp_page_field_value($page, 'date', date('Y-m-d'));
    $description = mp_page_field_value($page, 'description', '');
    $seoTitle = mp_page_field_value($page, 'seo_title', '');
    $generatedSeoTitle = mp_page_generated_seo_title(mp_page_field_value($page, 'title', 'Untitled Page'));
    $siteName = trim((string)mp_setting_or_config('site_name', 'Bonumark Stream'));
    $robots = mp_page_field_value($page, 'robots', '');
    ?>
    <section class="editor-card">
      <h2>Page Settings</h2>
      <label for="page_date">Date</label>
      <input type="date" id="page_date" name="page_date" value="<?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?>" required>
      <label for="page_description">Meta Description</label>
      <textarea class="small-textarea" id="page_description" name="page_description" maxlength="300"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></textarea>
      <label for="page_seo_title">Search Title</label>
      <input type="text" id="page_seo_title" name="page_seo_title" value="<?= htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8') ?>" maxlength="180" placeholder="Leave blank to use the generated title" data-page-seo-title-input>
      <p class="field-help">Blank uses the generated search title: <code data-page-seo-title-preview data-site-name="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($generatedSeoTitle, ENT_QUOTES, 'UTF-8') ?></code></p>
      <label for="page_robots">Search Indexing</label>
      <select id="page_robots" name="page_robots">
        <option value="" <?= $robots === '' ? 'selected' : '' ?>>Site default</option>
        <option value="index,follow" <?= $robots === 'index,follow' ? 'selected' : '' ?>>index,follow</option>
        <option value="noindex,follow" <?= $robots === 'noindex,follow' ? 'selected' : '' ?>>noindex,follow</option>
      </select>
      <p class="field-help">Public menu links are managed from Appearance → Navigation after the page is published.</p>
    </section>
    <?php
}

