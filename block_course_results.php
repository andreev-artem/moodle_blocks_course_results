<?php

define('B_COURSERESULTS_NAME_FORMAT_FULL', 1);
define('B_COURSERESULTS_NAME_FORMAT_ID',   2);
define('B_COURSERESULTS_NAME_FORMAT_ANON', 3);
define('B_COURSERESULTS_GRADE_FORMAT_PCT', 1);
define('B_COURSERESULTS_GRADE_FORMAT_FRA', 2);
define('B_COURSERESULTS_GRADE_FORMAT_ABS', 3);

class block_course_results extends block_base {
    function init() {
        $this->title = get_string('formaltitle', 'block_course_results');
        $this->version = 2008122300;
    }

    function applicable_formats() {
        return array('course' => true);
    }

    function get_content() {
        global $USER, $CFG, $DB;

        if (empty($this->instance)) {
            return $this->content;
        }
        
        if (!empty($this->config->blocktitle)) {
            $this->title = $this->config->blocktitle;
        }
        
        if ($this->content !== NULL) {
            return $this->content;
        }

        $this->content = new stdClass;
        if (!empty($this->config->blockheader['text'])) {
            $this->content->text = '<div class="textbeforeresults">' . $this->config->blockheader['text'] . '</div>' . '<br />';
        }
        $this->content->footer = '';

        $itemid   = empty($this->config->itemid) ? 0 : $this->config->itemid;
        $courseid = $this->page->course->id;
        $context = get_context_instance(CONTEXT_COURSE, $courseid);

        if(empty($itemid)) {
            $this->content->text .= get_string('error_emptyitemid', 'block_course_results');
            return $this->content;
        }

        // Get the grade item record
        $item = $DB->get_record('grade_items', array('id' => $itemid));
        if(empty($item)) {
            $this->content->text = get_string('error_emptyitemrecord', 'block_course_results');
            return $this->content;
        }

        // Get the grades for this item
        $grades = $DB->get_records('grade_grades', array('itemid' => $itemid), 'finalgrade, timemodified DESC');

        if(empty($grades)) {
            // No grades, sorry
            // The block will hide itself in this case
            return $this->content;
        }

        foreach ($grades as $key=>$value) {
            if (empty($value->finalgrade) || $value->finalgrade == 0) {
                unset($grades[$key]);
            }
        }
        if(empty($grades)) {
            return $this->content;
        }
        
        if(empty($this->config->showbest) && empty($this->config->showworst)) {
            $this->content->text = get_string('configuredtoshownothing', 'block_course_results');
            return $this->content;
        }

        $groupmode = NOGROUPS;
        $best      = array();
        $worst     = array();

        $nameformat = intval(empty($this->config->nameformat) ? B_COURSERESULTS_NAME_FORMAT_FULL : $this->config->nameformat);

        // If the block is configured to operate in group mode, or if the name display format
        // is other than "fullname", then we need to retrieve the full course record
        if(!empty($this->config->usegroups) || $nameformat != B_COURSERESULTS_NAME_FORMAT_FULL) {
            $course = $DB->get_record_select('course', 'id = '.$courseid, 'groupmode, groupmodeforce, student');
        }

        if(!empty($this->config->usegroups)) {
            // The block was configured to operate in group mode
            if($course->groupmodeforce) {
                $groupmode = $course->groupmode;
            }
            else {
                if ($item->itemtype == 'mod') {
                    $module = $DB->get_record_sql("SELECT cm.groupmode FROM {$CFG->prefix}modules m
                                            LEFT JOIN {$CFG->prefix}course_modules cm ON m.id = cm.module
                                            WHERE m.name = '{$item->itemmodule}' AND cm.instance = {$item->iteminstance}");
                    $groupmode = $module->groupmode;
                }
            }
            // The actual groupmode for the item is now known to be $groupmode
        }

        if(has_capability('moodle/site:accessallgroups', $context) && $groupmode == SEPARATEGROUPS) {
            // We 'll make an exception in this case
            $groupmode = VISIBLEGROUPS;
        }

        switch($groupmode) {
            case VISIBLEGROUPS:
            // Display group-mode results
            $groups = groups_get_all_groups($courseid);

            if(empty($groups)) {
                // No groups exist, sorry
                $this->content->text = get_string('error_nogroupsexist', 'block_course_results');
                return $this->content;
            }

            // Find out all the userids which have a submitted grade
            $userids = array();
            foreach($grades as $grade) {
                $userids[] = $grade->userid;
            }

            // Now find which groups these users belong in
            $groupofuser = $DB->get_records_sql(
            'SELECT m.userid, m.groupid, g.name FROM '.$CFG->prefix.'groups g LEFT JOIN '.$CFG->prefix.'groups_members m ON g.id = m.groupid '.
            'WHERE g.courseid = '.$courseid.' AND m.userid IN ('.implode(',', $userids).')'
            );

            $groupgrades = array();

            // OK... now, iterate the grades again and sum them up for each group
            foreach($grades as $grade) {
                if(isset($groupofuser[$grade->userid])) {
                    // Count this result only if the user is in a group
                    $groupid = $groupofuser[$grade->userid]->groupid;
                    if(!isset($groupgrades[$groupid])) {
                        $groupgrades[$groupid] = array('sum' => (float)$grade->finalgrade, 'number' => 1, 'group' => $groupofuser[$grade->userid]->name);
                    }
                    else {
                        $groupgrades[$groupid]['sum'] += $grade->finalgrade;
                        ++$groupgrades[$groupid]['number'];
                    }
                }
            }

            foreach($groupgrades as $groupid => $groupgrade) {
                $groupgrades[$groupid]['average'] = $groupgrades[$groupid]['sum'] / $groupgrades[$groupid]['number'];
            }

            // Sort groupgrades according to average grade, ascending
            uasort($groupgrades, create_function('$a, $b', 'if($a["average"] == $b["average"]) return 0; return ($a["average"] > $b["average"] ? 1 : -1);'));

            // How many groups do we have with graded member submissions to show?
            $numbest  = empty($this->config->showbest) ? 0 : min($this->config->showbest, count($groupgrades));
            $numworst = empty($this->config->showworst) ? 0 : min($this->config->showworst, count($groupgrades) - $numbest);

            // Collect all the group results we are going to use in $best and $worst
            $remaining = $numbest;
            $groupgrade = end($groupgrades);
            while($remaining--) {
                $best[key($groupgrades)] = $groupgrade['average'];
                $groupgrade = prev($groupgrades);
            }

            $remaining = $numworst;
            $groupgrade = reset($groupgrades);
            while($remaining--) {
                $worst[key($groupgrades)] = $groupgrade['average'];
                $groupgrade = next($groupgrades);
            }

            // Ready for output!
            $gradeformat = intval(empty($this->config->gradeformat) ? B_COURSERESULTS_GRADE_FORMAT_PCT : $this->config->gradeformat);

            if ($nameformat = B_COURSERESULTS_NAME_FORMAT_FULL) {
                if (has_capability('moodle/course:managegroups', $context)) {
                    $grouplink = $CFG->wwwroot.'/group/overview.php?id='.$courseid.'&amp;group=';
                } else if (has_capability('moodle/course:viewparticipants', $context)) {
                    $grouplink = $CFG->wwwroot.'/user/index.php?id='.$courseid.'&amp;group=';
                } else {
                    $grouplink = '';
                }
            }

            $rank = 0;
            if(!empty($best)) {
                $this->content->text .= '<table class="grades generaltable"><caption>';
                $this->content->text .= ($numbest == 1?get_string('bestgroupgrade', 'block_course_results'):get_string('bestgroupgrades', 'block_course_results', $numbest));
                $this->content->text .= '</caption><colgroup class="number" /><colgroup class="name" /><colgroup class="grade" /><tbody>';
                foreach($best as $groupid => $averagegrade) {
                    switch($nameformat) {
                        case B_COURSERESULTS_NAME_FORMAT_ANON:
                        case B_COURSERESULTS_NAME_FORMAT_ID:
                            $thisname = get_string('group');
                        break;
                        default:
                        case B_COURSERESULTS_NAME_FORMAT_FULL:
                            if ($grouplink) {
                                $thisname = '<a href="'.$grouplink.$groupid.'">'.$groupgrades[$groupid]['group'].'</a>';
                            } else {
                                $thisname = $groupgrades[$groupid]['group'];
                            }
                        break;
                    }
                    $this->content->text .= '<tr class="r'.($rank%2).'"><td>'.(++$rank).'.</td><td>'.$thisname.'</td><td>';
                    switch($gradeformat) {
                        case B_COURSERESULTS_GRADE_FORMAT_FRA:
                            $this->content->text .= (format_float($averagegrade,$item->decimals).'/'.(format_float($item->grademax,$item->decimals)));
                        break;
                        case B_COURSERESULTS_GRADE_FORMAT_ABS:
                            $this->content->text .= format_float($averagegrade,$item->decimals);
                        break;
                        default:
                        case B_COURSERESULTS_GRADE_FORMAT_PCT:
                            $this->content->text .= round((float)$averagegrade / (float)$item->grademax * 100).'%';
                        break;
                    }
                    $this->content->text .= '</td></tr>';
                }
                $this->content->text .= '</tbody></table>';
            }

            $rank = 0;
            if(!empty($worst)) {
                $worst = array_reverse($worst, true);
                $this->content->text .= '<table class="grades generaltable"><caption>';
                $this->content->text .= ($numworst == 1?get_string('worstgroupgrade', 'block_course_results'):get_string('worstgroupgrades', 'block_course_results', $numworst));
                $this->content->text .= '</caption><colgroup class="number" /><colgroup class="name" /><colgroup class="grade" /><tbody>';
                foreach($worst as $groupid => $averagegrade) {
                    switch($nameformat) {
                        case B_COURSERESULTS_NAME_FORMAT_ANON:
                        case B_COURSERESULTS_NAME_FORMAT_ID:
                            $thisname = get_string('group');
                        break;
                        default:
                        case B_COURSERESULTS_NAME_FORMAT_FULL:
                            $thisname = '<a href="'.$CFG->wwwroot.'/course/group.php?group='.$groupid.'&amp;id='.$courseid.'">'.$groupgrades[$groupid]['group'].'</a>';
                        break;
                    }
                    $this->content->text .= '<tr class="r'.($rank%2).'"><td>'.(++$rank).'.</td><td>'.$thisname.'</td><td>';
                    switch($gradeformat) {
                        case B_COURSERESULTS_GRADE_FORMAT_FRA:
                            $this->content->text .= (format_float($averagegrade,$item->decimals).'/'.(format_float($item->grademax,$item->decimals)));
                        break;
                        case B_COURSERESULTS_GRADE_FORMAT_ABS:
                            $this->content->text .= format_float($averagegrade,$item->decimals);
                        break;
                        default:
                        case B_COURSERESULTS_GRADE_FORMAT_PCT:
                            $this->content->text .= round((float)$averagegrade / (float)$item->grademax * 100).'%';
                        break;
                    }
                    $this->content->text .= '</td></tr>';
                }
                $this->content->text .= '</tbody></table>';
            }
            break;


            case SEPARATEGROUPS:
            // This is going to be just like no-groups mode, only we 'll filter
            // out the grades from people not in our group.
            if(empty($USER) || empty($USER->id)) {
                // Not logged in, so show nothing
                return $this->content;
            }

            $mygroups = groups_get_all_groups($courseid, $USER->id);
            if(empty($mygroups)) {
                // Not member of a group, show nothing
                return $this->content;
            }

            $mygroupsusers = $DB->get_records_list('groups_members', 'groupid', implode(',', array_keys($mygroups)), '', 'userid, id');
            // There should be at least one user there, ourselves. So no more tests.

            // Just filter out the grades belonging to other users, and proceed as if there were no groups
            $strallowedusers = implode(',', array_keys($mygroupsusers));
            $grades = array_filter($grades, create_function('$el', '$allowed = explode(",", "'.$strallowedusers.'"); return in_array($el->userid, $allowed);'));

            // NO break; HERE, JUST GO AHEAD
            default:
            case NOGROUPS:
            // Single user mode
            $numbest  = empty($this->config->showbest) ? 0 : min($this->config->showbest, count($grades));
            $numworst = empty($this->config->showworst) ? 0 : min($this->config->showworst, count($grades) - $numbest);

            // Collect all the usernames we are going to need
            $remaining = $numbest;
            $grade = end($grades);
            while($remaining--) {
                $best[$grade->userid] = $grade->id;
                $grade = prev($grades);
            }

            $remaining = $numworst;
            $grade = reset($grades);
            while($remaining--) {
                $worst[$grade->userid] = $grade->id;
                $grade = next($grades);
            }

            if(empty($best) && empty($worst)) {
                // Nothing to show, for some reason...
                return $this->content;
            }

            // Now grab all the users from the database
            $userids = array_merge(array_keys($best), array_keys($worst));
            $users = $DB->get_records_list('user', 'id', $userids, '', 'id, firstname, lastname, idnumber, picture, imagealt');

            // Ready for output!

            $gradeformat = intval(empty($this->config->gradeformat) ? B_COURSERESULTS_GRADE_FORMAT_PCT : $this->config->gradeformat);

            $rank = 0;
            if(!empty($best)) {
                $this->content->text .= '<table class="grades generaltable"><caption>';
                $this->content->text .= ($numbest == 1?get_string('bestgrade', 'block_course_results'):get_string('bestgrades', 'block_course_results', $numbest));
                $this->content->text .= '</caption><colgroup class="number" /><colgroup class="name" /><colgroup class="grade" /><tbody>';
                foreach($best as $userid => $gradeid) {
                    switch($nameformat) {
                        case B_COURSERESULTS_NAME_FORMAT_ID:
                            $thisname = $course->student.' '.intval($users[$userid]->idnumber);
                        break;
                        case B_COURSERESULTS_NAME_FORMAT_ANON:
                            $thisname = $course->student;
                        break;
                        default:
                        case B_COURSERESULTS_NAME_FORMAT_FULL:
                            $thisname = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$userid.'&amp;course='.$courseid.'">';
                            if (!empty($this->config->showuserpic)) {
                                $thisname .= print_user_picture($users[$userid], $courseid, NULL, 16, true, false, '', false);
                            }
                            $thisname .= fullname($users[$userid]).'</a>';
                        break;
                    }
                    $this->content->text .= '<tr class="r'.($rank%2).'"><td>'.(++$rank).'.</td><td>'.$thisname.'</td><td>';
                    switch($gradeformat) {
                        case B_COURSERESULTS_GRADE_FORMAT_FRA:
                            $this->content->text .= (format_float($grades[$gradeid]->finalgrade,$item->decimals).'/'.(format_float($item->grademax,$item->decimals)));
                        break;
                        case B_COURSERESULTS_GRADE_FORMAT_ABS:
                            $this->content->text .= format_float($grades[$gradeid]->finalgrade,$item->decimals);
                        break;
                        default:
                        case B_COURSERESULTS_GRADE_FORMAT_PCT:
                            if ($item->grademax) {
                                $this->content->text .= round((float)$grades[$gradeid]->finalgrade / (float)$item->grademax * 100).'%';
                            } else {
                                $this->content->text .= '--%';
                            }
                        break;
                    }
                    $this->content->text .= '</td></tr>';
                }
                $this->content->text .= '</tbody></table>';
            }

            $rank = 0;
            if(!empty($worst)) {
                $worst = array_reverse($worst, true);
                $this->content->text .= '<table class="grades generaltable"><caption>';
                $this->content->text .= ($numworst == 1?get_string('worstgrade', 'block_course_results'):get_string('worstgrades', 'block_course_results', $numworst));
                $this->content->text .= '</caption><colgroup class="number" /><colgroup class="name" /><colgroup class="grade" /><tbody>';
                foreach($worst as $userid => $gradeid) {
                    switch($nameformat) {
                        case B_COURSERESULTS_NAME_FORMAT_ID:
                            $thisname = $course->student.' '.intval($users[$userid]->idnumber);
                        break;
                        case B_COURSERESULTS_NAME_FORMAT_ANON:
                            $thisname = $course->student;
                        break;
                        default:
                        case B_COURSERESULTS_NAME_FORMAT_FULL:
                            $thisname = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$userid.'&amp;course='.$courseid.'">';
                            if (!empty($this->config->showuserpic)) {
                                $thisname .= print_user_picture($users[$userid], $courseid, NULL, 16, true, false, '', false);
                            }
                            $thisname .= fullname($users[$userid]).'</a>';
                        break;
                    }
                    $this->content->text .= '<tr class="r'.($rank%2).'"><td>'.(++$rank).'.</td><td>'.$thisname.'</td><td>';
                    switch($gradeformat) {
                        case B_COURSERESULTS_GRADE_FORMAT_FRA:
                            $this->content->text .= (format_float($grades[$gradeid]->finalgrade,$item->decimals).'/'.(format_float($item->grademax,$item->decimals)));
                        break;
                        case B_COURSERESULTS_GRADE_FORMAT_ABS:
                            $this->content->text .= format_float($grades[$gradeid]->finalgrade,$item->decimals);
                        break;
                        default:
                        case B_COURSERESULTS_GRADE_FORMAT_PCT:
                            $this->content->text .= round((float)$grades[$gradeid]->finalgrade / (float)$item->grademax * 100).'%';
                        break;
                    }
                    $this->content->text .= '</td></tr>';
                }
                $this->content->text .= '</tbody></table>';
            }
            break;
        }
        
        if (!empty($this->config->blockheader['text'])) {
            $this->content->text .= '<br />' . '<div class="textafterresults">' . $this->config->blockfooter['text'] . '</div>';
        }

        return $this->content;
    }

    function instance_allow_multiple() {
        return true;
    }
}

?>
