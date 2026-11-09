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
 * Download submissions
 *
 * SECURITY: Only submission files from groups the current user is authorised
 * to access are included in the archive (separate-groups enforcement).
 *
 * @package    mod_peerwork
 * @copyright  2020 Amanda Doughty
 * @author      2026 Francisco Berrizbeitia <francisco.berrizbeitia@concordia.ca>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use mod_peerwork\event\submissions_downloaded;

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/lib/grouplib.php');
require_once($CFG->dirroot . '/mod/peerwork/locallib.php');
require_once($CFG->libdir . '/filestorage/zip_archive.php');

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'peerwork');
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_sesskey();
require_capability('mod/peerwork:grade', $cm->context);

$peerwork = $DB->get_record('peerwork', ['id' => $cm->instance], '*', MUST_EXIST);

// SECURITY: Fail closed if the group-access helpers are missing from locallib.php.
if (!function_exists('peerwork_can_access_group') || !function_exists('peerwork_get_accessible_groups')) {
    throw new coding_exception('mod_peerwork group-access helpers are missing from locallib.php');
}

$viewurl = new moodle_url('/mod/peerwork/view.php', ['id' => $cm->id]);

// SECURITY: "All submissions" means all submissions from groups THIS USER may access.
// In Separate groups mode this is only the user's own groups, unless they hold
// moodle/site:accessallgroups in this module context.
$allgroups = peerwork_get_accessible_groups($cm, $peerwork);

if (empty($allgroups)) {
    redirect($viewurl, get_string('nogroups', 'mod_peerwork'), null, notification::NOTIFY_WARNING);
}

// Increase the server timeout to handle the creation and sending of large zip files.
core_php_time_limit::raise();

$fs = get_file_storage();
$groupfiles = [];

foreach ($allgroups as $group) {
    // SECURITY: Defence in depth - re-check access before adding any group's files.
    if (!peerwork_can_access_group($cm, $peerwork, (int) $group->id)) {
        continue;
    }

    if ($files = $fs->get_area_files($context->id, 'mod_peerwork', 'submission', $group->id, 'sortorder', false)) {
        // Include the group ID so two groups with the same name cannot overwrite each other's files.
        $groupfolder = clean_filename($group->name . '_' . $group->id);

        foreach ($files as $file) {
            if ($file->is_directory() && $file->get_filename() == '.') {
                continue;
            }
            $filepathinarchive = $groupfolder . DIRECTORY_SEPARATOR . $file->get_filename();
            $groupfiles[$filepathinarchive] = $file;
        }
    }
}

$zipper = get_file_packer('application/zip');
$filename = shorten_filename(clean_filename($peerwork->name . "-" . date("Ymd")) . ".zip");
$temppath = tempnam($CFG->tempdir . '/', 'peerwork_');

if ($zipper->archive_to_pathname($groupfiles, $temppath)) {
    $params = ['context' => $context];
    $event = submissions_downloaded::create($params);
    $event->trigger();

    // Send file and delete after sending.
    send_temp_file($temppath, $filename);
    // We will not get here - send_temp_file calls exit.
} else {
    throw new moodle_exception('cannotdownloaddir', 'repository');
}