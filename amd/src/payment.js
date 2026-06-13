// amd/src/payment.js
/**
 * WeBirr payment handling module.
 *
 * @module     paygw_webirr/payment
 * @copyright  2025 Your Name <your.email@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/notification', 'paygw_webirr/repository'],
function($, Notification, Repository) {
    var POLL_DELAY_MS = 5000;
    var paymentState = {};

    /**
     * Initialize the payment process.
     *
     * @param {string} component
     * @param {string} paymentArea
     * @param {number} itemId
     * @param {string} description
     * @param {string} sesskey
     */
    var init = function(component, paymentArea, itemId, description, sesskey) {
        paymentState = {
            component: component,
            paymentArea: paymentArea,
            itemId: itemId,
            sesskey: sesskey,
            paymentId: null,
            pollTimer: null
        };

        $('#payment-refresh-button').off('click').on('click', function() {
            checkPaymentStatus(false);
        });

        showActions(false);
        setStatus('info', 'Creating payment code...', true);

        // Get the payment code.
        Repository.getPaymentCode(component, paymentArea, itemId, description)
            .then(function(response) {
                if (response.success) {
                    paymentState.paymentId = response.paymentid;
                    $('#payment-loading').hide();
                    $('#payment-code-display')
                        .empty()
                        .append($('<div>').addClass('payment-code-title').text('WeBirr Payment Code'))
                        .append($('<div>').addClass('payment-code-large').text(response.paymentcode))
                        .append($('<p>').addClass('payment-instructions')
                            .text('Use this payment code in your banking app to complete the payment.'));

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
        $('#payment-actions').toggle(visible);
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
        setStatus('info', 'Waiting for payment confirmation...', true);
        setDetail('Checking payment status in about 5 seconds.');

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
        setStatus('info', automatic ? 'Checking payment status...' : 'Refreshing payment status...', true);
        setDetail('');

        // Check the payment status.
        Repository.getPaymentStatus(paymentState.paymentId)
            .then(function(response) {
                if (response.success) {
                    if (response.complete) {
                        // Payment is complete.
                        setStatus('success', 'Your payment was successful.', false);

                        // Redirect to success page.
                        setTimeout(function() {
                            window.location.href = M.cfg.wwwroot + '/payment/gateway/webirr/success.php'
                                + '?component=' + encodeURIComponent(paymentState.component)
                                + '&paymentarea=' + encodeURIComponent(paymentState.paymentArea)
                                + '&itemid=' + encodeURIComponent(paymentState.itemId)
                                + '&sesskey=' + encodeURIComponent(paymentState.sesskey);
                        }, 2000);
                    } else {
                        setStatus('warning', 'Payment not received yet.', true);
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

    return {
        init: init
    };
});
