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

// TODO: check if the courses exist.
// TODO: check if destination has currently grade categories
// TODO: option to keep current categories and attach them to the new root.
// TODO: copy outcomes if needed.

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
    public static $letter_equivalence;
    public static $scale_equivalence;

    public function __construct($params=NULL, $fetch=true) {
        if ($params == null) {
            die("need object in cloned_grade_category constructor.");
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

    // TODO: refactor get_children as a parameter
    public function traverse(callable $action) {
        $q = new SplQueue();
        $s = new SplObjectStorage();

        $s->attach($this);
        $q->enqueue($this);

        while(!$q->isEmpty()) {
            $current = $q->dequeue();

            $children = $current->get_children_categories();

            // call function with current element
            $action($current);

            foreach($children as $child) {
                if (!$s->contains($child)) {
                    $s->attach($child);
                    $q->enqueue($child);
                }
            }
        }
    }

    public static function clone_tree($origincourseid, $destinationcourseid) {
        // delete grades from destination first.
        self::delete_grade_tree($destinationcourseid);

        // copy scales and letters
        self::copy_letters($origincourseid, $destinationcourseid);
        self::copy_scales($origincourseid, $destinationcourseid);

        // copy grades and items
        self::copy_grades($origincourseid, $destinationcourseid);

        // clone manual grade items
        self::copy_manual_items($origincourseid, $destinationcourseid);

        // Fix formula ids in grade items copied
        self::fix_formula_ids($destinationcourseid);

        // Fix scale ids in grade items copied
        self::fix_scales($destinationcourseid);
    }

    // copy grade categories and grade items
    public static function copy_grades($origincourseid, $destinationcourseid) {
        $cloned_root =  cloned_grade_category::fetch_course_category($origincourseid);

        $cloned_root->traverse(function($category) use($destinationcourseid){
            print "copying " . $category->fullname . "..." . PHP_EOL;

            $category->courseid = $destinationcourseid;
            $oldcategoryid = $category->id;
            $category->id = null;
            $category->children = null;

            // update parent for new node.
            if ($category->parent == null) {
                $category->insert(null, true);
                self::$new_root = $category;
            }
            else {
                $category->parent = self::$equivalence[$category->parent];
                // igual no es necesario el build path...
                grade_category::build_path($category);
                $category->insert();
            }

            self::$equivalence[$oldcategoryid] = $category->id;
        });
    }

    public static function copy_manual_items($origincourseid, $destinationcourseid) {
        $cloned_root =  cloned_grade_category::fetch_course_category($origincourseid);

        $cloned_root->traverse(function($category) use($destinationcourseid){
            $items = $category->get_children_manual_items();

            foreach($items as $item) {
                $old_categoryid = $item->categoryid;
                $item->id = null;
                $item->courseid = $destinationcourseid;
                $item->categoryid = self::$equivalence[$old_categoryid];

                $newitem = new grade_item($item);
                $newitem->insert();
            }
        });
    }

    public function get_children_categories() {
        global $DB;

        $children =  $DB->get_records('grade_categories', ['parent' => $this->id]);

        $categories = [];
        foreach($children as $child) {
            $categories[] = new cloned_grade_category(new grade_category($child, false));
        }

        return $categories;
    }

    public function get_children_manual_items() {
        global $DB;

        $children = $DB->get_records('grade_items', ['categoryid' => $this->id, 'itemtype' => 'manual']);

        $items = [];
        foreach($children as $child) {
            $items[] = new grade_item($child);
        }

        return $items;
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

    static function delete_grade_tree($courseid) {
        $categories = grade_category::fetch_all(['courseid'=>$courseid]);

        if ($categories != null) {
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
        // TODO: use get_grade_item?
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

    static function fix_scales($destinationcourseid) {
        $cloned_root =  cloned_grade_category::fetch_course_category($destinationcourseid);

        $cloned_root->traverse(function($category) use($destinationcourseid) {
            // fix grade item
            $gradeitem = $category->get_grade_item();
            self::fix_scale($gradeitem);

            // fix manual grade items
            $manualgradeitems = $category->get_children_manual_items();

            foreach($manualgradeitems as $item)
                self::fix_scale($item);
        });
    }

    static function fix_scale($item) {
        if (!empty($item->scaleid)) {
            $item->scaleid = self::$scale_equivalence[$item->scaleid];
            $item->update();
        }
    }

    // find contextid related to course to filter letters.
    // and then change to the new contextid.
    static function copy_letters($origincourseid, $destinationcourseid) {
        global $DB;

        $origincontext = context_course::instance($origincourseid);
        $destinationcontext = context_course::instance($destinationcourseid);

        $DB->delete_records('grade_letters', array('contextid'=>$destinationcontext->id));

        $records = $DB->get_records('grade_letters', array('contextid'=>$origincontext->id), 'lowerboundary DESC');

        foreach ($records as $record) {
            $record->contextid = $destinationcontext->id;
            $record_id = $record->id;
            $record->id = null;
            $newrecord_id = $DB->insert_record('grade_letters', $record);
            self::$letter_equivalence[$record_id]= $newrecord_id;
        }
    }

    // copy course scales to destination course.
    static function copy_scales($origincourseid, $destinationcourseid) {
        global $DB;

        $DB->delete_records('scale', array('courseid'=>$destinationcourseid));

        $records = $DB->get_records('scale', ['courseid'=>$origincourseid]);

        foreach ($records as $record) {
            $record->courseid = $destinationcourseid;
            $record_id = $record->id;
            $record->id = null;
            $newrecord_id = $DB->insert_record('scale', $record);
            self::$scale_equivalence[$record_id] = $newrecord_id;

        }
    }

    public static function fetch_course_category($destinationcourseid) {
        $grade_category = parent::fetch_course_category($destinationcourseid);
        return new cloned_grade_category($grade_category);
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

cloned_grade_category::clone_tree($origincourseid, $destinationcourseid);
