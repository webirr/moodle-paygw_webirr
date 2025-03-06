// amd/src/payment.js
/**
 * WeBirr payment handling module.
 *
 * @module     paygw_webirr/payment
 * @copyright  2025 Your Name <your.email@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/str', 'core/notification', 'core/templates', 'paygw_webirr/repository'],
function($, Str, Notification, Templates, Repository) {
    /**
     * Initialize the payment process.
     *
     * @param {string} component
     * @param {string} paymentArea
     * @param {number} itemId
     * @param {string} description
     */
    var init = function(component, paymentArea, itemId, description) {
        // Get the payment code.
        Repository.getPaymentCode(component, paymentArea, itemId, description)
            .then(function(response) {
                if (response.success) {
                    // Display the payment code.
                    $('#webirr-payment-code').html('<p>' + Str.get_string('paymentcode', 'paygw_webirr') + ': ' + response.paymentcode + '</p>');
                    
                    // Display the QR code.
                    var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?data=' + response.paymentcode + '&size=200x200';
                    $('#webirr-payment-qr').html('<img src="' + qrUrl + '" alt="QR Code" width="200" height="200">');
                    
                    // Display instructions.
                    $('#webirr-payment-qr').append('<p>' + Str.get_string('scanqrcode', 'paygw_webirr') + '</p>');
                    
                    // Start polling for payment status.
                    pollPaymentStatus(response.paymentid);
                } else {
                    // Display error message.
                    $('#webirr-payment-status').html('<p class="text-danger">' + response.error + '</p>');
                }
            })
            .catch(Notification.exception);
    };

    /**
     * Poll for payment status.
     *
     * @param {number} paymentId
     */
    var pollPaymentStatus = function(paymentId) {
        // Check the payment status.
        Repository.getPaymentStatus(paymentId)
            .then(function(response) {
                if (response.success) {
                    if (response.complete) {
                        // Payment is complete.
                        $('#webirr-payment-status').html('<p class="text-success">' + Str.get_string('paymentsuccessful', 'paygw_webirr') + '</p>');
                        
                        // Redirect to success page.
                        setTimeout(function() {
                            window.location.href = M.cfg.wwwroot + '/payment/gateway/webirr/success.php';
                        }, 2000);
                    } else {
                        // Payment is still pending.
                        $('#webirr-payment-status').html('<p>' + Str.get_string('paymentpending', 'paygw_webirr') + '</p>');
                        
                        // Continue polling.
                        setTimeout(function() {
                            pollPaymentStatus(paymentId);
                        }, 5000);
                    }
                } else {
                    // Error checking payment status.
                    $('#webirr-payment-status').html('<p class="text-danger">' + response.error + '</p>');
                }
            })
            .catch(Notification.exception);
    };

    return {
        init: init
    };
});