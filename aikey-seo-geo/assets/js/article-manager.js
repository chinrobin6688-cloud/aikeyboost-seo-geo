(function($){
  'use strict';
  $(function(){
    var featuredFrame;

    function initPublishFloatingPanel(){
      var $card = $('.aikey-article-publish-panel').first();
      var $aside = $card.closest('aside');
      if (!$card.length || !$aside.length) { return; }

      var mediaQuery = window.matchMedia ? window.matchMedia('(max-width: 1050px)') : null;
      var rafId = 0;

      function clearFloating(){
        $card.removeClass('is-aikey-floating').css({
          '--aikey-article-float-top': '',
          '--aikey-article-float-left': '',
          '--aikey-article-float-width': '',
          '--aikey-article-float-max-height': ''
        });
      }

      function placeFloating(){
        rafId = 0;
        var narrow = mediaQuery ? mediaQuery.matches : window.innerWidth <= 1050;
        if (narrow) { clearFloating(); return; }

        // Measure the reserved grid column before the card leaves normal flow.
        var rect = $aside.get(0).getBoundingClientRect();
        if (!rect.width || rect.width < 220) { clearFloating(); return; }

        var adminBar = document.getElementById('wpadminbar');
        var adminBarHeight = adminBar ? adminBar.getBoundingClientRect().height : 0;
        var safeGap = 16;
        var viewportHeight = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
        var usableTop = adminBarHeight + safeGap;
        var usableBottom = viewportHeight - safeGap;
        var usableHeight = Math.max(0, usableBottom - usableTop);
        if (usableHeight < 180) { clearFloating(); return; }
        var maxHeight = usableHeight;

        // Width/max-height must be applied first so vertical centering uses the
        // actual visible height, including the panel's internal scrollbar.
        $card.addClass('is-aikey-floating').css({
          '--aikey-article-float-left': Math.round(rect.left) + 'px',
          '--aikey-article-float-width': Math.round(rect.width) + 'px',
          '--aikey-article-float-max-height': Math.round(maxHeight) + 'px'
        });

        var visibleHeight = Math.min($card.outerHeight(), maxHeight);
        var centeredTop = Math.round(usableTop + ((usableHeight - visibleHeight) / 2));
        $card.css('--aikey-article-float-top', centeredTop + 'px');
      }

      function requestPlace(){
        if (rafId) { window.cancelAnimationFrame(rafId); }
        rafId = window.requestAnimationFrame(placeFloating);
      }

      requestPlace();
      $(window).on('resize.aikeyArticlePublishFloat orientationchange.aikeyArticlePublishFloat', requestPlace);
      $('#collapse-menu').on('click.aikeyArticlePublishFloat', function(){
        window.setTimeout(requestPlace, 80);
        window.setTimeout(requestPlace, 300);
      });

      if (mediaQuery) {
        if (typeof mediaQuery.addEventListener === 'function') {
          mediaQuery.addEventListener('change', requestPlace);
        } else if (typeof mediaQuery.addListener === 'function') {
          mediaQuery.addListener(requestPlace);
        }
      }

      if (window.ResizeObserver) {
        var resizeObserver = new ResizeObserver(requestPlace);
        resizeObserver.observe($aside.get(0));
        resizeObserver.observe($card.get(0));
      }

      if (window.MutationObserver && document.body) {
        var mutationObserver = new MutationObserver(function(mutations){
          for (var i = 0; i < mutations.length; i++) {
            if (mutations[i].attributeName === 'class') { requestPlace(); break; }
          }
        });
        mutationObserver.observe(document.body, {attributes:true, attributeFilter:['class']});
      }
    }

    initPublishFloatingPanel();

    function initSmartSlug(){
      var $fieldWrap = $('.aikey-article-slug-field').first();
      var $title = $('#aikey_article_title');
      var $slug = $('#aikey_article_slug');
      var $button = $('#aikey_article_generate_slug');
      var $status = $('#aikey_article_slug_status');
      var $preview = $('#aikey_article_slug_preview');
      var $unlock = $('#aikey_article_unlock_slug');
      if (!$fieldWrap.length || !$title.length || !$slug.length) { return; }

      var cfg = window.AIKEYArticleManager || {};
      var postId = parseInt($('#aikey-article-form input[name="post_id"]').val() || '0', 10) || 0;
      var locked = String($fieldWrap.attr('data-aikey-slug-locked') || '') === '1';
      var autoManaged = String($fieldWrap.attr('data-aikey-slug-auto') || '') === '1';
      var lastGeneratedTitle = '';
      var request = null;

      function sanitizeLocal(value){
        var cleaned = String(value || '')
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/-+/g, '-')
          .replace(/^-|-$/g, '');
        if (cleaned.length > 80) {
          cleaned = cleaned.slice(0, 80).replace(/-[^-]*$/, '').replace(/-+$/, '');
        }
        return cleaned;
      }

      function setStatus(message, type){
        $status.removeClass('is-warning is-error is-success is-loading');
        if (type) { $status.addClass('is-' + type); }
        $status.text(String(message || ''));
      }

      function updatePreview(){
        var slug = String($slug.val() || '').trim().replace(/^\/+|\/+$/g, '');
        if (!slug) { $preview.prop('hidden', true).find('code').text(''); return; }
        var base = String(cfg.homeUrl || '').replace(/\/+$/, '');
        var structure = String(cfg.permalinkStructure || '');
        var remaining = structure.replace('%postname%', '');
        var preview = '';
        if (structure.indexOf('%postname%') !== -1 && !/%[^%]+%/.test(remaining)) {
          preview = base + '/' + structure.replace(/^\/+/, '').replace('%postname%', slug);
        } else {
          preview = base + '/' + slug + '/';
        }
        $preview.find('code').text(preview.replace(/([^:]\/)\/+/g, '$1'));
        $preview.prop('hidden', false);
      }

      function setLockedState(isLocked){
        locked = !!isLocked;
        $slug.prop('readonly', locked);
        $button.prop('disabled', locked);
      }

      function generate(force){
        var title = String($title.val() || '').trim();
        if (!title) { if (force) { setStatus('请先填写文章标题。', 'warning'); } return; }
        if (locked) { if (force) { setStatus('已发布文章 URL 当前处于保护锁定状态。', 'warning'); } return; }
        if (!force && !autoManaged) { return; }
        if (!force && title === lastGeneratedTitle && $slug.val()) { return; }
        if (!cfg.ajaxUrl || !cfg.slugNonce) { if (force) { setStatus('智能链接组件配置不完整，请刷新页面后重试。', 'error'); } return; }

        if (request && request.readyState !== 4) { request.abort(); }
        $button.prop('disabled', true).text('生成中…');
        setStatus('正在根据标题生成简短英文链接…', 'loading');
        request = $.ajax({
          url: cfg.ajaxUrl,
          method: 'POST',
          dataType: 'json',
          timeout: 20000,
          data: {
            action: 'aikey_article_generate_slug',
            nonce: cfg.slugNonce,
            post_id: postId,
            title: title
          }
        }).done(function(resp){
          if (!resp || !resp.success || !resp.data || !resp.data.slug) {
            var message = resp && resp.data && resp.data.message ? resp.data.message : '暂时无法生成英文链接。';
            setStatus(message, 'error');
            return;
          }
          $slug.val(String(resp.data.slug));
          autoManaged = true;
          lastGeneratedTitle = title;
          updatePreview();
          setStatus(resp.data.message || '英文链接已生成，请确认语义后保存。', resp.data.source === 'local' ? 'warning' : 'success');
        }).fail(function(xhr, textStatus){
          if (textStatus === 'abort') { return; }
          var message = '智能链接服务暂时不可用，但不影响文章编辑。请重试或手工填写英文 Slug。';
          if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) { message = xhr.responseJSON.data.message; }
          if (textStatus === 'timeout') { message = '生成英文链接超时，请重试；也可以直接手工填写英文 Slug。'; }
          setStatus(message, 'error');
        }).always(function(){
          $button.prop('disabled', locked).text('根据标题智能生成');
        });
      }

      $button.on('click', function(e){ e.preventDefault(); generate(true); });
      $title.on('blur.aikeyArticleSlug', function(){ generate(false); });
      $title.on('input.aikeyArticleSlug', function(){
        if (autoManaged && lastGeneratedTitle && String($title.val() || '').trim() !== lastGeneratedTitle) {
          setStatus('标题已变化，离开标题输入框后会重新生成英文链接。', 'warning');
        }
      });
      $slug.on('input.aikeyArticleSlug', function(){
        if (locked) { return; }
        autoManaged = false;
        var cleaned = sanitizeLocal($slug.val());
        if (cleaned !== String($slug.val() || '')) {
          setStatus('Slug 保存时只保留小写英文字母、数字和短横线。', 'warning');
        } else {
          setStatus('已切换为手工链接；之后修改标题不会自动覆盖它。', 'success');
        }
        updatePreview();
      });
      $slug.on('blur.aikeyArticleSlug', function(){
        if (locked) { return; }
        var cleaned = sanitizeLocal($slug.val());
        if (cleaned) { $slug.val(cleaned); updatePreview(); }
      });

      if ($unlock.length) {
        $unlock.on('change.aikeyArticleSlug', function(){
          var enabled = $unlock.is(':checked');
          setLockedState(!enabled);
          if (enabled) {
            autoManaged = true;
            setStatus('已解锁已发布 URL。正在根据当前标题准备英文链接；保存时系统会自动创建旧地址到新地址的 301。', 'warning');
            window.setTimeout(function(){ generate(false); }, 0);
          } else {
            setLockedState(true);
            setStatus('已发布文章 URL 已重新锁定，不会修改。', 'success');
          }
        });
      }

      updatePreview();
      if (!locked && autoManaged && !String($slug.val() || '').trim() && String($title.val() || '').trim()) {
        window.setTimeout(function(){ generate(false); }, 350);
      }
    }

    initSmartSlug();

    function editor(){
      return (window.tinymce && window.tinymce.get) ? window.tinymce.get('aikey_article_content') : null;
    }
    function getContent(){
      var ed = editor();
      if (ed && !(ed.isHidden && ed.isHidden())) { return ed.getContent({format:'html'}); }
      return String($('#aikey_article_content').val() || '');
    }
    function setContent(html){
      html = String(html || '');
      $('#aikey_article_content').val(html);
      var ed = editor();
      if (ed) { ed.setContent(html); if (ed.save) { ed.save(); } if (ed.nodeChanged) { ed.nodeChanged(); } }
    }
    var imageImportState = {
      pending: 0,
      imported: 0,
      failed: 0,
      sessionCount: 0,
      active: 0,
      queue: [],
      sourcePromises: Object.create(null),
      bound: false
    };

    function imageImportConfig(){ return window.AIKEYArticleManager || {}; }

    function updateImageImportStatus(message, type){
      var $status = $('#aikey_article_image_import_status');
      if (!$status.length) { return; }
      $status.removeClass('is-loading is-success is-warning is-error');
      if (type) { $status.addClass('is-' + type); }
      if (message) { $status.text(String(message)); return; }
      if (imageImportState.pending > 0) {
        $status.addClass('is-loading').text('正在将 ' + imageImportState.pending + ' 张粘贴图片保存到媒体库，请稍候…');
      } else if (imageImportState.failed > 0) {
        $status.addClass('is-warning').text('已自动保存 ' + imageImportState.imported + ' 张图片；有 ' + imageImportState.failed + ' 张未能入库，请检查原图或使用“添加媒体”上传。');
      } else if (imageImportState.imported > 0) {
        $status.addClass('is-success').text('已自动保存 ' + imageImportState.imported + ' 张粘贴图片到媒体库，并替换为本站图片地址。');
      }
    }

    function rememberImportedAttachment(id){
      id = parseInt(id || 0, 10) || 0;
      if (!id) { return; }
      var $field = $('#aikey_article_imported_media_ids');
      if (!$field.length) { return; }
      var ids = String($field.val() || '').split(',').map(function(v){ return parseInt(v, 10) || 0; }).filter(Boolean);
      if (ids.indexOf(id) === -1) { ids.push(id); }
      $field.val(ids.slice(0, 60).join(','));
    }

    function importErrorMessage(xhr, fallback){
      if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
        return String(xhr.responseJSON.data.message);
      }
      return fallback || '图片导入失败。';
    }

    function enqueueImageImport(task){
      return new Promise(function(resolve, reject){
        imageImportState.queue.push({task: task, resolve: resolve, reject: reject});
        pumpImageImportQueue();
      });
    }

    function pumpImageImportQueue(){
      while (imageImportState.active < 2 && imageImportState.queue.length) {
        var item = imageImportState.queue.shift();
        imageImportState.active++;
        Promise.resolve().then(item.task).then(item.resolve, item.reject).finally(function(){
          imageImportState.active--;
          pumpImageImportQueue();
        });
      }
    }

    function ajaxImportImage(formData){
      var cfg = imageImportConfig();
      return new Promise(function(resolve, reject){
        $.ajax({
          url: cfg.ajaxUrl,
          method: 'POST',
          data: formData,
          processData: false,
          contentType: false,
          dataType: 'json',
          timeout: 30000
        }).done(function(resp){
          if (resp && resp.success && resp.data && resp.data.url) { resolve(resp.data); return; }
          reject(new Error(resp && resp.data && resp.data.message ? resp.data.message : '媒体库没有返回有效图片地址。'));
        }).fail(function(xhr){ reject(new Error(importErrorMessage(xhr, '图片上传失败，请重试。'))); });
      });
    }

    function baseImportFormData(alt){
      var cfg = imageImportConfig();
      var fd = new FormData();
      fd.append('action', String(cfg.imageImportAction || 'aikey_article_import_image'));
      fd.append('nonce', String(cfg.imageImportNonce || ''));
      fd.append('post_id', String(parseInt($('#aikey-article-form input[name="post_id"]').val() || '0', 10) || 0));
      fd.append('alt', String(alt || '').slice(0, 200));
      return fd;
    }

    function mimeExtension(mime){
      mime = String(mime || '').toLowerCase();
      if (mime === 'image/jpeg') { return 'jpg'; }
      if (mime === 'image/png') { return 'png'; }
      if (mime === 'image/webp') { return 'webp'; }
      if (mime === 'image/gif') { return 'gif'; }
      return '';
    }

    function dataUrlToBlob(source){
      var match = /^data:([^;,]+)(;base64)?,(.*)$/i.exec(source);
      if (!match) { throw new Error('无法解析剪贴板图片数据。'); }
      var mime = String(match[1] || '').toLowerCase();
      if (!mimeExtension(mime)) { throw new Error('仅支持 JPG、PNG、WebP、GIF 图片。'); }
      var maxBytes = parseInt(imageImportConfig().imageImportMaxBytes || '0', 10) || 0;
      var encodedLength = String(match[3] || '').length;
      var estimatedBytes = match[2] ? Math.ceil(encodedLength * 0.75) : encodedLength;
      if (maxBytes && estimatedBytes > maxBytes) { throw new Error('图片超过站点允许的上传大小。'); }
      var raw;
      try { raw = match[2] ? window.atob(match[3]) : decodeURIComponent(match[3]); }
      catch (e) { throw new Error('剪贴板图片数据已损坏。'); }
      var bytes = new Uint8Array(raw.length);
      for (var i = 0; i < raw.length; i++) { bytes[i] = raw.charCodeAt(i) & 255; }
      return new Blob([bytes], {type: mime});
    }

    function blobUrlToBlob(source){
      return new Promise(function(resolve, reject){
        try {
          var xhr = new XMLHttpRequest();
          xhr.open('GET', source, true);
          xhr.responseType = 'blob';
          xhr.timeout = 10000;
          xhr.onload = function(){ xhr.status === 200 || xhr.status === 0 ? resolve(xhr.response) : reject(new Error('无法读取剪贴板临时图片。')); };
          xhr.onerror = function(){ reject(new Error('无法读取剪贴板临时图片。')); };
          xhr.ontimeout = function(){ reject(new Error('读取剪贴板临时图片超时。')); };
          xhr.send();
        } catch (e) { reject(new Error('无法读取剪贴板临时图片。')); }
      });
    }

    function uploadBlob(blob, alt){
      var cfg = imageImportConfig();
      var blobType = String(blob && blob.type || '').toLowerCase();
      var ext = mimeExtension(blobType);
      if (blobType && !ext) { return Promise.reject(new Error('仅支持 JPG、PNG、WebP、GIF 图片。')); }
      // Some Word/clipboard providers expose a valid image File with an empty MIME.
      // Send it with a harmless placeholder extension; the server detects the real
      // bytes and rewrites the final extension before WordPress stores the file.
      if (!ext) { ext = 'png'; }
      var maxBytes = parseInt(cfg.imageImportMaxBytes || '0', 10) || 0;
      if (!blob.size || (maxBytes && blob.size > maxBytes)) { return Promise.reject(new Error('图片为空或超过站点允许的上传大小。')); }
      var fd = baseImportFormData(alt);
      fd.append('image', blob, 'article-pasted-' + Date.now() + '.' + ext);
      return ajaxImportImage(fd);
    }

    function uploadLocalSource(source, alt){
      return Promise.resolve().then(function(){
        return source.indexOf('data:') === 0 ? dataUrlToBlob(source) : blobUrlToBlob(source);
      }).then(function(blob){ return uploadBlob(blob, alt); });
    }

    function uploadRemoteSource(source, alt){
      var fd = baseImportFormData(alt);
      fd.append('source_url', source);
      return ajaxImportImage(fd);
    }

    function sourceKind(source){
      source = String(source || '').trim();
      if (/^data:image\//i.test(source)) { return 'local'; }
      if (/^blob:/i.test(source)) { return 'local'; }
      if (/^https?:\/\//i.test(source)) {
        try {
          var home = new URL(String(imageImportConfig().homeUrl || window.location.origin), window.location.href);
          var image = new URL(source, window.location.href);
          if (image.hostname.toLowerCase() === home.hostname.toLowerCase()) { return 'same-site'; }
        } catch (e) { return 'unsupported'; }
        return 'remote';
      }
      if (/^(?:file|cid|mhtml):/i.test(source)) { return 'blocking'; }
      return 'same-site';
    }

    function replaceImageSource(img, data){
      if (!img || !data || !data.url) { return; }
      img.setAttribute('src', String(data.url));
      img.removeAttribute('srcset');
      img.removeAttribute('sizes');
      img.removeAttribute('data-src');
      img.removeAttribute('data-srcset');
      img.removeAttribute('data-lazy-src');
      img.removeAttribute('data-original');
      img.removeAttribute('data-aikey-image-import-blocking');
      if (!img.getAttribute('alt') && data.alt) { img.setAttribute('alt', String(data.alt)); }
      rememberImportedAttachment(data.attachmentId);
    }

    function requestImageImport(source, alt){
      source = String(source || '').trim();
      var kind = sourceKind(source);
      // Remote/blob URLs are small and safe to use as in-flight de-duplication keys.
      // Never retain a multi-megabyte data: URL as an object property key.
      var cacheKey = kind === 'remote' || /^blob:/i.test(source) ? source : '';
      if (cacheKey && imageImportState.sourcePromises[cacheKey]) { return imageImportState.sourcePromises[cacheKey]; }
      var cfg = imageImportConfig();
      var maxSession = parseInt(cfg.imageImportMaxSession || '60', 10) || 60;
      if (imageImportState.sessionCount >= maxSession) {
        return Promise.reject(new Error('本次编辑自动导入图片数量已达到上限，请保存文章后继续。'));
      }
      imageImportState.sessionCount++;
      imageImportState.pending++;
      updateImageImportStatus();
      var promise = enqueueImageImport(function(){
        return kind === 'local' ? uploadLocalSource(source, alt) : uploadRemoteSource(source, alt);
      }).then(function(data){
        imageImportState.pending--;
        imageImportState.imported++;
        updateImageImportStatus();
        return data;
      }).catch(function(error){
        imageImportState.pending--;
        imageImportState.failed++;
        if (cacheKey) { delete imageImportState.sourcePromises[cacheKey]; }
        updateImageImportStatus();
        throw error;
      });
      if (cacheKey) { imageImportState.sourcePromises[cacheKey] = promise; }
      return promise;
    }

    function requestClipboardBlob(blob, alt){
      var cfg = imageImportConfig();
      var maxSession = parseInt(cfg.imageImportMaxSession || '60', 10) || 60;
      if (imageImportState.sessionCount >= maxSession) {
        return Promise.reject(new Error('本次编辑自动导入图片数量已达到上限，请保存文章后继续。'));
      }
      imageImportState.sessionCount++;
      imageImportState.pending++;
      updateImageImportStatus();
      return enqueueImageImport(function(){ return uploadBlob(blob, alt); }).then(function(data){
        imageImportState.pending--;
        imageImportState.imported++;
        updateImageImportStatus();
        return data;
      }).catch(function(error){
        imageImportState.pending--;
        imageImportState.failed++;
        updateImageImportStatus();
        throw error;
      });
    }

    function clipboardImageFiles(event){
      var out = [];
      var data = event && event.clipboardData ? event.clipboardData : null;
      if (!data || !data.items) { return out; }
      for (var i = 0; i < data.items.length; i++) {
        var item = data.items[i];
        if (!item || item.kind !== 'file' || !/^image\//i.test(String(item.type || ''))) { continue; }
        var file = item.getAsFile ? item.getAsFile() : null;
        if (file) { out.push(file); }
      }
      return out;
    }

    function processPastedImages(ed, images, clipboardFiles){
      if (!images || !images.length) { return; }
      var cfg = imageImportConfig();
      if (!cfg.ajaxUrl || !cfg.imageImportNonce) {
        updateImageImportStatus('图片自动入库组件配置不完整，请刷新页面后重试。', 'error');
        return;
      }
      var maxPerPaste = parseInt(cfg.imageImportMaxPerPaste || '30', 10) || 30;
      var targets = Array.prototype.slice.call(images).slice(0, maxPerPaste);
      if (images.length > maxPerPaste) {
        imageImportState.failed += images.length - maxPerPaste;
        updateImageImportStatus('一次最多自动导入 ' + maxPerPaste + ' 张图片，多出的图片请分批粘贴。', 'warning');
      }
      var clipboardQueue = Array.isArray(clipboardFiles) ? clipboardFiles.slice(0, maxPerPaste) : [];
      targets.forEach(function(img){
        if (!img || !img.getAttribute) { return; }
        var source = String(img.getAttribute('src') || '').trim();
        if (!source) { return; }
        var kind = sourceKind(source);
        if (kind === 'same-site') { return; }
        var alt = String(img.getAttribute('alt') || '').trim();
        var clipboardFile = (kind === 'local' || kind === 'blocking' || kind === 'unsupported') && clipboardQueue.length ? clipboardQueue.shift() : null;
        var request;
        if (clipboardFile) {
          request = requestClipboardBlob(clipboardFile, alt);
        } else if (kind === 'blocking' || kind === 'unsupported') {
          img.setAttribute('data-aikey-image-import-blocking', '1');
          imageImportState.failed++;
          updateImageImportStatus('检测到无法公开使用的本地/未知图片地址，请删除该图或使用“添加媒体”上传。', 'warning');
          return;
        } else {
          request = requestImageImport(source, alt);
        }
        request.then(function(data){
          replaceImageSource(img, data);
          if (ed && ed.save) { ed.save(); }
          if (ed && ed.nodeChanged) { ed.nodeChanged(); }
        }).catch(function(error){
          if (kind !== 'remote') { img.setAttribute('data-aikey-image-import-blocking', '1'); }
          updateImageImportStatus(error && error.message ? error.message : '图片自动入库失败，请使用“添加媒体”上传。', kind === 'remote' ? 'warning' : 'error');
        });
      });
    }

    function bindPastedImageImporter(){
      var attempts = 0;
      var timer = window.setInterval(function(){
        attempts++;
        var ed = editor();
        if (!ed || !ed.on || !ed.getBody || !ed.getBody()) {
          if (attempts >= 80) { window.clearInterval(timer); }
          return;
        }
        window.clearInterval(timer);
        if (imageImportState.bound) { return; }
        imageImportState.bound = true;
        var body = ed.getBody();
        var onPaste = function(event){
          var current = ed.getBody();
          var before = current ? Array.prototype.slice.call(current.querySelectorAll('img')) : [];
          var clipboardFiles = clipboardImageFiles(event);
          window.setTimeout(function(){
            var currentBody = ed.getBody();
            if (!currentBody) { return; }
            var after = Array.prototype.slice.call(currentBody.querySelectorAll('img'));
            var fresh = after.filter(function(img){ return before.indexOf(img) === -1; });
            processPastedImages(ed, fresh, clipboardFiles);
          }, 80);
        };
        if (body && body.addEventListener) { body.addEventListener('paste', onPaste, true); }
        else { ed.on('paste', onPaste); }
      }, 250);
    }

    function unresolvedLocalImages(){
      var html = getContent();
      if (/src\s*=\s*["'](?:data:image\/|blob:|file:|cid:|mhtml:)/i.test(html)) { return true; }
      var ed = editor();
      if (ed && ed.getBody && ed.getBody()) {
        return !!ed.getBody().querySelector('img[data-aikey-image-import-blocking="1"]');
      }
      return false;
    }

    bindPastedImageImporter();

    function backupHtml(){
      return String($('#aikey_article_heading_backup').val() || '') || String($('#aikey_article_heading_saved_backup').val() || '');
    }
    function refreshRestore(){ $('#aikey_article_restore_headings').prop('disabled', !backupHtml()); }

    $('#aikey_article_auto_headings').on('click', function(e){
      e.preventDefault();
      var ed = editor();
      if (!ed || !window.AIKEYArticleHeadingAssistant || typeof window.AIKEYArticleHeadingAssistant.organizeEditor !== 'function') {
        window.alert('智能标题组件尚未加载完成，请切换到“可视化”模式后稍候再试。');
        return;
      }
      if (ed.isHidden && ed.isHidden()) { ed.setContent(getContent()); }
      window.AIKEYArticleHeadingAssistant.organizeEditor(ed);
      if (ed.save) { ed.save(); }
      refreshRestore();
    });

    $('#aikey_article_restore_headings').on('click', function(e){
      e.preventDefault();
      var backup = backupHtml();
      if (!backup) { return; }
      if (!window.confirm('确认恢复到最近一次智能标题整理前的文章正文？恢复后仍需点击“保存文章”才会写入数据库。')) { return; }
      var current = getContent();
      setContent(backup);
      $('#aikey_article_heading_backup').val(current);
      $('#aikey_article_heading_saved_backup').val('');
      refreshRestore();
    });
    refreshRestore();

    $('#aikey_article_featured_pick').on('click', function(e){
      e.preventDefault();
      if (featuredFrame) { featuredFrame.open(); return; }
      featuredFrame = wp.media({title:'选择文章主图', button:{text:'使用这张图片'}, multiple:false, library:{type:'image'}});
      featuredFrame.on('select', function(){
        var a = featuredFrame.state().get('selection').first().toJSON();
        $('#aikey_article_featured_id').val(a.id);
        var src = (a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url;
        $('#aikey_article_featured_preview').empty().append($('<img>', {alt:''}).attr('src', String(src || '')));
      });
      featuredFrame.open();
    });
    $('#aikey_article_featured_clear').on('click', function(e){ e.preventDefault(); $('#aikey_article_featured_id').val(''); $('#aikey_article_featured_preview').empty(); });

    function countChars(selector, key){
      var value = String($(selector).val() || '');
      $('[data-aikey-count="'+key+'"]').text(Array.from(value).length);
    }
    function splitKeywords(value){
      var seen = {}, out = [];
      String(value || '').split(/[\r\n,，;；、]+/).forEach(function(v){ v = v.trim(); if (v && !seen[v]) { seen[v]=1; out.push(v); } });
      return out;
    }
    function updatePlanningCounts(){
      countChars('#aikey_article_seo_title', 'seo-title');
      countChars('#aikey_article_meta_description', 'meta-description');
      $('[data-aikey-count="core-keywords"]').text(splitKeywords($('#aikey_article_core_keywords').val()).length);
      $('[data-aikey-count="related-keywords"]').text(splitKeywords($('#aikey_article_related_keywords').val()).length);
      var questions = String($('#aikey_article_search_questions').val() || '').split(/\r?\n/).map(function(v){return v.trim();}).filter(Boolean);
      $('[data-aikey-count="search-questions"]').text(questions.length);
    }
    $('#aikey_article_seo_title,#aikey_article_meta_description,#aikey_article_core_keywords,#aikey_article_related_keywords,#aikey_article_search_questions').on('input change', updatePlanningCounts);
    updatePlanningCounts();

    $('#aikey-article-form').on('submit', function(e){
      if (imageImportState.pending > 0) {
        e.preventDefault();
        window.alert('还有 ' + imageImportState.pending + ' 张粘贴图片正在保存到媒体库，请等待上传完成后再保存文章。');
        return;
      }
      if (unresolvedLocalImages()) {
        e.preventDefault();
        window.alert('正文中仍有未成功入库的本地/临时图片。请等待自动上传完成，或删除该图后使用“添加媒体”上传，再保存文章。');
        return;
      }
      var core = splitKeywords($('#aikey_article_core_keywords').val());
      var related = splitKeywords($('#aikey_article_related_keywords').val());
      var questions = String($('#aikey_article_search_questions').val() || '').split(/\r?\n/).map(function(v){return v.trim();}).filter(Boolean);
      if (core.length > 3 || related.length > 10 || questions.length > 10) {
        e.preventDefault();
        window.alert('SEO/GEO内容设置超出建议上限：核心关键词最多3个、相关关键词最多10个、用户搜索问题最多10个。请调整后再保存。');
        return;
      }
      var ed = editor(); if (ed && ed.save) { ed.save(); }
    });
  });
})(jQuery);
