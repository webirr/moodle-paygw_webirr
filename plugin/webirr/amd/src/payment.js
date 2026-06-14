// amd/src/payment.js
/**
 * WeBirr payment handling module.
 *
 * @module     paygw_webirr/payment
 * @copyright  2026 WeBirr
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/notification', 'paygw_webirr/repository'],
function($, Notification, Repository) {
    var POLL_DELAY_MS = 5000;
    var paymentState = {};
    var strings = {};

    /**
     * Initialize the payment process.
     *
     * @param {string} component
     * @param {string} paymentArea
     * @param {number} itemId
     * @param {string} description
     * @param {string} sesskey
     * @param {Object} localizedStrings Localized UI strings from Moodle
     */
    var init = function(component, paymentArea, itemId, description, sesskey, localizedStrings) {
        paymentState = {
            component: component,
            paymentArea: paymentArea,
            itemId: itemId,
            sesskey: sesskey,
            paymentId: null,
            pollTimer: null
        };
        strings = localizedStrings || {};

        $('#payment-refresh-button').off('click').on('click', function() {
            checkPaymentStatus(false);
        });

        showActions(false);
        setStatus('info', getString('creatingpaymentcode'), true);

        // Get the payment code.
        Repository.getPaymentCode(component, paymentArea, itemId, description)
            .then(function(response) {
                if (response.success) {
                    paymentState.paymentId = response.paymentid;
                    $('#payment-loading').hide();
                    $('#payment-code-display')
                        .empty()
                        .append($('<div>').addClass('payment-code-title').text(getString('webirrpaymentcode')))
                        .append($('<div>').addClass('payment-code-large').text(response.paymentcode));
                    $('#merchant-reference').text(response.billreference || '');
                    $('#local-payment-status').text('pending');
                    $('#payment-record').show();

                    waitAndCheckPaymentStatus();
                } else {
                    // Display error message.
                    setStatus('danger', response.error, false);
                }
            })
            .catch(Notification.exception);
    };

    /**
     * Show or hide manual payment status actions.
     *
     * @param {boolean} visible Whether actions should be visible
     */
    var showActions = function(visible) {
        $('#payment-actions').css('display', visible ? 'flex' : 'none');
    };

    /**
     * Enable or disable manual action buttons.
     *
     * @param {boolean} disabled Whether buttons should be disabled
     */
    var setActionsDisabled = function(disabled) {
        $('#payment-refresh-button').prop('disabled', disabled);
    };

    /**
     * Display the current payment status.
     *
     * @param {string} type Bootstrap alert type
     * @param {string} message Message to display
     * @param {boolean} spinning Whether to show the spinner
     */
    var setStatus = function(type, message, spinning) {
        var status = $('#payment-status');
        var statusText = $('#payment-status-text');

        status
            .removeClass()
            .addClass('alert alert-' + type);

        if (statusText.length) {
            statusText.text(message);
            $('#payment-spinner').toggle(!!spinning);
        } else {
            status.text(message);
        }
    };

    /**
     * Display secondary payment status details.
     *
     * @param {string} message Message to display
     */
    var setDetail = function(message) {
        $('#payment-detail').text(message);
    };

    /**
     * Wait briefly before checking payment status once.
     */
    var waitAndCheckPaymentStatus = function() {
        clearTimeout(paymentState.pollTimer);
        showActions(false);
        setActionsDisabled(true);
        setStatus('info', getString('waitingpaymentconfirmation'), true);
        setDetail(getString('checkingpaymentstatusdelay'));

        paymentState.pollTimer = setTimeout(function() {
            checkPaymentStatus(true);
        }, POLL_DELAY_MS);
    };

    /**
     * Check payment status once.
     *
     * @param {boolean} automatic Whether this check was started by the wait timer
     */
    var checkPaymentStatus = function(automatic) {
        if (!paymentState.paymentId) {
            return;
        }

        clearTimeout(paymentState.pollTimer);
        setActionsDisabled(true);
        setStatus('info', automatic ? getString('checkpaymentstatus') : getString('refreshingpaymentstatus'), true);
        setDetail('');

        // Check the payment status.
        Repository.getPaymentStatus(paymentState.paymentId)
            .then(function(response) {
                if (response.success) {
                    if (response.complete) {
                        // Payment is complete.
                        setStatus('success', getString('paymentsuccessful'), false);
                        $('#local-payment-status').text('paid');

                        // Redirect to success page.
                        setTimeout(function() {
                            window.location.href = M.cfg.wwwroot + '/payment/gateway/webirr/success.php'
                                + '?component=' + encodeURIComponent(paymentState.component)
                                + '&paymentarea=' + encodeURIComponent(paymentState.paymentArea)
                                + '&itemid=' + encodeURIComponent(paymentState.itemId)
                                + '&sesskey=' + encodeURIComponent(paymentState.sesskey);
                        }, 2000);
                    } else {
                        setStatus('warning', getString('paymentnotreceived'), true);
                        $('#local-payment-status').text('pending');
                        setDetail('');
                        showActions(true);
                        setActionsDisabled(true);
                        paymentState.pollTimer = setTimeout(function() {
                            checkPaymentStatus(true);
                        }, POLL_DELAY_MS);
                    }
                } else {
                    // Error checking payment status.
                    setStatus('danger', response.error, false);
                    showActions(true);
                    setActionsDisabled(false);
                }
            })
            .catch(function(error) {
                showActions(true);
                setActionsDisabled(false);
                Notification.exception(error);
            });
    };

    /**
     * Get a localized string with a defensive fallback.
     *
     * @param {string} key String key
     * @return {string}
     */
    var getString = function(key) {
        var defaults = {
            creatingpaymentcode: 'Creating payment code...',
            webirrpaymentcode: 'WeBirr Payment Code',
            usepaymentcode: 'Use this payment code in your banking app to complete the payment.',
            waitingpaymentconfirmation: 'Waiting for payment confirmation...',
            checkingpaymentstatusdelay: 'Checking payment status in about 5 seconds.',
            checkpaymentstatus: 'Checking payment status...',
            refreshingpaymentstatus: 'Refreshing payment status...',
            paymentsuccessful: 'Your payment was successful.',
            paymentnotreceived: 'Payment not received yet.'
        };

        return strings[key] || defaults[key] || key;
    };

    return {
        init: init
    };
});
