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
 * This file contains the parent class for questionnaire question types.
 *
 * @author Mike Churchward
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package questiontypes
 */

namespace mod_questionnaire\responsetype;
defined('MOODLE_INTERNAL') || die();

use mod_questionnaire\db\bulk_sql_config;
use mod_questionnaire\responsetype\answer\answer;

/**
 * Class for date response types.
 *
 * @author Mike Churchward
 * @package responsetypes
 */

class date extends responsetype {
    /**
     * @return string
     */
    static public function response_table() {
        return 'questionnaire_response_date';
    }

    /**
     * @param int|object $responsedata
     * @return bool|int
     * @throws \dml_exception
     */
    public function insert_response($responsedata) {
        global $DB;

        $val = isset($responsedata->{'q'.$this->question->id}) ? $responsedata->{'q'.$this->question->id} : '';
        $checkdateresult = questionnaire_check_date($val);
        $thisdate = $val;
        if (substr($checkdateresult, 0, 5) == 'wrong') {
            return false;
        }
        // Now use ISO date formatting.
        $checkdateresult = questionnaire_check_date($thisdate, true);

        $record = new \stdClass();
        $record->response_id = $responsedata->rid;
        $record->question_id = $this->question->id;
        $record->response = $checkdateresult;
        return $DB->insert_record(self::response_table(), $record);
    }

    /**
     * @param bool $rids
     * @param bool $anonymous
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function get_results($rids=false, $anonymous=false) {
        global $DB;

        $rsql = '';
        $params = array($this->question->id);
        if (!empty($rids)) {
            list($rsql, $rparams) = $DB->get_in_or_equal($rids);
            $params = array_merge($params, $rparams);
            $rsql = ' AND response_id ' . $rsql;
        }

        $sql = 'SELECT id, response ' .
               'FROM {'.self::response_table().'} ' .
               'WHERE question_id= ? ' . $rsql;

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Provide a template for results screen if defined.
     * @return mixed The template string or false/
     */
    public function results_template() {
        return 'mod_questionnaire/results_date';
    }

    /**
     * @param bool $rids
     * @param string $sort
     * @param bool $anonymous
     * @return string
     * @throws \coding_exception
     */
    public function display_results($rids=false, $sort='', $anonymous=false) {
        $numresps = count($rids);
        if ($rows = $this->get_results($rids, $anonymous)) {
            $numrespondents = count($rows);
            $counts = [];
            foreach ($rows as $row) {
                // Count identical answers (case insensitive).
                if (!empty($row->response)) {
                    $dateparts = preg_split('/-/', $row->response);
                    $text = make_timestamp($dateparts[0], $dateparts[1], $dateparts[2]); // Unix timestamp.
                    $textidx = clean_text($text);
                    $counts[$textidx] = !empty($counts[$textidx]) ? ($counts[$textidx] + 1) : 1;
                }
            }
            $pagetags = $this->get_results_tags($counts, $numresps, $numrespondents);
        } else {
            $pagetags = new \stdClass();
        }
        return $pagetags;
    }

    /**
     * Override the results tags function for templates for questions with dates.
     *
     * @param $weights
     * @param $participants Number of questionnaire participants.
     * @param $respondents Number of question respondents.
     * @param $showtotals
     * @param string $sort
     * @return \stdClass
     * @throws \coding_exception
     */
    public function get_results_tags($weights, $participants, $respondents, $showtotals = 1, $sort = '') {
        $dateformat = get_string('strfdate', 'questionnaire');

        $pagetags = new \stdClass();
        if ($respondents == 0) {
            return $pagetags;
        }

        if (!empty($weights) && is_array($weights)) {
            $pagetags->responses = [];
            $numresps = 0;
            ksort ($weights); // Sort dates into chronological order.
            foreach ($weights as $content => $num) {
                $response = new \stdClass();
                $response->text = userdate($content, $dateformat, '', false);    // Change timestamp into readable dates.
                $numresps += $num;
                $response->total = $num;
                $pagetags->responses[] = (object)['response' => $response];
            }

            if ($showtotals == 1) {
                $pagetags->total = new \stdClass();
                $pagetags->total->total = "$numresps/$participants";
            }
        }

        return $pagetags;
    }

    /**
     * Return an array of answers by question/choice for the given response. Must be implemented by the subclass.
     *
     * @param int $rid The response id.
     * @return array
     */
    static public function response_select($rid) {
        global $DB;

        $values = [];
        $sql = 'SELECT q.id, q.content, a.response as aresponse '.
            'FROM {'.self::response_table().'} a, {questionnaire_question} q '.
            'WHERE a.response_id=? AND a.question_id=q.id ';
        $records = $DB->get_records_sql($sql, [$rid]);
        $dateformat = get_string('strfdate', 'questionnaire');
        foreach ($records as $qid => $row) {
            unset ($row->id);
            $row = (array)$row;
            $newrow = array();
            foreach ($row as $key => $val) {
                if (!is_numeric($key)) {
                    $newrow[] = $val;
                    // Convert date from yyyy-mm-dd database format to actual questionnaire dateformat.
                    // does not work with dates prior to 1900 under Windows.
                    if (preg_match('/\d\d\d\d-\d\d-\d\d/', $val)) {
                        $dateparts = preg_split('/-/', $val);
                        $val = make_timestamp($dateparts[0], $dateparts[1], $dateparts[2]); // Unix timestamp.
                        $val = userdate ( $val, $dateformat);
                        $newrow[] = $val;
                    }
                }
            }
            $values["$qid"] = $newrow;
            $val = array_pop($values["$qid"]);
            array_push($values["$qid"], '', '', $val);
        }

        return $values;
    }

    /**
     * Return an array of answer objects by question for the given response id.
     * THIS SHOULD REPLACE response_select.
     *
     * @param int $rid The response id.
     * @return array array answer
     * @throws \dml_exception
     */
    static public function response_answers_by_question($rid) {
        global $DB;

        $answers = [];
        $sql = 'SELECT id, response_id as responseid, question_id as questionid, 0 as choiceid, response as value ' .
            'FROM {' . static::response_table() .'} ' .
            'WHERE response_id = ? ';
        $records = $DB->get_records_sql($sql, [$rid]);
        foreach ($records as $record) {
            $answers[$record->questionid][] = answer::create_from_data($record);
        }

        return $answers;
    }

    /**
     * Configure bulk sql
     * @return bulk_sql_config
     */
    protected function bulk_sql_config() {
        return new bulk_sql_config(self::response_table(), 'qrd', false, true, false);
    }
}