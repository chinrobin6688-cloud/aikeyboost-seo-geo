(function (blocks, element, components, blockEditor) {
    'use strict';
    var el = element.createElement;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var RangeControl = components.RangeControl;
    var ToggleControl = components.ToggleControl;
    var SelectControl = components.SelectControl;
    var Button = components.Button;
    var cfg = window.AIKEYEnterpriseMapBlock || {};

    blocks.registerBlockType('aikey-seo-geo/enterprise-map', {
        apiVersion: 2,
        title: 'Aikeyboost企业地图',
        icon: 'location-alt',
        category: 'widgets',
        description: '左侧轻量企业地图，右侧联系表单；无需地图 API。',
        attributes: {
            formId: { type: 'string' },
            height: { type: 'number' },
            showAddress: { type: 'boolean' },
            showNavigation: { type: 'boolean' }
        },
        edit: function (props) {
            var a = props.attributes;
            var height = Number(a.height || cfg.defaultHeight || 340);
            var profiles = cfg.profiles || [];
            var current = profiles.filter(function (f) { return f.value === (a.formId || ''); })[0] || profiles[0] || {};
            var options = profiles.map(function (f) { return { label: f.label, value: f.value }; });
            var showAddress = typeof a.showAddress === 'boolean' ? a.showAddress : cfg.defaultShowAddress !== false;
            var showNavigation = typeof a.showNavigation === 'boolean' ? a.showNavigation : cfg.defaultShowNavigation !== false;
            return el('div', { className: 'aikey-enterprise-map-editor' },
                el(InspectorControls, {},
                    el(PanelBody, { title: '企业地图设置', initialOpen: true },
                        el(SelectControl, { label: '语言 / 表单', value: a.formId || '', options: options, onChange: function(v){ props.setAttributes({formId:v}); } }),
                        el(Button, { variant: 'secondary', href: cfg.formsUrl || '#', target: '_blank' }, '管理多语言表单'),
                        el(RangeControl, { label: '地图卡片高度', min: 220, max: 720, step: 10, value: height, onChange: function(v){ props.setAttributes({height:v}); } }),
                        el(ToggleControl, { label: '显示企业地址', checked: showAddress, onChange: function(v){ props.setAttributes({showAddress:v}); } }),
                        el(ToggleControl, { label: '显示地图按钮', checked: showNavigation, onChange: function(v){ props.setAttributes({showNavigation:v}); } }),
                        el(Button, { variant: 'secondary', href: cfg.settingsUrl || '#', target: '_blank' }, '打开 Aikeyboost地图设置')
                    )
                ),
                el('div', { className: 'aikey-enterprise-map-editor__preview' },
                    el('div', { className: 'aikey-enterprise-map-editor__preview-map', style: { minHeight: height + 'px' } },
                        el('span', { className: 'dashicons dashicons-location-alt' }),
                        el('small', {}, current.mapLocation || '企业位置'),
                        el('strong', {}, current.company || cfg.company || '企业地图'),
                        (current.address || cfg.address) ? el('small', {}, current.address || cfg.address) : el('small', {}, current.mapMissing || '请先填写企业地址'),
                        el('em', {}, current.mapButton || '打开高德地图')
                    ),
                    el('div', { className: 'aikey-enterprise-map-editor__preview-form' },
                        el('strong', {}, current.heading || '留下您的联系方式'),
                        el('div', { className: 'aikey-enterprise-map-editor__preview-fields' },
                            el('div', { className: 'aikey-enterprise-map-editor__preview-field' }),
                            el('div', { className: 'aikey-enterprise-map-editor__preview-field' }),
                            el('div', { className: 'aikey-enterprise-map-editor__preview-field' })
                        ),
                        el('div', { className: 'aikey-enterprise-map-editor__preview-message' }),
                        el('div', { className: 'aikey-enterprise-map-editor__preview-button' })
                    )
                )
            );
        },
        save: function () { return null; }
    });
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor);
