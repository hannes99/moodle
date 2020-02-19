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
 * Badges external API
 *
 * @package    core_badges
 * @category   external
 * @copyright  2016 Juan Leyva <juan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.1
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/badgeslib.php');

use core_badges\external\user_badge_exporter;

/**
 * Badges external functions
 *
 * @package    core_badges
 * @category   external
 * @copyright  2016 Juan Leyva <juan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.1
 */
class core_badges_external extends external_api {

    /**
     * Describes the parameters for get_badges.
     *
     * @return external_function_parameters
     * @since Moodle 3.8
     */
    public static function get_badges_parameters() {
        return new external_function_parameters (
            array(
                'id' => new external_value(PARAM_INT, 'ID of the badge', VALUE_DEFAULT, 0),
                'name' => new external_value(PARAM_RAW, 'Filter badges by name', VALUE_DEFAULT, ''),
                'expiredate' => new external_value(PARAM_INT, 'Filter badges by expiredate', VALUE_DEFAULT, 0),
                'expireperiod' => new external_value(PARAM_INT, 'Filter badges by expireperiod', VALUE_DEFAULT, 0),
                'type' => new external_value(PARAM_INT, 'Filter badge by type (1: site or 2: course)', VALUE_DEFAULT, 0),
                'courseid' => new external_value(PARAM_INT, 'Filter badges by courseid, only if type is 2', VALUE_DEFAULT, 0),
                'status' => new external_value(PARAM_INT, 'Filter badges current status (0: inactive, 1: active, 2: inactive locked, 3: active locked, 4: archived, >4: no filter on status)', VALUE_DEFAULT, 5),
                'version' => new external_value(PARAM_RAW, 'Filter badges by version', VALUE_DEFAULT, '')
            )
        );
    }

    /**
     * Returns a list of badges matching the passed criteria
     *
     * @param int $id the id of the badge
     * @param string $name string to filter the badges name by
     * @param int $expiredate timestamp to filter the badges by
     * @param int $expireperiod timestamp to filter the badges name by
     * @param int $type only return badges of a specific type (1: site, 2: course, 0: all)
     * @param int $courseid id of the course to filter the badges by, only if type is 2
     * @param int $status only return badges of a specific status (0: inactive, 1: active, 2: inactive locked, 3: active locked, 4: archived, >4: no filter on status)
     * @param string $version string to filter the badges version by
     * @return array
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     * @since Moodle 3.8
     */
    public static function get_badges($id = 0, $name='', $expiredate=0, $expireperiod=0, $type=0, $courseid=0, $status=5, $version='') {
        global $CFG, $USER, $DB;

        $warnings = array();

        $params = array(
            'id' => $id,
            'name' => $name,
            'expiredate' => $expiredate,
            'expireperiod' => $expireperiod,
            'type' => $type,
            'courseid' => $courseid,
            'status' => $status,
            'version' => $version
        );
        $params = self::validate_parameters(self::get_badges_parameters(), $params);

        $usercontext = context_user::instance($USER->id);
        self::validate_context($usercontext);
        require_capability('moodle/badges:viewotherbadges', $usercontext);

        if (empty($CFG->enablebadges)) {
            throw new moodle_exception('badgesdisabled', 'badges');
        }

        $sqlclauses = array();
        $sqlclausevalues = array();

        if ($params['id'] != 0) {
            $sqlclauses[] = 'b.id = ?';
            $sqlclausevalues[] = $params['id'];
        }
        if (!empty($params['name'])) {
            $sqlclauses[] = '(' . $DB->sql_like('b.name', ':name', true) . ')';
            $sqlclausevalues['name'] = '%'.$params['name'].'%';
        }
        if (!empty($params['version'])) {
            $sqlclauses[] = '(' . $DB->sql_like('b.version', ':version', true) . ')';
            $sqlclausevalues['version'] = '%'.$params['version'].'%';
        }
        if ($params['expiredate'] != 0) {
            $sqlclauses[] = $DB->sql_equal('b.expiredate', ':expiredate');
            $sqlclausevalues['expiredate'] = $params['expiredate'];
        }
        if ($params['expireperiod'] != 0) {
            $sqlclauses[] = $DB->sql_equal('b.expireperiod', ':expireperiod');
            $sqlclausevalues['expireperiod'] = $params['expireperiod'];
        }
        if ($params['type'] != 0) {
            $sqlclauses[] = $DB->sql_equal('b.type', ':type');
            $sqlclausevalues['type'] = $params['type'];
        }
        if ($params['courseid'] != 0) {
            $sqlclauses[] = $DB->sql_equal('b.courseid', ':courseid');
            $sqlclausevalues['courseid'] = $params['courseid'];
        }
        if ($params['status'] < 5) {
            $sqlclauses[] = $DB->sql_equal('b.status', ':status');
            $sqlclausevalues['status'] = $params['status'];
        }

        $sql = 'SELECT b.* FROM {badge} b';
        if (!empty($sqlclauses)) {
            $sql = $sql.' WHERE ';
        }
        $sql = $sql.(implode(' AND ', $sqlclauses));

        $badges = $DB->get_records_sql($sql, $sqlclausevalues);

        return ['badges'=>$badges, 'warnings'=>$warnings];
    }

