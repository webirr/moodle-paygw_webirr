/**
 * Redirect the Moodle payment selector to the WeBirr checkout page.
 *
 * @module     paygw_webirr/gateways_modal
 * @copyright  2026 WeBirr
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Process the selected WeBirr payment method.
 *
 * @param {String} component Moodle component name.
 * @param {String} paymentArea Moodle payment area.
 * @param {Number} itemId Moodle payable item id.
 * @param {String} description Moodle payment description.
 * @return {Promise} Never resolves because the browser is redirected.
 */
export const process = (component, paymentArea, itemId, description) => {
    const params = new URLSearchParams({
        component,
        paymentarea: paymentArea,
        itemid: itemId,
        description,
    });

    window.location.href = M.cfg.wwwroot + '/payment/gateway/webirr/pay.php?' + params.toString();
    return new Promise(() => {});
};
