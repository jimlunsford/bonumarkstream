(function () {
  function onReady(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback);
      return;
    }
    callback();
  }

  function setupMenu() {
    var navToggle = document.querySelector('[data-stream-menu-toggle], .site-nav-toggle');
    var siteNav = document.getElementById('site-primary-nav');

    if (!navToggle || !siteNav) {
      return;
    }

    document.body.classList.add('nav-enhanced');

    function setMenuState(isOpen) {
      siteNav.classList.toggle('is-open', isOpen);
      navToggle.classList.toggle('is-active', isOpen);
      document.body.classList.toggle('stream-menu-open', isOpen);
      navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      navToggle.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
    }

    navToggle.addEventListener('click', function (event) {
      event.stopPropagation();
      setMenuState(!siteNav.classList.contains('is-open'));
    });

    document.addEventListener('click', function (event) {
      if (!siteNav.classList.contains('is-open')) {
        return;
      }
      if (navToggle.contains(event.target) || siteNav.contains(event.target)) {
        return;
      }
      setMenuState(false);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape' || !siteNav.classList.contains('is-open')) {
        return;
      }
      setMenuState(false);
      navToggle.focus();
    });
  }

  function setupComposer(root) {
    var scope = root || document;
    var forms = scope.querySelectorAll('[data-stream-form]');

    forms.forEach(function (form) {
      if (form.dataset.streamInitialized === '1') {
        return;
      }
      form.dataset.streamInitialized = '1';

      var input = form.querySelector('[data-stream-file]');
      var preview = form.querySelector('[data-stream-preview]');
      var textarea = form.querySelector('[data-stream-body]');
      var counter = form.querySelector('[data-stream-counter]');
      var submit = form.querySelector('[data-stream-submit]');
      var linkPreview = form.querySelector('[data-link-preview]');
      var linkPreviewEnabled = form.querySelector('[data-link-preview-enabled]');
      var linkPreviewFields = form.querySelectorAll('[data-link-preview-field]');
      var linkPreviewEndpoint = linkPreview ? linkPreview.getAttribute('data-link-preview-endpoint') || '' : '';
      var linkPreviewTimer = null;
      var linkPreviewLastUrl = '';
      var linkPreviewRemovedUrl = '';
      var linkPreviewRequestId = 0;

      function updateCounter() {
        if (!textarea || !counter) {
          return;
        }
        var length = textarea.value.length;
        counter.textContent = length.toLocaleString() + ' / 5,000';
        counter.classList.toggle('near-limit', length >= 4500);
      }

      function autoGrow() {
        if (!textarea) {
          return;
        }
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 260) + 'px';
      }

      function clearPreview() {
        if (!preview) {
          return;
        }
        preview.innerHTML = '';
        preview.hidden = false;
        preview.classList.remove('is-visible');
      }

      function typeLabel(file) {
        var type = file && file.type ? file.type : '';
        if (type.indexOf('image/') === 0) {
          return 'Image attachment';
        }
        if (type.indexOf('video/') === 0) {
          return 'Video attachment';
        }
        if (type.indexOf('audio/') === 0) {
          return 'Audio attachment';
        }
        return 'Document attachment';
      }

      function buildPreviewNode(file, imageSrc) {
        var wrapper = document.createElement('div');
        wrapper.className = 'stream-compose-preview-inner';

        if (imageSrc) {
          var thumb = document.createElement('div');
          thumb.className = 'stream-compose-preview-thumb';
          var img = document.createElement('img');
          img.alt = '';
          img.src = imageSrc;
          thumb.appendChild(img);
          wrapper.appendChild(thumb);
        } else {
          var icon = document.createElement('div');
          icon.className = 'stream-compose-preview-icon';
          icon.setAttribute('aria-hidden', 'true');
          icon.textContent = '📎';
          wrapper.appendChild(icon);
        }

        var metaWrap = document.createElement('div');
        metaWrap.className = 'stream-compose-preview-meta';

        var name = document.createElement('div');
        name.className = 'stream-compose-preview-name';
        name.textContent = file && file.name ? file.name : 'Attached media';
        metaWrap.appendChild(name);

        var meta = document.createElement('div');
        meta.className = 'stream-compose-preview-type';
        meta.textContent = typeLabel(file);
        metaWrap.appendChild(meta);

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'stream-compose-preview-remove';
        remove.textContent = 'Remove';
        remove.setAttribute('aria-label', 'Remove attachment');
        remove.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          if (input) {
            input.value = '';
          }
          clearPreview();
          if (input) {
            input.focus();
          } else if (textarea) {
            textarea.focus();
          }
        });
        metaWrap.appendChild(remove);
        wrapper.appendChild(metaWrap);
        return wrapper;
      }

      function showPreview(node) {
        if (!preview || !node) {
          return;
        }
        preview.innerHTML = '';
        preview.appendChild(node);
        preview.hidden = false;
        preview.classList.add('is-visible');
      }

      function firstUrl(text) {
        var match = String(text || '').match(/\bhttps?:\/\/[^\s<>()\[\]{}"']+/i);
        if (!match) {
          return '';
        }
        return match[0].replace(/[\.,;:!?]+$/g, '');
      }

      function setLinkPreviewFields(previewData, enabled) {
        if (linkPreviewEnabled) {
          linkPreviewEnabled.value = enabled ? '1' : '0';
        }
        var data = previewData || {};
        linkPreviewFields.forEach(function (field) {
          var key = field.getAttribute('data-link-preview-field') || '';
          field.value = enabled ? String(data[key] || '') : '';
        });
      }

      function clearLinkPreview(rememberUrl) {
        if (linkPreview) {
          linkPreview.innerHTML = '';
          linkPreview.hidden = true;
          linkPreview.classList.remove('is-visible', 'is-loading', 'has-error');
        }
        setLinkPreviewFields({}, false);
        if (rememberUrl && linkPreviewLastUrl) {
          linkPreviewRemovedUrl = linkPreviewLastUrl;
        }
      }

      function buildLinkPreviewNode(data) {
        var wrapper = document.createElement('div');
        wrapper.className = 'stream-compose-link-preview-inner';

        var dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'stream-compose-link-preview-dismiss';
        dismiss.textContent = '×';
        dismiss.setAttribute('aria-label', 'Remove link preview and post as a plain link');
        dismiss.setAttribute('title', 'Post as link only');
        dismiss.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          clearLinkPreview(true);
          if (textarea) {
            textarea.focus();
          }
        });
        wrapper.appendChild(dismiss);

        if (data.image) {
          wrapper.classList.add('has-image');
          var media = document.createElement('div');
          media.className = 'stream-compose-link-preview-image';
          var img = document.createElement('img');
          img.alt = '';
          img.src = data.image;
          img.addEventListener('error', function () {
            media.remove();
            wrapper.classList.remove('has-image');
            wrapper.classList.add('no-image');
          });
          media.appendChild(img);
          wrapper.appendChild(media);
        } else {
          wrapper.classList.add('no-image');
        }

        var body = document.createElement('div');
        body.className = 'stream-compose-link-preview-body';

        if (data.site_name) {
          var site = document.createElement('div');
          site.className = 'stream-compose-link-preview-site';
          site.textContent = data.site_name;
          body.appendChild(site);
        }

        var title = document.createElement('div');
        title.className = 'stream-compose-link-preview-title';
        title.textContent = data.title || data.url || 'Link preview';
        body.appendChild(title);

        if (data.description) {
          var description = document.createElement('div');
          description.className = 'stream-compose-link-preview-description';
          description.textContent = data.description;
          body.appendChild(description);
        }

        wrapper.appendChild(body);
        return wrapper;
      }

      function showLinkPreview(data) {
        if (!linkPreview || !data || !data.url) {
          return;
        }
        linkPreview.innerHTML = '';
        linkPreview.appendChild(buildLinkPreviewNode(data));
        linkPreview.hidden = false;
        linkPreview.classList.add('is-visible');
        linkPreview.classList.remove('is-loading', 'has-error');
        linkPreviewLastUrl = data.url;
        setLinkPreviewFields(data, true);
      }

      function fetchLinkPreview(url) {
        if (!linkPreview || !linkPreviewEndpoint || typeof fetch !== 'function') {
          return;
        }
        if (!url || url === linkPreviewRemovedUrl || url === linkPreviewLastUrl) {
          return;
        }
        var token = form.querySelector('input[name="csrf_token"]');
        var body = new URLSearchParams();
        body.set('url', url);
        if (token) {
          body.set('csrf_token', token.value || '');
        }
        var requestId = ++linkPreviewRequestId;
        linkPreview.hidden = false;
        linkPreview.innerHTML = '<div class="stream-compose-link-preview-loading">Loading link preview…</div>';
        linkPreview.classList.add('is-visible', 'is-loading');
        linkPreview.classList.remove('has-error');
        fetch(linkPreviewEndpoint, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: body.toString()
        }).then(function (response) {
          return response.json().then(function (json) {
            if (!response.ok || !json || json.ok !== true) {
              throw new Error(json && json.message ? json.message : 'Preview unavailable.');
            }
            return json.preview || null;
          });
        }).then(function (previewData) {
          if (requestId !== linkPreviewRequestId) {
            return;
          }
          showLinkPreview(previewData);
        }).catch(function () {
          if (requestId !== linkPreviewRequestId) {
            return;
          }
          clearLinkPreview(false);
        });
      }

      function scheduleLinkPreview() {
        if (!textarea || !linkPreview) {
          return;
        }
        var url = firstUrl(textarea.value);
        if (!url) {
          linkPreviewLastUrl = '';
          linkPreviewRemovedUrl = '';
          clearLinkPreview(false);
          return;
        }
        if (url === linkPreviewRemovedUrl || url === linkPreviewLastUrl) {
          return;
        }
        if (linkPreviewTimer) {
          window.clearTimeout(linkPreviewTimer);
        }
        linkPreviewTimer = window.setTimeout(function () {
          fetchLinkPreview(url);
        }, 550);
      }

      if (textarea) {
        textarea.addEventListener('input', function () {
          updateCounter();
          autoGrow();
          scheduleLinkPreview();
        });
        updateCounter();
        autoGrow();
        scheduleLinkPreview();
      }

      if (submit) {
        form.addEventListener('submit', function () {
          submit.disabled = true;
          submit.textContent = submit.getAttribute('data-busy-label') || 'Posting...';
          submit.classList.add('is-busy');
        });
      }

      if (input && preview) {
        input.addEventListener('change', function () {
          clearPreview();
          var file = input.files && input.files[0] ? input.files[0] : null;
          if (!file) {
            return;
          }

          if (!file.type || file.type.indexOf('image/') !== 0) {
            showPreview(buildPreviewNode(file, ''));
            return;
          }

          var reader = new FileReader();
          reader.onload = function (event) {
            var src = event && event.target ? event.target.result : '';
            showPreview(buildPreviewNode(file, src));
          };
          reader.onerror = function () {
            showPreview(buildPreviewNode(file, ''));
          };
          reader.readAsDataURL(file);
        });
      }
    });
  }

  function loadComposerMounts() {
    var mounts = document.querySelectorAll('[data-stream-composer-mount]');
    if (!mounts.length || typeof fetch !== 'function') {
      return;
    }

    mounts.forEach(function (mount) {
      if (mount.dataset.streamComposerLoaded === '1') {
        return;
      }

      var endpoint = mount.getAttribute('data-stream-composer-endpoint') || '';
      if (!endpoint) {
        return;
      }

      mount.dataset.streamComposerLoaded = '1';
      var returnTo = window.location.pathname + window.location.search;
      var separator = endpoint.indexOf('?') === -1 ? '?' : '&';
      var url = endpoint + separator + 'return_to=' + encodeURIComponent(returnTo || '/');

      fetch(url, {
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      }).then(function (response) {
        if (response.status === 204 || !response.ok) {
          return '';
        }
        return response.text();
      }).then(function (html) {
        if (!html || !html.trim()) {
          return;
        }
        mount.innerHTML = html;
        setupComposer(mount);
        setupLikes(mount);
        setupLinkPreviewImages(mount);
      }).catch(function () {
        mount.dataset.streamComposerLoaded = '0';
      });
    });
  }


  var knownLikeEndpoint = '';

  function parseLikeCount(text) {
    var match = String(text || '').match(/([\d,]+)/);
    if (!match || !match[1]) {
      return 0;
    }
    var value = parseInt(match[1].replace(/,/g, ''), 10);
    return isNaN(value) ? 0 : value;
  }

  function streamAssetBase() {
    var streamScript = null;
    var scripts = document.querySelectorAll('script[src]');

    scripts.forEach(function (script) {
      var src = script.getAttribute('src') || '';
      if (!streamScript && src.indexOf('assets/stream.js') !== -1) {
        streamScript = src;
      }
    });

    if (!streamScript) {
      var stylesheet = document.querySelector('link[href*="assets/stream.css"], link[href*="assets/style.css"]');
      streamScript = stylesheet ? stylesheet.getAttribute('href') || '' : '';
    }

    if (streamScript) {
      try {
        var url = new URL(streamScript, window.location.href);
        url.search = '';
        url.hash = '';
        return url.href.replace(/assets\/(stream\.js|stream\.css|style\.css)$/i, '');
      } catch (error) {
        return '';
      }
    }

    return '';
  }

  function addUniqueEndpoint(list, endpoint) {
    endpoint = String(endpoint || '').trim();
    if (!endpoint) {
      return;
    }

    try {
      endpoint = new URL(endpoint, window.location.href).href;
    } catch (error) {
      return;
    }

    if (list.indexOf(endpoint) === -1) {
      list.push(endpoint);
    }
  }

  function likeEndpointCandidates(button) {
    var list = [];
    var assetBase = streamAssetBase();

    addUniqueEndpoint(list, knownLikeEndpoint);

    if (button) {
      addUniqueEndpoint(list, button.getAttribute('data-like-endpoint'));
      addUniqueEndpoint(list, button.getAttribute('data-like-endpoint-alt'));
    }

    if (assetBase) {
      addUniqueEndpoint(list, new URL('stream-like.php', assetBase).href);
      addUniqueEndpoint(list, new URL('admin/stream-like.php', assetBase).href);
    }

    addUniqueEndpoint(list, '/stream-like.php');
    addUniqueEndpoint(list, '/admin/stream-like.php');

    return list;
  }

  function endpointUrl(endpoint, params) {
    var url = new URL(endpoint, window.location.href);
    if (params) {
      Object.keys(params).forEach(function (key) {
        url.searchParams.set(key, params[key]);
      });
    }
    return url.href;
  }

  function jsonFetch(url, options) {
    options = options || {};
    options.credentials = 'same-origin';
    options.cache = 'no-store';
    options.headers = options.headers || {};
    options.headers['X-Requested-With'] = 'XMLHttpRequest';
    options.headers['Accept'] = 'application/json';

    return fetch(url, options).then(function (response) {
      var contentType = response.headers.get('content-type') || '';

      return response.text().then(function (text) {
        var trimmed = String(text || '').trim();
        var first = trimmed.charAt(0);
        var json = null;

        if (!trimmed) {
          var emptyError = new Error('Like endpoint returned an empty response.');
          emptyError.endpointRecoverable = true;
          emptyError.status = response.status;
          throw emptyError;
        }

        if (contentType.indexOf('application/json') === -1 && first !== '{' && first !== '[') {
          var htmlError = new Error('Like endpoint returned HTML instead of JSON.');
          htmlError.endpointRecoverable = true;
          htmlError.status = response.status;
          throw htmlError;
        }

        try {
          json = JSON.parse(trimmed);
        } catch (error) {
          var parseError = new Error('Like endpoint returned invalid JSON.');
          parseError.endpointRecoverable = true;
          parseError.status = response.status;
          throw parseError;
        }

        if (!response.ok || !json || json.ok !== true) {
          var message = json && json.message ? json.message : 'Like failed.';
          var appError = new Error(message);
          appError.status = response.status;
          appError.endpointRecoverable = response.status === 404 || response.status === 405 || response.status >= 500;
          throw appError;
        }

        return json;
      });
    });
  }

  function tryLikeEndpoints(candidates, urlFactory, optionsFactory) {
    var index = 0;
    var lastError = null;

    function next() {
      if (index >= candidates.length) {
        throw lastError || new Error('Like endpoint unavailable.');
      }

      var endpoint = candidates[index++];
      var url = urlFactory(endpoint);
      var options = optionsFactory ? optionsFactory(endpoint) : {};

      return jsonFetch(url, options).then(function (json) {
        knownLikeEndpoint = endpoint;
        return json;
      }).catch(function (error) {
        lastError = error;
        return next();
      });
    }

    return Promise.resolve().then(next);
  }

  function updateLikeButton(button, data) {
    if (!button || !data) {
      return;
    }

    var label = button.querySelector('.stream-like-text');
    var srText = button.querySelector('.stream-like-sr-text');
    var count = typeof data.count === 'number' ? data.count : parseLikeCount(data.label || (label ? label.textContent : ''));
    var liked = !!data.liked;
    var text = data.label || (count.toLocaleString() + ' ' + (count === 1 ? 'like' : 'likes'));
    var actionText = liked ? 'Post liked.' : 'Like this post.';

    button.dataset.likeCount = String(count);
    button.setAttribute('aria-pressed', liked ? 'true' : 'false');
    button.setAttribute('aria-label', actionText + ' ' + text);
    button.classList.toggle('is-liked', liked);
    button.classList.remove('has-like-error');

    if (label) {
      label.textContent = text;
    }
    if (srText) {
      srText.textContent = actionText;
    }
  }

  function cleanLikeErrorMessage(error) {
    var message = error && error.message ? error.message : '';
    if (!message || message.indexOf('Unexpected token') !== -1 || message.indexOf('<!DOCTYPE') !== -1 || message.indexOf('HTML instead of JSON') !== -1 || message.indexOf('invalid JSON') !== -1) {
      return 'Like endpoint unavailable';
    }
    return message;
  }

  function showLikeError(button, message, previousText) {
    var label = button ? button.querySelector('.stream-like-text') : null;
    if (!button || !label) {
      return;
    }

    button.classList.add('has-like-error');
    button.setAttribute('aria-label', message || 'Like failed.');
    label.textContent = message || 'Like failed';

    window.setTimeout(function () {
      if (label) {
        label.textContent = previousText || label.textContent;
      }
      button.classList.remove('has-like-error');
    }, 2200);
  }

  function hydrateLikes(root) {
    var scope = root || document;
    var buttons = Array.prototype.slice.call(scope.querySelectorAll('[data-stream-like]'));
    if (!buttons.length || typeof fetch !== 'function') {
      return;
    }

    var unique = [];
    var seen = {};
    buttons.forEach(function (button) {
      var slug = button.getAttribute('data-like-slug') || '';
      if (!slug || seen[slug]) {
        return;
      }
      seen[slug] = true;
      unique.push(slug);
    });

    if (!unique.length) {
      return;
    }

    var candidates = likeEndpointCandidates(buttons[0]);
    tryLikeEndpoints(candidates, function (endpoint) {
      return endpointUrl(endpoint, {
        slugs: unique.join(','),
        _: Date.now()
      });
    }, function () {
      return { method: 'GET' };
    }).then(function (json) {
      var data = json.data || {};
      buttons.forEach(function (button) {
        var slug = button.getAttribute('data-like-slug') || '';
        if (data[slug]) {
          updateLikeButton(button, data[slug]);
        }
      });
    }).catch(function () {
      // Like status hydration is progressive enhancement. If every endpoint check fails,
      // keep the baked-in count visible and avoid changing the public card layout.
    });
  }

  function setupLikes(root) {
    var scope = root || document;
    scope.querySelectorAll('[data-stream-like]').forEach(function (button) {
      if (button.dataset.likeInitialized === '1') {
        return;
      }
      button.dataset.likeInitialized = '1';

      updateLikeButton(button, {
        liked: button.getAttribute('aria-pressed') === 'true',
        count: parseLikeCount(button.getAttribute('data-like-count') || (button.querySelector('.stream-like-text') || {}).textContent || ''),
        label: (button.querySelector('.stream-like-text') || {}).textContent || ''
      });

      button.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();

        var slug = button.getAttribute('data-like-slug') || '';
        var label = button.querySelector('.stream-like-text');
        if (!slug || button.disabled || typeof fetch !== 'function') {
          return;
        }

        if (button.classList.contains('is-liked') || button.getAttribute('aria-pressed') === 'true') {
          updateLikeButton(button, {
            liked: true,
            count: parseLikeCount(button.getAttribute('data-like-count') || (label ? label.textContent : '')),
            label: label ? label.textContent : ''
          });
          return;
        }

        var previousText = label ? label.textContent : '';
        button.disabled = true;
        button.classList.add('is-busy');
        if (label) {
          label.textContent = 'Liking...';
        }

        var candidates = likeEndpointCandidates(button);

        tryLikeEndpoints(candidates, function (endpoint) {
          return endpoint;
        }, function () {
          var body = new FormData();
          body.append('slug', slug);
          return {
            method: 'POST',
            body: body
          };
        }).then(function (json) {
          updateLikeButton(button, json.data || {});
        }).catch(function (error) {
          showLikeError(button, cleanLikeErrorMessage(error), previousText);
        }).finally(function () {
          button.disabled = false;
          button.classList.remove('is-busy');
        });
      });
    });

    hydrateLikes(scope);
  }

  function setupLoadMore() {
    var paginationContainer = document.querySelector('.pagination-load-more');
    var loadMoreLink = document.querySelector('.pagination-load-more .pagination-older a');
    var feed = document.querySelector('.stream-feed');
    var loadStatus = document.getElementById('stream-load-status');

    if (!paginationContainer || !loadMoreLink || !feed || typeof fetch !== 'function') {
      return;
    }

    var isLoading = false;
    var originalLabel = loadMoreLink.textContent;

    function setStatus(message) {
      if (loadStatus) {
        loadStatus.textContent = message;
      }
    }

    function disableLoadMore(message) {
      loadMoreLink.textContent = message || 'No more posts';
      loadMoreLink.classList.add('is-disabled');
      loadMoreLink.removeAttribute('href');
      loadMoreLink.setAttribute('aria-disabled', 'true');
      setStatus(message || 'No more posts');
    }

    loadMoreLink.addEventListener('click', function (event) {
      event.preventDefault();

      if (isLoading || loadMoreLink.classList.contains('is-disabled')) {
        return;
      }

      var url = loadMoreLink.getAttribute('href');
      if (!url) {
        return;
      }

      isLoading = true;
      feed.setAttribute('aria-busy', 'true');
      loadMoreLink.textContent = 'Loading...';
      setStatus('Loading...');

      fetch(url, {
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Load more request failed.');
          }
          return response.text();
        })
        .then(function (html) {
          var parser = new DOMParser();
          var doc = parser.parseFromString(html, 'text/html');
          var newPosts = Array.prototype.slice.call(doc.querySelectorAll('.stream-feed [data-stream-card], .stream-feed .stream-card'));

          var existingUrls = {};
          Array.prototype.slice.call(feed.querySelectorAll('[data-stream-card]')).forEach(function (post) {
            var url = post.getAttribute('data-stream-url') || '';
            if (url) {
              existingUrls[url] = true;
            }
          });

          newPosts = newPosts.filter(function (post) {
            var url = post.getAttribute('data-stream-url') || '';
            if (!url || !existingUrls[url]) {
              if (url) {
                existingUrls[url] = true;
              }
              return true;
            }
            return false;
          });

          if (!newPosts.length) {
            disableLoadMore('No more posts');
            return;
          }

          newPosts.forEach(function (post) {
            feed.appendChild(post);
          });

          setupCards(feed);
          setupComments(feed);
          setupLikes(feed);
          setupCopyLinks(feed);
          setupLinkPreviewImages(feed);

          var loadedMessage = newPosts.length + (newPosts.length === 1 ? ' more post loaded.' : ' more posts loaded.');
          setStatus(loadedMessage);

          var newMoreLink = doc.querySelector('.pagination-load-more .pagination-older a');

          if (newMoreLink && newMoreLink.getAttribute('href')) {
            loadMoreLink.setAttribute('href', newMoreLink.getAttribute('href'));
            loadMoreLink.textContent = originalLabel;
            loadMoreLink.classList.remove('is-disabled');
            loadMoreLink.removeAttribute('aria-disabled');
          } else {
            disableLoadMore('No more posts');
          }
        })
        .catch(function () {
          loadMoreLink.textContent = originalLabel;
          setStatus('Unable to load more posts right now.');
        })
        .finally(function () {
          isLoading = false;
          feed.removeAttribute('aria-busy');
        });
    });
  }

  function setupCopyLinks(root) {
    var scope = root || document;
    scope.querySelectorAll('[data-copy-url]').forEach(function (button) {
      if (button.dataset.copyInitialized === '1') {
        return;
      }
      button.dataset.copyInitialized = '1';

      button.addEventListener('click', function () {
        var url = button.getAttribute('data-copy-url') || '';
        var label = button.getAttribute('data-copy-label') || 'Copy';
        var copied = button.getAttribute('data-copied-label') || 'Copied';
        var textNode = button.querySelector('span:last-child');

        function showCopied() {
          if (textNode) {
            textNode.textContent = copied;
            window.setTimeout(function () {
              textNode.textContent = label;
            }, 1800);
          }
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(showCopied).catch(function () {
            window.prompt('Copy this link:', url);
          });
        } else {
          window.prompt('Copy this link:', url);
        }
      });
    });
  }



  function setupComments(root) {
    var scope = root || document;
    scope.querySelectorAll('[data-comments-mount]').forEach(function (mount) {
      if (mount.dataset.commentsInitialized === '1') {
        return;
      }
      mount.dataset.commentsInitialized = '1';
      var endpoint = mount.getAttribute('data-comments-endpoint') || '';
      var slug = mount.getAttribute('data-comments-slug') || '';
      if (!endpoint || !slug) {
        return;
      }

      function loadComments() {
        var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'slug=' + encodeURIComponent(slug);
        fetch(url, { credentials: 'same-origin' })
          .then(function (response) { return response.text(); })
          .then(function (html) {
            mount.innerHTML = html;
            bindCommentForm();
          })
          .catch(function () {
            mount.innerHTML = '<p class="comment-note">Comments could not be loaded.</p>';
          });
      }

      function bindCommentForm() {
        var form = mount.querySelector('[data-comment-form]');
        if (!form || form.dataset.commentFormInitialized === '1') {
          return;
        }
        form.dataset.commentFormInitialized = '1';
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          var submit = form.querySelector('button[type="submit"]');
          var original = submit ? submit.textContent : '';
          if (submit) {
            submit.disabled = true;
            submit.textContent = 'Posting...';
          }
          fetch(form.getAttribute('action') || endpoint, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin'
          })
            .then(function (response) { return response.text(); })
            .then(function (html) {
              mount.innerHTML = html;
              bindCommentForm();
            })
            .catch(function () {
              var note = document.createElement('p');
              note.className = 'comment-notice';
              note.textContent = 'Comment could not be posted right now.';
              form.insertAdjacentElement('beforebegin', note);
            })
            .finally(function () {
              if (submit) {
                submit.disabled = false;
                submit.textContent = original || 'Post Comment';
              }
            });
        });
      }

      loadComments();
    });
  }

  function setupLinkPreviewImages(root) {
    var scope = root || document;
    scope.querySelectorAll('.stream-link-preview-image img, .stream-compose-link-preview-image img').forEach(function (img) {
      if (img.dataset.linkPreviewImageInitialized === '1') {
        return;
      }
      img.dataset.linkPreviewImageInitialized = '1';

      function removeBrokenImage() {
        var imageWrap = img.closest('.stream-link-preview-image, .stream-compose-link-preview-image');
        var card = img.closest('.stream-link-preview, .stream-compose-link-preview-inner');
        if (imageWrap) {
          imageWrap.remove();
        }
        if (card) {
          card.classList.remove('has-image');
          card.classList.add('no-image');
        }
      }

      img.addEventListener('error', removeBrokenImage);
      if (img.complete && img.naturalWidth === 0) {
        removeBrokenImage();
      }
    });
  }

  function setupCards(root) {
    var scope = root || document;
    scope.querySelectorAll('[data-stream-card]').forEach(function (card) {
      if (card.dataset.cardInitialized === '1') {
        return;
      }
      card.dataset.cardInitialized = '1';

      card.addEventListener('click', function (event) {
        if (event.defaultPrevented) {
          return;
        }
        if (event.target.closest('a, button, input, textarea, label, select')) {
          return;
        }
        var url = card.getAttribute('data-stream-url');
        if (url) {
          window.location.href = url;
        }
      });
    });
  }

  onReady(function () {
    setupMenu();
    setupComposer(document);
    loadComposerMounts();
    setupLikes(document);
    setupCopyLinks(document);
    setupLinkPreviewImages(document);
    setupCards(document);
    setupComments(document);
    setupLoadMore();
  });
}());
