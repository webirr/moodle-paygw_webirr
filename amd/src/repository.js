// amd/src/repository.js
/**
 * WeBirr repository module to handle AJAX calls.
 *
 */
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    /**
     * Get a payment code from WeBirr.
     *
     * @param {string} component
     * @param {string} paymentArea
     * @param {number} itemId
     * @param {string} description
     * @return {Promise}
     */
    var getPaymentCode = function(component, paymentArea, itemId, description) {
        var request = {
            methodname: 'paygw_webirr_get_payment_code',
            args: {
                component: component,
                paymentarea: paymentArea,
                itemid: itemId,
                description: description
            }
        };

        return Ajax.call([request])[0];
    };

    /**
     * Check the status of a payment.
     *
     * @param {number} paymentId
     * @return {Promise}
     */
    var getPaymentStatus = function(paymentId) {
        var request = {
            methodname: 'paygw_webirr_get_payment_status',
            args: {
                paymentid: paymentId
            }
        };

        return Ajax.call([request])[0];
    };

    return {
        getPaymentCode: getPaymentCode,
        getPaymentStatus: getPaymentStatus
    };
});