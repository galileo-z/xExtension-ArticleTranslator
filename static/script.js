(function () {
  if (document.readyState && document.readyState !== 'loading') {
    configureArticleTranslator();
  } else {
    document.addEventListener('DOMContentLoaded', configureArticleTranslator, false);
  }

  function configureArticleTranslator() {
    var root = document.getElementById('global') || document.body;
    if (!root || root.dataset.oaiTranslatorBound === 'true') {
      return;
    }

    root.dataset.oaiTranslatorBound = 'true';
    root.addEventListener('click', function (event) {
      for (var target = event.target; target && target !== this; target = target.parentNode) {
        if (target.matches && target.matches('.oai-translation-btn')) {
          event.preventDefault();
          event.stopPropagation();
          translateArticle(target);
          break;
        }
      }
    }, false);
  }

  async function translateArticle(button) {
    var container = button.closest('.oai-translation-wrap');
    if (!container || container.classList.contains('oai-loading')) {
      return;
    }

    clearGeneratedTranslations(container);

    var segments = collectSegments(container);
    if (segments.length === 0) {
      setContainerState(container, 2, button.dataset.noContentText || 'No translatable content');
      return;
    }

    var loadingText = button.dataset.loadingText || 'Translating...';
    var doneText = button.dataset.doneText || 'Done';
    setContainerState(container, 1, loadingText + ' 0/' + segments.length);

    for (var i = 0; i < segments.length; i++) {
      var segment = segments[i];
      var block = insertTranslationBlock(segment, button.dataset.resultLabel || 'Chinese translation');
      setBlockState(block, 'loading', loadingText);
      setContainerState(container, 1, loadingText + ' ' + (i + 1) + '/' + segments.length);

      try {
        var translatedText = await translateSegment(button, segment, function (partialText) {
          setBlockText(block, partialText);
        });
        setBlockText(block, translatedText);
        setBlockState(block, 'done', null);
      } catch (error) {
        console.error(error);
        var message = error.message || button.dataset.requestFailedText || 'Request Failed';
        setBlockState(block, 'error', message);
        setContainerState(container, 2, message);
        return;
      }
    }

    setContainerState(container, 0, doneText + ' (' + segments.length + ')');
  }

  function collectSegments(container) {
    var segments = [];
    var header = findFluxHeader(container);
    var titleNode = findTitleNode(header);
    var titleText = '';

    if (titleNode && isVisibleNode(titleNode)) {
      titleText = readNodeText(titleNode);
    } else {
      titleText = container.dataset.entryTitle || '';
    }

    if (isUsefulText(titleText)) {
      segments.push({
        kind: 'title',
        source: container,
        text: titleText
      });
    }

    var bodySegmentStart = segments.length;
    var articleBody = container.parentElement;
    if (!articleBody) {
      return segments;
    }

    var nodes = Array.prototype.slice.call(articleBody.querySelectorAll('p, h1, h2, h3, h4, h5, h6, blockquote, li'));
    nodes.forEach(function (node) {
      if (!isEligibleContentNode(node, container)) {
        return;
      }

      var text = readNodeText(node);
      if (!isUsefulText(text)) {
        return;
      }

      segments.push({
        kind: kindForNode(node),
        source: node,
        text: text
      });
    });

    if (segments.length === bodySegmentStart) {
      addFallbackBodySegments(segments, articleBody, container);
    }

    return segments;
  }

  function addFallbackBodySegments(segments, articleBody, container) {
    var countBeforeFallback = segments.length;
    var candidates = fallbackContentCandidates(articleBody, container);
    candidates.forEach(function (child) {
      if (!isEligibleContentNode(child, container)) {
        return;
      }

      if (child.querySelector('p, h1, h2, h3, h4, h5, h6, blockquote, li')) {
        return;
      }

      var text = readNodeText(child);
      if (!isUsefulText(text)) {
        return;
      }

      segments.push({
        kind: kindForNode(child),
        source: child,
        text: text
      });
    });

    if (segments.length === countBeforeFallback) {
      fallbackTextNodeCandidates(articleBody, container).forEach(function (node) {
        var text = readNodeText(node);
        if (!isUsefulText(text)) {
          return;
        }

        segments.push({
          kind: 'paragraph',
          source: node,
          text: text
        });
      });
    }
  }

  function fallbackContentCandidates(articleBody, container) {
    var candidates = [];
    var walker = document.createTreeWalker(articleBody, NodeFilter.SHOW_ELEMENT, {
      acceptNode: function (node) {
        if (node === container || !articleBody.contains(node)) {
          return NodeFilter.FILTER_REJECT;
        }

        if (isPluginUiNode(node) || node.closest('script, style, noscript, pre, code')) {
          return NodeFilter.FILTER_REJECT;
        }

        if (!node.matches || !node.matches('p, h1, h2, h3, h4, h5, h6, blockquote, li, div, section, article, main, table, tr, td, th')) {
          return NodeFilter.FILTER_SKIP;
        }

        if (!isVisibleNode(node) || !isUsefulText(readNodeText(node))) {
          return NodeFilter.FILTER_SKIP;
        }

        return hasTranslatableChildBlock(node) ? NodeFilter.FILTER_SKIP : NodeFilter.FILTER_ACCEPT;
      }
    });

    var node;
    while ((node = walker.nextNode())) {
      candidates.push(node);
    }

    return candidates;
  }

  function fallbackTextNodeCandidates(articleBody, container) {
    var candidates = [];
    var walker = document.createTreeWalker(articleBody, NodeFilter.SHOW_TEXT, {
      acceptNode: function (node) {
        var parent = node.parentElement;

        if (!parent || container.contains(node)) {
          return NodeFilter.FILTER_REJECT;
        }

        if (isPluginUiNode(parent) || parent.closest('script, style, noscript, pre, code')) {
          return NodeFilter.FILTER_REJECT;
        }

        if (!isVisibleNode(parent) || !isUsefulText(readNodeText(node))) {
          return NodeFilter.FILTER_SKIP;
        }

        return NodeFilter.FILTER_ACCEPT;
      }
    });

    var node;
    while ((node = walker.nextNode())) {
      candidates.push(node);
    }

    return candidates;
  }

  function hasTranslatableChildBlock(node) {
    var children = Array.prototype.slice.call(node.children);
    return children.some(function (child) {
      if (isPluginUiNode(child) || child.closest('script, style, noscript, pre, code')) {
        return false;
      }

      if (!child.matches || !child.matches('p, h1, h2, h3, h4, h5, h6, blockquote, li, div, section, article, main, table, tr, td, th')) {
        return false;
      }

      return isVisibleNode(child) && isUsefulText(readNodeText(child));
    });
  }

  function isEligibleContentNode(node, container) {
    if (!node || container.contains(node)) {
      return false;
    }

    if (isPluginUiNode(node)) {
      return false;
    }

    if (node.closest('script, style, noscript, pre, code')) {
      return false;
    }

    if (node.matches('blockquote') && node.querySelector('p, h1, h2, h3, h4, h5, h6, li')) {
      return false;
    }

    if (node.matches('li') && node.querySelector('p, h1, h2, h3, h4, h5, h6, blockquote')) {
      return false;
    }

    return isVisibleNode(node);
  }

  function isVisibleNode(node) {
    return node.getClientRects().length > 0;
  }

  function findFluxHeader(container) {
    var articleBody = container.parentElement;
    if (articleBody && articleBody.previousElementSibling && articleBody.previousElementSibling.matches('.flux_header')) {
      return articleBody.previousElementSibling;
    }

    var article = container.closest('.flux, article, .entry');
    if (article) {
      return article.querySelector('.flux_header, header');
    }

    return null;
  }

  function findTitleNode(header) {
    if (!header) {
      return null;
    }

    var selectors = [
      '.item.title a',
      '.item.title',
      '.title a',
      '.title',
      'h1 a',
      'h1',
      'h2 a',
      'h2',
      'a'
    ];

    for (var i = 0; i < selectors.length; i++) {
      var node = header.querySelector(selectors[i]);
      if (node && isUsefulText(readNodeText(node))) {
        return node;
      }
    }

    var candidates = Array.prototype.slice.call(header.querySelectorAll('a, h1, h2, h3, .title'));
    candidates.sort(function (a, b) {
      return readNodeText(b).length - readNodeText(a).length;
    });

    return candidates[0] || null;
  }

  function readNodeText(node) {
    if (node.nodeType === Node.TEXT_NODE) {
      return normalizeText(node.nodeValue || '');
    }

    var clone = node.cloneNode(true);
    Array.prototype.slice.call(clone.querySelectorAll('.oai-translation-wrap, .oai-translation-result, .oai-summary-wrap, script, style, noscript')).forEach(function (child) {
      child.remove();
    });

    return normalizeText(clone.textContent || '');
  }

  function isPluginUiNode(node) {
    return Boolean(node.closest && node.closest('.oai-translation-wrap, .oai-translation-result, .oai-summary-wrap'));
  }

  function normalizeText(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
  }

  function isUsefulText(text) {
    return text.length > 1 && /[A-Za-z0-9\u00C0-\uFFFF]/.test(text);
  }

  function kindForNode(node) {
    var tagName = node.tagName ? node.tagName.toLowerCase() : '';
    if (/^h[1-6]$/.test(tagName)) {
      return 'heading';
    }
    if (tagName === 'li') {
      return 'list_item';
    }
    if (tagName === 'blockquote') {
      return 'quote';
    }
    return 'paragraph';
  }

  function insertTranslationBlock(segment, label) {
    var block = document.createElement('div');
    block.className = 'oai-translation-result oai-translation-loading';
    block.dataset.oaiGenerated = 'true';

    if (segment.kind === 'title') {
      block.classList.add('oai-title-translation');
    }

    var labelNode = document.createElement('div');
    labelNode.className = 'oai-translation-label';
    labelNode.textContent = label;

    var textNode = document.createElement('div');
    textNode.className = 'oai-translation-text';

    block.appendChild(labelNode);
    block.appendChild(textNode);

    if (segment.kind === 'title') {
      titleInsertAnchor(segment.source).insertAdjacentElement('afterend', block);
    } else if (segment.source.nodeType === Node.TEXT_NODE && segment.source.parentNode) {
      segment.source.parentNode.insertBefore(block, segment.source.nextSibling);
    } else if (segment.source.matches && segment.source.matches('li')) {
      segment.source.appendChild(block);
    } else {
      segment.source.insertAdjacentElement('afterend', block);
    }

    return block;
  }

  function titleInsertAnchor(node) {
    return node.closest('.item.title, .title') || node;
  }

  function clearGeneratedTranslations(container) {
    var articleBody = container.parentElement;
    if (articleBody) {
      Array.prototype.slice.call(articleBody.querySelectorAll('.oai-translation-result[data-oai-generated="true"]')).forEach(function (node) {
        node.remove();
      });
    }

    var header = findFluxHeader(container);
    if (header) {
      Array.prototype.slice.call(header.querySelectorAll('.oai-translation-result[data-oai-generated="true"]')).forEach(function (node) {
        node.remove();
      });
    }
  }

  function setContainerState(container, statusType, statusMessage) {
    var button = container.querySelector('.oai-translation-btn');
    var status = container.querySelector('.oai-translation-status');

    if (statusType === 1) {
      container.classList.add('oai-loading');
      container.classList.remove('oai-error');
      button.disabled = true;
    } else if (statusType === 2) {
      container.classList.remove('oai-loading');
      container.classList.add('oai-error');
      button.disabled = false;
    } else {
      container.classList.remove('oai-loading');
      container.classList.remove('oai-error');
      button.disabled = false;
    }

    status.textContent = statusMessage || '';
  }

  function setBlockState(block, state, message) {
    block.classList.remove('oai-translation-loading', 'oai-translation-error', 'oai-translation-done');
    block.classList.add('oai-translation-' + state);

    if (message) {
      block.querySelector('.oai-translation-text').textContent = message;
    }
  }

  function setBlockText(block, text) {
    var content = block.querySelector('.oai-translation-text');
    if (!content) {
      return;
    }

    block.classList.remove('oai-translation-loading', 'oai-translation-error');

    if (window.marked && window.marked.parse) {
      content.innerHTML = window.marked.parse(text || '');
    } else {
      content.textContent = text || '';
    }
  }

  async function translateSegment(button, segment, onText) {
    var translatedText = await requestTranslatedText(button, segment);
    onText(translatedText);
    return translatedText;
  }

  async function requestTranslatedText(button, segment) {
    var requestUrl = decodeHtmlEntities(button.dataset.request || '');
    var response;
    try {
      response = await axios.post(requestUrl, {
        ajax: true,
        _csrf: context.csrf,
        kind: segment.kind,
        text: segment.text
      }, {
        headers: {
          'Content-Type': 'application/json'
        }
      });
    } catch (error) {
      if (error.response) {
        throw new Error('FreshRSS translation endpoint returned HTTP ' + error.response.status + ': ' + requestUrl);
      }
      throw error;
    }

    var xresp = response.data;
    if (response.status !== 200 || !xresp || !xresp.response) {
      throw new Error(button.dataset.requestFailedText || 'Request Failed');
    }

    if (xresp.response.error) {
      throw new Error(xresp.response.data || xresp.response.error);
    }

    if (!xresp.response.data) {
      throw new Error(button.dataset.requestFailedText || 'Request Failed');
    }

    return String(xresp.response.data || '');
  }

  function decodeHtmlEntities(value) {
    var textarea = document.createElement('textarea');
    var decoded = value;

    for (var i = 0; i < 3; i++) {
      textarea.innerHTML = decoded;
      if (textarea.value === decoded) {
        break;
      }
      decoded = textarea.value;
    }

    return decoded;
  }

})();
