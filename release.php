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
 * Release grades.
 *
 * SECURITY:
 *  - groupid > 0: the group is authorised for the current user before anything is released.
 *  - groupid = 0: "release all" requires moodle/site:accessallgroups, and is still limited
 *    to the groups the user can access within this Peer Work activity.
 *
 * @package    mod_peerwork
 * @copyright  2019 Coventry University
 * @author     Frédéric Massart <fred@branchup.tech>
 * @author     2026 Francisco Berrizbeitia <francisco.berrizbeitia@concordia.ca>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use mod_peerwork\event\grades_released;

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

$PAGE->set_url('/mod/peerwork/release.php', ['id' => $cm->id, 'groupid' => $groupid]);

$peerwork = $DB->get_record('peerwork', ['id' => $cm->instance], '*', MUST_EXIST);

// SECURITY: Fail closed if the group-access helpers are missing from locallib.php.
if (!function_exists('peerwork_can_access_group') || !function_exists('peerwork_get_accessible_groups')) {
    throw new coding_exception('mod_peerwork group-access helpers are missing from locallib.php');
}

$viewurl = new moodle_url('/mod/peerwork/view.php', ['id' => $cm->id]);

if ($groupid > 0) {
    // SECURITY: Authorise the single requested group before touching any submission.
    // Covers: non-existent group, group from another course, group outside the
    // Peer Work grouping, and (in Separate groups mode) groups the user is not a
    // member of without moodle/site:accessallgroups.
    // Unknown and forbidden IDs get the same response to prevent enumeration.
    if (!peerwork_can_access_group($cm, $peerwork, $groupid)) {
        redirect($viewurl, get_string('groupaccessdenied', 'mod_peerwork'), null, notification::NOTIFY_ERROR);
    }
    $groupids = [$groupid];

} else if ($groupid === 0) {
    // SECURITY: "Release all grades for all groups" affects every group, so it is
    // restricted to users who can access all groups (matches the button in view.php).
    if (!has_capability('moodle/site:accessallgroups', $context)) {
        redirect($viewurl, get_string('groupaccessdenied', 'mod_peerwork'), null, notification::NOTIFY_ERROR);
    }

    // Defence in depth: still limit the release to the groups this user can access
    // within this Peer Work activity (respects the Peer Work grouping).
    $groupids = array_keys(peerwork_get_accessible_groups($cm, $peerwork));
    if (empty($groupids)) {
        redirect($viewurl, get_string('nogroups', 'mod_peerwork'), null, notification::NOTIFY_WARNING);
    }

} else {
    // Negative or otherwise invalid group IDs are rejected.
    redirect($viewurl, get_string('groupaccessdenied', 'mod_peerwork'), null, notification::NOTIFY_ERROR);
}

// Only graded, unreleased submissions belonging to authorised groups are released.
[$ingroupsql, $ingroupparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'grp');
$sql = "peerworkid = :peerworkid
        AND groupid $ingroupsql
        AND COALESCE(timegraded, 0) > 0
        AND released = 0";
$submissions = $DB->get_records_select('peerwork_submission', $sql,
    ['peerworkid' => $peerwork->id] + $ingroupparams);

foreach ($submissions as $submission) {
    // Release the submission.
    $submission->released = time();
    $submission->releasedby = $USER->id;
    $DB->update_record('peerwork_submission', $submission);

    // Trigger the event.
    $params = [
        'objectid' => $submission->id,
        'context' => $context,
        'other' => [
            'groupid' => $submission->groupid,
        ],
    ];
    $event = grades_released::create($params);
    $event->add_record_snapshot('peerwork_submission', $submission);
    $event->trigger();
}

// Trigger the gradebook update.
peerwork_update_grades($peerwork);

redirect($viewurl);