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
 * Export.
 *
 * SECURITY: Only groups the current user is authorised to access are exported.
 *  - groupid > 0: the group is authorised before any data is read.
 *  - groupid = 0: "all groups" is limited to the user's accessible groups.
 *
 * @package    mod_peerwork
 * @copyright  2013 LEARNING TECHNOLOGY SERVICES
 * @author     2026 Francisco Berrizbeitia <francisco.berrizbeitia@concordia.ca>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use core_user\fields;
use mod_peerwork\event\submissions_exported;

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/mod/peerwork/locallib.php');
require_once($CFG->dirroot . '/lib/grouplib.php');
require_once($CFG->libdir . '/csvlib.class.php');

$id = required_param('id', PARAM_INT);
$groupid = optional_param('groupid', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'peerwork');
$peerwork = $DB->get_record('peerwork', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
require_sesskey();
require_capability('mod/peerwork:grade', $cm->context);

$PAGE->set_url(new moodle_url('/mod/peerwork/export.php', ['id' => $id, 'groupid' => $groupid]));

// SECURITY: Fail closed if the group-access helpers are missing from locallib.php.
if (!function_exists('peerwork_can_access_group') || !function_exists('peerwork_get_accessible_groups')) {
    throw new coding_exception('mod_peerwork group-access helpers are missing from locallib.php');
}

$viewurl = new moodle_url('/mod/peerwork/view.php', ['id' => $cm->id]);

if (empty($groupid)) {
    // SECURITY: "All groups" means all groups THIS USER may access, not every group in the grouping.
    $groupids = array_keys(peerwork_get_accessible_groups($cm, $peerwork));

    if (empty($groupids)) {
        redirect($viewurl, get_string('nogroups', 'mod_peerwork'), null, notification::NOTIFY_WARNING);
    }
} else {
    // SECURITY: Authorise the single requested group before reading any data.
    // Covers: non-existent group, group from another course, group outside the
    // Peer Work grouping, and (in Separate groups mode) groups the user is not
    // a member of without moodle/site:accessallgroups.
    // Unknown and forbidden IDs get the same response to prevent enumeration.
    if (!peerwork_can_access_group($cm, $peerwork, $groupid)) {
        redirect($viewurl, get_string('groupaccessdenied', 'mod_peerwork'), null, notification::NOTIFY_ERROR);
    }
    $groupids = [$groupid];
}

$context = $cm->context;

// Log the export only once the request has been authorised.
$params = [
    'context' => $context,
    'other' => ['groupid' => $groupid],
];
$event = submissions_exported::create($params);
$event->trigger();

$headers = [
    get_string('group'),
    get_string('groupsubmittedon', 'mod_peerwork'),
    get_string('student', 'core_grades'),
    get_string('firstname', 'core'),
    get_string('lastname', 'core'),
    get_string('username', 'core'),
    get_string('email', 'core'),
    get_string('idnumber', 'core'),
    get_string('groupgrade', 'mod_peerwork'),
    get_string('studentcontribution', 'mod_peerwork'),
    get_string('studentcalculatedgrade', 'mod_peerwork'),
    get_string('studentfinalweightedgrade', 'mod_peerwork'),
    get_string('studentrevisedgrade', 'mod_peerwork'),
    get_string('studentfinalgrade', 'mod_peerwork'),
    get_string('feedback', 'mod_peerwork'),
    get_string('gradedby', 'mod_peerwork'),
    get_string('gradedon', 'mod_peerwork'),
    get_string('releasedby', 'mod_peerwork'),
    get_string('releasedon', 'mod_peerwork'),
];

$filename = clean_filename($peerwork->name . '-' . $id . '_' . ($groupid ? $groupid : 'all'));
$filename = preg_replace('/[^a-z0-9-]/', '_', core_text::strtolower(strip_tags($filename)));

$csvexport = new csv_export_writer();
$csvexport->set_filename($filename);
$csvexport->add_data($headers);

// $groupids is guaranteed non-empty and fully authorised at this point.
[$ingroupsql, $ingroupparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED);

$ufieldsapi = fields::for_userpic()->including('email', 'username', 'idnumber');
$stufields = $ufieldsapi->get_sql('u', false, 'user_', 'user_id', false)->selects;
$ufieldsapi = fields::for_userpic();
$graderfields = $ufieldsapi->get_sql('ug', false, 'grader_', 'grader_id', false)->selects;
$releaserfields = $ufieldsapi->get_sql('ur', false, 'reluser_', 'reluser_id', false)->selects;

$uniqid = $DB->sql_concat_join("'-'", ['g.id', 'COALESCE(s.id, 0)', 'COALESCE(u.id, 0)']);

// SECURITY: Groups are also constrained to this course in SQL (defence in depth).
$sql = "SELECT $uniqid, $stufields, $graderfields, $releaserfields,
               s.id AS submissionid, s.grade as groupgrade, s.timegraded, s.released, s.timecreated,
               s.feedbacktext, gg.score as studentcontribution, gg.prelimgrade AS studentcalculatedgrade,
               gg.grade AS studentgrade, gg.revisedgrade, g.name as groupname
          FROM {peerwork} p
          JOIN {groups} g
            ON g.id $ingroupsql
           AND g.courseid = p.course
          JOIN {groups_members} gm
            ON gm.groupid = g.id
     LEFT JOIN {user} u
            ON u.id = gm.userid
     LEFT JOIN {peerwork_submission} s
            ON s.groupid = g.id
           AND s.peerworkid = p.id
     LEFT JOIN {peerwork_grades} gg
            ON gg.submissionid = s.id
           AND gg.userid = u.id
     LEFT JOIN {user} ug
            ON ug.id = s.gradedby
     LEFT JOIN {user} ur
            ON ur.id = s.releasedby
         WHERE p.id = :peerworkid
      ORDER BY g.id, u.id";

$params = ['peerworkid' => $peerwork->id] + $ingroupparams;
$recordset = $DB->get_recordset_sql($sql, $params);

foreach ($recordset as $record) {
    $student = user_picture::unalias($record, ['email', 'username', 'idnumber', 'firstname', 'lastname'], 'user_id', 'user_');
    $grader = user_picture::unalias($record, null, 'grader_id', 'grader_');
    $releaser = user_picture::unalias($record, null, 'reluser_id', 'reluser_');

    $csvexport->add_data([
        $record->groupname,
        !empty($record->timecreated) ? userdate($record->timecreated) : '',
        fullname($student),
        $student->firstname,
        $student->lastname,
        $student->username,
        $student->email,
        $student->idnumber,
        $record->groupgrade ?? '',
        $record->studentcontribution ?? '',
        $record->studentcalculatedgrade ?? '',
        $record->studentgrade ?? '',
        $record->revisedgrade ?? '',
        $record->revisedgrade ?? $record->studentgrade ?? '',
        trim(html_to_text($record->feedbacktext ?? '')),
        !empty($grader->id) ? fullname($grader) : '',
        !empty($record->timegraded) ? userdate($record->timegraded) : '',
        !empty($releaser->id) ? fullname($releaser) : '',
        !empty($record->released) ? userdate($record->released) : '',
    ]);
}
$recordset->close();

$csvexport->download_file();