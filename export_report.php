<?php
require('../../config.php');
require_login();
require_capability('local/mikacustomreport:view', context_system::instance());

// Include dedication helper
require_once(__DIR__ . '/dedication_helper.php');

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
            'sitesongiris' => 'CASE 
                WHEN u.lastaccess = 0 THEN "Hiç giriş yapmamış"
                ELSE CONCAT(
                    CASE DAYOFWEEK(FROM_UNIXTIME(u.lastaccess))
                        WHEN 1 THEN "Pazar"
                        WHEN 2 THEN "Pazartesi"
                        WHEN 3 THEN "Salı"
                        WHEN 4 THEN "Çarşamba"
                        WHEN 5 THEN "Perşembe"
                        WHEN 6 THEN "Cuma"
                        WHEN 7 THEN "Cumartesi"
                    END, ", ",
                    DAY(FROM_UNIXTIME(u.lastaccess)), " ",
                    CASE MONTH(FROM_UNIXTIME(u.lastaccess))
                        WHEN 1 THEN "Ocak"
                        WHEN 2 THEN "Şubat"
                        WHEN 3 THEN "Mart"
                        WHEN 4 THEN "Nisan"
                        WHEN 5 THEN "Mayıs"
                        WHEN 6 THEN "Haziran"
                        WHEN 7 THEN "Temmuz"
                        WHEN 8 THEN "Ağustos"
                        WHEN 9 THEN "Eylül"
                        WHEN 10 THEN "Ekim"
                        WHEN 11 THEN "Kasım"
                        WHEN 12 THEN "Aralık"
                    END, " ",
                    YEAR(FROM_UNIXTIME(u.lastaccess)), ", ",
                    LPAD(HOUR(FROM_UNIXTIME(u.lastaccess)), 2, "0"), ":",
                    LPAD(MINUTE(FROM_UNIXTIME(u.lastaccess)), 2, "0")
                )
            END AS sitesongiris',
            'siteilkgiris' => 'CASE 
                WHEN u.firstaccess = 0 AND u.lastaccess = 0 THEN "Hiç giriş yapmamış"
                WHEN u.firstaccess = 0 AND u.lastaccess > 0 THEN CONCAT(
                    CASE DAYOFWEEK(FROM_UNIXTIME(u.lastaccess))
                        WHEN 1 THEN "Pazar"
                        WHEN 2 THEN "Pazartesi"
                        WHEN 3 THEN "Salı"
                        WHEN 4 THEN "Çarşamba"
                        WHEN 5 THEN "Perşembe"
                        WHEN 6 THEN "Cuma"
                        WHEN 7 THEN "Cumartesi"
                    END, ", ",
                    DAY(FROM_UNIXTIME(u.lastaccess)), " ",
                    CASE MONTH(FROM_UNIXTIME(u.lastaccess))
                        WHEN 1 THEN "Ocak"
                        WHEN 2 THEN "Şubat"
                        WHEN 3 THEN "Mart"
                        WHEN 4 THEN "Nisan"
                        WHEN 5 THEN "Mayıs"
                        WHEN 6 THEN "Haziran"
                        WHEN 7 THEN "Temmuz"
                        WHEN 8 THEN "Ağustos"
                        WHEN 9 THEN "Eylül"
                        WHEN 10 THEN "Ekim"
                        WHEN 11 THEN "Kasım"
                        WHEN 12 THEN "Aralık"
                    END, " ",
                    YEAR(FROM_UNIXTIME(u.lastaccess)), ", ",
                    LPAD(HOUR(FROM_UNIXTIME(u.lastaccess)), 2, "0"), ":",
                    LPAD(MINUTE(FROM_UNIXTIME(u.lastaccess)), 2, "0")
                )
                ELSE CONCAT(
                    CASE DAYOFWEEK(FROM_UNIXTIME(u.firstaccess))
                        WHEN 1 THEN "Pazar"
                        WHEN 2 THEN "Pazartesi"
                        WHEN 3 THEN "Salı"
                        WHEN 4 THEN "Çarşamba"
                        WHEN 5 THEN "Perşembe"
                        WHEN 6 THEN "Cuma"
                        WHEN 7 THEN "Cumartesi"
                    END, ", ",
                    DAY(FROM_UNIXTIME(u.firstaccess)), " ",
                    CASE MONTH(FROM_UNIXTIME(u.firstaccess))
                        WHEN 1 THEN "Ocak"
                        WHEN 2 THEN "Şubat"
                        WHEN 3 THEN "Mart"
                        WHEN 4 THEN "Nisan"
                        WHEN 5 THEN "Mayıs"
                        WHEN 6 THEN "Haziran"
                        WHEN 7 THEN "Temmuz"
                        WHEN 8 THEN "Ağustos"
                        WHEN 9 THEN "Eylül"
                        WHEN 10 THEN "Ekim"
                        WHEN 11 THEN "Kasım"
                        WHEN 12 THEN "Aralık"
                    END, " ",
                    YEAR(FROM_UNIXTIME(u.firstaccess)), ", ",
                    LPAD(HOUR(FROM_UNIXTIME(u.firstaccess)), 2, "0"), ":",
                    LPAD(MINUTE(FROM_UNIXTIME(u.firstaccess)), 2, "0")
                )
            END AS siteilkgiris',
            'sitekayittarihi' => 'CONCAT(
                CASE DAYOFWEEK(FROM_UNIXTIME(u.timecreated))
                    WHEN 1 THEN "Pazar"
                    WHEN 2 THEN "Pazartesi"
                    WHEN 3 THEN "Salı"
                    WHEN 4 THEN "Çarşamba"
                    WHEN 5 THEN "Perşembe"
                    WHEN 6 THEN "Cuma"
                    WHEN 7 THEN "Cumartesi"
                END, ", ",
                DAY(FROM_UNIXTIME(u.timecreated)), " ",
                CASE MONTH(FROM_UNIXTIME(u.timecreated))
                    WHEN 1 THEN "Ocak"
                    WHEN 2 THEN "Şubat"
                    WHEN 3 THEN "Mart"
                    WHEN 4 THEN "Nisan"
                    WHEN 5 THEN "Mayıs"
                    WHEN 6 THEN "Haziran"
                    WHEN 7 THEN "Temmuz"
                    WHEN 8 THEN "Ağustos"
                    WHEN 9 THEN "Eylül"
                    WHEN 10 THEN "Ekim"
                    WHEN 11 THEN "Kasım"
                    WHEN 12 THEN "Aralık"
                END, " ",
                YEAR(FROM_UNIXTIME(u.timecreated)), ", ",
                LPAD(HOUR(FROM_UNIXTIME(u.timecreated)), 2, "0"), ":",
                LPAD(MINUTE(FROM_UNIXTIME(u.timecreated)), 2, "0")
            ) AS sitekayittarihi',
            'durum' => 'CASE WHEN u.suspended = 0 THEN "Aktif" ELSE "Pasif" END AS durum'
        ],
        'activity' => [
            'activityname' => 'c.fullname AS activityname',
            'name' => 'c.fullname AS name',
            'fullname' => 'c.fullname AS fullname',
            'shortname' => 'c.shortname',
            'category' => 'cc.name AS category',
            'registrationdate' => 'ue.timecreated AS registrationdate',
            'progress' => 'COALESCE(ROUND(
                100 * 
                COALESCE(comp.completed_activities, 0) 
                / NULLIF(COALESCE(tot.total_activities, 0), 0)
            , 2), 0) AS progress',
            'completionstatus' => 'u.id AS userid, c.id AS courseid, "Tamamlanmadı" AS completionstatus',  // Will be calculated in PHP
            'activitiescompleted' => 'COALESCE(comp.completed_activities, 0) AS activitiescompleted',
            'totalactivities' => 'COALESCE(tot.total_activities, 0) AS totalactivities',
            'completiontime' => 'SEC_TO_TIME(ccmp.timecompleted - ue.timecreated) AS completiontime',
            'activitytimespent' => 'u.id AS userid, c.id AS courseid, "0:00:00" AS activitytimespent',  // Will be calculated using block_dedication
            'dedicationtime' => '"0:00:00" AS dedicationtime',  // Will be calculated in PHP
            'startdate' => 'c.startdate',
            'enddate' => 'c.enddate',
            'format' => 'CASE 
                WHEN c.format = "topics" THEN "Özel Etkinlik"
                WHEN c.format = "singleactivity" THEN "Tek Etkinlik"
                ELSE c.format
            END AS format',
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

    // Turkish header mappings for export
    $turkishHeaders = [
        // User fields
        'username' => 'Kullanıcı Adı',
        'email' => 'E-posta',
        'firstname' => 'Ad',
        'lastname' => 'Soyad',
        'timespent' => 'Sitede Geçirilen Zaman',
        'start' => 'Başlangıç',
        'bolum' => 'Bölüm',
        'end' => 'Bitiş',
        'departman' => 'Departman',
        'position' => 'Pozisyon',
        'unvan' => 'Ünvan',
        'adres' => 'Adres',
        'birim' => 'Birim',
        'sicil' => 'Sicil',
        'tc' => 'TC',
        'ceptelefonu' => 'Cep Telefonu',
        'sitesongiris' => 'Site Son Giriş',
        'siteilkgiris' => 'Site İlk Giriş',
        'sitekayittarihi' => 'Site Kayıt Tarihi',
        'durum' => 'Durum',
        // Activity fields
        'activityname' => 'Eğitim Adı',
        'name' => 'Eğitim Adı',
        'fullname' => 'Eğitim Tam Adı',
        'shortname' => 'Eğitim Kısa Adı',
        'category' => 'Kategori',
        'registrationdate' => 'Kayıt Tarihi',
        'progress' => 'İlerleme %',
        'completionstatus' => 'Tamamlanma Durumu',
        'activitiescompleted' => 'Tamamlanan Aktiviteler',
        'totalactivities' => 'Toplam Aktiviteler',
        'completiontime' => 'Tamamlanma Süresi',
        'activitytimespent' => 'Eğitimde Geçirilen Süre',
        'dedicationtime' => 'Dedication Süresi',
        'startdate' => 'Başlangıç Tarihi',
        'enddate' => 'Bitiş Tarihi',
        'format' => 'Format',
        'completionenabled' => 'Tamamlanma Etkin',
        'guestaccess' => 'Misafir Erişim',
        'kayityontemi' => 'Kayıt Yöntemi'
    ];
    
    // SELECT fieldları oluştur
    $selects = [];
    $headers = [];
    
    if ($hasUserFields) {
        foreach ($data['user'] as $field) {
            if (isset($fieldmaps['user'][$field])) {
                $selects[] = $fieldmaps['user'][$field];
                $headers[] = isset($turkishHeaders[$field]) ? $turkishHeaders[$field] : ucfirst(str_replace('_', ' ', $field));
            }
        }
    }
    
    if ($hasActivityFields) {
        foreach ($data['activity'] as $field) {
            if (isset($fieldmaps['activity'][$field])) {
                $selects[] = $fieldmaps['activity'][$field];
                $headers[] = isset($turkishHeaders[$field]) ? $turkishHeaders[$field] : ucfirst(str_replace('_', ' ', $field));
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
        
        // User-provided completion statistics calculation (separate JOINs as per user SQL)
        if (in_array('progress', $data['activity']) || in_array('activitiescompleted', $data['activity']) || in_array('totalactivities', $data['activity'])) {
            
            // Total activities per course (course-level, user-independent)
            $joins .= ' LEFT JOIN (
                SELECT
                    cm.course AS courseid,
                    SUM(CASE WHEN cm.completion > 0 THEN 1 ELSE 0 END) AS total_activities
                FROM cbd_course_modules cm
                WHERE cm.deletioninprogress = 0
                GROUP BY cm.course
            ) tot ON tot.courseid = c.id';
            
            // Completed activities per user+course (user+course level)
            $joins .= ' LEFT JOIN (
                SELECT
                    cm.course AS courseid,
                    cmc.userid,
                    SUM(CASE WHEN cmc.completionstate IN (1,2) THEN 1 ELSE 0 END) AS completed_activities
                FROM cbd_course_modules cm
                JOIN cbd_course_modules_completion cmc ON cmc.coursemoduleid = cm.id
                WHERE cm.deletioninprogress = 0
                  AND cm.completion > 0
                GROUP BY cm.course, cmc.userid
            ) comp ON comp.userid = u.id AND comp.courseid = c.id';
        }
        
        // activitytimespent is now calculated in PHP using block_dedication - no SQL JOIN needed
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
    
    // Get data from POST for field calculations
    if (isset($_POST['data'])) {
        $exportData = json_decode($_POST['data'], true);
    } else {
        $exportData = json_decode(file_get_contents('php://input'), true);
    }
    
    $hasActivityFields = !empty($exportData['activity']) && is_array($exportData['activity']);
    $hasDedicationField = $hasActivityFields && in_array('dedicationtime', $exportData['activity']);
    $hasActivityTimeField = $hasActivityFields && in_array('activitytimespent', $exportData['activity']);
    
    // Date range for calculations
    $dateRange = isset($exportData['dateRange']) ? $exportData['dateRange'] : null;
    $hasDateRange = $dateRange !== null && !empty($dateRange['startDate']) && !empty($dateRange['endDate']);
    
    foreach ($recordset as $record) {
        $row = [];
        foreach ($record as $key => $value) {
            // Skip userid and courseid from CSV output (they are only for calculations)
            if ($key === 'userid' || $key === 'courseid' || $key === 'completionstatus' || $key === 'activitytimespent') {
                continue;
            }
            
            // Timestamp alanlarını formatla
            if (in_array($key, ['timecreated', 'lastaccess', 'firstaccess', 'registrationdate', 'startdate', 'enddate']) && $value > 0) {
                $row[] = userdate($value);
            } else {
                $row[] = $value;
            }
        }
        
        // Dedication time hesapla (eğer seçilmişse ve block_dedication mevcutsa)
        if (($hasDedicationField || $hasActivityTimeField) && isset($record->userid) && isset($record->courseid)) {
            $timestart = $hasDateRange ? strtotime($dateRange['startDate']) : null;
            $timeend = $hasDateRange ? strtotime($dateRange['endDate']) : null;
            
            $dedicationSeconds = dedication_helper::calculate_dedication_time(
                $record->userid, 
                $record->courseid, 
                $timestart, 
                $timeend
            );
            
            $formattedTime = dedication_helper::format_dedication_time($dedicationSeconds);
            
            // Find and update column indexes
            if ($hasDedicationField) {
                $dedicationIndex = array_search('dedicationtime', array_keys((array)$record));
                if ($dedicationIndex !== false) {
                    $row[$dedicationIndex] = $formattedTime;
                }
            }
            if ($hasActivityTimeField) {
                $activityIndex = array_search('activitytimespent', array_keys((array)$record));
                if ($activityIndex !== false) {
                    $row[$activityIndex] = $formattedTime;
                }
            }
            
        }
        
        // Calculate completion status if completionstatus field is selected (EXACT COPY FROM get_report_data.php)
        $hasCompletionStatusField = $hasActivityFields && in_array('completionstatus', $exportData['activity']);
        if ($hasCompletionStatusField) {
            // Get userid and courseid from record (like activitytimespent does)
            $userid = isset($record->userid) ? $record->userid : null;
            $courseid = isset($record->courseid) ? $record->courseid : null;
            
            // Get values from existing fields
            $timecompleted = null;
            $progressPercentage = 0;
            
            // Check if completiontime field exists and has value (means completed)
            if (isset($record->completiontime) && $record->completiontime !== null && $record->completiontime !== '00:00:00') {
                $timecompleted = 1; // Mark as completed
            }
            
            // Get progress percentage if available
            if (isset($record->progress)) {
                $progressPercentage = floatval($record->progress);
            }
            
            // Get date range for calculation
            $timestart = $hasDateRange ? strtotime($dateRange['startDate']) : null;
            $timeend = $hasDateRange ? strtotime($dateRange['endDate']) : null;
            
            // Calculate completion status using helper (calculates activitytimespent internally)
            $completionStatus = dedication_helper::calculate_completion_status(
                $userid,
                $courseid,
                $progressPercentage,
                $timecompleted,
                $timestart,
                $timeend
            );
            
            // Find and update completionstatus column
            $completionStatusIndex = array_search('completionstatus', array_keys((array)$record));
            if ($completionStatusIndex !== false) {
                $row[$completionStatusIndex] = $completionStatus;
            }
            
            // Add debug info to CSV (like get_report_data.php debug)
            if (!isset($debugInfo['completionStatusDebug'])) {
                $debugInfo['completionStatusDebug'] = [];
            }
            $debugInfo['completionStatusDebug'][] = [
                'userid' => $userid,
                'courseid' => $courseid,
                'progressPercentage' => $progressPercentage,
                'timecompleted' => $timecompleted,
                'completionStatus' => $completionStatus,
                'completiontime_field' => isset($record->completiontime) ? $record->completiontime : 'not_set',
                'progress_field' => isset($record->progress) ? $record->progress : 'not_set',
                'timestart' => $timestart,
                'timeend' => $timeend,
                'logic' => 'progress + internal activitytimespent calculation'
            ];
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
