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



// Debug için error handling ekle
try {
    $draw = intval($data['draw'] ?? 1);
    $start = intval($data['start'] ?? 0);
    $length = intval($data['length'] ?? 10);

    // Field mappings - user.name gibi özel fieldlar için
    $fieldmaps = [
        'user' => [
            'username' => 'u.username',
            'email' => 'u.email',
            'firstname' => 'u.firstname',
            'lastname' => 'u.lastname',
            'timespent' => 'SEC_TO_TIME(u.lastaccess - u.firstaccess) AS timespent',
            'start' => "(SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = 'start') AS start",
            'bolum' => "(SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = 'bolum') AS bolum",
            'end' => "(SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = 'end') AS end",
            'department' => 'u.department',
            'position' => "(SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = 'position') AS position",
            'institution' => 'u.institution',
            'address' => 'u.address',
            'city' => 'u.city',
            'lastaccess' => 'u.lastaccess',
            'firstaccess' => 'u.firstaccess',
            'timecreated' => 'u.timecreated'
        ],
        'activity' => [
            'activityname' => 'c.fullname AS activityname',
            'name' => 'c.fullname AS name', // course.name için
            'fullname' => 'c.fullname AS fullname',
            'shortname' => 'c.shortname',
            'category' => 'cc.name AS category',
            'registrationdate' => 'ue.timecreated AS registrationdate',
            'progress' => 'COALESCE(ccmp.progress, 0) AS progress',
            'completionstatus' => 'CASE WHEN ccmp.timecompleted IS NOT NULL THEN "Completed" ELSE "Not Completed" END AS completionstatus',
            'activitiescompleted' => '(SELECT COUNT(*) FROM cbd_course_modules_completion cmc WHERE cmc.userid = u.id AND cmc.completionstate = 1 AND cmc.course = c.id) AS activitiescompleted',
            'totalactivities' => '(SELECT COUNT(*) FROM cbd_course_modules cm WHERE cm.course = c.id) AS totalactivities',
            'completiontime' => 'SEC_TO_TIME(ccmp.timecompleted - ue.timecreated) AS completiontime',
            'activitytimespent' => '(SELECT SEC_TO_TIME(COALESCE(logsure.total_time, 0)) FROM (SELECT t.userid, t.courseid, SUM(LEAST(t.diff, 1800)) AS total_time FROM (SELECT userid, courseid, LEAD(timecreated) OVER (PARTITION BY userid, courseid ORDER BY timecreated) - timecreated AS diff FROM cbd_logstore_standard_log WHERE action="viewed" AND target="course") AS t WHERE t.diff > 0 GROUP BY t.userid, t.courseid) AS logsure WHERE logsure.userid = u.id AND logsure.courseid = c.id) AS activitytimespent',
            'startdate' => 'c.startdate',
            'enddate' => 'c.enddate',
            'format' => 'c.format',
            'completionenabled' => 'c.enablecompletion',
            'guestaccess' => '(SELECT COUNT(*) FROM cbd_enrol e2 WHERE e2.courseid = c.id AND e2.enrol = "guest") AS guestaccess'
        ]
    ];

    $selects = [];
    $joins = "";
    $where = "u.deleted = 0 AND u.suspended = 0";
    $params = [];
    $from = 'cbd_user u';

    // Hangi fieldlar seçilmiş kontrol et
    $hasUserFields = !empty($data['user']) && is_array($data['user']);
    $hasActivityFields = !empty($data['activity']) && is_array($data['activity']);

    // User fields ekle
    if ($hasUserFields) {
        foreach ($data['user'] as $field) {
            if (isset($fieldmaps['user'][$field])) {
                $selects[] = $fieldmaps['user'][$field];
            }
        }
    }
   
    // Activity fields ekle - JOIN gerekiyorsa ekle
    if ($hasActivityFields) {
        $joins = " JOIN cbd_user_enrolments ue ON ue.userid = u.id";
        $joins .= " JOIN cbd_enrol e ON e.id = ue.enrolid";
        $joins .= " JOIN cbd_course c ON c.id = e.courseid";
        $joins .= " LEFT JOIN cbd_course_categories cc ON cc.id = c.category";
        $joins .= " LEFT JOIN cbd_course_completions ccmp ON ccmp.userid = u.id AND ccmp.course = c.id";

        foreach ($data['activity'] as $field) {
            if (isset($fieldmaps['activity'][$field])) {
                $selects[] = $fieldmaps['activity'][$field];
            }
        }
    }

    // Hiç field seçilmemişse default
    if (empty($selects)) {
        $selects[] = 'u.username';
        $selects[] = 'u.email';
    }

    // SQL oluştur
    $sql = "SELECT " . implode(", ", $selects) . " FROM $from$joins WHERE $where";
    
    // Sıralama - activity varsa karışık sıralama, yoksa user sıralaması
    if ($hasActivityFields) {
        $sql .= " ORDER BY u.id DESC, c.id DESC";
    } else {
        $sql .= " ORDER BY u.id DESC";
    }

    // Debug için SQL'i logla
    error_log("Custom Report SQL: " . $sql);

    // Count hesapla
    // Doğrudan SQL ile sayım yap
    $totalcount = $DB->count_records_sql("SELECT COUNT(*) FROM cbd_user WHERE deleted = 0 AND suspended = 0");
    
    // Filtered count - aynı WHERE condition ile
    $countSql = "SELECT COUNT(DISTINCT u.id) FROM $from$joins WHERE $where";
    $filteredcount = $DB->count_records_sql($countSql, $params);

    
    // Veriyi çek
    $records = $DB->get_records_sql($sql, $params, $start, $length);
    
    // Output hazırla
    $output = [];
    foreach ($records as $record) {
        $row = [];
        foreach ($record as $key => $value) {
            // Timestamp alanlarını formatla
            if (in_array($key, ['timecreated', 'lastaccess', 'firstaccess', 'registrationdate', 'startdate', 'enddate']) && $value > 0) {
                $row[$key] = userdate($value);
            } else {
                $row[$key] = $value;
            }
        }
        $output[] = $row;
    }

    
    // Response
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $totalcount,
        'recordsFiltered' => $filteredcount,
        'data' => $output,
        'debug' => [
            'sql' => $sql,
            'hasUserFields' => $hasUserFields,
            'hasActivityFields' => $hasActivityFields,
            'selectedFields' => $selects,
            'recordCount' => count($records)
        ]
    ]);

} catch (Exception $e) {
    // Hata durumunda detaylı bilgi ver
    error_log("Custom Report Error: " . $e->getMessage());
    echo json_encode([
        'draw' => $draw ?? 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => $e->getMessage(),
        'debug' => [
            'line' => $e->getLine(),
            'file' => $e->getFile(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
}
?>