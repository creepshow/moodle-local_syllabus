<?php
// This file is part of Moodle - http://moodle.org/
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
 * Allows for display and management of syllabi.
 *
 * Includes core functions for course syllabus: uploading, editing, 
 * displaying, removing, downloading, and making private. Responsible
 * for actual interface of syllabus page.
 *
 * @package     local_syllabus
 * @copyright   2012 UC Regents
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once(dirname(__FILE__).'/locallib.php');
require_once(dirname(__FILE__).'/syllabus_form.php');
require_once($CFG->libdir . '/resourcelib.php');

// Get script variables to be used later.
$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', array('id' => $id), '*', MUST_EXIST);
$syllabusmanager = new syllabus_manager($course);
$coursecontext = context_course::instance($course->id);
$canmanagesyllabus = $syllabusmanager->can_manage();

// See if user wants to do an action for a given syllabus type.
$action = optional_param('action', null, PARAM_ALPHA);
$type = optional_param('type', null, PARAM_ALPHA);

require_course_login($course);

// Set up page.
$PAGE->set_url('/local/syllabus/index.php', array('id' => $id));
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($coursecontext);
$PAGE->set_pagelayout('incourse');
$PAGE->set_pagetype('course-view-' . $course->format);

// Set editing button.
if ($canmanagesyllabus) {
    $url = new moodle_url('/local/syllabus/index.php',
                    array('id' => $course->id));
    set_editing_mode_button($url);

    // Set up form.
    $syllabusform = new syllabus_form(null,
            array('courseid' => $course->id,
                  'action' => $action,
                  'type' => $type,
                  'syllabus_manager' => $syllabusmanager),
            'post',
            '',
            array('class' => 'syllabus_form'));

    // If the cancel button is clicked, return to non-editing mode of syllabus page.
    if ($syllabusform->is_cancelled()) {
        $url = new moodle_url('/local/syllabus/index.php',
                array('id' => $course->id,
                      'sesskey' => sesskey(),
                      'edit' => 'on'));
        redirect($url);
    }
}

