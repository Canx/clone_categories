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

// FIX: bug when changing from undelete to delete.
// TODO: copy scales.
// TODO: copy letters.
// TODO: extract traverse function to be reused.
// TODO: check if the courses exist.
// TODO: check if destination has currently grade categories
// TODO: option to keep current categories and attach them to the new root.

define('CLI_SCRIPT', 1);

require('../../../config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->libdir.'/grade/constants.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->libdir.'/grade/grade_category.php');
require_once($CFG->libdir.'/grade/grade_item.php');


class cloned_grade_category extends grade_category {
    public $orig_category;
    public static $new_root;
    public static $equivalence;
    public static $item_equivalence;

    public function __construct($params=NULL, $fetch=true) {
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

    public function insert($source=null, $root=false) {
        if (empty($this->courseid)) {
            print_error('cannotinsertgrade');
        }

        if (!$root) {
            // Add under root category if parent not specified.
            if (empty($this->parent)) {
                $course_category = grade_category::fetch_course_category($this->courseid);
                $this->parent = $course_category->id;
            }
        } else {
            // TODO: we must check if exists a current root!
            $this->parent = null;

        }

        $this->path = null;
        $this->timecreated = $this->timemodified = time();

        if (!grade_object::insert($source)) {
            debugging("Could not insert this category: " . print_r($this, true));
            return false;
        }

        // copy grade_item from $orig_category to $this.
        $orig_grade_item = $this->orig_category->load_grade_item();

        //print "SORTORDER ORIG: " . $orig_grade_item->sortorder;
        $grade_item = new grade_item($orig_grade_item);
        $grade_item->courseid = $this->courseid;
        $grade_item->id = null;
        $grade_item->iteminstance = $this->id;
        $grade_item->insert();

        // Save item equivalence
        self::$item_equivalence[$orig_grade_item->id] = $grade_item->id;

        // PATCH to update sortorder correctly...
        $grade_item->sortorder = $orig_grade_item->sortorder;
        $grade_item->update();

        //print "SORTORDER DEST: " . $grade_item->sortorder;
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

    public function delete($source=null) {
        grade_object::delete($source=null);
    }


    // Attach categoryid as a new children of the current category.
    public function attach($category) {
        // first, old root grade_items must be changed to category type!
        $grade_item = $category->load_grade_item();
        $grade_item->itemtype = 'category';
        $grade_item->update();

        // Old root need to change parent
        $category->parent = $this->id;
        $category->fullname = "Old course root";

        grade_category::build_path($category);
        $category->set_hidden(true, true);
        $category->update();

        // TODO: update sortorder
    }

    public static function clone_tree($origincourseid, $destinationcourseid, $deletetree = false) {
        if (!is_numeric($origincourseid)) {
            die("origin courseid parameter is not an integer.\n");
        }

        if (!is_numeric($destinationcourseid)) {
            die("origin courseid parameter is not an integer.\n");
        }

        $q = new SplQueue();
        $s = new SplObjectStorage();

        // Clean cache
        grade_category::clean_record_set();

        $cat_array = grade_category::fetch_all(['courseid'=>$origincourseid]);

        $dest_root =  grade_category::fetch_course_category($destinationcourseid);

        if (!$cat_array) {
            die("No grade categories found for origin courseid. Maybe course doesn't exist?\n");
        }

        // get root element
        $root = null;
        foreach($cat_array as $element) {
            if ($element->parent == null) {
                $root = $element;
            }
        }

        if ($root == null) {
            die("No root category found for origin courseid.\n");
        }

        $s->attach($root);
        $q->enqueue($root);

        if ($deletetree) {
            cloned_grade_category::delete_tree($destinationcourseid);
        }

        while(!$q->isEmpty()) {
            $orig_current = $q->dequeue();

            // process current
            print "copying " . $orig_current->fullname . "..." . PHP_EOL;

            //print_number_categories($destinationcourseid);
            $cloned_current = new cloned_grade_category($orig_current, false);
            $cloned_current->courseid = $destinationcourseid;
            $cloned_current->id = null;
            $cloned_current->children = null;

            // update parent for new node.
            if ($orig_current->parent == null) {
                $cloned_current->itemtype = 'course';
                $cloned_current->parent = null;
                //$cloned_current->insert_root();
                $cloned_current->insert(null, true);
                self::$new_root = $cloned_current;
            }
            else {
                $cloned_current->itemtype = 'category';
                $cloned_current->parent = self::$equivalence[$orig_current->parent];
                grade_category::build_path($cloned_current);
                //$cloned_current->insert();
                $cloned_current->insert();
            }

            // TODO: check insert.
            self::$equivalence[$orig_current->id] = $cloned_current->id;

            $children = cloned_grade_category::get_static_children($orig_current, $cat_array);
            foreach($children as $child) {
                if (is_a($child, 'grade_category')) {
                    if (!$s->contains($child)) {
                        $s->attach($child);
                        $q->enqueue($child);
                    }
                }
            }
        }

        // attach old root to current one and hide it.
        if (!$deletetree) {
            self::$new_root->attach($dest_root);
        }

        // Fix formula ids
        self::fix_formula_ids($destinationcourseid);

        // Copy grade letters
        self::copy_letters($origincourseid, $destinationcourseid);

        // Clean cache
        grade_category::clean_record_set();

    }

    static function print_number_categories($courseid) {
        $temp_categories = grade_object::fetch_all_helper("grade_categories", "grade_category", ['courseid' => $courseid]);
        if ($temp_categories) {
            print "CATEGORIAS:" . sizeof($temp_categories);
        }
        else {
            print "CATEGORIAS: 0";
        }
    }

    static function print_number_items($courseid) {
        $temp_items = grade_object::fetch_all_helper("grade_items", "grade_item", ['courseid' => $courseid]);
        if ($temp_items) {
            print "ITEMS:" . sizeof($temp_items);
        }
        else {
            print "ITEMS: 0";
        }
    }

    static function delete_tree($courseid) {
        $categories = grade_category::fetch_all(['courseid'=>$courseid]);

        if ($categories) {
            foreach($categories as $cat) {
                // delete grade category and related grade items.

                $cat->delete();
            }
        }
    }

    static function get_static_children($category, $array) {
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

    static function fix_formula_ids($courseid) {
        $items = grade_object::fetch_all_helper("grade_items", "grade_item", ['courseid' => $courseid]);
        if ($items) {
            foreach($items as $item) {
                if ($item->calculation) {
                    if (preg_match_all('/##gi(\d+)##/', $item->calculation, $matches)) {
                        foreach ($matches[1] as $id) {
                            if (isset(self::$item_equivalence[$id])) {
                                $item->calculation = str_replace('##gi'.$id.'##', '##gi'. self::$item_equivalence[$id] .'##', $item->calculation);
                            }
                        }
                        $item->update();
                    }
                }
            }
        }
    }


    // find contextid related to course to filter letters.
    // and then change to the new contextid.
    static function copy_letters($origincourseid, $destinationcourseid) {
        global $DB;

        $origincontext = context_course::instance($origincourseid);
        $destinationcontext = context_course::instance($destinationcourseid);

        $records = $DB->get_records('grade_letters', array('contextid'=>$origincontext->id), 'lowerboundary DESC');

        foreach ($records as $record) {
            $record->contextid = $destinationcontext->id;
            $record->id = null;
            $DB->insert_record('grade_letters', $record);
        }
    }
}

/**** MAIN ******/

$origincourseid = null;
$destionationcourseid = null;

if (!defined('STDIN')) {
  die("script not called from command line.\n");
}

if ($argc !== 3) {
  die("need 2 arguments: origin courseid and destination courseid.\n");
}

$origincourseid = $argv[1];
$destinationcourseid = $argv[2];

cloned_grade_category::clone_tree($origincourseid, $destinationcourseid, true);
