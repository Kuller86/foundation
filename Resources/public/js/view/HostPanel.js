/**
 * @author Sergei Lissovski <sergei.lissovski@modera.org>
 */
Ext.define('Modera.backend.tools.settings.view.HostPanel', {
    extend: 'Ext.panel.Panel',

    // l10n
    titleText: 'Settings',

    /**
     * @param {Object} config
     */
    constructor: function(config) {
        var defaults = {
            basePanel: true,
            padding: 10,
            layout: 'fit',
            cls: 'modera-backend-tools-settings',
            tbar: {
                xtype: 'mfc-header',
                title: this.titleText,
                margin: '0 0 9 0',
                cls: 'modera-backend-tools-settings-toolbar',
                iconCls: 'modera-backend-tools-settings-icon',
                closeBtn: true,
                items: [
                    {
                        xtype: 'image',
                        glyph: FontAwesome.resolve('chevron-right'),
                        margin: '0 13 0 13',
                        bodyPadding: '2 0',
                        cls: 'section-separator'
                    },
                    {
                        itemId: 'sectionNameBox',
                        xtype: 'box',
                        cls: 'text'
                    },
                    '->'
                ]
            },
            items: {
                xtype: 'container',
                layout: {
                    type: 'hbox',
                    align: 'stretch'
                },
                split: true,
                items: [
                    {
                        itemId: 'sectionsGrid',
                        width: 200,
                        xtype: 'grid',
                        hideHeaders: true,
                        border: false,
                        cls: 'sections-grid',
                        margin: '0 11 0 0',
                        columns: [
                            {
                                dataIndex: 'glyph',
                                width: 30,
                                renderer: function(v, md) {
                                    if (!v) {
                                        return '';
                                    }

                                    md.tdCls = 'left';

                                    var glyph = FontAwesome.resolve(v),
                                        glyphParts = glyph.split('@');

                                    return Ext.String.format(
                                        '<span style="font-family: FontAwesome;">{0}</span>', '&#' + glyphParts[0] + ';'
                                    )
                                }
                            },
                            {
                                dataIndex: 'name',
                                flex: 1,
                                renderer: function(v, md) {
                                    md.tdCls = 'right';

                                    return v;
                                }
                            }
                        ],
                        store: Ext.create('Ext.data.Store', {
                            fields: ['id', 'name', 'glyph'],
                            data: [
                                { id: 'dummy1', name: 'Dummy 1', glyph: 'folder-open' },
                                { id: 'dummy2', name: 'Dummy 2', glyph: 'bolt' }
                            ],
                            proxy: {
                                type: 'memory'
                            }
                        })
                    },
                    {
                        itemId: 'hostPanel',
                        layout: 'card',
                        flex: 1,
                        items: [
                            {

                            },
                            {
                                activity: 'dummy1',
                                layout: 'fit'
                            },
                            {
                                activity: 'dummy2',
                                layout: 'fit'
                            }
                        ]
                    }
                ]
            }
        };

        this.config = Ext.apply(defaults, config || {});
        this.callParent([this.config]);

        this.addEvents(
            /**
             * @event showsection
             */
            'showsection'
        );

        this.assignListeners();
    },

    showSection: function(id) { // #B4D6E6
        var sectionsGrid = this.down('#sectionsGrid'),
            record = sectionsGrid.getStore().findRecord('id', id);

        if (record) {
            var hostPanel = this.down('#hostPanel'),
                activityContainer = hostPanel.down(Ext.String.format('component[activity={0}]', id));

            if (activityContainer) {
                var oldContainer = hostPanel.getLayout().getActiveItem(),
                    newContainer = hostPanel.getLayout().setActiveItem(activityContainer);

                if (this.rendered) {
                    sectionsGrid.getSelectionModel().select(record);
                } else {
                    this.on('render', function() {
                        sectionsGrid.getSelectionModel().select(record);
                    });
                }

                this.down('#sectionNameBox').update(record.get('name'));

                hostPanel.fireEvent('activitychange', hostPanel, newContainer, oldContainer);

                return newContainer;
            }
        }

        return false;
    },

    // private
    assignListeners: function() {
        var me = this;

        this.down('#sectionsGrid').on('itemclick', function(grid, record) {
            me.fireEvent('showsection', grid, record.data);
        });
    }
});