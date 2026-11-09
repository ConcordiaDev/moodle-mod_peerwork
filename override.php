<?php
// This file is part of 3rd party created module for Moodle - http://moodle.org/
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
 * Override.
 *
 * SECURITY: The group and the graded-by user are authorised BEFORE any peer
 * grades are displayed or saved. Saving uses only the validated URL values,
 * never the hidden form values.
 *
 * @package    mod_peerwork
 * @copyright  2020 Amanda Doughty
 * @author     2026 Francisco Berrizbeitia <francisco.berrizbeitia@concordia.ca>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/peerwork/lib.php');
require_once($CFG->dirroot . '/lib/grouplib.php');
require_once($CFG->dirroot . '/mod/peerwork/locallib.php');

$id = required_param('id', PARAM_INT);
$peerworkid = required_param('pid', PARAM_INT);
$groupid = required_param('gid', PARAM_INT);
$gradedbyid = required_param('uid', PARAM_INT);

$cm = get_coursemodule_from_id('peerwork', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$peerwork = $DB->get_record('peerwork', ['id' => $cm->instance], '*', MUST_EXIST);

// Authenticate and check the activity capability FIRST, before touching group data.
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/peerwork:grade', $context);

if ($peerworkid != $cm->instance) {
    throw new moodle_exception('invalidcoursemodule', 'error');
}

$viewurl = new moodle_url('/mod/peerwork/view.php', ['id' => $cm->id]);

// SECURITY: Authorise the requested group before reading any peer grades.
if (!peerwork_can_access_group($cm, $peerwork, $groupid)) {
    redirect($viewurl, get_string('groupaccessdenied', 'mod_peerwork'), null, notification::NOTIFY_ERROR);
}

$parenturl = new moodle_url('/mod/peerwork/details.php', ['id' => $cm->id, 'groupid' => $groupid]);

// Students in the group (teachers excluded).
$members = peerwork_get_group_members($peerwork, $groupid);

// SECURITY: The graded-by user must be a student in this group.
if (!isset($members[$gradedbyid])) {
    redirect($parenturl, get_string('invaliduser', 'error'), null, notification::NOTIFY_ERROR);
}

$PAGE->set_url('/mod/peerwork/override.php', ['id' => $cm->id, 'pid' => $peerworkid, 'gid' => $groupid, 'uid' => $gradedbyid]);
$PAGE->set_title(format_string($peerwork->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$gradedby = new stdClass();
$gradedby->id = $gradedbyid;

$grades = peerwork_grades_overrides_by_user($peerwork, $gradedby, $members);
$header = get_string('gradesgivenby', 'peerwork', fullname($members[$gradedbyid]));

$customdata['peerworkid'] = $peerwork->id;
$customdata['gradedby'] = $gradedby;
$customdata['groupid'] = $groupid;
$customdata['peers'] = $members;
$customdata['grades'] = $grades;
$customdata['selfgrading'] = $peerwork->selfgrading;

$action = new moodle_url('override.php', ['id' => $id, 'pid' => $peerworkid, 'gid' => $groupid, 'uid' => $gradedbyid]);
$mform = new mod_peerwork_override_form($action->out(false), $customdata);

if ($mform->is_cancelled()) {
    redirect($parenturl);

} else if ($fromform = $mform->get_data()) {
    // SECURITY: Use the validated URL values, not the hidden form fields,
    // so the target activity, group and student cannot be swapped on submit.
    peerwork_peer_override(
        $peerwork->id,
        $gradedbyid,
        $groupid,
        $fromform->overridden,
        $fromform->gradeoverrides,
        $fromform->comments
    );
    redirect($parenturl);

} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(s($header), 2);
    $mform->display();
    echo $OUTPUT->footer();
}