<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Extends the main navigation.
 *
 * @param global_navigation $navigation The global navigation object.
 */
function local_mikacustomreport_extend_navigation(global_navigation $navigation) {
    global $CFG;

    if (isloggedin() && !isguestuser()) {
        // Add to custom menu items
        if (stripos($CFG->custommenuitems, "/local/mikacustomreport/") === false) {
            $nodes = explode("\n", $CFG->custommenuitems);
            $node = get_string('pluginname', 'local_mikacustomreport');
            $node .= "|";
            $node .= "/local/mikacustomreport/index.php";
            array_push($nodes, $node);
            $CFG->custommenuitems = implode("\n", $nodes);
        }
    }
}

