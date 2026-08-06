(function () {
  'use strict';

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function inlineMarkdownToHtml(text) {
    let value = escapeHtml(text);
    const code = [];
    value = value.replace(/`([^`]+)`/g, function (_, inner) {
      const key = '%%CODE' + code.length + '%%';
      code.push('<code>' + escapeHtml(inner) + '</code>');
      return key;
    });
    value = value.replace(/!\[([^\]]*)\]\(([^\s)]+)(?:\s+"([^"]*)")?\)/g, function (_, alt, src, title) {
      const safeSrc = cleanUrl(src);
      const titleAttr = title ? ' title="' + escapeHtml(title) + '"' : '';
      return '<img src="' + escapeHtml(safeSrc) + '" alt="' + escapeHtml(alt) + '"' + titleAttr + '>';
    });
    value = value.replace(/\[([^\]]+)\]\(([^\s)]+)(?:\s+"([^"]*)")?\)/g, function (_, label, href, title) {
      const safeHref = cleanUrl(href);
      const type = mediaTypeFromMimeOrUrl('', safeHref);
      const titleAttr = title ? ' title="' + escapeHtml(title) + '"' : '';
      if (type === 'audio') { return '<audio controls preload="metadata" src="' + escapeHtml(safeHref) + '" data-media-label="' + escapeHtml(label) + '"></audio>'; }
      if (type === 'video') { return '<video controls preload="metadata" src="' + escapeHtml(safeHref) + '" data-media-label="' + escapeHtml(label) + '"></video>'; }
      return '<a href="' + escapeHtml(safeHref) + '"' + titleAttr + '>' + label + '</a>';
    });
    value = value.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    value = value.replace(/__([^_]+)__/g, '<strong>$1</strong>');
    value = value.replace(/(^|[^*])\*([^*]+)\*(?!\*)/g, '$1<em>$2</em>');
    value = value.replace(/(^|[^_])_([^_]+)_(?!_)/g, '$1<em>$2</em>');
    code.forEach(function (html, index) {
      value = value.replace('%%CODE' + index + '%%', html);
    });
    return value;
  }

  function cleanUrl(url) {
    const value = String(url || '').trim();
    if (/^(https?:\/\/|\/|\.\/|\.\.\/|#)/i.test(value)) {
      return value;
    }
    return '#';
  }

  function readStoredJson(key, fallback) {
    try {
      const raw = window.localStorage ? window.localStorage.getItem(key) : null;
      if (!raw) { return fallback; }
      return JSON.parse(raw);
    } catch (error) {
      return fallback;
    }
  }

  function writeStoredJson(key, value) {
    try {
      if (window.localStorage) {
        window.localStorage.setItem(key, JSON.stringify(value));
      }
    } catch (error) {
      // Editor state memory is helpful, but it should never block writing.
    }
  }

  function editorGlobalStateKey() {
    return 'bonumark-editor-state:global';
  }

  function editorPostStateKey(form) {
    const file = form && form.querySelector('input[name="file"]') ? form.querySelector('input[name="file"]').value : '';
    const type = form && form.querySelector('input[name="type"]') ? form.querySelector('input[name="type"]').value : 'new';
    const slug = form && form.querySelector('#stream_slug') ? form.querySelector('#stream_slug').value : '';
    const id = file || slug || 'new';
    return 'bonumark-editor-state:post:' + location.pathname + ':' + type + ':' + id;
  }

  function screenControlsStateKey() {
    return 'bonumark-editor-state:screen-controls';
  }

  function safeEditorMode(mode, fallback) {
    const value = String(mode || '').toLowerCase();
    return ['visual', 'markdown', 'preview'].indexOf(value) !== -1 ? value : (fallback || 'visual');
  }

  const focusableSelector = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"]), [contenteditable="true"]';
  let activeDialogReturnTarget = null;

  function focusableElements(container) {
    if (!container) { return []; }
    return Array.from(container.querySelectorAll(focusableSelector)).filter(function (node) {
      return !node.hidden && node.offsetParent !== null;
    });
  }

  function trapFocus(event, container, closeCallback) {
    if (event.key === 'Escape') {
      event.preventDefault();
      if (closeCallback) { closeCallback(); }
      return true;
    }
    if (event.key !== 'Tab') { return false; }
    const focusable = focusableElements(container);
    if (!focusable.length) {
      event.preventDefault();
      return true;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
      return true;
    }
    if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
      return true;
    }
    return false;
  }

  function restoreDialogReturnFocus() {
    const target = activeDialogReturnTarget;
    activeDialogReturnTarget = null;
    if (target && typeof target.focus === 'function' && document.contains(target)) {
      target.focus();
    }
  }


  function mediaTypeFromMimeOrUrl(mime, url) {
    const m = String(mime || '').toLowerCase();
    const u = String(url || '').toLowerCase().split('?')[0].split('#')[0];
    if (m.indexOf('image/') === 0 || /\.(jpe?g|png|gif|webp)$/i.test(u)) { return 'image'; }
    if (m.indexOf('audio/') === 0 || /\.(mp3|m4a|wav|ogg)$/i.test(u)) { return 'audio'; }
    if (m.indexOf('video/') === 0 || /\.(mp4|webm|mov)$/i.test(u)) { return 'video'; }
    return 'file';
  }

  function cleanMediaLabel(label, fallback) {
    const base = typeof fallback === 'undefined' ? 'Media' : fallback;
    const value = String(label || base || '').replace(/[\r\n\[\]]/g, ' ').replace(/\s+/g, ' ').trim();
    return value || base || '';
  }

  function markdownSafeLabel(label, fallback) {
    return cleanMediaLabel(label, fallback).replace(/\\/g, '\\\\').replace(/\[/g, '\\[').replace(/\]/g, '\\]');
  }

  function markdownSafeInlineText(value) {
    return String(value || '').replace(/\r\n?/g, '\n').replace(/[\[\]`*_#>]/g, '').replace(/\s+/g, ' ').trim();
  }

  function mediaMarkdown(data, overrideLabel, overrideCaption) {
    const url = cleanUrl(data.url || '#');
    const type = mediaTypeFromMimeOrUrl(data.mime, url);
    const label = markdownSafeLabel(overrideLabel || data.alt || data.label, 'Media');
    const caption = markdownSafeInlineText(overrideCaption || data.caption || '');
    let output = '';
    if (type === 'image') { output = '![' + label + '](' + url + ')'; }
    else if (type === 'audio') { output = '[Audio: ' + label + '](' + url + ')'; }
    else if (type === 'video') { output = '[Video: ' + label + '](' + url + ')'; }
    else { output = '[' + label + '](' + url + ')'; }
    return caption ? output + '\n\n*' + caption + '*' : output;
  }

  function mediaHtml(data, overrideLabel, overrideCaption) {
    const url = cleanUrl(data.url || '#');
    const type = mediaTypeFromMimeOrUrl(data.mime, url);
    const label = cleanMediaLabel(overrideLabel || data.alt || data.label, 'Media');
    const caption = cleanMediaLabel(overrideCaption || data.caption || '', '');
    let html = '';
    if (type === 'image') { html = '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(label) + '">'; }
    else if (type === 'audio') { html = '<audio controls preload="metadata" src="' + escapeHtml(url) + '" data-media-label="' + escapeHtml('Audio: ' + label) + '"></audio>'; }
    else if (type === 'video') { html = '<video controls preload="metadata" src="' + escapeHtml(url) + '" data-media-label="' + escapeHtml('Video: ' + label) + '"></video>'; }
    else { html = '<a href="' + escapeHtml(url) + '">' + escapeHtml(label) + '</a>'; }
    if (!caption) { return html; }
    return '<figure>' + html + '<figcaption>' + escapeHtml(caption) + '</figcaption></figure>';
  }

  function isTableStart(lines, i) {
    return lines[i + 1] && lines[i].indexOf('|') !== -1 && /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(lines[i + 1]);
  }

  function splitTableRow(line) {
    return line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(function (part) {
      return part.trim();
    });
  }

  function markdownToHtml(markdown) {
    const lines = String(markdown || '').replace(/\r\n?/g, '\n').split('\n');
    const html = [];
    let i = 0;

    while (i < lines.length) {
      let line = lines[i].replace(/\s+$/, '');
      if (line.trim() === '') {
        i += 1;
        continue;
      }

      const fence = line.trim().match(/^```([A-Za-z0-9_-]+)?\s*$/);
      if (fence) {
        const code = [];
        i += 1;
        while (i < lines.length && !/^```\s*$/.test(lines[i].trim())) {
          code.push(lines[i]);
          i += 1;
        }
        i += 1;
        html.push('<pre><code>' + escapeHtml(code.join('\n')) + '</code></pre>');
        continue;
      }

      const heading = line.match(/^(#{1,6})\s+(.+)$/);
      if (heading) {
        const level = heading[1].length;
        html.push('<h' + level + '>' + inlineMarkdownToHtml(heading[2].trim()) + '</h' + level + '>');
        i += 1;
        continue;
      }

      if (/^\s*[-*_]{3,}\s*$/.test(line)) {
        html.push('<hr>');
        i += 1;
        continue;
      }

      if (isTableStart(lines, i)) {
        const headers = splitTableRow(lines[i]);
        i += 2;
        const rows = [];
        while (i < lines.length && lines[i].trim() !== '' && lines[i].indexOf('|') !== -1) {
          rows.push(splitTableRow(lines[i]));
          i += 1;
        }
        let table = '<table><thead><tr>';
        headers.forEach(function (cell) {
          table += '<th>' + inlineMarkdownToHtml(cell) + '</th>';
        });
        table += '</tr></thead><tbody>';
        rows.forEach(function (row) {
          table += '<tr>';
          headers.forEach(function (_, index) {
            table += '<td>' + inlineMarkdownToHtml(row[index] || '') + '</td>';
          });
          table += '</tr>';
        });
        table += '</tbody></table>';
        html.push(table);
        continue;
      }

      if (/^>\s?/.test(line)) {
        const quote = [];
        while (i < lines.length && /^>\s?/.test(lines[i])) {
          quote.push(lines[i].replace(/^>\s?/, ''));
          i += 1;
        }
        html.push('<blockquote>' + markdownToHtml(quote.join('\n')) + '</blockquote>');
        continue;
      }

      if (/^\s*[-*+]\s+/.test(line)) {
        const items = [];
        while (i < lines.length && /^\s*[-*+]\s+/.test(lines[i])) {
          items.push('<li>' + inlineMarkdownToHtml(lines[i].replace(/^\s*[-*+]\s+/, '').trim()) + '</li>');
          i += 1;
        }
        html.push('<ul>' + items.join('') + '</ul>');
        continue;
      }

      if (/^\s*\d+\.\s+/.test(line)) {
        const items = [];
        while (i < lines.length && /^\s*\d+\.\s+/.test(lines[i])) {
          items.push('<li>' + inlineMarkdownToHtml(lines[i].replace(/^\s*\d+\.\s+/, '').trim()) + '</li>');
          i += 1;
        }
        html.push('<ol>' + items.join('') + '</ol>');
        continue;
      }

      const paragraph = [line.trim()];
      i += 1;
      while (i < lines.length) {
        const next = lines[i].replace(/\s+$/, '');
        if (next.trim() === '') {
          i += 1;
          break;
        }
        if (/^(#{1,6})\s+/.test(next) || /^```/.test(next.trim()) || /^>\s?/.test(next) || /^\s*[-*+]\s+/.test(next) || /^\s*\d+\.\s+/.test(next) || isTableStart(lines, i)) {
          break;
        }
        paragraph.push(next.trim());
        i += 1;
      }
      html.push('<p>' + inlineMarkdownToHtml(paragraph.join(' ')) + '</p>');
    }

    return html.join('\n');
  }

  function repeat(value, count) {
    return new Array(count + 1).join(value);
  }

  function textContent(node) {
    return (node.textContent || '').replace(/\u00a0/g, ' ').trim();
  }

  function isBlockTag(tag) {
    return ['address', 'article', 'aside', 'blockquote', 'div', 'dl', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul'].indexOf(tag) !== -1;
  }

  function hasBlockChild(node) {
    return Array.from(node.children || []).some(function (child) {
      return isBlockTag(child.tagName.toLowerCase());
    });
  }

  function normalizeInlineText(value) {
    return String(value || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ');
  }

  function normalizeMarkdownOutput(value) {
    return String(value || '')
      .replace(/\r\n?/g, '\n')
      .replace(/[ \t]+\n/g, '\n')
      .replace(/\n[ \t]+/g, '\n')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
  }

  function stripUnsafeEditorNodes(holder) {
    holder.querySelectorAll('script, style, link, meta, iframe, object, embed, form, input, button, textarea, select').forEach(function (node) {
      node.remove();
    });
    holder.querySelectorAll('*').forEach(function (node) {
      const tag = node.tagName.toLowerCase();
      Array.from(node.attributes).forEach(function (attr) {
        const name = attr.name.toLowerCase();
        const keep =
          (tag === 'a' && (name === 'href' || name === 'title')) ||
          (tag === 'img' && (name === 'src' || name === 'alt' || name === 'title')) ||
          ((tag === 'audio' || tag === 'video' || tag === 'source') && (name === 'src' || name === 'controls' || name === 'preload' || name === 'data-media-label')) ||
          ((tag === 'th' || tag === 'td') && (name === 'colspan' || name === 'rowspan'));
        if (!keep) {
          node.removeAttribute(attr.name);
        }
      });
    });
  }

  function inlineNodeToMarkdown(node) {
    if (node.nodeType === Node.TEXT_NODE) {
      return normalizeInlineText(node.nodeValue || '');
    }
    if (node.nodeType !== Node.ELEMENT_NODE) {
      return '';
    }

    const tag = node.tagName.toLowerCase();
    if (tag === 'br') {
      return '  \n';
    }
    if (tag === 'script' || tag === 'style') {
      return '';
    }
    if (isBlockTag(tag) && tag !== 'figcaption') {
      return blockNodeToMarkdown(node, 0);
    }
    if (tag === 'strong' || tag === 'b') {
      const value = inlineChildrenToMarkdown(node).trim();
      return value ? '**' + value + '**' : '';
    }
    if (tag === 'em' || tag === 'i') {
      const value = inlineChildrenToMarkdown(node).trim();
      return value ? '*' + value + '*' : '';
    }
    if (tag === 'code') {
      return '`' + textContent(node).replace(/`/g, '') + '`';
    }
    if (tag === 'a') {
      const href = cleanUrl(node.getAttribute('href') || '#');
      const label = inlineChildrenToMarkdown(node).trim() || href || 'link';
      return '[' + label.replace(/\[/g, '\\[').replace(/\]/g, '\\]') + '](' + href + ')';
    }
    if (tag === 'img') {
      const alt = markdownSafeLabel(node.getAttribute('alt') || '', 'Image');
      const src = cleanUrl(node.getAttribute('src') || '#');
      return '![' + alt + '](' + src + ')';
    }
    if (tag === 'audio' || tag === 'video') {
      const source = node.querySelector('source');
      const src = cleanUrl(node.getAttribute('src') || (source ? source.getAttribute('src') : '') || '#');
      const fallback = tag === 'audio' ? 'Audio' : 'Video';
      const label = markdownSafeLabel(node.getAttribute('data-media-label') || fallback, fallback);
      return '[' + label + '](' + src + ')';
    }

    return inlineChildrenToMarkdown(node);
  }

  function inlineChildrenToMarkdown(node) {
    return Array.from(node.childNodes).map(inlineNodeToMarkdown).join('')
      .replace(/[ \t]{2,}/g, ' ')
      .replace(/[ \t]+\n/g, '\n')
      .replace(/\n[ \t]+/g, '\n');
  }

  function blockNodeToMarkdown(node, depth) {
    if (node.nodeType === Node.TEXT_NODE) {
      return textContent(node);
    }
    if (node.nodeType !== Node.ELEMENT_NODE) {
      return '';
    }

    depth = depth || 0;
    const tag = node.tagName.toLowerCase();
    if (tag === 'script' || tag === 'style') {
      return '';
    }
    if (tag === 'figure') {
      const media = node.querySelector('img, audio, video, a');
      const caption = node.querySelector('figcaption');
      const mediaMarkdownValue = media ? blockNodeToMarkdown(media, depth) : childrenToMarkdown(node, depth).trim();
      const captionValue = caption ? inlineChildrenToMarkdown(caption).trim() : '';
      return captionValue ? mediaMarkdownValue + '\n\n*' + captionValue + '*' : mediaMarkdownValue;
    }
    if (tag === 'audio' || tag === 'video') {
      const source = node.querySelector('source');
      const src = cleanUrl(node.getAttribute('src') || (source ? source.getAttribute('src') : '') || '#');
      const fallback = tag === 'audio' ? 'Audio' : 'Video';
      const label = markdownSafeLabel(node.getAttribute('data-media-label') || fallback, fallback);
      return '[' + label + '](' + src + ')';
    }
    if (tag === 'img') {
      return inlineNodeToMarkdown(node);
    }
    if (tag === 'a') {
      return inlineNodeToMarkdown(node);
    }
    if (/^h[1-6]$/.test(tag)) {
      const level = Number(tag.slice(1));
      const value = inlineChildrenToMarkdown(node).trim();
      return value ? repeat('#', level) + ' ' + value : '';
    }
    if (tag === 'p') {
      return inlineChildrenToMarkdown(node).trim();
    }
    if (tag === 'div' || tag === 'section' || tag === 'article' || tag === 'main' || tag === 'header' || tag === 'footer') {
      if (hasBlockChild(node)) {
        return childrenToMarkdown(node, depth).trim();
      }
      return inlineChildrenToMarkdown(node).trim();
    }
    if (tag === 'blockquote') {
      const value = childrenToMarkdown(node, depth).trim();
      return value.split('\n').map(function (line) { return line.trim() ? '> ' + line : '>'; }).join('\n');
    }
    if (tag === 'ul') {
      return Array.from(node.children).filter(function (child) { return child.tagName && child.tagName.toLowerCase() === 'li'; }).map(function (li) {
        const value = childrenToMarkdown(li, depth + 1).trim().replace(/\n/g, '\n' + repeat('  ', depth + 1));
        return repeat('  ', depth) + '- ' + value;
      }).join('\n');
    }
    if (tag === 'ol') {
      return Array.from(node.children).filter(function (child) { return child.tagName && child.tagName.toLowerCase() === 'li'; }).map(function (li, index) {
        const value = childrenToMarkdown(li, depth + 1).trim().replace(/\n/g, '\n' + repeat('  ', depth + 1));
        return repeat('  ', depth) + (index + 1) + '. ' + value;
      }).join('\n');
    }
    if (tag === 'pre') {
      const code = node.querySelector('code') || node;
      return '```\n' + (code.textContent || '').replace(/\s+$/g, '') + '\n```';
    }
    if (tag === 'hr') {
      return '---';
    }
    if (tag === 'table') {
      const rows = Array.from(node.querySelectorAll('tr')).map(function (tr) {
        return Array.from(tr.children).map(function (cell) {
          return inlineChildrenToMarkdown(cell).trim().replace(/\|/g, '\\|');
        });
      }).filter(function (row) { return row.length > 0; });
      if (!rows.length) {
        return '';
      }
      const header = rows[0];
      const divider = header.map(function () { return '---'; });
      return ['| ' + header.join(' | ') + ' |', '| ' + divider.join(' | ') + ' |'].concat(rows.slice(1).map(function (row) {
        const cells = header.map(function (_, index) { return row[index] || ''; });
        return '| ' + cells.join(' | ') + ' |';
      })).join('\n');
    }

    return childrenToMarkdown(node, depth).trim();
  }

  function childrenToMarkdown(node, depth) {
    depth = depth || 0;
    return Array.from(node.childNodes).map(function (child) {
      return blockNodeToMarkdown(child, depth);
    }).filter(function (value) {
      return value.trim() !== '';
    }).join('\n\n');
  }

  function htmlToMarkdown(html) {
    const holder = document.createElement('div');
    holder.innerHTML = String(html || '');
    stripUnsafeEditorNodes(holder);
    return normalizeMarkdownOutput(childrenToMarkdown(holder, 0));
  }

  function updatePreview(root) {
    const textarea = root.querySelector('#body_markdown');
    const visual = root.querySelector('#visual-editor');
    const preview = root.querySelector('[data-editor-preview]');
    const current = root.getAttribute('data-current-mode') || 'visual';
    if (!textarea || !preview) { return; }
    if (current === 'visual' && visual) {
      textarea.value = htmlToMarkdown(visual.innerHTML);
    }
    preview.innerHTML = markdownToHtml(textarea.value);
    scheduleComposerViewportFill(root);
  }

  function updateEditorMetrics(root) {
    const textarea = root.querySelector('#body_markdown');
    const visual = root.querySelector('#visual-editor');
    const wordsNode = root.querySelector('[data-editor-word-count]');
    const charsNode = root.querySelector('[data-editor-character-count]');
    if (!wordsNode || !charsNode) { return; }
    let text = '';
    const current = root.getAttribute('data-current-mode') || 'visual';
    if (current === 'visual' && visual) {
      text = visual.textContent || '';
    } else if (textarea) {
      text = textarea.value || '';
    }
    const trimmed = text.trim();
    const words = trimmed ? trimmed.split(/\s+/).filter(Boolean).length : 0;
    const chars = text.replace(/\s+$/g, '').length;
    wordsNode.textContent = words + (words === 1 ? ' word' : ' words');
    charsNode.textContent = chars + (chars === 1 ? ' character' : ' characters');
  }


  let composerViewportFillFrame = null;

  function composerViewportMinimum(form) {
    if (window.innerWidth <= 640) { return 260; }
    if (window.innerWidth <= 900) { return 300; }
    if (form && form.classList.contains('is-density-compact')) { return 300; }
    if (form && form.classList.contains('is-density-spacious')) { return 480; }
    return 380;
  }

  function composerViewportMaximum(form) {
    if (window.innerWidth <= 640) { return 680; }
    if (window.innerWidth <= 900) { return 900; }
    if (form && form.classList.contains('is-density-compact')) { return 1100; }
    if (form && form.classList.contains('is-density-spacious')) { return 1800; }
    return 1400;
  }

  function composerDesktopColumnsActive(form) {
    if (!form || window.innerWidth <= 1080 || form.classList.contains('is-single-column-editor')) { return false; }
    const workspace = form.querySelector('.editor-workspace');
    const primary = form.querySelector('.editor-primary-column');
    const sidebar = form.querySelector('.editor-sidebar-column');
    if (!workspace || !primary || !sidebar || sidebar.hidden) { return false; }
    const primaryRect = primary.getBoundingClientRect();
    const sidebarRect = sidebar.getBoundingClientRect();
    return sidebarRect.left > primaryRect.left && sidebarRect.top < primaryRect.bottom + 100;
  }

  function visibleSidebarBottom(form) {
    const sidebar = form ? form.querySelector('.editor-sidebar-column') : null;
    if (!sidebar) { return null; }
    let bottom = null;
    sidebar.querySelectorAll('.side-card:not([hidden])').forEach(function (card) {
      const rect = card.getBoundingClientRect();
      if (!rect.width && !rect.height) { return; }
      const cardBottom = rect.top + card.offsetHeight;
      bottom = bottom === null ? cardBottom : Math.max(bottom, cardBottom);
    });
    if (bottom === null) {
      const rect = sidebar.getBoundingClientRect();
      bottom = rect.top + sidebar.offsetHeight;
    }
    return bottom;
  }

  function sidebarNaturalDocumentBottom(form) {
    const workspace = form ? form.querySelector('.editor-workspace') : null;
    const sidebar = form ? form.querySelector('.editor-sidebar-column') : null;
    if (!workspace || !sidebar || sidebar.hidden) { return null; }
    const workspaceTop = workspace.getBoundingClientRect().top + window.scrollY;
    return workspaceTop + sidebar.offsetTop + sidebar.offsetHeight;
  }

  function composerSurfaceContentHeight(surface) {
    if (!surface) { return 0; }
    const previousHeight = surface.style.height;
    const previousMinHeight = surface.style.minHeight;
    const previousMaxHeight = surface.style.maxHeight;
    const previousOverflow = surface.style.overflowY;
    surface.style.height = 'auto';
    surface.style.minHeight = '0px';
    surface.style.maxHeight = 'none';
    surface.style.overflowY = 'hidden';
    const height = Math.ceil(surface.scrollHeight || 0);
    surface.style.height = previousHeight;
    surface.style.minHeight = previousMinHeight;
    surface.style.maxHeight = previousMaxHeight;
    surface.style.overflowY = previousOverflow;
    return height;
  }

  function activeEditorSurfaces(root) {
    if (!root) { return []; }
    return Array.from(root.querySelectorAll('.visual-editor, textarea#body_markdown, .visual-preview'));
  }

  function updateComposerViewportFill(root) {
    if (!root || root.classList.contains('is-focus-mode')) { return; }
    const form = root.closest('form');
    if (!form) { return; }
    const activePanel = root.querySelector('.editor-mode-panel.active') || root.querySelector('[data-editor-panel]:not([hidden])');
    const surface = activePanel ? activePanel.querySelector('.visual-editor, textarea#body_markdown, .visual-preview') : root.querySelector('.visual-editor, textarea#body_markdown, .visual-preview');
    if (!surface) { return; }

    const minimum = composerViewportMinimum(form);
    const maximum = composerViewportMaximum(form);
    const contentHeight = composerSurfaceContentHeight(surface) + 24;
    let desired = Math.max(minimum, contentHeight);

    if (!Number.isFinite(desired)) {
      desired = minimum;
    }

    const isLongform = desired > maximum;
    desired = Math.min(desired, maximum);
    form.classList.toggle('is-longform-editor', isLongform);
    form.style.setProperty('--editor-composer-content-height', Math.max(contentHeight, 0) + 'px');
    form.style.setProperty('--editor-composer-viewport-height', desired + 'px');

    activeEditorSurfaces(root).forEach(function (candidate) {
      candidate.classList.toggle('is-longform-scroll-surface', isLongform && candidate === surface);
    });
  }

  function scheduleComposerViewportFill(root) {
    if (!root || !window.requestAnimationFrame) {
      updateComposerViewportFill(root);
      return;
    }
    if (composerViewportFillFrame) {
      window.cancelAnimationFrame(composerViewportFillFrame);
    }
    composerViewportFillFrame = window.requestAnimationFrame(function () {
      composerViewportFillFrame = null;
      updateComposerViewportFill(root);
    });
  }

  function setMode(root, mode) {
    mode = safeEditorMode(mode, 'visual');
    const textarea = root.querySelector('#body_markdown');
    const visual = root.querySelector('#visual-editor');
    const current = root.getAttribute('data-current-mode') || 'visual';

    if (current === 'visual' && textarea && visual) {
      textarea.value = htmlToMarkdown(visual.innerHTML);
    }

    if (mode === 'visual' && textarea && visual) {
      visual.innerHTML = markdownToHtml(textarea.value);
    }

    if (mode === 'preview') {
      updatePreview(root);
    }

    root.querySelectorAll('[data-editor-mode]').forEach(function (button) {
      const active = button.getAttribute('data-editor-mode') === mode;
      button.classList.toggle('active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
      button.setAttribute('tabindex', active ? '0' : '-1');
    });
    root.querySelectorAll('[data-editor-panel]').forEach(function (panel) {
      const active = panel.getAttribute('data-editor-panel') === mode;
      panel.classList.toggle('active', active);
      panel.hidden = !active;
      panel.setAttribute('tabindex', active ? '0' : '-1');
    });

    root.querySelectorAll('[data-editor-toolbar]').forEach(function (toolbar) {
      toolbar.hidden = toolbar.getAttribute('data-editor-toolbar') !== mode;
    });

    root.setAttribute('data-current-mode', mode);
    updateEditorMetrics(root);
    scheduleComposerViewportFill(root);
  }

  function selectionInside(container) {
    const selection = window.getSelection();
    if (!container || !selection || !selection.rangeCount) { return false; }
    const anchor = selection.anchorNode;
    const focus = selection.focusNode;
    return !!anchor && !!focus && container.contains(anchor) && container.contains(focus);
  }

  function moveCaretToEnd(container) {
    if (!container) { return; }
    container.focus();
    const selection = window.getSelection();
    const range = document.createRange();
    range.selectNodeContents(container);
    range.collapse(false);
    selection.removeAllRanges();
    selection.addRange(range);
  }

  function insertHtmlAtSelection(html, container) {
    if (container && !selectionInside(container)) {
      moveCaretToEnd(container);
    }
    const selection = window.getSelection();
    if (!selection || !selection.rangeCount) {
      document.execCommand('insertHTML', false, html);
      return;
    }
    const range = selection.getRangeAt(0);
    range.deleteContents();
    const holder = document.createElement('div');
    holder.innerHTML = html;
    const fragment = document.createDocumentFragment();
    let node;
    let lastNode = null;
    while ((node = holder.firstChild)) {
      lastNode = fragment.appendChild(node);
    }
    range.insertNode(fragment);
    if (lastNode) {
      range.setStartAfter(lastNode);
      range.collapse(true);
      selection.removeAllRanges();
      selection.addRange(range);
    }
  }


  function insertTextIntoTextarea(textarea, text) {
    const start = textarea.selectionStart || textarea.value.length;
    const end = textarea.selectionEnd || textarea.value.length;
    const before = textarea.value.slice(0, start);
    const after = textarea.value.slice(end);
    const prefix = before && !before.endsWith('\n') ? '\n\n' : '';
    const suffix = after && !after.startsWith('\n') ? '\n\n' : '';
    textarea.value = before + prefix + text + suffix + after;
    const cursor = (before + prefix + text).length;
    textarea.focus();
    textarea.setSelectionRange(cursor, cursor);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function selectedRange(textarea) {
    return {
      start: typeof textarea.selectionStart === 'number' ? textarea.selectionStart : textarea.value.length,
      end: typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : textarea.value.length
    };
  }

  function replaceTextareaSelection(textarea, replacement, cursorOffset) {
    const range = selectedRange(textarea);
    const before = textarea.value.slice(0, range.start);
    const after = textarea.value.slice(range.end);
    textarea.value = before + replacement + after;
    const cursor = before.length + (typeof cursorOffset === 'number' ? cursorOffset : replacement.length);
    textarea.focus();
    textarea.setSelectionRange(cursor, cursor);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function wrapTextareaSelection(textarea, before, after, placeholder) {
    const range = selectedRange(textarea);
    const selected = textarea.value.slice(range.start, range.end) || placeholder || '';
    replaceTextareaSelection(textarea, before + selected + after, before.length + selected.length);
  }

  function linePrefixTextareaSelection(textarea, prefix, placeholder) {
    const range = selectedRange(textarea);
    const selected = textarea.value.slice(range.start, range.end) || placeholder || '';
    const lines = selected.split('\n');
    const replacement = lines.map(function (line, index) {
      if (prefix === 'ordered') {
        return (index + 1) + '. ' + line.replace(/^\d+\.\s+/, '');
      }
      return prefix + line.replace(/^([#>\-*+]\s+)/, '');
    }).join('\n');
    replaceTextareaSelection(textarea, replacement, replacement.length);
  }

  function applyMarkdownCommand(root, command, value) {
    const textarea = root.querySelector('#body_markdown');
    if (!textarea) { return; }
    textarea.focus();

    if (command === 'heading') {
      linePrefixTextareaSelection(textarea, value || '## ', 'Heading');
      return;
    }
    if (command === 'bold') {
      wrapTextareaSelection(textarea, '**', '**', 'bold text');
      return;
    }
    if (command === 'italic') {
      wrapTextareaSelection(textarea, '*', '*', 'italic text');
      return;
    }
    if (command === 'link') {
      const range = selectedRange(textarea);
      const selected = textarea.value.slice(range.start, range.end) || 'link text';
      const url = window.prompt('Enter the link URL');
      if (url) {
        replaceTextareaSelection(textarea, '[' + selected + '](' + cleanUrl(url) + ')', selected.length + 3);
      }
      return;
    }
    if (command === 'unordered-list') {
      linePrefixTextareaSelection(textarea, '- ', 'List item');
      return;
    }
    if (command === 'ordered-list') {
      linePrefixTextareaSelection(textarea, 'ordered', 'List item');
      return;
    }
    if (command === 'quote') {
      linePrefixTextareaSelection(textarea, '> ', 'Quoted text');
      return;
    }
    if (command === 'code') {
      const range = selectedRange(textarea);
      const selected = textarea.value.slice(range.start, range.end);
      if (selected.indexOf('\n') !== -1) {
        replaceTextareaSelection(textarea, '```\n' + selected + '\n```', selected.length + 4);
      } else {
        wrapTextareaSelection(textarea, '`', '`', 'code');
      }
      return;
    }
    if (command === 'rule') {
      insertTextIntoTextarea(textarea, '---');
    }
  }

  function mediaFromButton(button) {
    return {
      url: button.getAttribute('data-media-url') || '',
      path: button.getAttribute('data-media-path') || button.getAttribute('data-media-url') || '',
      alt: button.getAttribute('data-media-alt') || '',
      label: button.getAttribute('data-media-label') || button.getAttribute('data-media-alt') || '',
      mime: button.getAttribute('data-media-mime') || '',
      kind: button.getAttribute('data-media-kind') || '',
      caption: button.getAttribute('data-media-caption') || '',
      markdown: button.getAttribute('data-media-markdown') || ''
    };
  }

  function insertMediaIntoEditor(root, data, overrideLabel, overrideCaption) {
    const textarea = root.querySelector('#body_markdown');
    const visual = root.querySelector('#visual-editor');
    const current = root.getAttribute('data-current-mode') || 'visual';
    const markdown = mediaMarkdown(data, overrideLabel, overrideCaption);

    if (current === 'markdown' && textarea) {
      insertTextIntoTextarea(textarea, markdown);
      updateEditorMetrics(root);
      scheduleComposerViewportFill(root);
      return;
    }

    if (current === 'preview') {
      setMode(root, 'visual');
    }

    if (visual) {
      visual.focus();
      insertHtmlAtSelection(mediaHtml(data, overrideLabel, overrideCaption), visual);
      visual.dispatchEvent(new Event('input', { bubbles: true }));
      updateEditorMetrics(root);
      scheduleComposerViewportFill(root);
      return;
    }

    if (textarea) {
      insertTextIntoTextarea(textarea, markdown);
    }
  }

  function setupEditorPhotoGallery(form, root) {
    if (!form || form._bmsPhotoGalleryController) { return form ? form._bmsPhotoGalleryController : null; }
    const shell = form.querySelector('[data-editor-photo-gallery]');
    if (!shell) { return null; }
    const list = shell.querySelector('[data-editor-photo-gallery-list]');
    const fields = shell.querySelector('[data-editor-photo-gallery-fields]');
    const empty = shell.querySelector('[data-editor-photo-gallery-empty]');
    const countNode = shell.querySelector('[data-editor-photo-gallery-count]');
    const featuredInput = form.querySelector('input[name="featured_media"]');
    const max = Math.max(1, Math.min(4, parseInt(shell.getAttribute('data-gallery-max') || '4', 10) || 4));
    let items = [];
    let managed = false;

    shell.querySelectorAll('[data-gallery-item]').forEach(function (node) {
      const path = node.getAttribute('data-gallery-path') || '';
      const url = node.getAttribute('data-gallery-url') || path;
      if (!path) { return; }
      items.push({ path: path, url: url, alt: node.getAttribute('data-gallery-alt') || 'Stream post photo', mime: 'image/' });
    });
    items = items.slice(0, max);

    function setStatus(message, isError) {
      if (!countNode) { return; }
      countNode.textContent = message || (items.length + ' of ' + max + ' photos');
      countNode.classList.toggle('is-error', !!isError);
    }

    function render(updateFeatured) {
      if (list) {
        list.innerHTML = '';
        items.forEach(function (item, index) {
          const card = document.createElement('div');
          card.className = 'editor-photo-gallery-item';
          const img = document.createElement('img');
          img.src = item.url || item.path;
          img.alt = '';
          img.loading = 'lazy';
          card.appendChild(img);
          const label = document.createElement('span');
          label.textContent = 'Photo ' + (index + 1);
          card.appendChild(label);
          const actions = document.createElement('div');
          actions.className = 'editor-photo-gallery-actions';
          const left = document.createElement('button');
          left.type = 'button';
          left.textContent = '←';
          left.disabled = index === 0;
          left.setAttribute('aria-label', 'Move photo left');
          left.addEventListener('click', function () {
            if (index <= 0) { return; }
            const moved = items.splice(index, 1)[0];
            items.splice(index - 1, 0, moved);
            managed = true;
            render(true);
          });
          actions.appendChild(left);
          const right = document.createElement('button');
          right.type = 'button';
          right.textContent = '→';
          right.disabled = index === items.length - 1;
          right.setAttribute('aria-label', 'Move photo right');
          right.addEventListener('click', function () {
            if (index >= items.length - 1) { return; }
            const moved = items.splice(index, 1)[0];
            items.splice(index + 1, 0, moved);
            managed = true;
            render(true);
          });
          actions.appendChild(right);
          const remove = document.createElement('button');
          remove.type = 'button';
          remove.textContent = 'Remove';
          remove.setAttribute('aria-label', 'Remove photo ' + (index + 1));
          remove.addEventListener('click', function () {
            items.splice(index, 1);
            managed = true;
            render(true);
          });
          actions.appendChild(remove);
          card.appendChild(actions);
          list.appendChild(card);
        });
      }
      if (fields) {
        fields.innerHTML = '';
        items.forEach(function (item) {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'media_gallery[]';
          input.value = item.path;
          fields.appendChild(input);
        });
      }
      if (empty) { empty.hidden = items.length > 0; }
      setStatus('', false);
      if (featuredInput && updateFeatured && managed) {
        featuredInput.value = items.length ? items[0].path : '';
      }
    }

    const controller = {
      add: function (data) {
        const mime = String(data && data.mime || '').toLowerCase();
        const path = String(data && (data.path || data.public_path || data.url) || '').trim();
        const url = String(data && data.url || path).trim();
        if (!path || mime.indexOf('image/') !== 0) {
          setStatus('Photo galleries only accept image files.', true);
          return false;
        }
        if (items.some(function (item) { return item.path === path; })) {
          setStatus('That photo is already in this gallery.', true);
          return false;
        }
        if (items.length >= max) {
          setStatus('This gallery already has the maximum of ' + max + ' photos.', true);
          return false;
        }
        items.push({ path: path, url: url, alt: String(data.alt || 'Stream post photo'), mime: mime });
        managed = true;
        render(true);
        return true;
      },
      count: function () { return items.length; }
    };
    form._bmsPhotoGalleryController = controller;
    render(false);
    return controller;
  }

  function addMediaToPhotoGallery(root, data) {
    const form = root ? root.closest('form') : null;
    const controller = setupEditorPhotoGallery(form, root);
    return controller ? controller.add(data || {}) : false;
  }

  function mediaItemButtonHtml(item) {
    const type = mediaTypeFromMimeOrUrl(item.mime, item.url);
    const label = cleanMediaLabel(item.label || item.alt || item.filename, 'Media');
    const thumb = type === 'image'
      ? '<img src="' + escapeHtml(item.url || '#') + '" alt="' + escapeHtml(item.alt || label) + '" loading="lazy">'
      : '<span class="media-file-badge">' + escapeHtml(item.kind || 'File') + '</span>';
    const dimensions = item.width && item.height ? (item.width + '×' + item.height + ' · ') : '';
    const search = String(item.search || (label + ' ' + (item.alt || '') + ' ' + (item.caption || '') + ' ' + (item.kind || '') + ' ' + (item.mime || ''))).toLowerCase();
    return '<button type="button" class="media-picker-item" data-insert-media aria-label="Insert media"'
      + ' data-media-id="' + escapeHtml(item.id || '') + '"'
      + ' data-media-url="' + escapeHtml(item.url || '') + '"'
      + ' data-media-path="' + escapeHtml(item.public_path || item.path || item.url || '') + '"'
      + ' data-media-alt="' + escapeHtml(item.alt || '') + '"'
      + ' data-media-caption="' + escapeHtml(item.caption || '') + '"'
      + ' data-media-label="' + escapeHtml(label) + '"'
      + ' data-media-mime="' + escapeHtml(item.mime || '') + '"'
      + ' data-media-kind="' + escapeHtml(item.kind || '') + '"'
      + ' data-media-markdown="' + escapeHtml(item.markdown || mediaMarkdown(item)) + '"'
      + ' data-media-search-text="' + escapeHtml(search) + '">'
      + '<span class="media-picker-thumb">' + thumb + '</span>'
      + '<span class="media-picker-item-title">' + escapeHtml(label) + '</span>'
      + '<span class="media-picker-item-meta">' + escapeHtml((item.kind || 'Media') + ' · ' + dimensions + (item.size || '')) + '</span>'
      + '</button>';
  }

  function renderMediaLibrary(picker, items) {
    const grid = picker.querySelector('[data-media-library-grid]');
    if (!grid) { return; }
    if (!items || !items.length) {
      grid.innerHTML = '<div class="empty-state compact-empty-state" data-media-empty-state><h3>No media yet.</h3><p class="meta">Upload a file from this window, then insert it directly into the post.</p></div>';
      return;
    }
    grid.innerHTML = items.map(mediaItemButtonHtml).join('');
  }

  function setMediaTab(picker, tabName) {
    let activeTab = null;
    picker.querySelectorAll('[data-media-tab]').forEach(function (button) {
      const active = button.getAttribute('data-media-tab') === tabName;
      button.classList.toggle('active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
      button.setAttribute('tabindex', active ? '0' : '-1');
      if (active) { activeTab = button; }
    });
    picker.querySelectorAll('[data-media-panel]').forEach(function (panel) {
      const active = panel.getAttribute('data-media-panel') === tabName;
      panel.classList.toggle('active', active);
      panel.hidden = !active;
    });
    return activeTab;
  }

  function closeMediaPicker(picker) {
    if (!picker) {
      return;
    }
    picker.hidden = true;
    picker.setAttribute('aria-hidden', 'true');
    restoreDialogReturnFocus();
  }

  function openMediaPicker(root, trigger) {
    const picker = root.parentElement ? root.parentElement.querySelector('[data-media-picker]') : null;
    if (!picker) {
      return false;
    }
    const mode = trigger && trigger.getAttribute ? (trigger.getAttribute('data-media-picker-mode') || 'body') : 'body';
    picker.setAttribute('data-media-picker-mode', mode);
    const pickerTitle = picker.querySelector('#media-picker-title');
    const pickerDescription = picker.querySelector('#media-picker-description');
    if (pickerTitle) { pickerTitle.textContent = mode === 'gallery' ? 'Add Gallery Photo' : 'Add Media'; }
    if (pickerDescription) { pickerDescription.textContent = mode === 'gallery' ? 'Choose or upload an image for this post gallery. Galleries support up to four photos.' : 'Upload a new file or insert something already in the library without leaving the editor.'; }
    const uploadFileInput = picker.querySelector('[data-media-upload-form] input[type="file"]');
    if (uploadFileInput) {
      if (!uploadFileInput.getAttribute('data-original-accept')) { uploadFileInput.setAttribute('data-original-accept', uploadFileInput.getAttribute('accept') || ''); }
      uploadFileInput.setAttribute('accept', mode === 'gallery' ? 'image/*' : (uploadFileInput.getAttribute('data-original-accept') || ''));
    }
    activeDialogReturnTarget = trigger || document.activeElement;
    picker.hidden = false;
    picker.setAttribute('aria-hidden', 'false');
    const panel = picker.querySelector('.media-picker-panel');
    const first = picker.querySelector('[data-media-tab][aria-selected="true"], input[type="file"], [data-insert-media], [data-close-media-picker]');
    if (first) {
      first.focus();
    } else if (panel) {
      panel.focus();
    }
    return true;
  }

  function sideCardStorageKey() {
    return 'bonumark-editor-state:sidebar-cards';
  }

  function cardKeyFrom(card, index) {
    const explicit = card.getAttribute('data-editor-card');
    if (explicit) { return explicit; }
    const heading = card.querySelector('h3');
    const label = (heading ? heading.textContent : card.getAttribute('aria-label') || ('card-' + index)).trim();
    return label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || ('card-' + index);
  }

  function setCardCollapsed(card, toggle, collapsed) {
    card.classList.toggle('is-collapsed', collapsed);
    if (toggle) {
      toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      toggle.textContent = collapsed ? 'Open' : 'Close';
      toggle.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
    }
  }

  function setupSidebarCardState(form) {
    const sidebar = form ? form.querySelector('.editor-sidebar-column') : null;
    if (!sidebar) { return; }
    const key = sideCardStorageKey();
    const state = readStoredJson(key, {});

    sidebar.querySelectorAll('.side-card').forEach(function (card, index) {
      if (card.classList.contains('publish-card') || card.matches('details')) { return; }
      const heading = card.querySelector('h3');
      if (!heading || heading.querySelector('[data-side-card-toggle]')) { return; }
      const cardKey = cardKeyFrom(card, index);
      card.setAttribute('data-side-card-key', cardKey);
      heading.classList.add('side-card-heading');

      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'side-card-toggle';
      toggle.setAttribute('data-side-card-toggle', '');
      toggle.setAttribute('aria-label', 'Toggle ' + (heading.textContent || cardKey));
      heading.appendChild(toggle);

      const hasStoredState = Object.prototype.hasOwnProperty.call(state, cardKey);
      const defaultCollapsed = card.getAttribute('data-editor-default-collapsed') === '1';
      setCardCollapsed(card, toggle, hasStoredState ? state[cardKey] === true : defaultCollapsed);
      toggle.addEventListener('click', function () {
        const current = readStoredJson(key, {});
        const collapsed = !card.classList.contains('is-collapsed');
        current[cardKey] = collapsed;
        writeStoredJson(key, current);
        setCardCollapsed(card, toggle, collapsed);
        const root = form.querySelector('[data-bonumark-editor]');
        if (root) { scheduleComposerViewportFill(root); }
      });
    });
  }

  function defaultScreenControlState() {
    return {
      cards: {
        'post-url': true,
        'stream-post': true,
        'media': true,
        'location': true,
        'revisions': true,
        'page-url': true,
        'page-settings': true,
      },
      metrics: true,
      previewTools: true,
      stickySidebar: true,
      density: 'comfortable',
      layout: 'standard'
    };
  }

  function normalizeScreenControlState(raw) {
    const defaults = defaultScreenControlState();
    const state = Object.assign({}, defaults, raw || {});
    state.cards = Object.assign({}, defaults.cards, (raw && raw.cards) || {});
    ['metrics', 'previewTools', 'stickySidebar'].forEach(function (key) {
      state[key] = state[key] !== false;
    });
    if (['comfortable', 'compact', 'spacious'].indexOf(state.density) === -1) { state.density = 'comfortable'; }
    if (['standard', 'wide', 'single'].indexOf(state.layout) === -1) { state.layout = 'standard'; }
    return state;
  }

  function readScreenControlState() {
    return normalizeScreenControlState(readStoredJson(screenControlsStateKey(), defaultScreenControlState()));
  }

  function writeScreenControlState(state) {
    writeStoredJson(screenControlsStateKey(), normalizeScreenControlState(state));
  }

  function applyScreenControls(form, root, state) {
    if (!form) { return; }
    const normalized = normalizeScreenControlState(state);
    form.classList.toggle('is-density-compact', normalized.density === 'compact');
    form.classList.toggle('is-density-spacious', normalized.density === 'spacious');
    form.classList.toggle('is-wide-editor', normalized.layout === 'wide');
    form.classList.toggle('is-single-column-editor', normalized.layout === 'single');
    form.classList.toggle('has-sticky-sidebar', normalized.stickySidebar !== false);
    form.classList.toggle('no-sticky-sidebar', normalized.stickySidebar === false);

    if (root) {
      const metrics = root.querySelector('[data-editor-metrics]');
      if (metrics) { metrics.hidden = normalized.metrics === false; }
      root.querySelectorAll('[data-screen-preview-tool]').forEach(function (node) {
        node.hidden = normalized.previewTools === false;
      });
    }

    form.querySelectorAll('[data-editor-card]').forEach(function (card) {
      const key = card.getAttribute('data-editor-card');
      if (key === 'publish') {
        card.hidden = false;
        return;
      }
      card.hidden = normalized.cards[key] === false;
    });
  }

  function syncScreenControlsPanel(shell, state) {
    if (!shell) { return; }
    const normalized = normalizeScreenControlState(state);
    shell.querySelectorAll('[data-screen-card-toggle]').forEach(function (control) {
      const key = control.getAttribute('data-screen-card-toggle');
      control.checked = normalized.cards[key] !== false;
    });
    shell.querySelectorAll('[data-screen-toggle]').forEach(function (control) {
      const key = control.getAttribute('data-screen-toggle');
      control.checked = normalized[key] !== false;
    });
    const density = shell.querySelector('[data-screen-density]');
    if (density) { density.value = normalized.density; }
    const layout = shell.querySelector('[data-screen-layout]');
    if (layout) { layout.value = normalized.layout; }
  }

  function setupScreenControls(form, root) {
    const shell = document.querySelector('[data-editor-screen-controls-shell]');
    if (!shell || !form) { return; }
    const panel = shell.querySelector('[data-screen-controls]');
    const toggle = shell.querySelector('[data-screen-controls-toggle]');
    const close = shell.querySelector('[data-screen-controls-close]');
    let state = readScreenControlState();

    function saveAndApply(next) {
      state = normalizeScreenControlState(next || state);
      writeScreenControlState(state);
      applyScreenControls(form, root, state);
      syncScreenControlsPanel(shell, state);
      scheduleComposerViewportFill(root);
    }

    function setPanelOpen(open) {
      if (!panel || !toggle) { return; }
      panel.hidden = !open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    if (toggle) {
      toggle.addEventListener('click', function () {
        setPanelOpen(panel ? panel.hidden : false);
      });
    }
    if (close) { close.addEventListener('click', function () { setPanelOpen(false); }); }

    shell.querySelectorAll('[data-screen-card-toggle]').forEach(function (control) {
      control.addEventListener('change', function () {
        const next = readScreenControlState();
        next.cards[control.getAttribute('data-screen-card-toggle')] = control.checked;
        saveAndApply(next);
      });
    });

    shell.querySelectorAll('[data-screen-toggle]').forEach(function (control) {
      control.addEventListener('change', function () {
        const next = readScreenControlState();
        next[control.getAttribute('data-screen-toggle')] = control.checked;
        saveAndApply(next);
      });
    });

    const density = shell.querySelector('[data-screen-density]');
    if (density) {
      density.addEventListener('change', function () {
        const next = readScreenControlState();
        next.density = density.value;
        saveAndApply(next);
      });
    }

    const layout = shell.querySelector('[data-screen-layout]');
    if (layout) {
      layout.addEventListener('change', function () {
        const next = readScreenControlState();
        next.layout = layout.value;
        saveAndApply(next);
      });
    }

    const openAll = shell.querySelector('[data-side-cards-open]');
    if (openAll) {
      openAll.addEventListener('click', function () {
        const sidebarState = readStoredJson(sideCardStorageKey(), {});
        form.querySelectorAll('.editor-sidebar-column .side-card').forEach(function (card, index) {
          if (card.classList.contains('publish-card')) { return; }
          if (card.matches('details')) { card.open = true; return; }
          const key = card.getAttribute('data-side-card-key') || cardKeyFrom(card, index);
          sidebarState[key] = false;
          setCardCollapsed(card, card.querySelector('[data-side-card-toggle]'), false);
        });
        writeStoredJson(sideCardStorageKey(), sidebarState);
        scheduleComposerViewportFill(root);
      });
    }

    const collapseAll = shell.querySelector('[data-side-cards-collapse]');
    if (collapseAll) {
      collapseAll.addEventListener('click', function () {
        const sidebarState = readStoredJson(sideCardStorageKey(), {});
        form.querySelectorAll('.editor-sidebar-column .side-card').forEach(function (card, index) {
          if (card.classList.contains('publish-card') || card.hidden) { return; }
          if (card.matches('details')) { card.open = false; return; }
          const key = card.getAttribute('data-side-card-key') || cardKeyFrom(card, index);
          sidebarState[key] = true;
          setCardCollapsed(card, card.querySelector('[data-side-card-toggle]'), true);
        });
        writeStoredJson(sideCardStorageKey(), sidebarState);
        scheduleComposerViewportFill(root);
      });
    }

    const reset = shell.querySelector('[data-screen-controls-reset]');
    if (reset) {
      reset.addEventListener('click', function () {
        try { localStorage.removeItem(screenControlsStateKey()); } catch (error) {}
        saveAndApply(defaultScreenControlState());
      });
    }

    syncScreenControlsPanel(shell, state);
    applyScreenControls(form, root, state);
  }


  function openShortcuts(root, trigger) {
    const panel = root.querySelector('[data-shortcuts-panel]');
    const toggle = root.querySelector('[data-shortcuts-toggle]');
    if (!panel) { return; }
    activeDialogReturnTarget = trigger || document.activeElement;
    panel.hidden = false;
    if (toggle) { toggle.setAttribute('aria-expanded', 'true'); }
    const card = panel.querySelector('.editor-shortcuts-card');
    const first = panel.querySelector('[data-shortcuts-close]') || card;
    if (first && typeof first.focus === 'function') { first.focus(); }
  }

  function closeShortcuts(root) {
    const panel = root.querySelector('[data-shortcuts-panel]');
    const toggle = root.querySelector('[data-shortcuts-toggle]');
    if (!panel) { return; }
    panel.hidden = true;
    if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
    restoreDialogReturnFocus();
  }

  function isTypingTarget(target) {
    if (!target) { return false; }
    const tag = target.tagName ? target.tagName.toLowerCase() : '';
    return target.isContentEditable || tag === 'textarea' || tag === 'input' || tag === 'select';
  }

  function normalizedFormatBlockValue(value) {
    const v = String(value || '').toLowerCase();
    if (v === 'p' || v === 'paragraph') { return '<p>'; }
    if (v === 'h2') { return '<h2>'; }
    if (v === 'h3') { return '<h3>'; }
    if (v === 'blockquote') { return '<blockquote>'; }
    if (v === 'pre') { return '<pre>'; }
    return value || null;
  }

  function applyCommand(root, command, value) {
    const visual = root.querySelector('#visual-editor');
    if (!visual) {
      return;
    }
    visual.focus();

    if (command === 'createLink') {
      const url = window.prompt('Enter the link URL');
      if (url) {
        document.execCommand(command, false, cleanUrl(url));
      }
      visual.dispatchEvent(new Event('input', { bubbles: true }));
      return;
    }

    if (command === 'insertMedia' || command === 'insertImage') {
      openMediaPicker(root);
      return;
    }

    if (command === 'formatBlock') {
      document.execCommand(command, false, normalizedFormatBlockValue(value));
      visual.dispatchEvent(new Event('input', { bubbles: true }));
      return;
    }

    document.execCommand(command, false, value || null);
    visual.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function attachEditor(root) {
    const textarea = root.querySelector('#body_markdown');
    const visual = root.querySelector('#visual-editor');
    if (!textarea || !visual) {
      return;
    }

    const form = root.closest('form');
    setupEditorPhotoGallery(form, root);
    const globalStateKey = editorGlobalStateKey();
    const postStateKey = editorPostStateKey(form);
    const globalState = readStoredJson(globalStateKey, {});
    const postState = readStoredJson(postStateKey, {});

    function saveGlobalState(patch) {
      const current = readStoredJson(globalStateKey, {});
      writeStoredJson(globalStateKey, Object.assign({}, current, patch || {}));
    }

    function savePostState(patch) {
      const current = readStoredJson(postStateKey, {});
      writeStoredJson(postStateKey, Object.assign({}, current, patch || {}, { updatedAt: new Date().toISOString() }));
    }

    function setEditorMode(rootNode, mode, persist) {
      setMode(rootNode, mode);
      if (persist !== false) {
        saveGlobalState({ lastMode: mode });
        savePostState({ mode: mode });
      }
    }

    function setFocusMode(active, persist) {
      const button = root.querySelector('[data-editor-focus-toggle]');
      root.classList.toggle('is-focus-mode', active);
      document.body.classList.toggle('editor-focus-active', active);
      if (button) {
        button.textContent = active ? 'Exit Focus' : 'Focus Mode';
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      }
      if (persist !== false) {
        saveGlobalState({ focusMode: active });
      }
      if (active) {
        const activePanel = root.querySelector('.editor-mode-panel.active .visual-editor, .editor-mode-panel.active textarea');
        if (activePanel) { activePanel.focus(); }
      }
      scheduleComposerViewportFill(root);
    }

    root.querySelectorAll('[data-editor-mode]').forEach(function (button) {
      button.addEventListener('click', function () {
        setEditorMode(root, button.getAttribute('data-editor-mode'), true);
      });
    });

    root.querySelectorAll('[data-command]').forEach(function (button) {
      button.addEventListener('click', function () {
        applyCommand(root, button.getAttribute('data-command'), button.getAttribute('data-value'));
        updateEditorMetrics(root);
      });
    });

    root.querySelectorAll('[data-markdown-command]').forEach(function (button) {
      button.addEventListener('click', function () {
        applyMarkdownCommand(root, button.getAttribute('data-markdown-command'), button.getAttribute('data-value'));
        updateEditorMetrics(root);
      });
    });

    root.querySelectorAll('[data-shortcuts-toggle]').forEach(function (button) {
      button.addEventListener('click', function () { openShortcuts(root, button); });
    });
    root.querySelectorAll('[data-shortcuts-close]').forEach(function (button) {
      button.addEventListener('click', function () { closeShortcuts(root); });
    });
    const shortcutsPanel = root.querySelector('[data-shortcuts-panel]');
    if (shortcutsPanel) {
      shortcutsPanel.addEventListener('keydown', function (event) {
        trapFocus(event, shortcutsPanel, function () { closeShortcuts(root); });
      });
      shortcutsPanel.addEventListener('click', function (event) {
        if (event.target === shortcutsPanel) { closeShortcuts(root); }
      });
    }

    root.querySelectorAll('[data-editor-preview-refresh]').forEach(function (button) {
      button.addEventListener('click', function () {
        updatePreview(root);
        updateEditorMetrics(root);
        scheduleComposerViewportFill(root);
      });
    });

    root.querySelectorAll('[data-editor-focus-toggle]').forEach(function (button) {
      button.addEventListener('click', function () {
        setFocusMode(!root.classList.contains('is-focus-mode'), true);
      });
    });

    const picker = root.parentElement ? root.parentElement.querySelector('[data-media-picker]') : null;
    if (picker) {
      picker.querySelectorAll('[data-close-media-picker]').forEach(function (button) {
        button.addEventListener('click', function () { closeMediaPicker(picker); });
      });
      const mediaTabs = Array.from(picker.querySelectorAll('[data-media-tab]'));
      mediaTabs.forEach(function (button, index) {
        button.addEventListener('click', function () { setMediaTab(picker, button.getAttribute('data-media-tab') || 'library'); });
        button.addEventListener('keydown', function (event) {
          if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') { return; }
          event.preventDefault();
          const direction = event.key === 'ArrowRight' ? 1 : -1;
          const nextIndex = (index + direction + mediaTabs.length) % mediaTabs.length;
          const nextTab = mediaTabs[nextIndex];
          if (nextTab) {
            setMediaTab(picker, nextTab.getAttribute('data-media-tab') || 'library');
            nextTab.focus();
          }
        });
      });
      picker.addEventListener('keydown', function (event) {
        trapFocus(event, picker, function () { closeMediaPicker(picker); });
      });
      const mediaSearch = picker.querySelector('[data-media-search]');
      if (mediaSearch) {
        mediaSearch.addEventListener('input', function () {
          const q = String(mediaSearch.value || '').toLowerCase().trim();
          picker.querySelectorAll('[data-insert-media]').forEach(function (button) {
            const haystack = button.getAttribute('data-media-search-text') || '';
            button.hidden = q !== '' && haystack.indexOf(q) === -1;
          });
        });
      }
      picker.addEventListener('click', function (event) {
        const button = event.target.closest ? event.target.closest('[data-insert-media]') : null;
        if (!button || !picker.contains(button)) { return; }
        const altInput = picker.querySelector('[data-media-alt-input]');
        const override = altInput && altInput.value.trim() ? altInput.value.trim() : '';
        const captionInput = picker.querySelector('[data-media-caption-input]');
        const captionOverride = captionInput && captionInput.value.trim() ? captionInput.value.trim() : '';
        const selectedMedia = mediaFromButton(button);
        const pickerMode = picker.getAttribute('data-media-picker-mode') || 'body';
        const completed = pickerMode === 'gallery'
          ? addMediaToPhotoGallery(root, selectedMedia)
          : (insertMediaIntoEditor(root, selectedMedia, override, captionOverride), true);
        if (completed) {
          if (altInput) { altInput.value = ''; }
          if (captionInput) { captionInput.value = ''; }
          closeMediaPicker(picker);
        }
      });

      const uploadForm = picker.querySelector('[data-media-upload-form]');
      const uploadInsert = picker.querySelector('[data-media-upload-insert]');
      const uploadOnly = picker.querySelector('[data-media-upload-only]');
      const uploadStatus = picker.querySelector('[data-media-upload-status]');
      const libraryStatus = picker.querySelector('[data-media-library-status]');
      const endpoint = picker.getAttribute('data-media-endpoint') || '';
      let uploadShouldInsert = true;
      function setUploadStatus(message) { if (uploadStatus) { uploadStatus.textContent = message || ''; } }
      function mediaUploadPayload() {
        const payload = new FormData();
        if (!uploadForm) { return payload; }
        uploadForm.querySelectorAll('input, textarea, select').forEach(function (field) {
          if (!field.name || field.disabled) { return; }
          if (field.type === 'file') {
            Array.from(field.files || []).forEach(function (file) { payload.append(field.name, file); });
            return;
          }
          if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) { return; }
          payload.append(field.name, field.value || '');
        });
        return payload;
      }
      function resetMediaUploadPanel() {
        if (!uploadForm) { return; }
        uploadForm.querySelectorAll('input, textarea, select').forEach(function (field) {
          if (field.type === 'hidden') { return; }
          if (field.type === 'file') { field.value = ''; return; }
          if (field.type === 'checkbox' || field.type === 'radio') { field.checked = false; return; }
          field.value = '';
        });
      }
      function handleUpload() {
        if (!uploadForm || !endpoint || !window.fetch) { return; }
        const fileInputForUpload = uploadForm.querySelector('input[type="file"][name="media_file"]');
        if (!fileInputForUpload || !fileInputForUpload.files || !fileInputForUpload.files.length) {
          setUploadStatus('Choose a media file before uploading.');
          if (fileInputForUpload && typeof fileInputForUpload.focus === 'function') { fileInputForUpload.focus(); }
          return;
        }
        const payload = mediaUploadPayload();
        payload.append('action', 'upload');
        if ((picker.getAttribute('data-media-picker-mode') || 'body') === 'gallery') {
          payload.append('image_only', '1');
        }
        setUploadStatus('Uploading media...');
        fetch(endpoint, { method: 'POST', body: payload, credentials: 'same-origin' })
          .then(function (response) { return response.json().then(function (json) { json.status = response.status; return json; }); })
          .then(function (json) {
            if (!json || !json.ok) {
              setUploadStatus((json && json.message) || 'Upload failed.');
              return;
            }
            renderMediaLibrary(picker, json.items || []);
            if (libraryStatus) { libraryStatus.textContent = 'Library refreshed.'; }
            resetMediaUploadPanel();
            setUploadStatus(json.message || 'Media uploaded.');
            if (uploadShouldInsert && json.media) {
              const pickerMode = picker.getAttribute('data-media-picker-mode') || 'body';
              const completed = pickerMode === 'gallery'
                ? addMediaToPhotoGallery(root, json.media)
                : (insertMediaIntoEditor(root, json.media, ''), true);
              if (completed) { closeMediaPicker(picker); }
            } else {
              setMediaTab(picker, 'library');
            }
          })
          .catch(function () { setUploadStatus('Upload failed. Check the file type and try again.'); });
      }
      if (uploadOnly) {
        uploadOnly.addEventListener('click', function () {
          uploadShouldInsert = false;
          handleUpload();
        });
      }
      if (uploadInsert) {
        uploadInsert.addEventListener('click', function () {
          uploadShouldInsert = true;
          handleUpload();
        });
      }
      if (uploadForm && uploadForm.tagName && uploadForm.tagName.toLowerCase() === 'form') {
        uploadForm.addEventListener('submit', function (event) {
          event.preventDefault();
          uploadShouldInsert = true;
          handleUpload();
        });
      }
      const dropzone = picker.querySelector('[data-media-dropzone]');
      const fileInput = uploadForm ? uploadForm.querySelector('input[type="file"]') : null;
      if (dropzone && fileInput) {
        ['dragenter', 'dragover'].forEach(function (name) {
          dropzone.addEventListener(name, function (event) { event.preventDefault(); dropzone.classList.add('dragging'); });
        });
        ['dragleave', 'drop'].forEach(function (name) {
          dropzone.addEventListener(name, function (event) { event.preventDefault(); dropzone.classList.remove('dragging'); });
        });
        dropzone.addEventListener('drop', function (event) {
          const files = event.dataTransfer && event.dataTransfer.files;
          if (files && files.length) {
            fileInput.files = files;
            setUploadStatus(files[0].name + ' ready to upload.');
          }
        });
      }
    }

    visual.addEventListener('paste', function (event) {
      const clipboard = event.clipboardData || window.clipboardData;
      if (!clipboard) { return; }
      const html = clipboard.getData('text/html');
      const text = clipboard.getData('text/plain');
      let cleanHtml = '';
      if (html) {
        const markdown = htmlToMarkdown(html);
        cleanHtml = markdown ? markdownToHtml(markdown) : '';
      }
      if (!cleanHtml && text) {
        cleanHtml = markdownToHtml(String(text).replace(/\r\n?/g, '\n'));
      }
      if (!cleanHtml) { return; }
      event.preventDefault();
      insertHtmlAtSelection(cleanHtml, visual);
      visual.dispatchEvent(new Event('input', { bubbles: true }));
      updateEditorMetrics(root);
      scheduleComposerViewportFill(root);
    });

    function handleEditorShortcut(event) {
      const modifier = event.ctrlKey || event.metaKey;
      const key = String(event.key || '').toLowerCase();
      const currentMode = root.getAttribute('data-current-mode') || 'visual';

      if (event.key === 'Escape') {
        const openPicker = root.parentElement ? root.parentElement.querySelector('[data-media-picker]:not([hidden])') : null;
        const openShortcutsPanel = root.querySelector('[data-shortcuts-panel]:not([hidden])');
        if (openPicker) { event.preventDefault(); closeMediaPicker(openPicker); return; }
        if (openShortcutsPanel) { event.preventDefault(); closeShortcuts(root); return; }
        if (root.classList.contains('is-focus-mode')) { event.preventDefault(); setFocusMode(false, true); return; }
      }

      if (!modifier) { return; }
      const targetIsEditor = event.target === visual || event.target === textarea || (visual && visual.contains(event.target));
      const targetIsTypingOutsideEditor = isTypingTarget(event.target) && !targetIsEditor;
      if (key === 's') {
        event.preventDefault();
        const submitButton = form ? form.querySelector('.publish-main-button') : null;
        if (submitButton) { submitButton.click(); }
        return;
      }
      if (targetIsTypingOutsideEditor) { return; }
      if (key === '/' && !event.shiftKey) {
        event.preventDefault();
        openShortcuts(root, event.target);
        return;
      }
      if (event.shiftKey && key === 'p') {
        event.preventDefault();
        setEditorMode(root, 'preview', true);
        updatePreview(root);
        return;
      }
      if (event.shiftKey && key === 'f') {
        event.preventDefault();
        setFocusMode(!root.classList.contains('is-focus-mode'), true);
        return;
      }
      if (event.shiftKey && key === 'm') {
        event.preventDefault();
        openMediaPicker(root, event.target);
        return;
      }
      if (key === 'b') {
        event.preventDefault();
        if (currentMode === 'markdown') { applyMarkdownCommand(root, 'bold'); } else { applyCommand(root, 'bold'); }
        updateEditorMetrics(root);
        return;
      }
      if (key === 'i') {
        event.preventDefault();
        if (currentMode === 'markdown') { applyMarkdownCommand(root, 'italic'); } else { applyCommand(root, 'italic'); }
        updateEditorMetrics(root);
        return;
      }
      if (key === 'k') {
        event.preventDefault();
        if (currentMode === 'markdown') { applyMarkdownCommand(root, 'link'); } else { applyCommand(root, 'createLink'); }
        updateEditorMetrics(root);
      }
    }

    root.addEventListener('keydown', handleEditorShortcut);

    setupSidebarCardState(form);
    setupScreenControls(form, root);

    const preferredMode = safeEditorMode(postState.mode || globalState.lastMode || root.getAttribute('data-default-mode') || 'visual', 'visual');
    const preferredFocus = globalState.focusMode === true;

    if (form) {
      let dirty = false;
      const autosaveEnabled = root.getAttribute('data-autosave-enabled') === '1';
      const titleField = form.querySelector('#stream_title');
      const slugField = form.querySelector('#stream_slug');
      const saveState = root.querySelector('[data-editor-save-state]');
      const banner = root.querySelector('[data-autosave-banner]');
      const restoreButton = root.querySelector('[data-autosave-restore]');
      const discardButton = root.querySelector('[data-autosave-discard]');
      const autosaveSessionStorageKey = 'bonumark-editor-session:' + location.pathname;
      const autosaveKeyField = form.querySelector('input[name="autosave_key"]');
      const autosaveSavedAtField = form.querySelector('input[name="autosave_saved_at"]');
      let autosaveDismissed = false;
      const autosaveSessionKey = (function () {
        const explicitKey = autosaveKeyField && autosaveKeyField.value ? String(autosaveKeyField.value) : '';
        if (explicitKey.indexOf('bonumark-autosave:') === 0) {
          return explicitKey.replace(/^bonumark-autosave:/, '');
        }
        const fileField = form.querySelector('input[name="file"]');
        if (fileField && fileField.value) {
          return location.pathname + ':file:' + fileField.value;
        }
        // Server-first autosave needs a stable key so a new-post recovery can follow the user across browsers.
        // Older builds used a random sessionStorage key, which kept new-post autosaves trapped on one machine.
        return location.pathname + ':new-stream-post';
      }());
      function autosaveKeyValue() {
        const explicitKey = autosaveKeyField && autosaveKeyField.value ? String(autosaveKeyField.value) : '';
        return explicitKey || ('bonumark-autosave:' + autosaveSessionKey);
      }
      const autosaveUrl = root.getAttribute('data-autosave-url') || '';
      function parseAutosaveTimestamp(value) {
        if (!value) { return 0; }
        const normalized = String(value).indexOf('T') === -1 ? String(value).replace(' ', 'T') : String(value);
        const parsed = Date.parse(normalized);
        return Number.isNaN(parsed) ? 0 : parsed;
      }
      const savedBaselineTime = parseAutosaveTimestamp(autosaveSavedAtField ? autosaveSavedAtField.value : '');
      function autosaveIsNewerThanSaved(saved) {
        if (!saved || !saved.savedAt) { return true; }
        if (!savedBaselineTime) { return true; }
        return parseAutosaveTimestamp(saved.savedAt) > (savedBaselineTime + 1000);
      }
      function currentMarkdown() {
        if ((root.getAttribute('data-current-mode') || 'visual') === 'visual') {
          textarea.value = htmlToMarkdown(visual.innerHTML);
        }
        return textarea.value;
      }
      function snapshot() {
        return {
          title: titleField ? titleField.value : '',
          slug: slugField ? slugField.value : '',
          markdown: currentMarkdown(),
          savedAt: new Date().toISOString()
        };
      }
      function showSaveState(text, state) {
        if (!saveState) { return; }
        saveState.textContent = text;
        saveState.setAttribute('data-save-state', state || 'ready');
      }
      function hideRestoreBanner(disableActions) {
        if (!banner) { return; }
        banner.hidden = true;
        banner.setAttribute('aria-hidden', 'true');
        banner.classList.add('is-dismissed');
        banner.style.display = 'none';
        if (disableActions) {
          if (restoreButton) { restoreButton.disabled = true; restoreButton.onclick = null; }
          if (discardButton) { discardButton.disabled = true; discardButton.onclick = null; }
        }
      }
      function applySnapshot(saved, label) {
        if (autosaveDismissed || !saved || !saved.markdown) { return; }
        if (titleField && saved.title) { titleField.value = saved.title; }
        if (slugField && saved.slug) { slugField.value = saved.slug; slugField.dispatchEvent(new Event('input', { bubbles: true })); }
        textarea.value = saved.markdown;
        visual.innerHTML = markdownToHtml(saved.markdown);
        scheduleComposerViewportFill(root);
        hideRestoreBanner(false);
        dirty = true;
        showSaveState(label || 'Restored autosave.', 'dirty');
      }
      function clearBrowserAutosave() {
        try {
          const currentKey = autosaveKeyValue();
          const oldPathKey = 'bonumark-autosave:' + location.pathname;
          localStorage.removeItem(currentKey);
          // Older pre-session builds used the pathname directly. Clear it too so a stale browser copy cannot reappear.
          localStorage.removeItem(oldPathKey);
          for (let index = localStorage.length - 1; index >= 0; index -= 1) {
            const key = localStorage.key(index);
            if (!key || key.indexOf('bonumark-autosave:') !== 0) { continue; }
            if (key === currentKey || key === oldPathKey || key.indexOf(location.pathname) !== -1) {
              localStorage.removeItem(key);
            }
          }
        } catch (error) {}
        try {
          if (window.sessionStorage && autosaveSessionStorageKey.indexOf('bonumark-editor-session:') === 0) {
            window.sessionStorage.removeItem(autosaveSessionStorageKey);
          }
        } catch (error) {}
      }
      function deleteServerAutosaveForKey(key) {
        if (!autosaveUrl || !window.fetch || !key) { return Promise.resolve(false); }
        const data = serverPayload('delete');
        data.set('key', key);
        return fetch(autosaveUrl, { method: 'POST', body: data, credentials: 'same-origin' })
          .then(function (response) { return response.ok; })
          .catch(function () { return false; });
      }
      function deleteServerAutosave() {
        const keys = [autosaveKeyValue(), 'bonumark-autosave:' + location.pathname].filter(function (key, index, list) {
          return key && list.indexOf(key) === index;
        });
        return Promise.all(keys.map(deleteServerAutosaveForKey)).then(function (results) {
          return results.some(Boolean);
        });
      }
      function discardAutosave() {
        autosaveDismissed = true;
        clearBrowserAutosave();
        hideRestoreBanner(true);
        dirty = false;
        showSaveState(autosaveEnabled ? 'Autosave discarded. No unsaved changes.' : 'Autosave discarded.', 'ready');
        deleteServerAutosave();
      }
      function showRestoreBanner(saved, label, source) {
        if (autosaveDismissed || !saved || !saved.markdown || saved.markdown === currentMarkdown() || !banner) { return; }
        if (!autosaveIsNewerThanSaved(saved)) { return; }
        const bannerTitle = banner.querySelector('[data-autosave-banner-title]');
        const bannerText = banner.querySelector('[data-autosave-banner-text]');
        const sourceType = source || saved.source || 'server';
        if (bannerTitle) {
          bannerTitle.textContent = sourceType === 'browser'
            ? 'Autosave found a local browser backup.'
            : 'Autosave found a newer server copy.';
        }
        if (bannerText) {
          bannerText.textContent = sourceType === 'browser'
            ? 'This backup only exists in this browser. You can restore it or discard it.'
            : 'This server copy follows your account. You can restore it or keep the saved version.';
        }
        banner.setAttribute('data-autosave-source', sourceType);
        banner.classList.remove('is-dismissed');
        banner.style.display = '';
        banner.removeAttribute('aria-hidden');
        if (restoreButton) { restoreButton.disabled = false; }
        if (discardButton) { discardButton.disabled = false; }
        banner.hidden = false;
        if (restoreButton) {
          restoreButton.onclick = function (event) {
            if (event) { event.preventDefault(); event.stopPropagation(); }
            applySnapshot(saved, label);
          };
        }
        if (discardButton) {
          discardButton.onclick = function (event) {
            if (event) { event.preventDefault(); event.stopPropagation(); }
            discardAutosave();
          };
        }
      }
      root.addEventListener('click', function (event) {
        const discardTrigger = event.target && event.target.closest ? event.target.closest('[data-autosave-discard]') : null;
        if (!discardTrigger || !root.contains(discardTrigger)) { return; }
        event.preventDefault();
        event.stopPropagation();
        discardAutosave();
      });
      function markDirty() {
        dirty = true;
        updateEditorMetrics(root);
        scheduleComposerViewportFill(root);
        showSaveState('Unsaved changes.', 'dirty');
      }
      function csrfToken() {
        const token = form.querySelector('input[name="csrf_token"]');
        return token ? token.value : '';
      }
      function fieldValue(selector) {
        const field = form.querySelector(selector);
        return field ? field.value : '';
      }
      function serverPayload(action) {
        const data = new FormData();
        data.append('csrf_token', csrfToken());
        data.append('action', action || 'save');
        data.append('key', autosaveKeyValue());
        data.append('title', fieldValue('#stream_title'));
        data.append('slug', fieldValue('#stream_slug'));
        data.append('section', fieldValue('input[name="type"]') === 'published' ? 'published' : 'drafts');
        data.append('filename', fieldValue('input[name="file"]'));
        data.append('date', fieldValue('#stream_date'));
        data.append('content_type', fieldValue('#stream_content_type'));
        data.append('description', fieldValue('#stream_description'));
        data.append('category', fieldValue('#stream_category'));
        data.append('tags', fieldValue('#stream_tags'));
        if ((action || 'save') === 'save') {
          data.append('markdown', currentMarkdown());
        }
        return data;
      }
      function saveBrowserAutosaveFallback() {
        try {
          const localSnapshot = snapshot();
          localSnapshot.source = 'browser';
          localStorage.setItem(autosaveKeyValue(), JSON.stringify(localSnapshot));
          showSaveState('Autosave failed. Local backup kept at ' + new Date().toLocaleTimeString() + '.', 'warning');
          return true;
        } catch (error) {
          showSaveState('Autosave failed and this browser could not keep a local backup.', 'warning');
          return false;
        }
      }
      function saveServerAutosave() {
        if (!autosaveUrl || !window.fetch) { return Promise.resolve(false); }
        return fetch(autosaveUrl, { method: 'POST', body: serverPayload('save'), credentials: 'same-origin' })
          .then(function (response) { return response.ok ? response.json() : null; })
          .then(function (data) {
            if (data && data.ok) {
              clearBrowserAutosave();
              showSaveState('Autosaved to server at ' + new Date().toLocaleTimeString() + '.', 'saved');
              return true;
            }
            return false;
          })
          .catch(function () { return false; });
      }
      function loadBrowserAutosave() {
        try {
          const savedRaw = localStorage.getItem(autosaveKeyValue());
          if (savedRaw) {
            const saved = JSON.parse(savedRaw);
            saved.source = 'browser';
            showRestoreBanner(saved, 'Restored local browser backup from ' + new Date(saved.savedAt || Date.now()).toLocaleString(), 'browser');
            return true;
          }
        } catch (error) {}
        return false;
      }
      function loadServerAutosave() {
        if (!autosaveUrl || !window.fetch) { return Promise.resolve(false); }
        return fetch(autosaveUrl + '?key=' + encodeURIComponent(autosaveKeyValue()), { credentials: 'same-origin' })
          .then(function (response) { return response.ok ? response.json() : null; })
          .then(function (data) {
            if (autosaveDismissed) { return true; }
            if (data && data.ok && data.autosave) {
              showRestoreBanner({
                title: data.autosave.title,
                slug: data.autosave.slug,
                markdown: data.autosave.markdown,
                savedAt: data.autosave.updated_at,
                source: 'server'
              }, 'Restored server autosave from ' + new Date(data.autosave.updated_at || Date.now()).toLocaleString(), 'server');
              return true;
            }
            return false;
          })
          .catch(function () { return false; });
      }
      function runAutosave() {
        if (!autosaveEnabled || !dirty) { return; }
        showSaveState('Autosaving to server...', 'saving');
        saveServerAutosave().then(function (savedOnServer) {
          if (!savedOnServer) { saveBrowserAutosaveFallback(); }
        });
      }
      textarea.addEventListener('input', markDirty);
      visual.addEventListener('input', markDirty);
      form.querySelectorAll('input, textarea, select').forEach(function (field) {
        field.addEventListener('input', markDirty);
        field.addEventListener('change', markDirty);
      });
      showSaveState(autosaveEnabled ? 'Autosave ready. No unsaved changes.' : 'No unsaved changes.', 'ready');
      if (autosaveEnabled) {
        loadServerAutosave().then(function (foundServerAutosave) {
          if (!foundServerAutosave && !autosaveDismissed) {
            loadBrowserAutosave();
          }
        });
        setInterval(runAutosave, 30000);
      }
      function editorSubmitButtons() {
        const buttons = Array.prototype.slice.call(form.querySelectorAll('button[type="submit"][name="stream_submit_action"]'));
        if (form.id) {
          document.querySelectorAll('button[type="submit"][form="' + form.id + '"][name="stream_submit_action"]').forEach(function (button) {
            if (buttons.indexOf(button) === -1) { buttons.push(button); }
          });
        }
        return buttons;
      }
      editorSubmitButtons().forEach(function (button) {
        button.addEventListener('click', function (event) {
          form.setAttribute('data-last-stream-submit-action', button.value || '');
          const ownsButton = button.form === form || (form.id && button.getAttribute('form') === form.id);
          if (!ownsButton) {
            event.preventDefault();
            const existing = form.querySelector('input[type="hidden"][name="stream_submit_action"][data-submit-intent]');
            const intent = existing || document.createElement('input');
            intent.type = 'hidden';
            intent.name = 'stream_submit_action';
            intent.value = button.value || '';
            intent.setAttribute('data-submit-intent', '1');
            if (!existing) { form.appendChild(intent); }
            if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
          }
        });
      });
      form.addEventListener('submit', function (event) {
        const submitter = event.submitter || document.activeElement;
        const fallbackAction = form.getAttribute('data-last-stream-submit-action') || '';
        if (fallbackAction && (!submitter || submitter.name !== 'stream_submit_action')) {
          const existing = form.querySelector('input[type="hidden"][name="stream_submit_action"][data-submit-intent]');
          const intent = existing || document.createElement('input');
          intent.type = 'hidden';
          intent.name = 'stream_submit_action';
          intent.value = fallbackAction;
          intent.setAttribute('data-submit-intent', '1');
          if (!existing) { form.appendChild(intent); }
        }
        const isPreviewSubmit = !!(submitter && submitter.hasAttribute && submitter.hasAttribute('data-public-preview-submit'));
        if ((root.getAttribute('data-current-mode') || 'visual') === 'visual') {
          textarea.value = htmlToMarkdown(visual.innerHTML);
        }
        if (isPreviewSubmit) {
          showSaveState('Opening current-content preview...', 'ready');
          return;
        }
        showSaveState('Saving changes...', 'saving');
        clearBrowserAutosave();
        dirty = false;
        showSaveState('Submitting saved changes...', 'saving');
      });
      window.addEventListener('beforeunload', function (event) {
        if (!dirty) { return; }
        event.preventDefault();
        event.returnValue = '';
      });
    }

    textarea.addEventListener('input', function () {
      if ((root.getAttribute('data-current-mode') || 'visual') === 'markdown') {
        // The preview and visual panes are refreshed when their tabs are opened.
      }
      scheduleComposerViewportFill(root);
    });

    visual.addEventListener('input', function () {
      scheduleComposerViewportFill(root);
    });

    setEditorMode(root, preferredMode, false);
    setFocusMode(preferredFocus, false);
    updateEditorMetrics(root);
    scheduleComposerViewportFill(root);
    window.addEventListener('resize', function () { scheduleComposerViewportFill(root); });

    if (typeof ResizeObserver !== 'undefined') {
      const workspace = form.querySelector('.editor-workspace');
      const sidebar = form.querySelector('.editor-sidebar-column');
      const resizeObserver = new ResizeObserver(function () { scheduleComposerViewportFill(root); });
      if (workspace) { resizeObserver.observe(workspace); }
      if (sidebar) { resizeObserver.observe(sidebar); }
    }
  }



  function slugifyValue(value) {
    return String(value || '')
      .toLowerCase()
      .replace(/&/g, ' and ')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .replace(/-{2,}/g, '-');
  }

  function attachPermalinkPreview() {
    const slugInput = document.querySelector('#stream_slug');
    const titleInput = document.querySelector('#stream_title');
    const preview = document.querySelector('[data-permalink-preview]');
    const finalUrlInput = document.querySelector('[data-final-url-input]');
    const warning = document.querySelector('[data-slug-change-warning]');
    const confirmChange = document.querySelector('[data-confirm-slug-change]');
    if (!slugInput || !preview) {
      return;
    }

    const base = preview.getAttribute('data-permalink-base') || '/stream/';
    const finalBase = finalUrlInput ? (finalUrlInput.getAttribute('data-final-url-base') || base) : base;
    const initialSlug = String(slugInput.value || '').trim();
    const originalSlug = slugifyValue(slugInput.getAttribute('data-original-slug') || initialSlug);
    const isPublished = slugInput.getAttribute('data-is-published') === '1';
    let userEditedSlug = !(/^untitled-\d{8}-\d{6}$/.test(initialSlug) || initialSlug === '' || initialSlug === 'generated-on-save');

    function currentSlug() {
      return slugifyValue(slugInput.value) || 'generated-on-save';
    }

    function updatePreview() {
      const clean = currentSlug();
      preview.textContent = base + clean + '/';
      if (finalUrlInput) {
        finalUrlInput.value = finalBase + clean + '/';
      }
      if (warning && isPublished) {
        const changed = originalSlug !== '' && clean !== originalSlug;
        warning.hidden = !changed;
        if (!changed && confirmChange) {
          confirmChange.checked = false;
        }
      }
    }

    slugInput.addEventListener('input', function () {
      userEditedSlug = true;
      updatePreview();
    });

    if (titleInput) {
      titleInput.addEventListener('input', function () {
        if (!userEditedSlug) {
          slugInput.value = slugifyValue(titleInput.value) || initialSlug || '';
        }
        updatePreview();
      });
    }

    updatePreview();
  }

  function pageSlugNeedsGeneration(value) {
    const clean = slugifyValue(value);
    return clean === '' || clean === 'untitled' || clean === 'generated-on-save' || /^untitled-\d{8}(?:-\d{6})?$/.test(clean);
  }

  function pageGeneratedSearchTitle(title) {
    const baseTitle = String(title || '').trim() || 'Untitled Page';
    const preview = document.querySelector('[data-page-seo-title-preview]');
    const siteName = (preview ? preview.getAttribute('data-site-name') : '').trim();
    const generated = siteName ? (baseTitle + ' | ' + siteName) : baseTitle;
    return generated.length > 65 ? generated.slice(0, 64).trimEnd() + '…' : generated;
  }

  function attachPageMetadataPreview() {
    const titleInput = document.querySelector('#page_title');
    const slugInput = document.querySelector('[data-page-slug-input]');
    const permalinkPreview = document.querySelector('[data-page-permalink-preview]');
    const finalUrlInput = document.querySelector('[data-page-final-url-input]');
    const warning = document.querySelector('[data-page-slug-warning]');
    const confirmChange = document.querySelector('[data-page-confirm-slug-change]');
    const seoInput = document.querySelector('[data-page-seo-title-input]');
    const seoPreview = document.querySelector('[data-page-seo-title-preview]');

    if (!titleInput && !slugInput && !seoPreview) {
      return;
    }

    const initialSlug = slugInput ? String(slugInput.value || '').trim() : '';
    const originalSlug = slugInput ? slugifyValue(slugInput.getAttribute('data-original-slug') || initialSlug) : '';
    const isPublished = slugInput && slugInput.getAttribute('data-is-published') === '1';
    let userEditedSlug = slugInput ? !pageSlugNeedsGeneration(initialSlug) : false;

    function currentCleanSlug() {
      if (!slugInput) { return ''; }
      return slugifyValue(slugInput.value) || slugifyValue(titleInput ? titleInput.value : '') || 'generated-on-save';
    }

    function updateSlugPreview() {
      if (!slugInput || !permalinkPreview) { return; }
      const base = permalinkPreview.getAttribute('data-page-permalink-base') || '/pages/';
      const clean = currentCleanSlug();
      permalinkPreview.textContent = base + clean + '/';
      if (finalUrlInput) {
        const absoluteBase = finalUrlInput.getAttribute('data-page-final-url-base') || '';
        finalUrlInput.value = absoluteBase + clean + '/';
      }
      if (warning && isPublished) {
        const changed = originalSlug !== '' && clean !== originalSlug;
        warning.hidden = !changed;
        if (!changed && confirmChange) {
          confirmChange.checked = false;
        }
      }
    }

    function updateSeoPreview() {
      if (!seoPreview) { return; }
      const custom = seoInput ? String(seoInput.value || '').trim() : '';
      seoPreview.textContent = custom || pageGeneratedSearchTitle(titleInput ? titleInput.value : '');
    }

    if (slugInput) {
      slugInput.addEventListener('input', function () {
        userEditedSlug = true;
        updateSlugPreview();
      });
    }

    if (titleInput) {
      titleInput.addEventListener('input', function () {
        if (slugInput && !userEditedSlug) {
          slugInput.value = slugifyValue(titleInput.value);
        }
        updateSlugPreview();
        updateSeoPreview();
      });
    }

    if (seoInput) {
      seoInput.addEventListener('input', updateSeoPreview);
    }

    updateSlugPreview();
    updateSeoPreview();
  }


  function attachCopyButtons() {
    document.querySelectorAll('[data-copy-target]').forEach(function (button) {
      button.addEventListener('click', function () {
        const target = document.getElementById(button.getAttribute('data-copy-target'));
        if (!target) {
          return;
        }
        const value = typeof target.value === 'string' ? target.value : target.textContent;
        if (target.select) {
          target.focus();
          target.select();
        }
        const original = button.textContent;
        function copied() {
          button.textContent = 'Copied';
          setTimeout(function () { button.textContent = original; }, 1400);
        }
        try {
          if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(copied).catch(function () {
              document.execCommand('copy');
              copied();
            });
          } else {
            document.execCommand('copy');
            copied();
          }
        } catch (error) {
          try { document.execCommand('copy'); copied(); } catch (innerError) {}
        }
      });
    });
  }


  function attachMobileEditorActions() {
    const bars = Array.from(document.querySelectorAll('[data-editor-mobile-action-bar]'));
    if (bars.length === 0) { return; }

    document.body.classList.add('has-editor-mobile-actions');

    bars.forEach(function (bar) {
      if (bar.getAttribute('data-editor-mobile-bound') === '1') { return; }
      bar.setAttribute('data-editor-mobile-bound', '1');

      const publishCard = document.querySelector('.editor-publish-card');
      const protectedControls = Array.from(document.querySelectorAll('.editor-command-bar, .visual-toolbar'));
      const mobileQuery = window.matchMedia('(max-width: 900px)');
      let publishVisible = false;
      let keyboardOpen = false;
      let frame = 0;

      function dockHeight() {
        const rect = bar.getBoundingClientRect();
        return Math.max(68, Math.round(rect.height || 0));
      }

      function controlsEnterDockZone() {
        if (!mobileQuery.matches) { return false; }
        const boundary = window.innerHeight - dockHeight() - 6;
        return protectedControls.some(function (control) {
          if (!control || control.offsetParent === null) { return false; }
          const rect = control.getBoundingClientRect();
          return rect.top < window.innerHeight && rect.bottom > boundary;
        });
      }

      function refreshDock() {
        frame = 0;
        const hidden = !mobileQuery.matches || publishVisible || keyboardOpen || controlsEnterDockZone();
        bar.classList.toggle('is-context-hidden', hidden);
        bar.toggleAttribute('inert', hidden);
      }

      function requestRefresh() {
        if (frame) { return; }
        frame = window.requestAnimationFrame(refreshDock);
      }

      bar.querySelectorAll('[data-editor-jump-publish]').forEach(function (button) {
        if (button.getAttribute('data-editor-jump-bound') === '1') { return; }
        button.setAttribute('data-editor-jump-bound', '1');
        button.addEventListener('click', function () {
          if (!publishCard) { return; }
          bar.classList.add('is-context-hidden');
          publishCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
          window.setTimeout(function () {
            const target = publishCard.querySelector('button, a, summary');
            if (target && typeof target.focus === 'function') { target.focus({ preventScroll: true }); }
          }, 350);
        });
      });

      if (publishCard && 'IntersectionObserver' in window) {
        const publishObserver = new IntersectionObserver(function (entries) {
          const entry = entries[0];
          publishVisible = Boolean(entry && entry.isIntersecting && entry.intersectionRatio >= 0.24);
          requestRefresh();
        }, {
          threshold: [0, 0.24, 0.5],
          rootMargin: '0px 0px -72px 0px'
        });
        publishObserver.observe(publishCard);
      }

      const viewport = window.visualViewport;
      function refreshKeyboardState() {
        if (!viewport || !mobileQuery.matches) {
          keyboardOpen = false;
        } else {
          const lostHeight = Math.max(0, window.innerHeight - viewport.height);
          keyboardOpen = lostHeight > 160 && viewport.height < window.innerHeight * 0.82;
        }
        requestRefresh();
      }

      window.addEventListener('scroll', requestRefresh, { passive: true });
      window.addEventListener('resize', function () {
        refreshKeyboardState();
        requestRefresh();
      }, { passive: true });
      if (viewport) {
        viewport.addEventListener('resize', refreshKeyboardState, { passive: true });
        viewport.addEventListener('scroll', requestRefresh, { passive: true });
      }
      if (typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', requestRefresh);
      }

      refreshKeyboardState();
      requestRefresh();
    });
  }

  function attachConfirmations() {
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        const message = form.getAttribute('data-confirm') || 'Continue?';
        if (!window.confirm(message)) {
          event.preventDefault();
        }
      });
    });
  }

  function attachSelectAll() {
    document.querySelectorAll('[data-select-all]').forEach(function (control) {
      control.addEventListener('change', function () {
        const table = control.closest('table');
        if (!table) {
          return;
        }
        table.querySelectorAll('tbody input[type="checkbox"]').forEach(function (box) {
          box.checked = control.checked;
        });
      });
    });
  }

  function attachOpenMediaButtons() {
    document.querySelectorAll('[data-open-media-picker]').forEach(function (button) {
      if (button.getAttribute('data-media-open-bound') === '1') { return; }
      button.setAttribute('data-media-open-bound', '1');
      button.addEventListener('click', function () {
        const form = button.closest('form');
        const root = form ? form.querySelector('[data-bonumark-editor]') : document.querySelector('[data-bonumark-editor]');
        if (root) { openMediaPicker(root, button); }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bonumark-editor]').forEach(attachEditor);
    attachPermalinkPreview();
    attachPageMetadataPreview();
    attachSelectAll();
    attachCopyButtons();
    attachOpenMediaButtons();
    attachMobileEditorActions();
    attachConfirmations();
  });
}());


/* Bonumark Local Places editor integration */
(function () {
  function setupLocalPlaces(root) {
    var scope = root || document;
    var pickers = scope.querySelectorAll('[data-local-places]');

    pickers.forEach(function (picker) {
      if (picker.dataset.localPlacesInitialized === '1') {
        return;
      }
      picker.dataset.localPlacesInitialized = '1';

      var form = picker.closest('form');
      if (!form) {
        return;
      }
      var toggle = form.querySelector('[data-local-places-toggle]');
      var placeId = picker.querySelector('[data-place-id]');
      var modeInput = picker.querySelector('[data-place-display-mode]');
      var select = picker.querySelector('[data-place-select]');
      var pickerBody = picker.querySelector('[data-place-picker-body]');
      var selected = picker.querySelector('[data-place-selected]');
      var selectedPrimary = picker.querySelector('[data-place-selected-primary]');
      var selectedSecondary = picker.querySelector('[data-place-selected-secondary]');
      var change = picker.querySelector('[data-place-change]');
      var remove = picker.querySelector('[data-place-remove]');
      var nearbyButton = picker.querySelector('[data-place-nearby]');
      var nearbyResults = picker.querySelector('[data-place-nearby-results]');
      var status = picker.querySelector('[data-place-status]');
      var createOpen = picker.querySelector('[data-place-create-open]');
      var createModal = picker.querySelector('[data-place-create-modal]');
      var createCloseButtons = picker.querySelectorAll('[data-place-create-close]');
      var saveButton = picker.querySelector('[data-place-save]');
      var createStatus = picker.querySelector('[data-place-create-status]');
      var newName = picker.querySelector('[data-place-new-name]');
      var newPublicLabel = picker.querySelector('[data-place-new-public-label]');
      var newLatitude = picker.querySelector('[data-place-new-latitude]');
      var newLongitude = picker.querySelector('[data-place-new-longitude]');
      var nearbyEndpoint = picker.getAttribute('data-nearby-endpoint') || '';
      var saveEndpoint = picker.getAttribute('data-save-endpoint') || '';
      var lastFocused = null;

      function csrfToken() {
        var token = form.querySelector('input[name="csrf_token"]');
        return token ? token.value || '' : '';
      }

      function setStatus(message, isError) {
        if (!status) {
          return;
        }
        status.textContent = message || '';
        status.classList.toggle('is-error', !!isError);
      }

      function setCreateStatus(message, isError) {
        if (!createStatus) {
          return;
        }
        createStatus.textContent = message || '';
        createStatus.classList.toggle('is-error', !!isError);
      }

      function locationLine(place) {
        var parts = [];
        if (place.locality) parts.push(place.locality);
        if (place.region && parts.indexOf(place.region) === -1) parts.push(place.region);
        if (!parts.length && place.country) parts.push(place.country);
        return parts.join(', ');
      }

      function labelsFor(place, mode) {
        mode = mode || place.default_display_mode || 'exact';
        var primary = '';
        var secondary = '';
        if (mode === 'city') {
          primary = place.locality || place.region || place.country || place.name || '';
          secondary = place.locality && place.region ? place.region : ((place.country || '') !== primary ? (place.country || '') : '');
        } else if (mode === 'area') {
          primary = place.area_label || place.locality || place.name || '';
          var parts = [];
          if (place.locality && place.locality !== primary) parts.push(place.locality);
          if (place.region && parts.indexOf(place.region) === -1) parts.push(place.region);
          secondary = parts.join(', ');
          if (!secondary && place.country && place.country !== primary) secondary = place.country;
        } else {
          primary = place.name || '';
          secondary = locationLine(place);
        }
        return { primary: primary, secondary: secondary, mode: mode };
      }

      function optionToPlace(option) {
        if (!option || !option.value) {
          return null;
        }
        return {
          id: parseInt(option.value, 10) || 0,
          name: option.getAttribute('data-place-name') || '',
          area_label: option.getAttribute('data-place-area') || '',
          locality: option.getAttribute('data-place-locality') || '',
          region: option.getAttribute('data-place-region') || '',
          country: option.getAttribute('data-place-country') || '',
          default_display_mode: option.getAttribute('data-place-mode') || 'exact'
        };
      }

      function ensureOption(place) {
        if (!select || !place || !place.id) {
          return null;
        }
        var option = select.querySelector('option[value="' + String(place.id) + '"]');
        if (!option) {
          option = document.createElement('option');
          option.value = String(place.id);
          select.appendChild(option);
        }
        option.setAttribute('data-place-name', place.name || '');
        option.setAttribute('data-place-area', place.area_label || '');
        option.setAttribute('data-place-locality', place.locality || '');
        option.setAttribute('data-place-region', place.region || '');
        option.setAttribute('data-place-country', place.country || '');
        option.setAttribute('data-place-mode', place.default_display_mode || 'exact');
        var labels = labelsFor(place, place.default_display_mode || 'exact');
        option.textContent = labels.primary + (labels.secondary ? ' · ' + labels.secondary : '');
        return option;
      }

      function closeCreateModal() {
        if (!createModal || createModal.hidden) {
          return;
        }
        createModal.hidden = true;
        document.body.classList.remove('local-places-modal-open');
        if (lastFocused && typeof lastFocused.focus === 'function') {
          lastFocused.focus();
        }
      }

      function openCreateModal() {
        if (!createModal) {
          return;
        }
        lastFocused = document.activeElement;
        createModal.hidden = false;
        setCreateStatus('', false);
        document.body.classList.add('local-places-modal-open');
        window.setTimeout(function () {
          if (newName) newName.focus();
        }, 0);
      }

      function setSelected(place, mode) {
        if (!place || !place.id) {
          clearSelected();
          return;
        }
        mode = mode || place.default_display_mode || 'exact';
        if (placeId) placeId.value = String(place.id);
        if (modeInput) modeInput.value = mode;
        var labels = labelsFor(place, mode);
        if (selectedPrimary) selectedPrimary.textContent = labels.primary;
        if (selectedSecondary) selectedSecondary.textContent = labels.secondary;
        if (selected) selected.classList.add('is-selected');
        if (change) change.hidden = false;
        if (remove) remove.hidden = false;
        if (pickerBody) pickerBody.hidden = true;
        var option = ensureOption(place);
        if (select && option) select.value = String(place.id);
        if (toggle) {
          toggle.classList.add('is-active');
          toggle.setAttribute('aria-label', 'Change location');
          toggle.setAttribute('title', 'Change location');
        }
        closeCreateModal();
      }

      function clearSelected() {
        if (placeId) placeId.value = '';
        if (modeInput) modeInput.value = 'exact';
        if (select) select.value = '';
        if (selectedPrimary) selectedPrimary.textContent = '';
        if (selectedSecondary) selectedSecondary.textContent = '';
        if (selected) selected.classList.remove('is-selected');
        if (change) change.hidden = true;
        if (remove) remove.hidden = true;
        if (pickerBody) pickerBody.hidden = false;
        if (toggle) {
          toggle.classList.remove('is-active');
          toggle.setAttribute('aria-label', 'Add location');
          toggle.setAttribute('title', 'Add location');
        }
      }

      function requestPosition(callback, forCreate) {
        var notify = forCreate ? setCreateStatus : setStatus;
        if (!navigator.geolocation) {
          notify('This browser does not provide location access. You can still choose a saved place.', true);
          return;
        }
        notify('Finding your current location…', false);
        navigator.geolocation.getCurrentPosition(function (position) {
          var latitude = Number(position.coords.latitude);
          var longitude = Number(position.coords.longitude);
          var accuracy = Number(position.coords.accuracy || 0);
          if (newLatitude) newLatitude.value = latitude.toFixed(7);
          if (newLongitude) newLongitude.value = longitude.toFixed(7);
          callback(latitude, longitude, accuracy);
        }, function () {
          notify('Location permission was denied or the device could not provide a location.', true);
        }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
      }

      function renderNearby(places) {
        if (!nearbyResults) {
          return;
        }
        nearbyResults.innerHTML = '';
        if (!places || !places.length) {
          nearbyResults.hidden = true;
          setStatus('No saved places were found nearby. Add a new place if needed.', false);
          return;
        }
        var heading = document.createElement('strong');
        heading.textContent = 'Nearby saved places';
        nearbyResults.appendChild(heading);
        places.forEach(function (place) {
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'local-places-nearby-result';
          var distance = typeof place.distance_meters === 'number' ? ' · ' + place.distance_meters + ' m' : '';
          button.textContent = (place.name || 'Saved place') + (locationLine(place) ? ' · ' + locationLine(place) : '') + distance;
          button.addEventListener('click', function () {
            setSelected(place, place.default_display_mode || 'exact');
            setStatus('Location attached to this post.', false);
          });
          nearbyResults.appendChild(button);
        });
        nearbyResults.hidden = false;
        setStatus('Choose a nearby saved place.', false);
      }

      function findNearby() {
        if (!nearbyEndpoint || !window.fetch) {
          setStatus('Nearby search is unavailable in this browser. You can still choose a saved place.', true);
          return;
        }
        if (nearbyButton) nearbyButton.disabled = true;
        requestPosition(function (latitude, longitude, accuracy) {
          var body = new URLSearchParams();
          body.set('csrf_token', csrfToken());
          body.set('latitude', String(latitude));
          body.set('longitude', String(longitude));
          body.set('accuracy', String(accuracy));
          window.fetch(nearbyEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
          }).then(function (response) {
            return response.json().then(function (json) {
              if (!response.ok || !json || json.ok !== true) {
                throw new Error(json && json.message ? json.message : 'Nearby places could not be loaded.');
              }
              return json.places || [];
            });
          }).then(renderNearby).catch(function (error) {
            setStatus(error && error.message ? error.message : 'Nearby places could not be loaded.', true);
          }).finally(function () {
            if (nearbyButton) nearbyButton.disabled = false;
          });
        });
      }

      function saveCurrentPlace() {
        if (!saveEndpoint || !window.fetch) {
          setStatus('Saving places is unavailable in this browser.', true);
          return;
        }
        var saveWithCoordinates = function (latitude, longitude) {
          var name = newName ? newName.value.trim() : '';
          var publicLabel = newPublicLabel ? newPublicLabel.value.trim() : '';
          if (!name) {
            setCreateStatus('Enter a place name before saving.', true);
            if (newName) newName.focus();
            return;
          }
          if (saveButton) saveButton.disabled = true;
          setCreateStatus('Saving this place locally…', false);
          var body = new URLSearchParams();
          body.set('csrf_token', csrfToken());
          body.set('name', name);
          body.set('category', 'other');
          body.set('area_label', '');
          body.set('locality', publicLabel);
          body.set('region', '');
          body.set('country', '');
          body.set('default_display_mode', 'exact');
          body.set('latitude', String(latitude));
          body.set('longitude', String(longitude));
          window.fetch(saveEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
          }).then(function (response) {
            return response.json().then(function (json) {
              if (!response.ok || !json || json.ok !== true) {
                throw new Error(json && json.message ? json.message : 'The place could not be saved.');
              }
              return json.place;
            });
          }).then(function (place) {
            setSelected(place, place.default_display_mode || 'exact');
            setStatus('Place saved locally and attached to this post.', false);
            if (newName) newName.value = '';
            if (newPublicLabel) newPublicLabel.value = '';
          }).catch(function (error) {
            setCreateStatus(error && error.message ? error.message : 'The place could not be saved.', true);
          }).finally(function () {
            if (saveButton) saveButton.disabled = false;
          });
        };

        var latitude = newLatitude && newLatitude.value ? parseFloat(newLatitude.value) : NaN;
        var longitude = newLongitude && newLongitude.value ? parseFloat(newLongitude.value) : NaN;
        if (!isNaN(latitude) && !isNaN(longitude)) {
          saveWithCoordinates(latitude, longitude);
          return;
        }
        requestPosition(function (lat, lng) {
          saveWithCoordinates(lat, lng);
        }, true);
      }

      if (toggle) {
        toggle.addEventListener('click', function (event) {
          event.preventDefault();
          picker.hidden = !picker.hidden;
          toggle.setAttribute('aria-expanded', picker.hidden ? 'false' : 'true');
          if (!picker.hidden && !placeId.value && select) select.focus();
        });
      }
      if (select) {
        select.addEventListener('change', function () {
          var place = optionToPlace(select.options[select.selectedIndex]);
          if (!place) {
            clearSelected();
            return;
          }
          setSelected(place, place.default_display_mode || 'exact');
          setStatus('Location attached to this post.', false);
        });
      }
      if (change) {
        change.addEventListener('click', function () {
          if (pickerBody) pickerBody.hidden = false;
          if (select) select.focus();
        });
      }
      if (remove) {
        remove.addEventListener('click', function () {
          clearSelected();
          setStatus('Location removed from this post.', false);
        });
      }
      if (nearbyButton) nearbyButton.addEventListener('click', findNearby);
      if (createOpen) createOpen.addEventListener('click', openCreateModal);
      createCloseButtons.forEach(function (button) {
        button.addEventListener('click', closeCreateModal);
      });
      if (saveButton) saveButton.addEventListener('click', saveCurrentPlace);
      if (createModal) {
        createModal.addEventListener('keydown', function (event) {
          if (event.key === 'Enter' && event.target && event.target.tagName === 'INPUT') {
            event.preventDefault();
            saveCurrentPlace();
          }
        });
      }
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && createModal && !createModal.hidden) {
          closeCreateModal();
        }
      });
    });
  }

  function initLocalPlacesEditor() { setupLocalPlaces(document); }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLocalPlacesEditor);
  } else {
    initLocalPlacesEditor();
  }
}());
