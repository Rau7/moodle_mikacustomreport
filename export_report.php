<?php
require('../../config.php');
require_login();
require_capability('local/mikacustomreport:view', context_system::instance());

// POST verilerini al
if (isset($_POST['data'])) {
    // Form submission'dan gelen data
    $data = json_decode($_POST['data'], true);
} else {
    // JSON body'den gelen data
    $data = json_decode(file_get_contents('php://input'), true);
}

if (!$data) {
    throw new Exception('No data received');
}

global $DB;

// Export formatını al (csv, excel, pdf)
$format = isset($data['format']) ? $data['format'] : 'csv';
$filename = 'mikacustomreport_' . date('Y-m-d_H-i-s');

try {
    // Hangi fieldlar seçilmiş kontrol et
    $hasUserFields = !empty($data['user']) && is_array($data['user']);
    $hasActivityFields = !empty($data['activity']) && is_array($data['activity']);
    
    // Date range parametrelerini al (activitytimespent için)
    $dateRange = isset($data['dateRange']) ? $data['dateRange'] : null;
    $hasDateRange = $dateRange !== null && !empty($dateRange['startDate']) && !empty($dateRange['endDate']);
    
    if ($hasDateRange) {
        error_log("Export date range detected: {$dateRange['startDate']} to {$dateRange['endDate']}");
    }

    // Field mappings - SQL ifadeleri (get_report_data.php'den kopyalandı)
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
            'name' => 'c.fullname AS name',
            'fullname' => 'c.fullname AS fullname',
            'shortname' => 'c.shortname',
            'category' => 'cc.name AS category',
            'registrationdate' => 'ue.timecreated AS registrationdate',
            'progress' => 'CASE 
                WHEN COALESCE(cstats.total_activities, 0) = 0 THEN 0
                ELSE ROUND(COALESCE(cstats.completed_activities, 0) * 100.0 / cstats.total_activities, 1)
            END AS progress',
            'completionstatus' => 'CASE WHEN ccmp.timecompleted IS NOT NULL THEN "Completed" ELSE "Not Completed" END AS completionstatus',
            'activitiescompleted' => 'COALESCE(cstats.completed_activities, 0) AS activitiescompleted',
            'totalactivities' => 'COALESCE(cstats.total_activities, 0) AS totalactivities',
            'completiontime' => 'SEC_TO_TIME(ccmp.timecompleted - ue.timecreated) AS completiontime',
            'activitytimespent' => 'SEC_TO_TIME(IFNULL(logsure.total_time, 0)) AS activitytimespent',
            'startdate' => 'c.startdate',
            'enddate' => 'c.enddate',
            'format' => 'c.format',
            'completionenabled' => 'c.enablecompletion',
            'guestaccess' => '(SELECT COUNT(*) FROM cbd_enrol e2 WHERE e2.courseid = c.id AND e2.enrol = "guest") AS guestaccess'
        ]
    ];

    // SELECT fieldları oluştur
    $selects = [];
    $headers = [];
    
    if ($hasUserFields) {
        foreach ($data['user'] as $field) {
            if (isset($fieldmaps['user'][$field])) {
                $selects[] = $fieldmaps['user'][$field];
                $headers[] = ucfirst(str_replace('_', ' ', $field));
            }
        }
    }
    
    if ($hasActivityFields) {
        foreach ($data['activity'] as $field) {
            if (isset($fieldmaps['activity'][$field])) {
                $selects[] = $fieldmaps['activity'][$field];
                $headers[] = ucfirst(str_replace('_', ' ', $field));
            }
        }
    }

    // Hiç field seçilmemişse default
    if (empty($selects)) {
        $selects[] = 'u.username';
        $selects[] = 'u.email';
        $headers = ['Username', 'Email'];
    }

    // SQL oluştur
    $from = 'cbd_user u';
    $joins = '';
    $where = 'u.deleted = 0';
    
    // Activity fields varsa JOIN ekle
    if ($hasActivityFields) {
        $joins = ' JOIN cbd_user_enrolments ue ON ue.userid = u.id';
        $joins .= ' JOIN cbd_enrol e ON e.id = ue.enrolid';
        $joins .= ' JOIN cbd_course c ON c.id = e.courseid';
        $joins .= ' LEFT JOIN cbd_course_categories cc ON cc.id = c.category';
        $joins .= ' LEFT JOIN cbd_course_completions ccmp ON ccmp.userid = u.id AND ccmp.course = c.id';
        
        // Performance optimized JOIN for completion statistics
        $joins .= ' LEFT JOIN (
            SELECT 
                u2.id as userid,
                c2.id as courseid,
                COUNT(CASE WHEN cmc.completionstate >= 1 THEN 1 END) as completed_activities,
                COUNT(cm.id) as total_activities
            FROM cbd_user u2
            JOIN cbd_user_enrolments ue2 ON ue2.userid = u2.id
            JOIN cbd_enrol e2 ON e2.id = ue2.enrolid
            JOIN cbd_course c2 ON c2.id = e2.courseid
            JOIN cbd_course_modules cm ON cm.course = c2.id AND cm.completion > 0
            LEFT JOIN cbd_course_modules_completion cmc ON cmc.coursemoduleid = cm.id AND cmc.userid = u2.id
            WHERE u2.deleted = 0
            GROUP BY u2.id, c2.id
        ) cstats ON cstats.userid = u.id AND cstats.courseid = c.id';
        
        // Optimized JOIN for activity time spent calculation
        $dateRangeCondition = '';
        if ($hasDateRange) {
            $startTimestamp = strtotime($dateRange['startDate']);
            $endTimestamp = strtotime($dateRange['endDate'] . ' 23:59:59'); // End of day
            $dateRangeCondition = " AND l.timecreated BETWEEN $startTimestamp AND $endTimestamp";
            error_log("Export date range condition: $dateRangeCondition");
        }
        
        $joins .= ' LEFT JOIN (
            SELECT 
                oturum.userid,
                oturum.courseid,
                SUM(LEAST(oturum.diff, 1800)) AS total_time
            FROM (
                SELECT
                    l.userid,
                    l.courseid,
                    l.timecreated,
                    CASE
                        WHEN LEAD(l.timecreated) OVER (PARTITION BY l.userid, l.courseid ORDER BY l.timecreated) IS NULL THEN 300
                        ELSE LEAD(l.timecreated) OVER (PARTITION BY l.userid, l.courseid ORDER BY l.timecreated) - l.timecreated
                    END AS diff
                FROM cbd_logstore_standard_log l
                WHERE l.courseid IS NOT NULL
                  AND l.action = "viewed"' . $dateRangeCondition . '
            ) AS oturum
            WHERE oturum.diff > 0 AND oturum.diff <= 1800
            GROUP BY oturum.userid, oturum.courseid
        ) logsure ON logsure.userid = u.id AND logsure.courseid = c.id';
    }

    // Arama filtresi (eğer varsa)
    $searchWhere = '';
    $searchParams = [];
    if (!empty($data['search'])) {
        $search = $data['search'];
        $searchConditions = [];
        $searchValue = '%' . $search . '%';
        
        if ($hasUserFields) {
            foreach ($data['user'] as $field) {
                if (isset($fieldmaps['user'][$field])) {
                    $fieldExpr = $fieldmaps['user'][$field];
                    if (strpos($fieldExpr, ' AS ') !== false) {
                        $fieldExpr = substr($fieldExpr, 0, strpos($fieldExpr, ' AS '));
                    }
                    $searchConditions[] = "LOWER($fieldExpr) LIKE LOWER(:search$field)";
                    $searchParams["search$field"] = $searchValue;
                }
            }
        }
        
        if ($hasActivityFields) {
            foreach ($data['activity'] as $field) {
                if (isset($fieldmaps['activity'][$field])) {
                    $fieldExpr = $fieldmaps['activity'][$field];
                    if (strpos($fieldExpr, ' AS ') !== false) {
                        $fieldExpr = substr($fieldExpr, 0, strpos($fieldExpr, ' AS '));
                    }
                    $searchConditions[] = "LOWER($fieldExpr) LIKE LOWER(:search$field)";
                    $searchParams["search$field"] = $searchValue;
                }
            }
        }
        
        if (!empty($searchConditions)) {
            $searchWhere = ' AND (' . implode(' OR ', $searchConditions) . ')';
        }
    }

    // Final SQL - TÜM VERİYİ ÇEK (LIMIT YOK!)
    $selectClause = implode(', ', $selects);
    $sql = "SELECT $selectClause FROM $from$joins WHERE $where$searchWhere";
    
    // Default sıralama
    if ($hasActivityFields) {
        $sql .= ' ORDER BY u.username, c.fullname';
    } else {
        $sql .= ' ORDER BY u.username';
    }

    // Format'a göre export et
    switch ($format) {
        case 'csv':
            exportCSV($sql, $searchParams, $headers, $filename);
            break;
        case 'excel':
            exportExcel($sql, $searchParams, $headers, $filename);
            break;
        case 'pdf':
            exportPDF($sql, $searchParams, $headers, $filename);
            break;
        default:
            exportCSV($sql, $searchParams, $headers, $filename);
    }

} catch (Exception $e) {
    error_log("Export Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function exportCSV($sql, $params, $headers, $filename) {
    global $DB;
    
    // CSV headers ayarla
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: max-age=0');
    
    // Output buffer'ı temizle
    ob_clean();
    
    // CSV writer oluştur
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM ekle (Excel için)
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header satırını yaz
    fputcsv($output, $headers);
    
    // Verileri streaming ile yaz
    $recordset = $DB->get_recordset_sql($sql, $params);
    foreach ($recordset as $record) {
        $row = [];
        foreach ($record as $key => $value) {
            // Timestamp alanlarını formatla
            if (in_array($key, ['timecreated', 'lastaccess', 'firstaccess', 'registrationdate', 'startdate', 'enddate']) && $value > 0) {
                $row[] = userdate($value);
            } else {
                $row[] = $value;
            }
        }
        fputcsv($output, $row);
    }
    
    $recordset->close();
    fclose($output);
    exit;
}

function exportExcel($sql, $params, $headers, $filename) {
    // Excel export için SimpleXLSXGen kullanabiliriz
    // Şimdilik CSV olarak export edelim
    exportCSV($sql, $params, $headers, $filename);
}

function exportPDF($sql, $params, $headers, $filename) {
    // PDF export için TCPDF kullanabiliriz  
    // Şimdilik CSV olarak export edelim
    exportCSV($sql, $params, $headers, $filename);
}
?>
