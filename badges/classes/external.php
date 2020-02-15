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
     * Describes the parameters for get_badge_users.
     *
     * @return external_function_parameters
     * @since Moodle 3.8
     */
    public static function get_badge_users_parameters() {
        return new external_function_parameters (
            array(
                'badgeids' => new external_value(PARAM_SEQUENCE,
                    'IDs of the badges of which users must have one', VALUE_DEFAULT, ''),
                'includeexpired' => new external_value(PARAM_BOOL,
                    'If true all users are returned, even if their badge is expired', VALUE_DEFAULT, false),
                'includesuspended' => new external_value(PARAM_BOOL,
                    'If true, include disabled users as well', VALUE_DEFAULT, false)
            )
        );
    }

    /**
     * Return a list of users which have earned the specified badge and match the specified criteria
     *
     * @param string $badgeids sequence of badge ids (12,33,...), if empty all badges will be considered (default: '')
     * @param bool $includeexpired should expired badges be included (default: false)
     * @param bool $includesuspended should suspended user be included (default: false)
     * @return array
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     * @since Moodle 3.8
     */
    public static function get_badge_users($badgeids = '', $includeexpired = false, $includesuspended = false) {
        global $CFG, $DB, $USER;

        $warnings = array();

        $params = array(
            'badgeids' => $badgeids,
            'includeexpired' => $includeexpired,
            'includesuspended' => $includesuspended
        );
        $params = self::validate_parameters(self::get_badge_users_parameters(), $params);

        $usercontext = context_user::instance($USER->id);
        self::validate_context($usercontext);
        require_capability('moodle/badges:viewotherbadges', $usercontext);

        if ($badgeids === '') {
            $badgeids = array();
            $records = $DB->get_records('badge', null, '', 'id');
            foreach ($records as $badge) {
                $badgeids[] = $badge->id;
            }
        } else {
            $badgeids = array_map('intval', explode(',', $badgeids));
        }

        if (empty($CFG->enablebadges)) {
            throw new moodle_exception('badgesdisabled', 'badges');
        }
        $result = array();
        foreach ($badgeids as $badgeid) {
            $now = time();
            $badge = null;
            if ($DB->record_exists('badge', ['id' => $badgeid])) {
                $record = $DB->get_record('badge', ['id' => $badgeid], 'id,name,status', MUST_EXIST);
                $badge = ['id' => $record->id,
                    'name' => $record->name,
                    'status' => $record->status];
            } else {
                throw new moodle_exception('badgeidnotvalid', 'badges');
            }

            $partresult = array();
            $partresult['badge'] = $badge;
            $partresult['users'] = array();
            $partresult['warnings'] = $warnings;

            $sql = 'SELECT u.*, bi.dateissued, bi.dateexpire
                FROM {badge_issued} bi
                JOIN {user} u
                    ON u.id=userid
                WHERE badgeid = ?';

            if (!$params['includeexpired']) {
                $sql = $sql . ' AND (bi.dateexpire IS NULL OR bi.dateexpire > ' . $now . ')';
            }
            if (!$params['includesuspended']) {
                $sql = $sql . ' AND u.suspended = 0';
            }
            $userrecords = $DB->get_records_sql($sql, [$badge['id']]);

            foreach ($userrecords as $user) {
                $item = [
                    'id' => $user->id,
                    'username' => $user->username,
                    'idnumber' => $user->idnumber,
                    'suspended' => $user->suspended,
                    'dateissued' => $user->dateissued,
                    'dateexpire' => $user->dateexpire];
                $partresult['users'][] = $item;
            }
            $result[] = $partresult;
        }
        return $result;
    }

    /**
     * Describes the get_badge_users return value.
     *
     * @return external_multiple_structure
     * @since Moodle 3.8
     */
    public static function get_badge_users_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'badge' => new external_single_structure(
                        array(
                            'id' => new external_value(PARAM_INT, 'id of the badge'),
                            'name' => new external_value(PARAM_RAW, 'name of the badge'),
                            'status' => new external_value(PARAM_INT, 'status of the badge')
                        )
                    ),
                    'users' => new external_multiple_structure(
                        new external_single_structure(
                            array(
                                'id' => new external_value(PARAM_INT, 'ID of the user'),
                                'username' => new external_value(PARAM_RAW, 'username of the user'),
                                'idnumber' => new external_value(PARAM_RAW, 'idnumber of the user'),
                                'suspended' => new external_value(PARAM_BOOL, 'is the user suspended'),
                                'dateissued' => new external_value(PARAM_INT, 'time when the badge was issued to the student'),
                                'dateexpire' => new external_value(PARAM_INT, 'time when the badge will expire')
                            )
                        )
                    ),
                    'warnings' => new external_warnings(),
                )
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
