pimcore.registerNS("pimcore.plugin.ResourceSpacePullBundle");

pimcore.plugin.ResourceSpacePullBundle = Class.create(pimcore.plugin.admin, {
    getClassName: function () {
        return "pimcore.plugin.ResourceSpacePullBundle";
    },

    initialize: function () {
        pimcore.plugin.broker.registerPlugin(this);
    },

    pimcoreReady: function (params, broker) {
        // alert("ResourceSpacePullBundle ready!");
    }
});

var ResourceSpacePullBundlePlugin = new pimcore.plugin.ResourceSpacePullBundle();
