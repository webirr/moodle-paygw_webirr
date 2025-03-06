<?php

namespace paygw_webirr\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;


class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Returns metadata about this system.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'paygw_webirr_payments',
            [
                'userid' => 'privacy:metadata:paygw_webirr:userid',
                'billreference' => 'privacy:metadata:paygw_webirr:billreference',
                'status' => 'privacy:metadata:paygw_webirr:paymentstatus',
            ],
            'privacy:metadata:paygw_webirr'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist $contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        
        // The payments are associated with the system context.
        $contextlist->add_system_context();
        
        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof \context_system) {
            return;
        }

        $sql = "SELECT userid FROM {paygw_webirr_payments}";
        $userids = $DB->get_fieldset_sql($sql);
        
        $userlist->add_users($userids);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();
        
        // Export WebIrr payment data.
        $sql = "SELECT * FROM {paygw_webirr_payments} WHERE userid = :userid";
        $params = ['userid' => $user->id];
        $records = $DB->get_records_sql($sql, $params);
        
        if (!empty($records)) {
            $data = [];
            foreach ($records as $record) {
                $data[] = [
                    'billreference' => $record->billreference,
                    'wbc_code' => $record->wbc_code,
                    'amount' => $record->amount,
                    'currency' => $record->currency,
                    'status' => $record->status,
                    'timecreated' => transform::datetime($record->timecreated),
                    'timemodified' => transform::datetime($record->timemodified),
                ];
            }
            
            writer::with_context(\context_system::instance())->export_data(
                [get_string('pluginname', 'paygw_webirr')],
                (object) ['payments' => $data]
            );
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_system) {
            return;
        }

        $DB->delete_records('paygw_webirr_payments');
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();
        
        $DB->delete_records('paygw_webirr_payments', ['userid' => $user->id]);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof \context_system) {
            return;
        }

        list($userinsql, $userparams) = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        
        $DB->delete_records_select('paygw_webirr_payments', "userid {$userinsql}", $userparams);
    }
}