<?php
require('../../config.php');
require_login();
require_capability('local/mikacustomreport:view', context_system::instance());

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
global $DB;

$draw = intval($data['draw'] ?? 1);
$start = intval($data['start'] ?? 0);
$length = intval($data['length'] ?? 10);

$fieldmaps = [
    'user' => [
        'username' => 'u.username',
        'email' => 'u.email',
        'firstname' => 'u.firstname',
        'lastname' => 'u.lastname',
        'timespent' => '(u.lastaccess - u.firstaccess) AS timespent',
        'start' => "(SELECT data FROM {user_info_data} d JOIN {user_info_field} f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = 'start') AS start",
        'bolum' => "(SELECT data FROM {user_info_data} d JOIN {user_info_field} f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = 'bolum') AS bolum",
        'end' => "(SELECT data FROM {user_info_data} d JOIN {user_info_field} f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = 'end') AS end",
        'department' => 'u.department',
        'position' => "(SELECT data FROM {user_info_data} d JOIN {user_info_field} f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = 'position') AS position",
        'institution' => 'u.institution',
        'address' => 'u.address',
        'city' => 'u.city'
    ],
    'activity' => [
        'activityname' => 'c.fullname AS activityname',
        'category' => 'cc.name AS category',
        'registrationdate' => 'ue.timecreated AS registrationdate',
        'progress' => 'COALESCE(ccmp.progress, 0) AS progress',
        'completionstatus' => 'CASE WHEN ccmp.timecompleted IS NOT NULL THEN 1 ELSE 0 END AS completionstatus',
        'activitiescompleted' => '(SELECT COUNT(*) FROM {course_modules_completion} cmc WHERE cmc.userid = u.id AND cmc.completionstate = 1 AND cmc.course = c.id) AS activitiescompleted',
        'totalactivities' => '(SELECT COUNT(*) FROM {course_modules} cm WHERE cm.course = c.id) AS totalactivities',
        'completiontime' => '(ccmp.timecompleted - ue.timecreated) AS completiontime',
        'activitytimespent' => '(SELECT SUM(l.duration) FROM {logstore_standard_log} l WHERE l.userid = u.id AND l.courseid = c.id) AS activitytimespent',
        'startdate' => 'c.startdate',
        'enddate' => 'c.enddate',
        'format' => 'c.format',
        'completionenabled' => 'c.enablecompletion',
        'guestaccess' => '(SELECT COUNT(*) FROM {enrol} e2 WHERE e2.courseid = c.id AND e2.enrol = "guest") AS guestaccess'
    ]
];

$selects = [];
$joins = [];
$where = "1=1";
$params = [];
$from = '';

$hasUserFields = !empty($data['user']);
$hasActivityFields = !empty($data['activity']);

if ($hasUserFields && !$hasActivityFields) {
    $from = '{user} u';
    foreach ($data['user'] as $field) {
        if (isset($fieldmaps['user'][$field])) {
            $selects[] = $fieldmaps['user'][$field];
        }
    }
    if (!in_array('u.id', $selects)) {
        $selects[] = 'u.id';
    }
    $where .= " AND u.deleted = 0 AND u.suspended = 0";
}
elseif (!$hasUserFields && $hasActivityFields) {
    $from = '{course} c';
    foreach ($data['activity'] as $field) {
        if (isset($fieldmaps['activity'][$field])) {
            $selects[] = $fieldmaps['activity'][$field];
        }
    }
    if (!in_array('c.id', $selects)) {
        $selects[] = 'c.id';
    }
} else {
    $from = '{user} u';
    $joins[] = "JOIN {user_enrolments} ue ON ue.userid = u.id";
    $joins[] = "JOIN {enrol} e ON e.id = ue.enrolid";
    $joins[] = "JOIN {course} c ON c.id = e.courseid";
    $joins[] = "LEFT JOIN {course_categories} cc ON cc.id = c.category";
    $joins[] = "LEFT JOIN {course_completions} ccmp ON ccmp.userid = u.id AND ccmp.course = c.id";

    foreach ($data['user'] as $field) {
        if (isset($fieldmaps['user'][$field])) {
            $selects[] = $fieldmaps['user'][$field];
        }
    }
    foreach ($data['activity'] as $field) {
        if (isset($fieldmaps['activity'][$field])) {
            $selects[] = $fieldmaps['activity'][$field];
        }
    }
    if (!in_array('u.id', $selects)) {
        $selects[] = 'u.id';
    }
    $where .= " AND u.deleted = 0 AND u.suspended = 0";
}

if (empty($selects)) {
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => []
    ]);
    exit;
}

$sql = "SELECT " . implode(", ", $selects) . " FROM $from ";
if (!empty($joins)) {
    $sql .= implode(" ", $joins);
}
$sql .= " WHERE $where ORDER BY ".($hasUserFields ? "u.id" : "c.id")." DESC";

$totalcount = 1000; // Dummy
$records = $DB->get_records_sql($sql, $params, $start, $length);

$output = [];
foreach ($records as $row) {
    $output[] = (array)$row;
}

echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $totalcount,
    'recordsFiltered' => $totalcount,
    'data' => $output,
]);