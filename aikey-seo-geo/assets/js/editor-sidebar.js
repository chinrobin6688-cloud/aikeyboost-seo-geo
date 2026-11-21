(function (wp, config) {
    'use strict';

    if (!wp || !config || !wp.plugins || !wp.editor || !wp.components || !wp.element || !wp.data) {
        return;
    }

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useMemo = wp.element.useMemo;
    var useState = wp.element.useState;
    var useSelect = wp.data.useSelect;
    var useDispatch = wp.data.useDispatch;
    var registerPlugin = wp.plugins.registerPlugin;
    var PluginSidebar = wp.editor.PluginSidebar;
    var PluginSidebarMoreMenuItem = wp.editor.PluginSidebarMoreMenuItem;
    var PanelBody = wp.components.PanelBody;
    var TextControl = wp.components.TextControl;
    var TextareaControl = wp.components.TextareaControl;
    var SelectControl = wp.components.SelectControl;
    var Notice = wp.components.Notice;
    var Button = wp.components.Button;
    var Spinner = wp.components.Spinner;
    var apiFetch = wp.apiFetch;
    var createBlock = wp.blocks && wp.blocks.createBlock ? wp.blocks.createBlock : null;
    var getBlockContent = wp.blocks && wp.blocks.getBlockContent ? wp.blocks.getBlockContent : null;

    var keys = config.metaKeys || {};

    function text(value) {
        return (value == null ? '' : String(value)).trim();
    }

    function charLength(value) {
        return Array.from(value || '').length;
    }

    function stripHtml(value) {
        var node = document.createElement('div');
        node.innerHTML = value || '';
        return text((node.textContent || node.innerText || '').replace(/\s+/g, ' '));
    }

    function htmlMetrics(html) {
        var node = document.createElement('div');
        node.innerHTML = html || '';
        var headings = Array.prototype.slice.call(node.querySelectorAll('h2,h3'));
        var links = Array.prototype.slice.call(node.querySelectorAll('a[href]'));
        var images = Array.prototype.slice.call(node.querySelectorAll('img'));
        var homeHost = '';
        try { homeHost = new URL(config.homeUrl).host; } catch (e) { homeHost = window.location.host; }
        var internal = 0;
        var external = 0;
        links.forEach(function (link) {
            var href = link.getAttribute('href') || '';
            if (!href || href.charAt(0) === '#') { return; }
            try {
                var url = new URL(href, config.homeUrl);
                if (url.host === homeHost) { internal += 1; } else if (/^https?:$/i.test(url.protocol)) { external += 1; }
            } catch (e) {}
        });
        var imagesWithAlt = images.filter(function (img) { return text(img.getAttribute('alt')).length > 0; }).length;
        return {
            headings: headings,
            headingText: headings.map(function (h) { return text(h.textContent); }).join(' '),
            internalLinks: internal,
            externalLinks: external,
            images: images.length,
            imagesWithAlt: imagesWithAlt,
            lists: node.querySelectorAll('ul,ol').length,
            tables: node.querySelectorAll('table').length
        };
    }

    function contains(haystack, needle) {
        haystack = text(haystack).toLocaleLowerCase();
        needle = text(needle).toLocaleLowerCase();
        return needle !== '' && haystack.indexOf(needle) !== -1;
    }

    function lineCount(value) {
        return (value || '').split(/\r?\n/).map(text).filter(Boolean).length;
    }

    function splitKeywords(value) {
        var seen = {};
        return String(value || '').split(/[\r\n,，;；、]+/).map(text).filter(function (item) {
            if (!item || seen[item]) { return false; }
            seen[item] = true;
            return true;
        });
    }

    function firstKeyword(value) {
        var rows = splitKeywords(value);
        return rows.length ? rows[0] : '';
    }

    function faqCount(value) {
        return (value || '').split(/\r?\n/).map(text).filter(function (line) {
            if (!line || line.indexOf('||') === -1) { return false; }
            var parts = line.split('||');
            return text(parts[0]) !== '' && text(parts.slice(1).join('||')) !== '';
        }).length;
    }

    function defaultSeoTitle(postTitle) {
        var template = config.titleTemplate || '%title% %sep% %sitename%';
        return text(template
            .replace(/%title%/g, postTitle || '')
            .replace(/%sep%/g, config.separator || '-')
            .replace(/%sitename%/g, config.siteName || '')
            .replace(/%posttype%/g, config.postType || '')
            .replace(/\s+/g, ' '));
    }

    function addCheck(checks, ok, label, help, weight, partial) {
        var earned = ok ? weight : (partial ? Math.round(weight / 2) : 0);
        checks.push({ok: !!ok, partial: !ok && !!partial, label: label, help: help || '', earned: earned, weight: weight});
        return earned;
    }

    function analyze(data) {
        var postTitle = text(data.postTitle);
        var html = data.content || '';
        var plain = stripHtml(html);
        var metrics = htmlMetrics(html);
        var meta = data.meta || {};
        var keyword = firstKeyword(meta[keys.coreKeywords] || meta[keys.keyword]);
        var seoTitle = text(meta[keys.title]) || defaultSeoTitle(postTitle);
        var description = text(meta[keys.description]) || text(data.excerpt) || plain.slice(0, 160);
        var slug = text(data.slug);
        var intro = plain.slice(0, Math.max(180, Math.ceil(plain.length * 0.12)));
        var titleLen = charLength(seoTitle);
        var descriptionLen = charLength(description);
        var contentLen = charLength(plain);

        var seoChecks = [];
        var seoScore = 0;
        seoScore += addCheck(seoChecks, keyword !== '', '已设置核心关键词', '设置一个最重要的搜索主题，便于持续检查页面是否围绕同一意图。', 8);
        seoScore += addCheck(seoChecks, seoTitle !== '' && titleLen >= 8 && titleLen <= 60, 'SEO 标题长度合理', '中国搜索结果通常更需要简洁、明确、包含主题的标题；建议约 8–60 个字符。当前 ' + titleLen + ' 字符。', 12, seoTitle !== '');
        seoScore += addCheck(seoChecks, description !== '' && descriptionLen >= 50 && descriptionLen <= 180, 'Meta 描述信息充分', '建议用自然中文概括页面价值、对象与差异点。当前 ' + descriptionLen + ' 字符。', 12, description !== '');
        seoScore += addCheck(seoChecks, keyword !== '' && contains(postTitle, keyword), '页面标题包含核心主题', '核心主题不必机械堆砌，但主标题应让搜索引擎和用户立即知道页面在讲什么。', 8);
        seoScore += addCheck(seoChecks, keyword !== '' && contains(seoTitle, keyword), 'SEO 标题包含核心主题', '如果不影响自然表达，建议在 SEO 标题中明确出现核心主题。', 8);
        seoScore += addCheck(seoChecks, keyword !== '' && contains(description, keyword), '描述与核心主题一致', 'Meta 描述应准确概括正文，而不是加入正文没有的信息。', 8);
        seoScore += addCheck(seoChecks, keyword !== '' && contains(intro, keyword), '开头较早说明核心主题', '首段尽快回答“这是什么/能解决什么问题”，比单纯重复关键词更重要。', 8);
        seoScore += addCheck(seoChecks, contentLen >= 600, '正文信息量充足', '当前正文约 ' + contentLen + ' 字符。不要为凑长度扩写，优先补充真实参数、案例、流程和答案。', 8, contentLen >= 300);
        seoScore += addCheck(seoChecks, metrics.headings.length >= 2, 'H2/H3 层级清晰', '建议用小标题组织不同问题、参数、流程或场景。当前 H2/H3：' + metrics.headings.length + ' 个。', 8, metrics.headings.length === 1);
        seoScore += addCheck(seoChecks, metrics.internalLinks >= 1, '存在相关站内链接', '链接到相关产品、服务、案例或知识页，帮助搜索引擎理解站内主题关系。', 6);
        seoScore += addCheck(seoChecks, metrics.externalLinks >= 1, '存在外部证据/来源链接', '涉及法规、数据、标准或第三方事实时，建议链接到可信来源。', 6);
        seoScore += addCheck(seoChecks, metrics.images === 0 || metrics.imagesWithAlt === metrics.images, '图片 ALT 完整', metrics.images === 0 ? '当前正文没有图片。' : '当前 ' + metrics.imagesWithAlt + '/' + metrics.images + ' 张图片有 ALT。', 4, metrics.images > 0 && metrics.imagesWithAlt > 0);
        seoScore += addCheck(seoChecks, keyword !== '' && contains(slug.replace(/[-_]/g, ' '), keyword.replace(/[-_]/g, ' ')), 'URL 与主题一致', 'URL 应短、稳定、可读。中文站点可使用简洁拼音或英文语义词，不必为了得分强行修改已收录 URL。', 4, slug !== '');
        seoScore = Math.min(100, seoScore);

        var geoChecks = [];
        var geoScore = 0;
        var entity = text(meta[keys.entity]);
        var entityType = text(meta[keys.entityType]);
        var schema = text(meta[keys.schema]);
        var facts = lineCount(meta[keys.facts]);
        var faqs = faqCount(meta[keys.faq]);
        var sources = lineCount(meta[keys.sources]);
        var questionHeadings = metrics.headings.filter(function (h) { return /[？?]|什么|如何|怎么|为什么|多少|哪种|是否|区别|流程|价格|费用|条件|标准/.test(text(h.textContent)); }).length;
        var numeric = /\d/.test(plain);
        var entityMention = entity !== '' && contains(postTitle + ' ' + plain, entity);

        geoScore += addCheck(geoChecks, entity !== '', '核心实体明确', '明确企业、产品、服务、人物或地点实体，便于 AI 将页面内容归属到正确对象。', 12);
        geoScore += addCheck(geoChecks, entityMention, '实体在标题/正文中清晰出现', '核心实体应自然出现在关键位置，避免只藏在结构化数据里。', 8);
        geoScore += addCheck(geoChecks, entityType !== '' || schema !== '', '实体/Schema 类型明确', '明确 Organization、Product、Service、Article 等页面类型。', 8);
        geoScore += addCheck(geoChecks, facts >= 3, '可验证核心事实充足', '当前 ' + facts + ' 条。建议加入资质、规格、产能、时间、区域、价格范围、案例数据等可核验事实。', 12, facts > 0);
        geoScore += addCheck(geoChecks, faqs >= 3, '真实问题与明确答案充足', '当前 ' + faqs + ' 组 FAQ。优先写用户在购买、比较、决策时真正会问的问题。', 10, faqs > 0);
        geoScore += addCheck(geoChecks, sources >= 2, '来源/证据可追溯', '当前 ' + sources + ' 个来源。涉及标准、政策、认证、统计数据时应给出公开可验证来源。', 10, sources === 1);
        geoScore += addCheck(geoChecks, numeric, '正文包含具体参数或数据', 'AI 更容易引用具体、边界清楚、可核验的事实，而不是空泛宣传语。', 8);
        geoScore += addCheck(geoChecks, contentLen >= 600, '正文具备独立信息价值', '不要批量生成同质化文章；优先补充真实经验、案例、流程、参数和判断依据。', 8, contentLen >= 300);
        geoScore += addCheck(geoChecks, questionHeadings >= 1, '存在可直接回答的问题结构', '问题式小标题便于搜索与 AI 抽取答案，但不要为了形式把所有标题都写成问句。', 6);
        geoScore += addCheck(geoChecks, metrics.lists + metrics.tables >= 1, '信息采用列表/表格结构', '规格、步骤、对比、条件等信息适合结构化呈现，便于用户和机器理解。', 6);
        geoScore += addCheck(geoChecks, metrics.externalLinks >= 1 || sources >= 1, '页面具备证据链', '对关键事实提供来源或权威链接，增强可信度与可引用性。', 5);
        geoScore += addCheck(geoChecks, !!config.organizationReady, '全站企业实体资料已配置', '在插件 Schema 设置中完善企业名称、联系方式、Logo、统一社会信用代码、备案号和外部实体链接。', 7);
        geoScore = Math.min(100, geoScore);

        var chinaChecks = [
            {ok: text(meta[keys.index]) !== 'noindex', label: '页面允许索引', help: 'Noindex 页面不会进入常规搜索索引。'},
            {ok: /^https:/i.test(config.homeUrl || ''), label: '网站使用 HTTPS', help: 'HTTPS 是基础技术质量要求。'},
            {ok: !!config.sitemapEnabled, label: 'XML Sitemap 已启用', help: '便于百度、Bing 等发现站点重要 URL。'},
            {ok: seoTitle !== '' && description !== '', label: '标题与摘要完整', help: '对百度、360、搜狗、Bing 等传统搜索均属于基础页面信息。'},
            {ok: entity !== '' || !!config.organizationReady, label: '实体信息清晰', help: '同时服务传统搜索和 DeepSeek、豆包、Kimi、元宝、夸克、秘塔等 GEO 场景。'},
            {ok: facts >= 1 || numeric, label: '页面存在可核验事实', help: '比泛泛营销文案更有利于搜索理解和 AI 引用。'}
        ];

        var issues = seoChecks.concat(geoChecks).filter(function (item) { return !item.ok; });
        return {
            seoScore: seoScore,
            geoScore: geoScore,
            seoChecks: seoChecks,
            geoChecks: geoChecks,
            chinaChecks: chinaChecks,
            issues: issues,
            seoTitle: seoTitle,
            description: description,
            titleLength: titleLen,
            descriptionLength: descriptionLen,
            contentLength: contentLen
        };
    }

    function Score(props) {
        var score = props.score || 0;
        var tone = score >= 80 ? 'good' : (score >= 60 ? 'mid' : 'bad');
        return el('div', {className: 'aikey-live-score ' + tone},
            el('strong', null, String(score)),
            el('span', null, '/100'),
            el('small', null, props.label)
        );
    }

    function CheckList(props) {
        var items = props.items || [];
        return el('div', {className: 'aikey-live-checks'}, items.map(function (item, index) {
            var state = item.ok ? 'ok' : (item.partial ? 'partial' : 'fail');
            return el('div', {className: 'aikey-live-check ' + state, key: index},
                el('span', {className: 'aikey-live-check-icon', 'aria-hidden': true}, item.ok ? '✓' : (item.partial ? '!' : '×')),
                el('div', null,
                    el('strong', null, item.label),
                    item.help ? el('p', null, item.help) : null
                )
            );
        }));
    }

    function FieldHelp(props) {
        return el('div', {className: 'aikey-field-help'}, props.children);
    }


    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function listToLines(items) {
        return (items || []).map(function (item) { return text(item); }).filter(Boolean).join('\n');
    }

    function faqToLines(items) {
        return (items || []).map(function (row) {
            if (!row) { return ''; }
            var q = text(row.q); var a = text(row.a);
            return q && a ? q + ' || ' + a : '';
        }).filter(Boolean).join('\n');
    }

    function normalizeText(value) {
        return text(value).toLocaleLowerCase().replace(/[\s，。！？、：；,.!?;:'"“”‘’（）()【】\[\]<>《》_-]+/g, '');
    }

    function collectContentUnits() {
        if (!wp.data || !wp.data.select) { return []; }
        var store = wp.data.select('core/block-editor');
        if (!store || !store.getBlocks) { return []; }
        var units = [];
        (store.getBlocks() || []).forEach(function (block, index) {
            if (!block) { return; }
            var html = '';
            try {
                html = getBlockContent ? getBlockContent(block) : ((block.attributes && block.attributes.content) || '');
            } catch (e) {
                html = (block.attributes && block.attributes.content) || '';
            }
            var plain = stripHtml(html);
            if (!plain) { return; }
            units.push({
                id: 'u' + (units.length + 1),
                clientId: block.clientId,
                parentId: null,
                index: index,
                type: block.name || '',
                level: block.name === 'core/heading' && block.attributes ? (parseInt(block.attributes.level || 2, 10) || 2) : 0,
                text: plain.slice(0, 320)
            });
        });
        return units;
    }

    function publicUnits(units) {
        return (units || []).slice(0, 80).map(function (unit) {
            return {id: unit.id, type: unit.type, level: unit.level || 0, text: unit.text};
        });
    }

    function dedupeNumbers(values) {
        var seen = {};
        return (values || []).map(function (v) { return parseInt(v, 10); }).filter(function (v) {
            if (!v || seen[v]) { return false; }
            seen[v] = true; return true;
        });
    }

    function SidebarApp() {
        var state = useSelect(function (select) {
            var editor = select('core/editor');
            return {
                postTitle: editor.getEditedPostAttribute('title') || '',
                content: editor.getEditedPostContent() || '',
                excerpt: editor.getEditedPostAttribute('excerpt') || '',
                tags: config.tagsSupported ? (editor.getEditedPostAttribute(config.tagRestBase || 'tags') || []) : [],
                slug: editor.getEditedPostSlug ? (editor.getEditedPostSlug() || '') : '',
                meta: editor.getEditedPostAttribute('meta') || {},
                isSaving: editor.isSavingPost ? editor.isSavingPost() : false
            };
        }, []);
        var dispatch = useDispatch('core/editor');
        var blockDispatch = useDispatch('core/block-editor');
        var report = useMemo(function () { return analyze(state); }, [state.postTitle, state.content, state.excerpt, state.slug, state.meta]);
        var meta = state.meta || {};
        var aiPair = useState(null);
        var aiSuggestions = aiPair[0];
        var setAiSuggestions = aiPair[1];
        var aiLoadingPair = useState(false);
        var aiLoading = aiLoadingPair[0];
        var setAiLoading = aiLoadingPair[1];
        var aiErrorPair = useState('');
        var aiError = aiErrorPair[0];
        var setAiError = aiErrorPair[1];
        var linksPair = useState([]);
        var linkSuggestions = linksPair[0];
        var setLinkSuggestions = linksPair[1];
        var linksLoadingPair = useState(false);
        var linksLoading = linksLoadingPair[0];
        var setLinksLoading = linksLoadingPair[1];
        var linksErrorPair = useState('');
        var linksError = linksErrorPair[0];
        var setLinksError = linksErrorPair[1];
        var contentToolsPair = useState(null);
        var contentTools = contentToolsPair[0];
        var setContentTools = contentToolsPair[1];
        var contentToolsLoadingPair = useState(false);
        var contentToolsLoading = contentToolsLoadingPair[0];
        var setContentToolsLoading = contentToolsLoadingPair[1];
        var contentToolsErrorPair = useState('');
        var contentToolsError = contentToolsErrorPair[0];
        var setContentToolsError = contentToolsErrorPair[1];
        var contentToolsNoticePair = useState('');
        var contentToolsNotice = contentToolsNoticePair[0];
        var setContentToolsNotice = contentToolsNoticePair[1];

        function updateMeta(key, value) {
            if (!key) { return; }
            var next = Object.assign({}, meta);
            next[key] = value == null ? '' : String(value);
            dispatch.editPost({meta: next});
        }

        function updateManyMeta(values) {
            var next = Object.assign({}, meta, values || {});
            dispatch.editPost({meta: next});
        }

        function runAiOptimize() {
            if (!apiFetch || !config.aiConfigured || aiLoading) { return; }
            setAiLoading(true); setAiError('');
            apiFetch({
                path: '/aikey-seo-geo/v1/ai/optimize/' + config.postId,
                method: 'POST',
                data: {title: state.postTitle, content: state.content, meta: meta}
            }).then(function (response) {
                setAiSuggestions(response && response.suggestions ? response.suggestions : null);
            }).catch(function (error) {
                setAiError(error && error.message ? error.message : 'AI 优化请求失败。');
            }).finally(function () { setAiLoading(false); });
        }

        function applyAi(scope) {
            if (!aiSuggestions) { return; }
            var values = {};
            if (scope === 'seo' || scope === 'all') {
                if (aiSuggestions.suggested_title) { values[keys.title] = aiSuggestions.suggested_title; }
                if (aiSuggestions.suggested_description) { values[keys.description] = aiSuggestions.suggested_description; }
                if (aiSuggestions.focus_keyword) { values[keys.keyword] = aiSuggestions.focus_keyword; values[keys.coreKeywords] = aiSuggestions.focus_keyword; }
            }
            if (scope === 'geo' || scope === 'all') {
                if (aiSuggestions.schema_type) { values[keys.schema] = aiSuggestions.schema_type; }
                if (aiSuggestions.entity) { values[keys.entity] = aiSuggestions.entity; }
                if (aiSuggestions.entity_type) { values[keys.entityType] = aiSuggestions.entity_type; }
                if ((aiSuggestions.facts || []).length) { values[keys.facts] = listToLines(aiSuggestions.facts); }
            }
            if (scope === 'faq' || scope === 'all') {
                if ((aiSuggestions.faq || []).length) { values[keys.faq] = faqToLines(aiSuggestions.faq); }
            }
            updateManyMeta(values);
        }

        function findOrCreateTag(name) {
            name = text(name);
            if (!apiFetch || !name || !config.tagsSupported) { return Promise.resolve(0); }
            var base = String(config.tagRestBase || 'tags').replace(/^\/+|\/+$/g, '');
            var searchPath = '/wp/v2/' + base + '?search=' + encodeURIComponent(name) + '&per_page=100&context=edit';
            function findExact(rows) {
                var target = normalizeText(name);
                var match = (rows || []).find(function (row) { return normalizeText(row && row.name) === target; });
                return match && match.id ? parseInt(match.id, 10) : 0;
            }
            return apiFetch({path: searchPath}).then(function (rows) {
                var id = findExact(rows);
                if (id) { return id; }
                return apiFetch({path: '/wp/v2/' + base, method: 'POST', data: {name: name}}).then(function (row) {
                    return row && row.id ? parseInt(row.id, 10) : 0;
                }).catch(function () {
                    return apiFetch({path: searchPath}).then(findExact);
                });
            });
        }

        function applyTags(tags) {
            if (!config.tagsSupported || !(tags || []).length) { return Promise.resolve(0); }
            return Promise.all((tags || []).slice(0, 8).map(findOrCreateTag)).then(function (ids) {
                ids = ids.filter(Boolean);
                var merged = dedupeNumbers((state.tags || []).concat(ids)).slice(0, 12);
                var patch = {};
                patch[config.tagRestBase || 'tags'] = merged;
                dispatch.editPost(patch);
                return ids.length;
            });
        }

        function applyHeadingLevels(suggestions, requestedUnits) {
            if (!createBlock || !blockDispatch || !suggestions || !wp.data || !wp.data.select) { return 0; }
            var plan = suggestions.heading_levels || [];
            if (!plan.length) { return 0; }
            var store = wp.data.select('core/block-editor');
            if (!store || !store.getBlock) { return 0; }
            var originalById = {};
            (requestedUnits || []).forEach(function (unit) { originalById[unit.id] = unit; });
            var changed = 0;
            var usedClientIds = {};

            plan.forEach(function (row) {
                if (!row || !row.unit_id || !row.original_text) { return; }
                var source = originalById[row.unit_id];
                if (!source || !source.clientId || usedClientIds[source.clientId]) { return; }
                var block = store.getBlock(source.clientId);
                if (!block || (block.name !== 'core/paragraph' && block.name !== 'core/heading')) { return; }

                var html = '';
                try { html = getBlockContent ? getBlockContent(block) : ((block.attributes && block.attributes.content) || ''); }
                catch (e) { html = (block.attributes && block.attributes.content) || ''; }
                var currentText = stripHtml(html);
                if (normalizeText(currentText) !== normalizeText(row.original_text) || normalizeText(currentText) !== normalizeText(source.text)) { return; }

                var level = parseInt(row.level, 10) === 3 ? 3 : 2;
                try {
                    if (block.name === 'core/heading') {
                        var currentLevel = parseInt((block.attributes && block.attributes.level) || 2, 10) || 2;
                        if (currentLevel !== level && blockDispatch.updateBlockAttributes) {
                            blockDispatch.updateBlockAttributes(block.clientId, {level: level});
                            changed += 1;
                        }
                    } else if (blockDispatch.replaceBlock) {
                        var attrs = {level: level, content: (block.attributes && block.attributes.content) || currentText};
                        if (block.attributes && block.attributes.anchor) { attrs.anchor = block.attributes.anchor; }
                        blockDispatch.replaceBlock(block.clientId, createBlock('core/heading', attrs));
                        changed += 1;
                    }
                    usedClientIds[source.clientId] = true;
                } catch (e) {}
            });
            return changed;
        }

        function applyContentTools(scope, suggestions, requestUnits) {
            suggestions = suggestions || contentTools;
            if (!suggestions) { return Promise.resolve(); }
            var messages = [];
            if ((scope === 'excerpt' || scope === 'all') && config.excerptSupported && suggestions.excerpt) {
                dispatch.editPost({excerpt: suggestions.excerpt});
                messages.push('摘要已设置');
            }
            if (scope === 'headings' || scope === 'all') {
                var headingCount = applyHeadingLevels(suggestions, requestUnits || []);
                if (headingCount) { messages.push('已将 ' + headingCount + ' 个原有段落/标题设置为 H2/H3'); }
                else { messages.push('没有发现需要调整的原有标题层级'); }
            }
            var tagsPromise = Promise.resolve(0);
            if (scope === 'tags' || scope === 'all') {
                tagsPromise = applyTags(suggestions.tags || []);
            }
            return tagsPromise.then(function (tagCount) {
                if (tagCount) { messages.push('已补充 ' + tagCount + ' 个标签'); }
                setContentToolsNotice(messages.length ? messages.join('；') + '。请检查后保存/更新文章。' : '没有需要应用的新内容。');
            });
        }

        function runContentTools(scope) {
            if (!apiFetch || !config.aiConfigured || contentToolsLoading) { return; }
            var units = collectContentUnits();
            setContentToolsLoading(true); setContentToolsError(''); setContentToolsNotice('');
            apiFetch({
                path: '/aikey-seo-geo/v1/ai/content-tools/' + config.postId,
                method: 'POST',
                data: {title: state.postTitle, content: state.content, excerpt: state.excerpt, units: publicUnits(units), scope: scope}
            }).then(function (response) {
                var suggestions = response && response.suggestions ? response.suggestions : null;
                setContentTools(suggestions);
                if (!suggestions) { throw new Error('AI 没有返回可用建议。'); }
                return applyContentTools(scope, suggestions, units);
            }).catch(function (error) {
                setContentToolsError(error && error.message ? error.message : '文章一键优化请求失败。');
            }).finally(function () { setContentToolsLoading(false); });
        }

        function loadInternalLinks() {
            if (!apiFetch || linksLoading) { return; }
            setLinksLoading(true); setLinksError('');
            apiFetch({
                path: '/aikey-seo-geo/v1/links/suggest/' + config.postId,
                method: 'POST',
                data: {title: state.postTitle, content: state.content, keyword: meta[keys.keyword] || ''}
            }).then(function (response) {
                setLinkSuggestions(response && response.suggestions ? response.suggestions : []);
            }).catch(function (error) {
                setLinksError(error && error.message ? error.message : '内链建议读取失败。');
            }).finally(function () { setLinksLoading(false); });
        }

        function insertInternalLink(item) {
            if (!item || !createBlock || !blockDispatch || !blockDispatch.insertBlocks) { return; }
            var html = '推荐阅读：<a href="' + escapeHtml(item.url) + '">' + escapeHtml(item.title) + '</a>';
            blockDispatch.insertBlocks(createBlock('core/paragraph', {content: html}));
        }

        var topIssues = report.issues.slice(0, 6);

        return el(Fragment, null,
            el(PluginSidebarMoreMenuItem, {target: 'aikey-seo-geo-sidebar', icon: 'chart-line'}, 'Aikeyboost SEO&GEO'),
            el(PluginSidebar, {name: 'aikey-seo-geo-sidebar', title: 'Aikeyboost SEO&GEO', icon: 'chart-line', isPinnable: true},
                el('div', {className: 'aikey-sidebar-wrap'},
                    config.conflict ? el(Notice, {status: 'warning', isDismissible: false}, '兼容保护：检测到 ' + (config.conflictSummary || '其他 SEO 插件') + '。实时分析可正常使用，但 Aikeyboost暂不重复输出前台 SEO 标签。') : null,
                    el('div', {className: 'aikey-live-score-grid'},
                        el(Score, {score: report.seoScore, label: 'SEO'}),
                        el(Score, {score: report.geoScore, label: 'GEO'})
                    ),
                    el('p', {className: 'aikey-live-caption'}, '实时分析当前未保存内容；正文、标题和设置变化后会立即重新计算。'),

                    el(PanelBody, {title: '优先优化建议', initialOpen: true},
                        topIssues.length ? el(CheckList, {items: topIssues}) : el('div', {className: 'aikey-live-allgood'}, '当前核心检查项表现良好，可继续补充真实数据与证据。')
                    ),

                    el(PanelBody, {title: 'SEO 设置与实时检查', initialOpen: true},
                        el(TextControl, {
                            label: '核心关键词（1–3个）',
                            value: meta[keys.coreKeywords] || meta[keys.keyword] || '',
                            onChange: function (v) {
                                var patch = {};
                                patch[keys.coreKeywords] = v;
                                patch[keys.keyword] = firstKeyword(v);
                                updateManyMeta(patch);
                            },
                            help: '首个关键词作为主核心关键词参与诊断；仅用于内容规划，不输出 meta keywords。'
                        }),
                        el(TextControl, {
                            label: '相关关键词（3–10个）',
                            value: meta[keys.relatedKeywords] || '',
                            onChange: function (v) { updateMeta(keys.relatedKeywords, v); },
                            help: '覆盖同一主题下的相关表达，不自动堆入正文。'
                        }),
                        el(TextareaControl, {
                            label: '用户搜索问题',
                            value: meta[keys.searchQuestions] || '',
                            onChange: function (v) { updateMeta(keys.searchQuestions, v); },
                            help: '每行一个真实问题；仅用于文章规划，不会在没有答案时生成 FAQ Schema。',
                            rows: 4
                        }),
                        el(TextControl, {
                            label: 'SEO 标题',
                            value: meta[keys.title] || '',
                            onChange: function (v) { updateMeta(keys.title, v); },
                            help: '留空按全站模板生成。当前预览 ' + report.titleLength + ' 字符。'
                        }),
                        el(TextareaControl, {
                            label: 'Meta 描述',
                            value: meta[keys.description] || '',
                            onChange: function (v) { updateMeta(keys.description, v); },
                            help: '当前预览 ' + report.descriptionLength + ' 字符。留空时插件可从摘要/正文生成。',
                            rows: 4
                        }),
                        el('div', {className: 'aikey-mini-serp'},
                            el('span', null, '搜索结果预览'),
                            el('strong', null, report.seoTitle || state.postTitle || config.siteName),
                            el('p', null, report.description || '填写描述或正文后，这里会实时预览。')
                        ),
                        el(CheckList, {items: report.seoChecks})
                    ),

                    el(PanelBody, {title: 'GEO 实时检查', initialOpen: false},
                        el(TextControl, {
                            label: '核心实体名称',
                            value: meta[keys.entity] || '',
                            onChange: function (v) { updateMeta(keys.entity, v); },
                            help: '例如公司名、产品名、服务名。'
                        }),
                        el(SelectControl, {
                            label: '实体类型',
                            value: meta[keys.entityType] || '',
                            options: [
                                {label: '自动', value: ''}, {label: '企业 Organization', value: 'Organization'},
                                {label: '产品 Product', value: 'Product'}, {label: '服务 Service', value: 'Service'},
                                {label: '人物 Person', value: 'Person'}, {label: '地点 Place', value: 'Place'},
                                {label: '文章 Article', value: 'Article'}
                            ],
                            onChange: function (v) { updateMeta(keys.entityType, v); }
                        }),
                        el(TextareaControl, {
                            label: '可验证核心事实',
                            value: meta[keys.facts] || '',
                            onChange: function (v) { updateMeta(keys.facts, v); },
                            help: '每行一条真实事实。公开显示：在正文插入 [aikey_geo_facts]；已写在正文里的事实也可作为Schema依据。',
                            rows: 5
                        }),
                        el(TextareaControl, {
                            label: 'FAQ',
                            value: meta[keys.faq] || '',
                            onChange: function (v) { updateMeta(keys.faq, v); },
                            help: '每行：问题 || 答案。仅公开可见问答生成Schema；可在正文插入 [aikey_geo_faq]。',
                            rows: 5
                        }),
                        el(TextareaControl, {
                            label: '来源 / 证据 URL',
                            value: meta[keys.sources] || '',
                            onChange: function (v) { updateMeta(keys.sources, v); },
                            help: '每行一个公开可验证URL。如需页面展示来源链接，插入 [aikey_geo_sources]。',
                            rows: 4
                        }),
                        el(CheckList, {items: report.geoChecks})
                    ),

                    config.aiEnabled ? el(PanelBody, {title: '文章一键优化', initialOpen: true},
                        el(FieldHelp, null, '直接使用 WordPress 原生标签与摘要。H2/H3 会分析原文结构，只把原文中已经承担小标题作用的段落设置为 H2 或 H3；不新增标题文字、不改写正文。'),
                        !config.aiConfigured ? el(Notice, {status: 'info', isDismissible: false}, '需要先在 SEO&GEO → 设置 中配置 DeepSeek API Key。') : null,
                        config.aiConfigured ? el('div', {className: 'aikey-content-tool-actions'},
                            config.tagsSupported ? el(Button, {variant: 'secondary', onClick: function () { runContentTools('tags'); }, disabled: contentToolsLoading}, '一键设置标签') : null,
                            config.excerptSupported ? el(Button, {variant: 'secondary', onClick: function () { runContentTools('excerpt'); }, disabled: contentToolsLoading}, '一键设置摘要') : null,
                            el(Button, {variant: 'secondary', onClick: function () { runContentTools('headings'); }, disabled: contentToolsLoading}, '一键设置 H2/H3'),
                            el(Button, {variant: 'primary', onClick: function () { runContentTools('all'); }, disabled: contentToolsLoading}, '一键全部设置')
                        ) : null,
                        !config.tagsSupported ? el('p', {className: 'description'}, '当前内容类型未启用 WordPress 标签 taxonomy，因此不显示标签写入功能。') : null,
                        !config.excerptSupported ? el('p', {className: 'description'}, '当前内容类型未启用 WordPress 摘要支持，因此不写入原生摘要。') : null,
                        contentToolsLoading ? el('div', {className: 'aikey-ai-loading'}, el(Spinner, null), ' 正在分析当前未保存正文…') : null,
                        contentToolsError ? el(Notice, {status: 'error', isDismissible: false}, contentToolsError) : null,
                        contentToolsNotice ? el(Notice, {status: 'success', isDismissible: true, onRemove: function () { setContentToolsNotice(''); }}, contentToolsNotice) : null,
                        contentTools ? el('div', {className: 'aikey-content-tool-preview'},
                            (contentTools.tags || []).length ? el('div', {className: 'aikey-ai-item'}, el('strong', null, '建议标签'), el('p', null, contentTools.tags.join('、'))) : null,
                            contentTools.excerpt ? el('div', {className: 'aikey-ai-item'}, el('strong', null, '建议摘要'), el('p', null, contentTools.excerpt)) : null,
                            (contentTools.heading_levels || []).length ? el('div', {className: 'aikey-ai-item'}, el('strong', null, '将原文设置为 H2/H3'), el('ul', null, contentTools.heading_levels.map(function (row, i) { return el('li', {key: i}, 'H' + row.level + '：' + row.original_text); }))) : null
                        ) : null
                    ) : null,

                    config.aiEnabled ? el(PanelBody, {title: 'AI 一键优化', initialOpen: false},
                        el(FieldHelp, null, '基于当前未保存正文生成可选择应用的 SEO/GEO 建议。AI 不会自动重写正文；只有点击应用后才写入字段。'),
                        !config.aiConfigured ? el(Notice, {status: 'info', isDismissible: false}, '尚未配置 DeepSeek API Key，请先到 SEO&GEO → 设置 中配置。') : null,
                        config.aiConfigured ? el(Button, {variant: 'primary', onClick: runAiOptimize, disabled: aiLoading}, aiLoading ? '正在分析…' : '生成 AI 优化建议') : null,
                        aiLoading ? el('div', {className: 'aikey-ai-loading'}, el(Spinner, null), ' 正在读取当前正文并生成建议…') : null,
                        aiError ? el(Notice, {status: 'error', isDismissible: false}, aiError) : null,
                        aiSuggestions ? el('div', {className: 'aikey-ai-suggestions'},
                            aiSuggestions.suggested_title ? el('div', {className: 'aikey-ai-item'}, el('strong', null, '建议标题'), el('p', null, aiSuggestions.suggested_title)) : null,
                            aiSuggestions.suggested_description ? el('div', {className: 'aikey-ai-item'}, el('strong', null, '建议描述'), el('p', null, aiSuggestions.suggested_description)) : null,
                            (aiSuggestions.optimization_notes || []).length ? el('div', {className: 'aikey-ai-item'}, el('strong', null, '优化说明'), el('ul', null, aiSuggestions.optimization_notes.map(function (v, i) { return el('li', {key: i}, v); }))) : null,
                            (aiSuggestions.missing_questions || []).length ? el('div', {className: 'aikey-ai-item'}, el('strong', null, '建议补充的问题'), el('ul', null, aiSuggestions.missing_questions.map(function (v, i) { return el('li', {key: i}, v); }))) : null,
                            el('div', {className: 'aikey-ai-actions'},
                                el(Button, {variant: 'secondary', onClick: function () { applyAi('seo'); }}, '应用 SEO'),
                                el(Button, {variant: 'secondary', onClick: function () { applyAi('geo'); }}, '应用实体/事实'),
                                el(Button, {variant: 'secondary', onClick: function () { applyAi('faq'); }, disabled: !(aiSuggestions.faq || []).length}, '应用 FAQ'),
                                el(Button, {variant: 'primary', onClick: function () { applyAi('all'); }}, '全部应用')
                            )
                        ) : null
                    ) : null,

                    config.internalLinksEnabled ? el(PanelBody, {title: '智能内链建议', initialOpen: false},
                        el(FieldHelp, null, '根据当前页面主题、核心关键词与站内已有内容计算相关页面，不会自动批量改正文。'),
                        el(Button, {variant: 'secondary', onClick: loadInternalLinks, disabled: linksLoading}, linksLoading ? '正在计算…' : '查找相关内链'),
                        linksLoading ? el(Spinner, null) : null,
                        linksError ? el(Notice, {status: 'error', isDismissible: false}, linksError) : null,
                        linkSuggestions.length ? el('div', {className: 'aikey-link-suggestions'}, linkSuggestions.map(function (item) {
                            return el('div', {className: 'aikey-link-item', key: item.id},
                                el('div', null, el('strong', null, item.title), el('code', null, item.url)),
                                el(Button, {variant: 'secondary', onClick: function () { insertInternalLink(item); }}, '插入链接段落')
                            );
                        })) : (!linksLoading ? el('p', {className: 'description'}, '点击上方按钮后显示建议；如果没有足够相关页面，不会强行推荐。') : null)
                    ) : null,

                    el(PanelBody, {title: '中国搜索 / AI 引擎准备度', initialOpen: false},
                        el(FieldHelp, null, '这些检查面向百度、搜狗、360、神马、Bing，以及 DeepSeek、豆包、Kimi、腾讯元宝、夸克、秘塔等中文 AI 搜索/回答场景。它们不是对各平台未公开算法的猜测，而是检查共同需要的可访问、可理解、可验证信息。'),
                        el(CheckList, {items: report.chinaChecks})
                    ),

                    el(PanelBody, {title: '索引与 Schema', initialOpen: false},
                        el(SelectControl, {
                            label: '索引',
                            value: meta[keys.index] || 'default',
                            options: [{label: '默认', value: 'default'}, {label: 'Index', value: 'index'}, {label: 'Noindex', value: 'noindex'}],
                            onChange: function (v) { updateMeta(keys.index, v); }
                        }),
                        el(SelectControl, {
                            label: '跟踪链接',
                            value: meta[keys.follow] || 'default',
                            options: [{label: '默认', value: 'default'}, {label: 'Follow', value: 'follow'}, {label: 'Nofollow', value: 'nofollow'}],
                            onChange: function (v) { updateMeta(keys.follow, v); }
                        }),
                        el(TextControl, {
                            label: 'Canonical URL',
                            value: meta[keys.canonical] || '',
                            type: 'url',
                            onChange: function (v) { updateMeta(keys.canonical, v); },
                            help: '通常留空使用当前规范 URL。'
                        }),
                        el(SelectControl, {
                            label: 'Schema 类型',
                            value: meta[keys.schema] || '',
                            options: [
                                {label: '自动判断', value: ''}, {label: 'WebPage', value: 'WebPage'},
                                {label: 'Article', value: 'Article'}, {label: 'Product', value: 'Product'},
                                {label: 'Service', value: 'Service'}, {label: 'AboutPage', value: 'AboutPage'},
                                {label: 'ContactPage', value: 'ContactPage'}
                            ],
                            onChange: function (v) { updateMeta(keys.schema, v); }
                        })
                    ),

                    state.isSaving ? el('div', {className: 'aikey-saving'}, el(Spinner, null), ' 正在保存 SEO/GEO 设置…') : null
                )
            )
        );
    }

    registerPlugin('aikey-seo-geo-editor', {render: SidebarApp, icon: 'chart-line'});
}(window.wp, window.AIKEY_EDITOR));