    /**
     * Describes the get_badges return value.
     *
     * @return external_single_structure
     * @since Moodle 3.8
     */
    public static function get_badges_returns() {
        return new external_single_structure(
            array(
                'badges' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'id' => new external_value(PARAM_INT, 'id of the badge'),
                            'name' => new external_value(PARAM_RAW, 'name of the badge'),
                            'description' => new external_value(PARAM_RAW, 'description of the badge'),
                            'timecreated' => new external_value(PARAM_INT, 'Timestamp the badge was created'),
                            'timemodified' => new external_value(PARAM_INT, 'Timestamp the badge was last modified'),
                            'usercreated' => new external_value(PARAM_INT, 'ID of the user who created the badge'),
                            'usermodified' => new external_value(PARAM_INT, 'ID of the user who last modified the badge'),
                            'issuername' => new external_value(PARAM_RAW, 'Name of the issuer of the badge'),
                            'issuerurl' => new external_value(PARAM_RAW, 'Issuer URL, e.g. homepage'),
                            'issuercontact' => new external_value(PARAM_RAW, 'Contact information of the issuer', VALUE_OPTIONAL),
                            'expiredate' => new external_value(PARAM_INT, 'Fixed date when the badge will expire', VALUE_OPTIONAL),
                            'expireperiod' => new external_value(PARAM_INT, 'Relative date when the badge will expire, after the badge was issued', VALUE_OPTIONAL),
                            'type' => new external_value(PARAM_INT, 'Type of the badge (1: site badge, 2: course badge)'),
                            'courseid' => new external_value(PARAM_INT, 'The course where the badge relates to, only if type = 2', VALUE_OPTIONAL),
                            'message' => new external_value(PARAM_RAW, 'Text of the message the user receives when the badge is issued'),
                            'messagesubject' => new external_value(PARAM_RAW, 'Subject of the message the user receives when the badge is issued'),
                            'attachement' => new external_value(PARAM_INT, 'Whether to attach badge to message', VALUE_OPTIONAL),
                            'notification' => new external_value(PARAM_INT, 'Whether to notify issuer when badge is awarded'. VALUE_OPTIONAL),
                            'status' => new external_value(PARAM_INT, 'Status of the badge (0: inactive, 1: active, 2: inactive locked, 3: active locked, 4: archived)'),
                            'nextcron' => new external_value(PARAM_INT, 'Next cron'),
                            'version' => new external_value(PARAM_RAW, 'Version of the badge', VALUE_OPTIONAL),
                            'language' => new external_value(PARAM_RAW, 'Language of the badge'),
                            'imageauthorname' => new external_value(PARAM_RAW, 'Name of the image author', VALUE_OPTIONAL),
                            'imageauthoremail' => new external_value(PARAM_RAW, 'Email of the image author', VALUE_OPTIONAL),
                            'imageauthorurl' => new external_value(PARAM_RAW, 'URL of the image author', VALUE_OPTIONAL),
                            'imagecaption' => new external_value(PARAM_RAW, 'Caption of the image', VALUE_OPTIONAL)
                        )
                    )
                ),
                'warnings' => new external_warnings(),
            )
        );
    }
    /**
     * Describes the parameters for get_user_badges.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function get_user_badges_parameters() {
        return new external_function_parameters (
            array(
                'userid' => new external_value(PARAM_INT, 'Badges only for this user id, empty for current user', VALUE_DEFAULT, 0),
                'courseid' => new external_value(PARAM_INT, 'Filter badges by course id, empty all the courses', VALUE_DEFAULT, 0),
                'page' => new external_value(PARAM_INT, 'The page of records to return.', VALUE_DEFAULT, 0),
                'perpage' => new external_value(PARAM_INT, 'The number of records to return per page', VALUE_DEFAULT, 0),
                'search' => new external_value(PARAM_RAW, 'A simple string to search for', VALUE_DEFAULT, ''),
                'onlypublic' => new external_value(PARAM_BOOL, 'Whether to return only public badges', VALUE_DEFAULT, false),
            )
        );
    }

    /**
     * Returns the list of badges awarded to a user.
     *
     * @param int $userid       user id
     * @param int $courseid     course id
     * @param int $page         page of records to return
     * @param int $perpage      number of records to return per page
     * @param string  $search   a simple string to search for
     * @param bool $onlypublic  whether to return only public badges
     * @return array array containing warnings and the awarded badges
     * @since  Moodle 3.1
     * @throws moodle_exception
     */
    public static function get_user_badges($userid = 0, $courseid = 0, $page = 0, $perpage = 0, $search = '', $onlypublic = false) {
        global $CFG, $USER, $PAGE;

        $warnings = array();

        $params = array(
            'userid' => $userid,
            'courseid' => $courseid,
            'page' => $page,
            'perpage' => $perpage,
            'search' => $search,
            'onlypublic' => $onlypublic,
        );
        $params = self::validate_parameters(self::get_user_badges_parameters(), $params);

        if (empty($CFG->enablebadges)) {
            throw new moodle_exception('badgesdisabled', 'badges');
        }

        if (empty($CFG->badges_allowcoursebadges) && $params['courseid'] != 0) {
            throw new moodle_exception('coursebadgesdisabled', 'badges');
        }

        // Default value for userid.
        if (empty($params['userid'])) {
            $params['userid'] = $USER->id;
        }

        // Validate the user.
        $user = core_user::get_user($params['userid'], '*', MUST_EXIST);
        core_user::require_active_user($user);

        $usercontext = context_user::instance($user->id);
        self::validate_context($usercontext);

        if ($USER->id != $user->id) {
            require_capability('moodle/badges:viewotherbadges', $usercontext);
            // We are looking other user's badges, we must retrieve only public badges.
            $params['onlypublic'] = true;
        }

        $userbadges = badges_get_user_badges($user->id, $params['courseid'], $params['page'], $params['perpage'], $params['search'],
                                                $params['onlypublic']);

        $result = array();
        $result['badges'] = array();
        $result['warnings'] = $warnings;

        foreach ($userbadges as $badge) {
            $context = ($badge->type == BADGE_TYPE_SITE) ? context_system::instance() : context_course::instance($badge->courseid);
            $canconfiguredetails = has_capability('moodle/badges:configuredetails', $context);

            // If the user is viewing another user's badge and doesn't have the right capability return only part of the data.
            if ($USER->id != $user->id and !$canconfiguredetails) {
                $badge = (object) array(
                    'id' => $badge->id,
                    'name' => $badge->name,
                    'description' => $badge->description,
                    'issuername' => $badge->issuername,
                    'issuerurl' => $badge->issuerurl,
                    'issuercontact' => $badge->issuercontact,
                    'uniquehash' => $badge->uniquehash,
                    'dateissued' => $badge->dateissued,
                    'dateexpire' => $badge->dateexpire,
                    'version' => $badge->version,
                    'language' => $badge->language,
                    'imageauthorname' => $badge->imageauthorname,
                    'imageauthoremail' => $badge->imageauthoremail,
                    'imageauthorurl' => $badge->imageauthorurl,
                    'imagecaption' => $badge->imagecaption,
                );
            }

            // Create a badge instance to be able to get the endorsement and other info.
            $badgeinstance = new badge($badge->id);
            $endorsement = $badgeinstance->get_endorsement();
            $alignments = $badgeinstance->get_alignments();
            $relatedbadges = $badgeinstance->get_related_badges();

            if (!$canconfiguredetails) {
                // Return only the properties visible by the user.

                if (!empty($alignments)) {
                    foreach ($alignments as $alignment) {
                        unset($alignment->targetdescription);
                        unset($alignment->targetframework);
                        unset($alignment->targetcode);
                    }
                }

                if (!empty($relatedbadges)) {
                    foreach ($relatedbadges as $relatedbadge) {
                        unset($relatedbadge->version);
                        unset($relatedbadge->language);
                        unset($relatedbadge->type);
                    }
                }
            }

            $related = array(
                'context' => $context,
                'endorsement' => $endorsement ? $endorsement : null,
                'alignment' => $alignments,
                'relatedbadges' => $relatedbadges,
            );

            $exporter = new user_badge_exporter($badge, $related);
            $result['badges'][] = $exporter->export($PAGE->get_renderer('core'));
        }

        return $result;
    }

    /**
     * Describes the get_user_badges return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function get_user_badges_returns() {
        return new external_single_structure(
            array(
                'badges' => new external_multiple_structure(
                    user_badge_exporter::get_read_structure()
                ),
                'warnings' => new external_warnings(),
            )
        );
    }
}
