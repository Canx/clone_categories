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
 * This script allows to clone grade category from a course to other.
 *
 * @package    core
 * @subpackage cli
 * @copyright  2017 Ruben Cancho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', 1);

require(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->libdir.'/grade/constants.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->libdir.'/grade/grade_category.php');
require_once($CFG->libdir.'/grade/grade_item.php');


class cloned_grade_category extends grade_category {
    public $orig_category;
    
    public function __construct($params, $fetch=true) {
        if ($params == null) {
            die("falta objeto en constructor");
        }
        if (is_a($params, 'grade_category')) {
            $this->orig_category = $params;
        }
        else {
            print_error($params);
            exit;
        }
        
        parent::__construct($params, $fetch);
    }
    
    public function insert($source=null) {
    
        if (empty($this->courseid)) {
            print_error('cannotinsertgrade');
        }
    
        if (empty($this->parent)) {
            $course_category = grade_category::fetch_course_category($this->courseid);
            $this->parent = $course_category->id;
        }
    
        $this->path = null;
    
        $this->timecreated = $this->timemodified = time();
    
        if (!grade_object::insert($source)) {
            debugging("Could not insert this category: " . print_r($this, true));
            return false;
        }
    
        // TODO: copy grade_item from $orig_category to $this.
        $grade_item = $this->orig_category->load_grade_item();
        $grade_item->courseid = $this->courseid;
        //$grade_item->categoryid = $this->id;
        $grade_item->iteminstance = $this->id;
        $grade_item->update();
        
        $this->force_regrading();
    
        // build path and depth
        $this->update($source);
    
        return $this->id;
    }
    
    public function force_regrading() {
        $grade_item = $this->load_grade_item();
        
        if ($grade_item != null) {
            $grade_item->force_regrading();
        }
    }

}

/**** MAIN ******/

// TODO: add args
// TODO: check if destination has currently grade categories

$origincourseid = 2;
$destinationcourseid = 3;

$q = new SplQueue();
$s = new SplObjectStorage();

$cat_array = grade_category::fetch_all(['courseid'=>$origincourseid]);

$dest_root =  grade_category::fetch_course_category($destinationcourseid);

/* get root element */
foreach($cat_array as $element) {
    if ($element->parent == null) {
        $root = $element;
    }
}

if ($root == null) {
    print "No root category found";
    return;
}

$s->attach($root);
$q->enqueue($root);

$dest_current = $dest_root;

while(!$q->isEmpty()) {
    $orig_current = $q->dequeue();

    // process current
    print "copying " . $orig_current->fullname . "..." . PHP_EOL;

    // update parent for new node.
    if ($orig_current->parent != null) {
        $cloned_current = new cloned_grade_category($orig_current, false);
        $cloned_current->courseid = $destinationcourseid;
        $cloned_current->parent = $equivalence[$orig_current->parent];
        $cloned_current->id = null;
        grade_category::build_path($cloned_current);
        $cloned_current->insert();

    }
    else {
        $cloned_current = $dest_current;
    }

    
    // TODO: check insert.
    $equivalence[$orig_current->id] = $cloned_current->id;
    
    $children = get_children($orig_current, $cat_array);
    foreach($children as $child) {
        if (is_a($child, 'grade_category')) {
            if (!$s->contains($child)) {
                $s->attach($child);
                $q->enqueue($child);
            }
        }
    }
}

function get_children($category, $array) {
    $children = [];
    
    foreach($array as $element) {
        if ($element->parent == $category->id) {
            $children[] = $element;
        }
    }
    return $children;
}

function print_categories($array) {
    foreach($array as $element) {
        echo $element->fullname . PHP_EOL;
    }
}

function insert($category, $source=null) {

    if (empty($category->courseid)) {
        print_error('cannotinsertgrade');
    }

    if (empty($category->parent)) {
        $course_category = grade_category::fetch_course_category($category->courseid);
        $category->parent = $course_category->id;
    }

    $category->path = null;

    $category->timecreated = $category->timemodified = time();

    if (!parent::insert($source)) {
        debugging("Could not insert this category: " . print_r($category, true));
        return false;
    }

    //$this->force_regrading();

    // build path and depth
    $category->update($source);

    return $category->id;
}
