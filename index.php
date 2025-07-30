<?php
require('../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/mikacustomreport:view', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/mikacustomreport/index.php'));
$PAGE->set_title(get_string('pluginname', 'local_mikacustomreport'));
$PAGE->set_heading(get_string('pluginname', 'local_mikacustomreport'));

echo $OUTPUT->header();
echo html_writer::tag('h2', get_string('pluginname', 'local_mikacustomreport'));
echo $OUTPUT->footer();
