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
            'timespent' => 'IFNULL(user_time.toplam_sure, "00:00:00") AS timespent',
            'timespent_seconds' => 'IFNULL(TIME_TO_SEC(user_time.toplam_sure), 0) AS timespent_seconds',
            'start' => "(SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = 'start') AS start",
            'bolum' => "(SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = 'bolum') AS bolum",
            'end' => "(SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = 'end') AS end",
            'departman' => 'u.department AS departman',
            'position' => "(SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = 'position') AS position",
            'unvan' => 'u.institution AS unvan',
            'adres' => 'u.address AS adres',
            'birim' => 'u.city AS birim',
            'sicil' => 'u.phone1 AS sicil',
            'tc' => 'u.idnumber AS tc',
            'ceptelefonu' => 'u.phone2 AS ceptelefonu',
            'sitesongiris' => 'u.lastaccess AS sitesongiris',
            'siteilkgiris' => 'u.firstaccess AS siteilkgiris',
            'sitekayittarihi' => 'u.timecreated AS sitekayittarihi',
            'durum' => 'CASE WHEN u.suspended = 0 THEN "Aktif" ELSE "Pasif" END AS durum'
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
            'guestaccess' => '(SELECT COUNT(*) FROM cbd_enrol e2 WHERE e2.courseid = c.id AND e2.enrol = "guest") AS guestaccess',
            'kayityontemi' => 'CASE 
                WHEN e.enrol = "manual" THEN "El ile kayıt"
                WHEN e.enrol = "self" THEN "Kendi kendine kayıt"
                WHEN e.enrol = "autoenrol" THEN "Otomatik kayıt"
                WHEN e.enrol = "guest" THEN "Misafir erişim"
                WHEN e.enrol = "cohort" THEN "Grup kayıt"
                WHEN e.enrol = "database" THEN "Veritabanı kayıt"
                WHEN e.enrol = "ldap" THEN "LDAP kayıt"
                ELSE CONCAT("Diğer (", e.enrol, ")")
            END AS kayityontemi'
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
    
    // Add user_time JOIN if timespent field is selected
    if ($hasUserFields && in_array('timespent', $data['user'])) {
        $joins .= ' LEFT JOIN (
            SELECT
                logs.userid,
                SEC_TO_TIME(SUM(session_duration)) AS toplam_sure
            FROM (
                SELECT
                    userid,
                    timecreated,
                    LEAD(timecreated) OVER (PARTITION BY userid ORDER BY timecreated) - timecreated AS session_duration
                FROM cbd_logstore_standard_log
            ) AS logs
            WHERE session_duration > 0 AND session_duration <= 1800
            GROUP BY logs.userid
        ) user_time ON user_time.userid = u.id';
    }
    
    // Activity fields varsa JOIN ekle
    if ($hasActivityFields) {
        $joins .= ' JOIN cbd_user_enrolments ue ON ue.userid = u.id';
        $joins .= ' JOIN cbd_enrol e ON e.id = ue.enrolid';
        $joins .= ' JOIN cbd_course c ON c.id = e.courseid';
        $joins .= ' LEFT JOIN cbd_course_categories cc ON cc.id = c.category';
        $joins .= ' LEFT JOIN cbd_course_completions ccmp ON ccmp.userid = u.id AND ccmp.course = c.id';
        
        // User-provided completion statistics calculation (exact formula)
        if (in_array('progress', $data['activity']) || in_array('activitiescompleted', $data['activity']) || in_array('totalactivities', $data['activity'])) {
            $joins .= ' LEFT JOIN (
                SELECT 
                    cm.course as courseid,
                    cmc.userid,
                    SUM(CASE WHEN cmc.completionstate > 0 THEN 1 ELSE 0 END) as completed_activities,
                    SUM(CASE WHEN cm.completion > 0 THEN 1 ELSE 0 END) as total_activities
                FROM cbd_course_modules cm
                LEFT JOIN cbd_course_modules_completion cmc ON cmc.coursemoduleid = cm.id AND cmc.userid IS NOT NULL
                WHERE cm.deletioninprogress = 0
                GROUP BY cm.course, cmc.userid
            ) cstats ON cstats.userid = u.id AND cstats.courseid = c.id';
        }
        
        // Optimized JOIN for activity time spent calculation - only when needed
        if (in_array('activitytimespent', $data['activity'])) {
            $dateRangeCondition = '';
            if ($hasDateRange) {
                $startTimestamp = strtotime($dateRange['startDate']);
                $endTimestamp = strtotime($dateRange['endDate'] . ' 23:59:59'); // End of day
                $dateRangeCondition = " AND l.timecreated BETWEEN $startTimestamp AND $endTimestamp";
                error_log("Export date range condition: $dateRangeCondition");
            }
            
            // Improved session-based time estimation (performant but more accurate)
            $joins .= ' LEFT JOIN (
                SELECT 
                    l.userid,
                    l.courseid,
                    -- Her benzersiz gün için 30 dakika (günlük ortalama oturum)
                    COUNT(DISTINCT DATE(FROM_UNIXTIME(l.timecreated))) * 1800 + 
                    -- Aynı gün içinde ekstra aktiviteler için 5 dakika
                    GREATEST(0, (COUNT(*) - COUNT(DISTINCT DATE(FROM_UNIXTIME(l.timecreated))))) * 300 AS total_time
                FROM cbd_logstore_standard_log l
                WHERE l.courseid IS NOT NULL
                  AND l.target = "course"
                  AND l.action = "viewed"' . $dateRangeCondition . '
                GROUP BY l.userid, l.courseid
            ) logsure ON logsure.userid = u.id AND logsure.courseid = c.id';
        }
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

    // No filter for activitytimespent - show all records including 0 values
    $activityTimeFilter = '';
    
    // Final SQL - TÜM VERİYİ ÇEK (LIMIT YOK!)
    $selectClause = implode(', ', $selects);
    $sql = "SELECT $selectClause FROM $from$joins WHERE $where$searchWhere$activityTimeFilter";
    
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
