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
 * Simple calculator.
 *
 * @package    peerworkcalculator_simplepa
 * @copyright  2019 Coventry University
 * @author     Frédéric Massart <fred@branchup.tech>
 * @copyright   2026 Concordia University
 * @author      Francisco Berrizbeitia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace peerworkcalculator_simplepa;

use mod_peerwork\pa_result;
use mod_peerwork\peerworkcalculator_plugin;

class calculator extends peerworkcalculator_plugin {

    /**
     * Get the name of the simplePA calculator plugin
     *
     * @return string
     */
    public function get_name() {
        return get_string('simplepa', 'peerworkcalculator_simplepa');
    }

    /**
     * Calculate.
     *
     * Returns the simple average of the grades
     *
     * @param array $grades The list of marks given.
     * @param int $groupmark The mark given to the group.
     * @param int $noncompletionpenalty The penalty to be applied.
     * @param int $paweighting The weighting to be applied.
     * @param bool $selfgrade If self grading is enabled.
     * @return pa_result.
     */
    public function calculate($grades, $groupmark, $noncompletionpenalty = 0, $paweighting = 1, $selfgrade = false) {
        $memberids = array_keys($grades);
        $totalscores = [];
        $fracscores = [];
        $numsubmitted = 0;

        

        // Calculate the total scores.
        foreach ($memberids as $memberid) {
            foreach ($grades as $graderid => $gradesgiven) {
                if (!isset($totalscores[$graderid])) {
                    $totalscores[$graderid] = [];
                }

                if (isset($gradesgiven[$memberid])) {
                    $sum = array_reduce($gradesgiven[$memberid], function($carry, $item) {
                        $carry += $item;
                        return $carry;
                    });

                    $totalscores[$graderid][$memberid] = $sum;
                    $number_of_criteria = count($gradesgiven[$memberid]);
                }
            }
        }

    //now that I have the total scores I should calculate the averages and use that for all the grades
        foreach ($memberids as $memberid) {
            $scoretotal = 0;
            $scorecount = 0;
           
            
            foreach ($totalscores as $graderid => $memberscores) {
                
                if (!$selfgrade && $graderid == $memberid) {
                    continue;
                }
                if (isset($memberscores[$memberid])) {
                    $scoretotal += $memberscores[$memberid];
                    $scorecount++;
                }
                
            }

            // Use the average received by the member for all subsequent grades.
            $average = $scorecount ? $scoretotal / ($scorecount*$number_of_criteria) : 0;
            
            $redscores[$memberid] = $average;
            $pascores[$memberid] = $average; // this maps to the contribution column
            $prelimgrades[$memberid] = $average * 20; // this maps to thge calculated grades
            $noncomplpenalties[$memberid] = ($average*20) * ($noncompletionpenalty);
            $grades[$memberid] = 
                ($average * 20)*($paweighting ) + 
                ($groupmark)*(1 - $paweighting) -
                $noncomplpenalties[$memberid]; // this maps to final weighted gtrades
            
        }



    /** 
    @var array The reduced scores. 
    protected $redscores;
     @var array The PA scores. 
    protected $pascores;
     @var array The preliminary grades. 
    protected $prelimgrades;
     @var array The final grades. 
    protected $grades;
     @var array The penalities. 
    protected $noncomplpenalties;
    */


        return new pa_result($redscores, $pascores, $prelimgrades, $grades, $noncomplpenalties);
    }

    /**
     * Function to return if calculation uses paweighting.
     *
     * @return bool
     */
    public static function usespaweighting() {
        return true;
    }
}