if (!empty($USER->editing) && $canmanagesyllabus) {
    // User uploaded/edited a syllabus file, so handle it.
    $data = $syllabusform->get_data();
    if (!empty($data) && confirm_sesskey()) {
        $result = $syllabusmanager->save_syllabus($data);
        if ($result) {
            // Upload was successful, give success message to user (redirect to
            // refresh site menu and prevent duplication submission of file).

            $url = new moodle_url('/local/syllabus/index.php',
                    array('action' => SYLLABUS_ACTION_VIEW,
                          'id' => $course->id));
            if (isset($data->entryid)) {
                // Syllabus was updated.
                $successmessage = get_string('successful_update', 'local_syllabus');
            } else {
                // Syllabus was added.
                $successmessage = get_string('successful_add', 'local_syllabus');
            }

            flash_redirect($url, $successmessage);
        }
    } else if ($action == SYLLABUS_ACTION_DELETE) {
        // User wants to delete syllabus.
        $syllabi = $syllabusmanager->get_syllabi();
        $todel = null;

        if ($type == SYLLABUS_TYPE_PUBLIC && !empty($syllabi[SYLLABUS_TYPE_PUBLIC])) {
            $todel = $syllabi[SYLLABUS_TYPE_PUBLIC];
        } else if ($type == SYLLABUS_TYPE_PRIVATE && !empty($syllabi[SYLLABUS_TYPE_PRIVATE])) {
            $todel = $syllabi[SYLLABUS_TYPE_PRIVATE];
        }

        if (empty($todel)) {
            print_error('err_syllabus_notexist', 'local_syllabus');
        } else {
            $syllabusmanager->delete_syllabus($todel);

            $url = new moodle_url('/local/syllabus/index.php',
                    array('action' => SYLLABUS_ACTION_VIEW,
                          'id' => $course->id));
            $successmessage = get_string('successful_delete', 'local_syllabus');
            flash_redirect($url, $successmessage);
        }
    } else if ($action == SYLLABUS_ACTION_CONVERT) {
        // User is converting between public or private syllabus.
        $syllabi = $syllabusmanager->get_syllabi();

        $convertto = 0;
        if ($type == SYLLABUS_TYPE_PUBLIC) {
            $convertto = SYLLABUS_ACCESS_TYPE_PRIVATE;
        } else if ($type == SYLLABUS_TYPE_PRIVATE) {
            // Using the stricter version of public - require user login.
            $convertto = SYLLABUS_ACCESS_TYPE_LOGGEDIN;
        }

        if ($convertto == 0) {
             print_error('err_syllabus_notexist', 'local_syllabus');
        } else {
            $syllabusmanager->convert_syllabus($syllabi[$type], $convertto);

            $url = new moodle_url('/local/syllabus/index.php',
                    array('action' => SYLLABUS_ACTION_VIEW,
                          'id' => $course->id));

            if ($convertto == SYLLABUS_ACCESS_TYPE_PRIVATE) {
                $successmessage = get_string('successful_restrict', 'local_syllabus');
            } else {
                $successmessage = get_string('successful_unrestrict', 'local_syllabus');
            }
            flash_redirect($url, $successmessage);
        }
    }

    display_header(get_string('syllabus_manager', 'local_syllabus'));
    $syllabusform->display();

} else {
    // Just display syllabus.
    $title = ''; $body = '';

    $syllabi = $syllabusmanager->get_syllabi();

     $syllabustodisplay = null;
    if (!empty($syllabi[SYLLABUS_TYPE_PRIVATE]) &&
            $syllabi[SYLLABUS_TYPE_PRIVATE]->can_view()) {
        // See if logged in user can view private syllabus.
        $syllabustodisplay = $syllabi[SYLLABUS_TYPE_PRIVATE];
    } else if (!empty($syllabi[SYLLABUS_TYPE_PUBLIC]) &&
            $syllabi[SYLLABUS_TYPE_PUBLIC]->can_view()) {
        // Fallback on trying to see if user can view public syllabus.
        $syllabustodisplay = $syllabi[SYLLABUS_TYPE_PUBLIC];
    }

    // Set up what to display.
    if (empty($syllabustodisplay)) {
        // If there is no syllabus, then display no info.
        $title = get_string('display_name_default', 'local_syllabus');

        $errorstring = '';
        if (!empty($syllabi[SYLLABUS_TYPE_PUBLIC])) {
            $errorstring = get_string('cannot_view_public_syllabus', 'local_syllabus');
        } else if (!empty($syllabi[SYLLABUS_TYPE_PRIVATE])) {
            $errorstring = get_string('cannot_view_private_syllabus', 'local_syllabus');
        } else {
            $errorstring = get_string('no_syllabus_uploaded', 'local_syllabus');
        }

        $body = html_writer::tag('p', $errorstring, array('class' => 'no_syllabus'));

        // If user can upload a syllabus, let them know about turning editing on.
        if ($canmanagesyllabus) {
            $body .= html_writer::tag('p',
                    get_string('no_syllabus_uploaded_help', 'local_syllabus'));
        }
    } else {
        $title = $syllabustodisplay->display_name;

        // Give preference to URL.
        if (empty($syllabustodisplay->url)) {
            $fullurl = $syllabustodisplay->get_file_url();
            $mimetype = $syllabustodisplay->get_mimetype();
            $clicktoopen = get_string('err_noembed', 'local_syllabus');
            $downloadlink = $syllabustodisplay->get_download_link();

        } else {
            $fullurl = $syllabustodisplay->url;
            $mimetype = 'text/html';
            $clicktoopen = get_string('err_noembed', 'local_syllabus');
            $downloadlink = html_writer::link($syllabustodisplay->url, $syllabustodisplay->url);
        }

        // Add download link.
        $body .= html_writer::tag('div', $downloadlink, array('id' => 'download_link'));

        // Try to embed file using resource functions.
        if ($mimetype === 'application/pdf') {
            $body .= resourcelib_embed_pdf($fullurl, $title, $clicktoopen);
        } else {
            $body .= resourcelib_embed_general($fullurl, $title, $clicktoopen, $mimetype);
        }

        // If this is a preview syllabus, give some disclaimer text.
        $disclaimertext = '';
        $typetext = '';
        if ($syllabustodisplay instanceof public_syllabus) {
            if ($syllabustodisplay->is_preview) {
                $typetext = get_string('preview', 'local_syllabus');
                $disclaimertext = get_string('preview_disclaimer', 'local_syllabus');
            }
        } else {
            $typetext = get_string('private', 'local_syllabus');
            $disclaimertext = get_string('private_disclaimer', 'local_syllabus');
        }

        // Add modified date.
        $modifiedtext = '';
        if (!empty($syllabustodisplay->timemodified)) {
            $modifiedtext = get_string('modified', 'local_syllabus')
                    . userdate($syllabustodisplay->timemodified);
        }

        if (!empty($typetext)) {
            $title .= sprintf(' (%s)*', $typetext);
            $body .= html_writer::tag('p', '*' . $disclaimertext,
                    array('class' => 'syllabus_disclaimer'));
        }
        $body .= html_writer::tag('p', $modifiedtext,
                array('class' => 'syllabus-modified'));
    }

    // Now display content.
    display_header($title);
    echo $OUTPUT->container($body, 'syllabus-container');

    // Log for statistics later.
    add_to_log($course->id, 'syllabus', 'view', 'index.php?id='.$course->id, '');
}

echo $OUTPUT->footer();


/**
 * Display the heading of the page.
 * 
 * @param string $pagetitle
 */
function display_header($pagetitle) {
    global $OUTPUT;
    echo $OUTPUT->header();
    echo $OUTPUT->heading($pagetitle, 2, 'headingblock');
    flash_display();    // Display any success messages.
}
