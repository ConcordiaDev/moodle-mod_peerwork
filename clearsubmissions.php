<?php
// This file is part of a 3rd party created module for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Clear submissions.
 *
 * SECURITY:
 *  - groupid > 0: the group is authorised for the current user before anything is deleted.
 *  - groupid = 0: "clear all" requires moodle/site:accessallgroups, and is still limited
 *    to the groups the user can access within this Peer Work activity.
 *
 * @package    mod_peerwork
 * @copyright  2020 Xi'an Jiaotong-Liverpool University
 * @author     Frédéric Massart <fred@branchup.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$groupid = required_param('groupid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'peerwork');
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_sesskey();
require_capability('mod/peerwork:grade', $context);

$PAGE->set_url('/mod/peerwork/clearsubmissions.php', ['id' => $cm->id, 'groupid' => $groupid]);

$peerwork = $DB->get_record('peerwork', ['id' => $cm->instance], '*', MUST_EXIST);

// SECURITY: Fail closed if the group-access helpers are missing from locallib.php.
if (!function_exists('peerwork_can_access_group') || !function_exists('peerwork_get_accessible_groups')) {
    throw new coding_exception('mod_peerwork group-access helpers are missing from locallib.php');
}

$viewurl = new moodle_url('/mod/peerwork/view.php', ['id' => $cm->id]);

if ($groupid > 0) {
    // SECURITY: Authorise the single requested group before deleting anything.
    // Covers: non-existent group, group from another course, group outside the
    // Peer Work grouping, and (in Separate groups mode) groups the user is not a
    // member of without moodle/site:accessallgroups.
    // Unknown and forbidden IDs get the same response to prevent enumeration.
    if (!peerwork_can_access_group($cm, $peerwork, $groupid)) {
        redirect($viewurl, get_string('groupaccessdenied', 'mod_peerwork'), null, notification::NOTIFY_ERROR);
    }

    mod_peerwork_clear_submissions($peerwork, $context, $groupid);

} else if ($groupid === 0) {
    // SECURITY: "Clear all submissions for all groups" deletes data for every group,
    // so it is restricted to users who can access all groups.
    if (!has_capability('moodle/site:accessallgroups', $context)) {
        redirect($viewurl, get_string('groupaccessdenied', 'mod_peerwork'), null, notification::NOTIFY_ERROR);
    }

    // Defence in depth: never pass groupid = 0 to the delete function, because it
    // would clear every submission in the activity. Instead, clear each accessible
    // group explicitly (respects the Peer Work grouping).
    $groupids = array_keys(peerwork_get_accessible_groups($cm, $peerwork));
    if (empty($groupids)) {
        redirect($viewurl, get_string('nogroups', 'mod_peerwork'), null, notification::NOTIFY_WARNING);
    }

    foreach ($groupids as $accessiblegroupid) {
        mod_peerwork_clear_submissions($peerwork, $context, (int) $accessiblegroupid);
    }

} else {
    // Negative or otherwise invalid group IDs are rejected.
    redirect($viewurl, get_string('groupaccessdenied', 'mod_peerwork'), null, notification::NOTIFY_ERROR);
}

redirect($viewurl);