(function(root, factory){
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.AIKEYArticleHeadingAssistant = api;
  }

  if (root && root.tinymce && root.tinymce.PluginManager) {
    root.tinymce.PluginManager.add('aikey_article_heading_assistant', function(editor){
      editor.addCommand('aikeyArticleAutoHeadings', function(){
        api.organizeEditor(editor);
      });

      editor.addButton('aikey_article_heading_assistant', {
        text: '智能标题',
        tooltip: '保守识别并设置 H2/H3/H4',
        onclick: function(){ editor.execCommand('aikeyArticleAutoHeadings'); }
      });

      editor.on('PastePostProcess', function(event){
        if (!event || !event.node) { return; }
        // Pasting only removes foreign font-family declarations. Heading conversion
        // is deliberately deferred until the editor explicitly clicks Smart Heading.
        // This keeps Word/website paste non-destructive and gives the backup/restore
        // mechanism a chance to protect the original content.
        api.resetPastedFontFamilies(event.node);
      });
    });
  }
})(typeof window !== 'undefined' ? window : (typeof global !== 'undefined' ? global : this), function(){
  'use strict';

  var CHINESE_NUM = '一二三四五六七八九十百零〇两';
  var CONNECTIVE_START = /^(例如|比如|其中|因此|同时|另外|此外|如果|因为|所以|建议|说明|需要|可以|通过|我们|本文|本次|首先|其次|再次|最后|然后|接着|对于|关于|根据|由于|为了|从而|并且|以及|但|但是|而且|注意到)[:：]?/;
  var LABEL_START = /^(例如|比如|说明|注意|提示|备注|注|温馨提示|特别说明|小提示|举例|案例)[:：]/;

  function cleanText(text){
    return String(text || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function resetPastedFontFamilies(container){
    if (!container) { return 0; }
    var nodes = [];
    if (container.nodeType === 1) { nodes.push(container); }
    if (container.querySelectorAll) {
      nodes = nodes.concat(Array.prototype.slice.call(container.querySelectorAll('[style],[face],font')));
    }
    var changed = 0;
    nodes.forEach(function(el){
      if (!el || !el.getAttribute) { return; }
      if (el.hasAttribute && el.hasAttribute('face')) {
        el.removeAttribute('face');
        changed++;
      }
      if (el.style && typeof el.style.removeProperty === 'function') {
        var before = el.getAttribute('style') || '';
        el.style.removeProperty('font-family');
        el.style.removeProperty('mso-ascii-font-family');
        el.style.removeProperty('mso-hansi-font-family');
        el.style.removeProperty('mso-fareast-font-family');
        el.style.removeProperty('mso-bidi-font-family');
        var after = el.getAttribute('style') || '';
        if (!after.trim()) { el.removeAttribute('style'); after = ''; }
        if (before !== after) { changed++; }
      }
    });
    return changed;
  }

  function rawExplicitLevelFromMeta(tagName, className, styleText){
    var tag = String(tagName || '').toLowerCase();
    var tagMatch = tag.match(/^h([1-6])$/);
    if (tagMatch) { return parseInt(tagMatch[1], 10) || 0; }

    var cls = String(className || '');
    var style = String(styleText || '');
    var match = cls.match(/(?:MsoHeading|Heading)[\s_-]*([1-6])/i);
    if (!match) { match = style.match(/mso-outline-level\s*:\s*([1-6])/i); }
    return match ? (parseInt(match[1], 10) || 0) : 0;
  }

  function normalizedBodyHeadingLevel(sourceLevel, sourceBase){
    var raw = parseInt(sourceLevel || 0, 10) || 0;
    var base = parseInt(sourceBase || 0, 10) || 0;
    if (!raw) { return 0; }
    if (!base) { base = 1; }
    return Math.max(2, Math.min(4, 2 + Math.max(0, raw - base)));
  }

  function explicitLevelFromMeta(tagName, className, styleText){
    // Keep the historical single-node interpretation for callers/tests. The
    // document organizer uses rawExplicitLevelFromMeta() + a document baseline
    // so pasted Heading 2 / H3-only bodies can still normalize to H2.
    var tag = String(tagName || '').toLowerCase();
    if (tag === 'h1' || tag === 'h2') { return 2; }
    if (tag === 'h3') { return 3; }
    if (tag === 'h4' || tag === 'h5' || tag === 'h6') { return 4; }
    var raw = rawExplicitLevelFromMeta(tagName, className, styleText);
    if (!raw) { return 0; }
    if (raw <= 1) { return 2; }
    if (raw === 2) { return 3; }
    return 4;
  }

  function numberedInfo(text){
    var t = cleanText(text);
    if (!t) { return null; }

    if (new RegExp('^第[' + CHINESE_NUM + '0-9]+[章节篇部分卷]\\s*').test(t)) {
      return { level: 2, kind: 'chapter', family: 'chapter', strong: true };
    }
    if (/^\d+\.\d+\.\d+(?:\.\d+)*\s*\S/.test(t)) {
      return { level: 4, kind: 'decimal_deep', family: 'decimal', strong: false };
    }
    if (/^\d+\.\d+\s*\S/.test(t)) {
      return { level: 3, kind: 'decimal_two', family: 'decimal', strong: false };
    }
    if (new RegExp('^[' + CHINESE_NUM + ']+[、.．]\\s*\\S').test(t)) {
      return { level: 2, kind: 'chinese_top', family: 'top', strong: false };
    }
    if (/^\d+[、．]\s*\S/.test(t) || /^\d+\.\s+\S/.test(t)) {
      return { level: 2, kind: 'arabic_top', family: 'top', strong: false };
    }
    if (new RegExp('^[（(][' + CHINESE_NUM + ']+[）)]\\s*\\S').test(t)) {
      return { level: 3, kind: 'chinese_paren', family: 'paren', strong: false };
    }
    if (/^[（(]\d+[）)]\s*\S/.test(t)) {
      return { level: 4, kind: 'arabic_paren', family: 'paren', strong: false };
    }
    return null;
  }

  function numberedLevel(text){
    var info = numberedInfo(text);
    return info ? info.level : 0;
  }

  function punctuationCount(text){
    var matches = cleanText(text).match(/[，,；;。]/g);
    return matches ? matches.length : 0;
  }

  function isSentenceLikeText(text){
    var t = cleanText(text);
    if (!t) { return true; }
    if (t.length > 42) { return true; }
    // Question headings are common in SEO/GEO articles, so ?/？ are allowed.
    if (/[。；;，,]$/.test(t)) { return true; }
    if (punctuationCount(t) >= 2) { return true; }
    if (CONNECTIVE_START.test(t)) { return true; }
    if (LABEL_START.test(t)) { return true; }
    if (/https?:\/\/|www\.|@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/i.test(t)) { return true; }
    return false;
  }

  function isShortHeadingLikeText(text){
    var t = cleanText(text);
    if (!t || t.length > 36) { return false; }
    return !isSentenceLikeText(t);
  }

  function styleIsBold(styleText){
    return /font-weight\s*:\s*(?:bold|bolder|[6-9]00)/i.test(String(styleText || ''));
  }

  function styleIsNormalWeight(styleText){
    return /font-weight\s*:\s*(?:normal|lighter|[1-5]00)/i.test(String(styleText || ''));
  }

  function subtreeTextIsBold(node, inheritedBold){
    if (!node) { return true; }
    if (node.nodeType === 3) {
      return !cleanText(node.nodeValue) || !!inheritedBold;
    }
    if (node.nodeType !== 1) { return true; }

    var tag = String(node.tagName || '').toLowerCase();
    var ownBold = !!inheritedBold || tag === 'strong' || tag === 'b';
    if (node.getAttribute) {
      var ownStyle = node.getAttribute('style') || '';
      if (styleIsNormalWeight(ownStyle)) { ownBold = false; }
      if (styleIsBold(ownStyle)) { ownBold = true; }
    }

    var children = node.childNodes || [];
    for (var i = 0; i < children.length; i++) {
      if (!subtreeTextIsBold(children[i], ownBold)) { return false; }
    }
    return true;
  }

  function isFullyBoldElement(el){
    if (!el || !el.childNodes || !cleanText(el.textContent || '')) { return false; }
    var selfBold = el.getAttribute && styleIsBold(el.getAttribute('style') || '');
    return subtreeTextIsBold(el, selfBold);
  }

  function parseCssSizePx(value){
    var s = String(value || '').trim().toLowerCase();
    var match = s.match(/^([0-9]+(?:\.[0-9]+)?)(px|pt|em|rem)$/);
    if (!match) { return 0; }
    var n = parseFloat(match[1]);
    if (!isFinite(n) || n <= 0) { return 0; }
    if (match[2] === 'pt') { return n * (96 / 72); }
    if (match[2] === 'em' || match[2] === 'rem') { return n * 16; }
    return n;
  }

  function inlineFontSizePx(el){
    if (!el || !el.getAttribute) { return 0; }

    // Prefer the block's own explicit size. When Word stores font-size on inner
    // spans instead, use a text-weighted median rather than the maximum. This
    // prevents one oversized symbol/superscript from making a normal sentence
    // look like a heading.
    var ownStyle = String(el.getAttribute('style') || '');
    var ownMatch = ownStyle.match(/font-size\s*:\s*([^;]+)/i);
    if (ownMatch) {
      var ownSize = parseCssSizePx(ownMatch[1]);
      if (ownSize) { return ownSize; }
    }

    if (!el.querySelectorAll) { return 0; }
    var weighted = [];
    var totalWeight = 0;
    Array.prototype.slice.call(el.querySelectorAll('[style]')).forEach(function(node){
      if (!node || !node.getAttribute) { return; }
      var style = String(node.getAttribute('style') || '');
      var match = style.match(/font-size\s*:\s*([^;]+)/i);
      if (!match) { return; }
      var size = parseCssSizePx(match[1]);
      if (!size) { return; }
      var weight = Math.max(1, cleanText(node.textContent || '').length);
      weighted.push({ size: size, weight: weight });
      totalWeight += weight;
    });
    if (!weighted.length || !totalWeight) { return 0; }
    weighted.sort(function(a,b){ return a.size - b.size; });
    var halfway = totalWeight / 2;
    var seen = 0;
    for (var i = 0; i < weighted.length; i++) {
      seen += weighted[i].weight;
      if (seen >= halfway) { return weighted[i].size; }
    }
    return weighted[weighted.length - 1].size;
  }

  function median(values){
    var arr = (values || []).filter(function(v){ return isFinite(v) && v > 0; }).sort(function(a,b){ return a-b; });
    if (!arr.length) { return 0; }
    var mid = Math.floor(arr.length / 2);
    return arr.length % 2 ? arr[mid] : (arr[mid - 1] + arr[mid]) / 2;
  }

  function hasBlockChildren(el){
    if (!el || !el.children) { return false; }
    for (var i = 0; i < el.children.length; i++) {
      if (/^(P|DIV|H[1-6]|UL|OL|TABLE|BLOCKQUOTE|FIGURE|SECTION|ARTICLE)$/i.test(el.children[i].tagName || '')) {
        return true;
      }
    }
    return false;
  }

  function insideExcludedStructure(el, stopAt){
    var node = el && el.parentNode;
    while (node && node !== stopAt) {
      var tag = String(node.tagName || '').toLowerCase();
      if (/^(li|ul|ol|table|thead|tbody|tfoot|tr|td|th|blockquote|pre|code|figure|figcaption)$/i.test(tag)) { return true; }
      node = node.parentNode;
    }
    return false;
  }

  function previousElement(el){
    if (!el) { return null; }
    if (el.previousElementSibling) { return el.previousElementSibling; }
    var node = el.previousSibling;
    while (node) {
      if (node.nodeType === 1) { return node; }
      node = node.previousSibling;
    }
    return null;
  }

  function nextElement(el){
    if (!el) { return null; }
    if (el.nextElementSibling) { return el.nextElementSibling; }
    var node = el.nextSibling;
    while (node) {
      if (node.nodeType === 1) { return node; }
      node = node.nextSibling;
    }
    return null;
  }

  function sameNumberingFamily(el, info){
    if (!el || !info) { return false; }
    var tag = String(el.tagName || '').toLowerCase();
    if (tag !== 'p' && tag !== 'div') { return false; }
    var other = numberedInfo(el.textContent || '');
    return !!other && other.family === info.family;
  }

  function looksLikeNumberedListSequence(el, info){
    if (!info || info.kind === 'chapter') { return false; }
    return sameNumberingFamily(previousElement(el), info) || sameNumberingFamily(nextElement(el), info);
  }

  function nextLooksLikeBody(el){
    var node = nextElement(el);
    if (!node) { return true; }
    var tag = String(node.tagName || '').toLowerCase();
    var text = cleanText(node.textContent || '');
    if (!text) { return true; }
    if (/^(ul|ol|table|blockquote|figure)$/i.test(tag)) { return true; }
    if (/^h[2-4]$/.test(tag)) { return false; }
    if ((tag === 'p' || tag === 'div') && text.length >= 18 && !numberedInfo(text)) { return true; }
    if ((tag === 'p' || tag === 'div') && (numberedInfo(text) || (isFullyBoldElement(node) && isShortHeadingLikeText(text)))) { return false; }
    return text.length >= 18;
  }

  function estimateBodyFontPx(container){
    if (!container || !container.querySelectorAll) { return 0; }
    var values = [];
    var nodes = Array.prototype.slice.call(container.querySelectorAll('p,div'));
    nodes.forEach(function(el){
      if (!el.parentNode || hasBlockChildren(el) || insideExcludedStructure(el, container)) { return; }
      var text = cleanText(el.textContent || '');
      if (text.length < 28 || numberedInfo(text) || isFullyBoldElement(el)) { return; }
      var size = inlineFontSizePx(el);
      if (size) { values.push(size); }
    });
    return median(values);
  }

  function visualLevel(el, bodyFontPx){
    var size = inlineFontSizePx(el);
    if (!size) { return 0; }
    if (bodyFontPx > 0) {
      var ratio = size / bodyFontPx;
      if (ratio >= 1.45) { return 2; }
      if (ratio >= 1.20) { return 3; }
      return 0;
    }
    if (size >= 24) { return 2; }
    if (size >= 19) { return 3; }
    return 0;
  }

  function inferCandidateLevel(text, meta, context){
    meta = meta || {};
    context = context || {};
    var explicit = explicitLevelFromMeta(meta.tagName, meta.className, meta.styleText);
    if (explicit) { return explicit; }

    if (meta.excluded || meta.sequenceLike || !isShortHeadingLikeText(text)) { return 0; }

    var numbered = meta.numbered || numberedInfo(text);
    var bold = !!meta.isBold;
    var visual = parseInt(meta.visualLevel || 0, 10) || 0;
    var prev = parseInt(context.previousHeadingLevel || 0, 10) || 0;
    var followedByBody = context.followedByBody !== false;

    if (numbered) {
      if (numbered.kind === 'chapter') { return 2; }

      if (numbered.kind === 'chinese_top') {
        // Chinese top-level numbering is useful, but still require isolation or a
        // visual cue so ordinary numbered lists are not promoted to H2.
        return (bold || visual || followedByBody) ? 2 : 0;
      }

      if (numbered.kind === 'arabic_top') {
        // "1、" / "1. " is very common for lists and steps. Numbering alone is
        // never sufficient.
        return (bold || visual) ? 2 : 0;
      }

      if (numbered.kind === 'decimal_two') {
        if (!(bold || visual) || prev !== 2) { return 0; }
        return 3;
      }

      if (numbered.kind === 'decimal_deep') {
        if (!(bold || visual) || prev !== 3) { return 0; }
        return 4;
      }

      if (numbered.kind === 'chinese_paren' || numbered.kind === 'arabic_paren') {
        // Parenthesized numbering is overwhelmingly used for list items. Only a
        // clear visual heading cue plus an existing parent heading allows it.
        if (!(bold || visual)) { return 0; }
        if (prev === 2) { return 3; }
        if (prev === 3) { return 4; }
        return 0;
      }
    }

    // A short fully-bold standalone line can be a section heading, but without
    // explicit hierarchy evidence we only promote it to H2. We intentionally do
    // not guess H3/H4 from global "has H2" state.
    if (bold && followedByBody && isShortHeadingLikeText(text)) {
      return visual || 2;
    }

    // A visibly larger standalone line may be a heading even if Word stripped
    // its Heading class during paste.
    if (visual && followedByBody) { return visual; }

    return 0;
  }

  function replaceTag(el, tagName){
    if (!el || !el.ownerDocument || !el.parentNode) { return el; }
    if (String(el.tagName || '').toLowerCase() === String(tagName).toLowerCase()) { return el; }
    var replacement = el.ownerDocument.createElement(tagName);
    if (el.attributes) {
      for (var i = 0; i < el.attributes.length; i++) {
        var attr = el.attributes[i];
        if (!attr || /^on/i.test(attr.name)) { continue; }
        try { replacement.setAttribute(attr.name, attr.value); } catch (e) {}
      }
    }
    while (el.firstChild) { replacement.appendChild(el.firstChild); }
    el.parentNode.replaceChild(replacement, el);
    return replacement;
  }

  function normalizeExplicitWordHeadings(container){
    if (!container || !container.querySelectorAll) { return 0; }
    var nodes = Array.prototype.slice.call(container.querySelectorAll('h1,h2,h3,h4,h5,h6,p,div'));
    var records = [];

    nodes.forEach(function(el){
      if (!el.parentNode) { return; }
      var tag = String(el.tagName || '').toLowerCase();
      if ((tag === 'p' || tag === 'div') && hasBlockChildren(el)) { return; }
      if (insideExcludedStructure(el, container)) { return; }
      var raw = rawExplicitLevelFromMeta(tag, el.className || '', el.getAttribute ? (el.getAttribute('style') || '') : '');
      if (!raw) { return; }
      records.push({ el: el, tag: tag, raw: raw });
    });

    if (!records.length) { return 0; }

    // The article title lives outside post_content and is the page H1. Therefore
    // the highest heading level actually present in the body must become H2.
    // This fixes the common Word workflow where the document title is omitted
    // from the pasted body and all sections arrive as Heading 2: those sections
    // are siblings at H2, not H3 children of a non-existent H2.
    var sourceBase = records.reduce(function(min, row){
      return min === 0 || row.raw < min ? row.raw : min;
    }, 0) || 1;

    var changed = 0;
    records.forEach(function(row){
      if (!row.el.parentNode) { return; }
      var level = normalizedBodyHeadingLevel(row.raw, sourceBase);
      var target = 'h' + level;
      if (row.tag !== target) { replaceTag(row.el, target); changed++; }
    });
    return changed;
  }

  // Kept as a public helper for backwards compatibility/tests. The one-click
  // organizer no longer calls this globally because auto-promoting an author's
  // existing H3/H4 can destroy deliberate hierarchy.
  function correctHierarchy(container){
    if (!container || !container.querySelectorAll) { return 0; }
    var headings = Array.prototype.slice.call(container.querySelectorAll('h2,h3,h4'));
    var changed = 0;
    var last = 0;
    headings.forEach(function(el){
      if (!el.parentNode) { return; }
      var level = parseInt(String(el.tagName).replace(/\D/g, ''), 10) || 2;
      var target = level;
      if (!last && target > 2) { target = 2; }
      else if (last && target > last + 1) { target = last + 1; }
      if (target < 2) { target = 2; }
      if (target > 4) { target = 4; }
      if (target !== level) { el = replaceTag(el, 'h' + target); changed++; }
      last = target;
    });
    return changed;
  }

  function repairOrphanHeadingHierarchy(container){
    if (!container || !container.querySelectorAll) { return 0; }
    var headings = Array.prototype.slice.call(container.querySelectorAll('h2,h3,h4'));
    var changed = 0;
    var last = 0;

    headings.forEach(function(el){
      if (!el.parentNode) { return; }
      var level = parseInt(String(el.tagName || '').replace(/\D/g, ''), 10) || 2;
      var target = level;

      // A body may not begin at H3/H4 because the page H1 is the post title.
      // Likewise H4 may not appear directly under H2. Repair only these orphan/
      // skipped-parent cases; valid H2 -> H3 -> H4 structures stay untouched.
      if (!last && target > 2) { target = 2; }
      else if (last && target > last + 1) { target = last + 1; }

      if (target !== level) {
        replaceTag(el, 'h' + target);
        changed++;
      }
      last = target;
    });
    return changed;
  }

  function sameVisualSize(a, b){
    a = parseFloat(a || 0) || 0;
    b = parseFloat(b || 0) || 0;
    if (!a || !b) { return false; }
    return Math.abs(a - b) / Math.max(a, b) <= 0.08;
  }

  function nearestPreviousHeadingLevel(el){
    var node = previousElement(el);
    while (node) {
      var tag = String(node.tagName || '').toLowerCase();
      if (/^h[2-4]$/.test(tag)) { return parseInt(tag.charAt(1), 10) || 0; }
      node = previousElement(node);
    }
    return 0;
  }

  function organizeContainer(container){
    if (!container || !container.querySelectorAll) { return { changed: 0, headings: 0, skipped: 0 }; }

    var changed = normalizeExplicitWordHeadings(container);
    var skipped = 0;
    var bodyFontPx = estimateBodyFontPx(container);
    var promotedVisualRootSize = 0;
    var nodes = Array.prototype.slice.call(container.querySelectorAll('p,div'));

    nodes.forEach(function(el){
      if (!el.parentNode || hasBlockChildren(el)) { return; }
      var text = cleanText(el.textContent || '');
      if (!text) { return; }

      var numbered = numberedInfo(text);
      var sequenceLike = looksLikeNumberedListSequence(el, numbered);
      var excluded = insideExcludedStructure(el, container);
      var bold = isFullyBoldElement(el);
      var visualSize = inlineFontSizePx(el);
      var vLevel = visualLevel(el, bodyFontPx);
      var previousHeadingLevel = nearestPreviousHeadingLevel(el);
      var followedByBody = nextLooksLikeBody(el);

      // If the document has no parent heading yet and the strongest visual cue
      // available is only the old "H3-sized" tier, that tier is the document's
      // top body level and must start at H2. Keep later siblings with the same
      // size at H2 instead of turning only the first one into H2 and the rest H3.
      if (vLevel === 3 && !previousHeadingLevel && followedByBody) {
        promotedVisualRootSize = visualSize || promotedVisualRootSize;
        vLevel = 2;
      } else if (vLevel === 3 && promotedVisualRootSize && sameVisualSize(visualSize, promotedVisualRootSize)) {
        vLevel = 2;
      }

      var level = inferCandidateLevel(text, {
        tagName: String(el.tagName || '').toLowerCase(),
        className: el.className || '',
        styleText: el.getAttribute ? (el.getAttribute('style') || '') : '',
        isBold: bold,
        visualLevel: vLevel,
        numbered: numbered,
        sequenceLike: sequenceLike,
        excluded: excluded
      }, {
        previousHeadingLevel: previousHeadingLevel,
        followedByBody: followedByBody
      });

      if (!level) {
        // Count only plausible-but-ambiguous short/numbered/bold lines, not every
        // ordinary body paragraph. This makes the editor notification useful.
        if (!excluded && isShortHeadingLikeText(text) && (numbered || bold || vLevel)) { skipped++; }
        return;
      }

      var target = 'h' + Math.max(2, Math.min(4, level));
      replaceTag(el, target);
      changed++;
    });

    // Final structural invariant: the page H1 is outside post_content, so the
    // body cannot legally start at H3/H4 or skip a parent level. This pass is
    // deliberately narrow and does not flatten valid author-created hierarchy.
    changed += repairOrphanHeadingHierarchy(container);

    return {
      changed: changed,
      headings: container.querySelectorAll('h2,h3,h4').length,
      skipped: skipped
    };
  }

  function setSessionBackup(html){
    if (typeof document === 'undefined') { return; }
    var field = document.getElementById('aikey_article_heading_backup');
    if (field && !field.value) { field.value = String(html || ''); }
    var restore = document.getElementById('aikey_article_restore_headings');
    if (restore && field && field.value) { restore.disabled = false; }
  }

  function organizeEditor(editor){
    if (!editor || !editor.getBody) { return { changed: 0, headings: 0, skipped: 0 }; }
    if (editor.isHidden && editor.isHidden()) {
      var textarea = typeof document !== 'undefined' ? document.getElementById('aikey_article_content') : null;
      if (textarea) { editor.setContent(textarea.value || ''); }
    }
    var before = editor.getContent({ format: 'html' });
    setSessionBackup(before);
    var result = { changed: 0, headings: 0, skipped: 0 };
    var run = function(){ result = organizeContainer(editor.getBody()); };
    if (editor.undoManager && editor.undoManager.transact) { editor.undoManager.transact(run); } else { run(); }
    if (editor.nodeChanged) { editor.nodeChanged(); }
    if (editor.save) { editor.save(); }
    if (editor.notificationManager && editor.notificationManager.open) {
      var text;
      if (result.changed) {
        text = '已保守整理 ' + result.changed + ' 处标题';
        if (result.skipped) { text += '，另有 ' + result.skipped + ' 处不确定内容已跳过'; }
        text += '；建议人工快速检查。';
      } else if (result.skipped) {
        text = '未自动修改；发现 ' + result.skipped + ' 处不确定内容，已按“宁可漏判、不误判”原则跳过。';
      } else {
        text = '未发现高置信度标题，不做自动修改。';
      }
      editor.notificationManager.open({
        text: text,
        type: result.changed ? 'success' : 'info',
        timeout: 5200
      });
    }
    return result;
  }

  return {
    cleanText: cleanText,
    resetPastedFontFamilies: resetPastedFontFamilies,
    rawExplicitLevelFromMeta: rawExplicitLevelFromMeta,
    normalizedBodyHeadingLevel: normalizedBodyHeadingLevel,
    explicitLevelFromMeta: explicitLevelFromMeta,
    numberedInfo: numberedInfo,
    numberedLevel: numberedLevel,
    isSentenceLikeText: isSentenceLikeText,
    isShortHeadingLikeText: isShortHeadingLikeText,
    isFullyBoldElement: isFullyBoldElement,
    parseCssSizePx: parseCssSizePx,
    inferCandidateLevel: inferCandidateLevel,
    normalizeExplicitWordHeadings: normalizeExplicitWordHeadings,
    correctHierarchy: correctHierarchy,
    repairOrphanHeadingHierarchy: repairOrphanHeadingHierarchy,
    organizeContainer: organizeContainer,
    organizeEditor: organizeEditor
  };
});
