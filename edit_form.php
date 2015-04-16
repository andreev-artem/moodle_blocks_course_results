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
 * @package    block_course_results
 * @copyright  2011 Artem Andreev
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/grade/lib.php');

class block_course_results_edit_form extends block_edit_form {
    /**
     * @param MoodleQuickForm $mform
     */
    protected function specific_definition($mform) {
        global $DB;

        // Fields for editing HTML block title and contents.
        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

        $options = array();
        $items = grade_item::fetch_all(array('courseid' => $this->page->course->id));
        foreach ($items as $key => $item) {
            switch($item->itemtype) {
                case 'category':
                    $options[$key] = get_string('categorytotal', 'grades') . ': ' . $item->get_item_category()->fullname;
                    break;
                case 'course':
                    $options[$key] = get_string('coursetotal', 'grades');
                    break;
                default:
                    $options[$key] = $item->itemname;
            }
        }
        $mform->addElement('select', 'config_itemid', get_string('config_select_item', 'block_course_results'), $options);

        $mform->addElement('text', 'config_showbest', get_string('config_show_best', 'block_course_results'), array('size' => 3));
        $mform->setDefault('config_showbest', 3);
        $mform->setType('config_showbest', PARAM_INT);

        $mform->addElement('text', 'config_showworst', get_string('config_show_worst', 'block_course_results'), array('size' => 3));
        $mform->setDefault('config_showworst', 0);
        $mform->setType('config_showworst', PARAM_INT);

        $mform->addElement('selectyesno', 'config_usegroups', get_string('config_use_groups', 'block_course_results'));
        $mform->setDefault('config_usegroups', 1);

        $nameoptions = array(
            B_COURSERESULTS_NAME_FORMAT_FULL => get_string('config_names_full', 'block_course_results'),
            B_COURSERESULTS_NAME_FORMAT_ID => get_string('config_names_id', 'block_course_results'),
            B_COURSERESULTS_NAME_FORMAT_ANON => get_string('config_names_anon', 'block_course_results')
        );
        $mform->addElement('select', 'config_nameformat', get_string('config_name_format', 'block_course_results'), $nameoptions);
        $mform->setDefault('config_nameformat', B_COURSERESULTS_NAME_FORMAT_FULL);

        $gradeeoptions = array(
            B_COURSERESULTS_GRADE_FORMAT_PCT => get_string('config_format_percentage', 'block_course_results'),
            B_COURSERESULTS_GRADE_FORMAT_FRA => get_string('config_format_fraction', 'block_course_results'),
            B_COURSERESULTS_GRADE_FORMAT_ABS => get_string('config_format_absolute', 'block_course_results')
        );
        $mform->addElement('select', 'config_gradeformat', get_string('config_grade_format', 'block_course_results'), $gradeeoptions);
        $mform->setDefault('config_gradeformat', B_COURSERESULTS_GRADE_FORMAT_PCT);

        $mform->addElement('text', 'config_blocktitle', get_string('config_title', 'block_course_results'), array('size' => 24));
        $mform->setType('config_blocktitle', PARAM_TEXT);

        $mform->addElement('selectyesno', 'config_showuserpic', get_string('config_showuserpic', 'block_course_results'));
        $mform->setDefault('config_showconcept', 1);

        $editoroptions = array('maxfiles' => EDITOR_UNLIMITED_FILES, 'noclean' => true, 'context' => $this->block->context);
        $mform->addElement('editor', 'config_blockheader', get_string('config_header', 'block_course_results'), null, $editoroptions);
        $mform->setType('config_text', PARAM_RAW); // XSS is prevented when printing the block contents and serving files.

        $mform->addElement('editor', 'config_blockfooter', get_string('config_footer', 'block_course_results'), null, $editoroptions);
        $mform->setType('config_text', PARAM_RAW); // XSS is prevented when printing the block contents and serving files.
    }
}