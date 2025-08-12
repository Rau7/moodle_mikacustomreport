<?php
require('../../config.php');
require_login();
require_capability('local/mikacustomreport:view', context_system::instance());

header('Content-Type: application/json');

// POST verilerini al
$data = json_decode(file_get_contents('php://input'), true);
global $DB;

try {
    // DataTables server-side processing parametreleri
    $draw = isset($data['draw']) ? intval($data['draw']) : 1;
    $start = isset($data['start']) ? intval($data['start']) : 0;
    $length = isset($data['length']) ? intval($data['length']) : 10;
    $search = isset($data['search']['value']) ? $data['search']['value'] : '';
    $order = isset($data['order']) ? $data['order'] : [];
    $getColumns = isset($data['getColumns']) ? $data['getColumns'] : false;
    
    // Hangi fieldlar seçilmiş kontrol et
    $hasUserFields = !empty($data['user']) && is_array($data['user']);
    $hasActivityFields = !empty($data['activity']) && is_array($data['activity']);
    
    // Date range parametrelerini al (activitytimespent için)
    $dateRange = isset($data['dateRange']) ? $data['dateRange'] : null;
    $hasDateRange = $dateRange !== null && !empty($dateRange['startDate']) && !empty($dateRange['endDate']);
    
    if ($hasDateRange) {
        error_log("Date range detected: {$dateRange['startDate']} to {$dateRange['endDate']}");
    }

    // Turkish field labels for column headers
    $fieldLabels = [
        'user' => [
            'username' => 'Kullanıcı Adı',
            'email' => 'E-posta',
            'firstname' => 'Ad',
            'lastname' => 'Soyad',
            'timespent' => 'Sitede Geçirilen Zaman',
            'start' => 'Başlangıç Tarihi (Özel)',
            'bolum' => 'Bölüm (Özel)',
            'end' => 'Bitiş Tarihi (Özel)',
            'departman' => 'Departman',
            'position' => 'Pozisyon (Özel)',
            'unvan' => 'Unvan',
            'adres' => 'Adres',
            'birim' => 'Birim',
            'sicil' => 'Sicil',
            'tc' => 'TC',
            'ceptelefonu' => 'Cep Telefonu',
            'sitesongiris' => 'Site Son Giriş Tarihi',
            'siteilkgiris' => 'Site İlk Giriş Tarihi',
            'sitekayittarihi' => 'Site Kayıt Tarihi',
            'durum' => 'Durum (Aktif/Pasif)'
        ],
        'activity' => [
            'activityname' => 'Etkinlik Adı',
            'category' => 'Kategori',
            'registrationdate' => 'Kayıt Tarihi',
            'progress' => 'İlerleme (%)',
            'completionstatus' => 'Tamamlanma Durumu',
            'activitiescompleted' => 'Tamamlanan Aktiviteler',
            'totalactivities' => 'Toplam Aktiviteler',
            'activitytimespent' => 'Eğitimde Geçirilen Süre',
            'kayityontemi' => 'Kayıt Yöntemi'
        ]
    ];

    // Field mappings - SQL ifadeleri
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
            'progress' => 'ROUND(
                100 * 
                COALESCE(cstats.completed_activities, 0) 
                / NULLIF(COALESCE(cstats.total_activities, 0), 0)
            , 2) AS progress',
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
    if ($hasUserFields) {
        foreach ($data['user'] as $field) {
            if (isset($fieldmaps['user'][$field])) {
                $selects[] = $fieldmaps['user'][$field];
            }
        }
    }
    
    if ($hasActivityFields) {
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

    // Eğer sadece sütun bilgisi isteniyorsa
    if ($getColumns) {
        $columns = [];
        if ($hasUserFields) {
            foreach ($data['user'] as $field) {
                if (isset($fieldmaps['user'][$field])) {
                    $title = isset($fieldLabels['user'][$field]) ? $fieldLabels['user'][$field] : ucfirst(str_replace('_', ' ', $field));
                    $columns[] = ['data' => $field, 'title' => $title];
                }
            }
        }
        if ($hasActivityFields) {
            foreach ($data['activity'] as $field) {
                if (isset($fieldmaps['activity'][$field])) {
                    $title = isset($fieldLabels['activity'][$field]) ? $fieldLabels['activity'][$field] : ucfirst(str_replace('_', ' ', $field));
                    $columns[] = ['data' => $field, 'title' => $title];
                }
            }
        }
        
        echo json_encode([
            'draw' => $draw,
            'columns' => $columns,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => []
        ]);
        return;
    }

    // SQL oluştur
    $from = 'cbd_user u';
    $joins = '';
    $where = 'u.deleted = 0'; // Silinmemiş kullanıcılar
    
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
    
    // Search will be handled in unified search logic below
    
    // Activity fields varsa JOIN ekle
    if ($hasActivityFields) {
        $joins .= ' JOIN cbd_user_enrolments ue ON ue.userid = u.id';
        $joins .= ' JOIN cbd_enrol e ON e.id = ue.enrolid';
        $joins .= ' JOIN cbd_course c ON c.id = e.courseid';
        $joins .= ' LEFT JOIN cbd_course_categories cc ON cc.id = c.category';
        $joins .= ' LEFT JOIN cbd_course_completions ccmp ON ccmp.userid = u.id AND ccmp.course = c.id';
        
        // User-provided completion statistics calculation (exact formula)
        if (in_array('progress', $data['activity']) || in_array('activitiescompleted', $data['activity']) || in_array('totalactivities', $data['activity']) || in_array('completionpercentage', $data['activity'])) {
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
                error_log("Date range condition: $dateRangeCondition");
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

    // Arama filtresi ekle
    $searchWhere = '';
    $searchParams = [];
    if (!empty($search)) {
        $searchConditions = [];
        $searchValue = '%' . $search . '%';
        
        // Her seçili field'da arama yap
        if ($hasUserFields) {
            foreach ($data['user'] as $field) {
                if (isset($fieldmaps['user'][$field])) {
                    $fieldExpr = $fieldmaps['user'][$field];
                    // AS kısmını çıkar
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
                    // AS kısmını çıkar
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

    // Sıralama ekle
    $orderBy = '';
    if (!empty($order)) {
        $orderConditions = [];
        foreach ($order as $orderItem) {
            $columnIndex = intval($orderItem['column']);
            $direction = $orderItem['dir'] === 'desc' ? 'DESC' : 'ASC';
            
            // Sütun indeksine göre field adını bul
            $fieldIndex = 0;
            $orderField = null;
            
            if ($hasUserFields) {
                foreach ($data['user'] as $field) {
                    if ($fieldIndex == $columnIndex) {
                        $orderField = $field;
                        break;
                    }
                    $fieldIndex++;
                }
            }
            
            if (!$orderField && $hasActivityFields) {
                foreach ($data['activity'] as $field) {
                    if ($fieldIndex == $columnIndex) {
                        $orderField = $field;
                        break;
                    }
                    $fieldIndex++;
                }
            }
            
            if ($orderField) {
                // Special handling for timespent - sort by seconds, not string
                if ($orderField === 'timespent') {
                    $orderConditions[] = "TIME_TO_SEC(IFNULL(user_time.toplam_sure, '00:00:00')) $direction";
                } else {
                    $orderConditions[] = "$orderField $direction";
                }
            }
        }
        
        if (!empty($orderConditions)) {
            $orderBy = ' ORDER BY ' . implode(', ', $orderConditions);
        }
    }
    
    // Default sıralama
    if (empty($orderBy)) {
        if ($hasActivityFields) {
            $orderBy = ' ORDER BY u.username, c.fullname';
        } else {
            $orderBy = ' ORDER BY u.username';
        }
    }

    // No filter for activitytimespent - show all records including 0 values
    $activityTimeFilter = '';
    
    // Final SQL
    $selectClause = implode(', ', $selects);
    $countSql = "SELECT COUNT(*) as total FROM $from$joins WHERE $where$searchWhere$activityTimeFilter";
    $dataSql = "SELECT $selectClause FROM $from$joins WHERE $where$searchWhere$activityTimeFilter$orderBy";
    
    // LIMIT ve OFFSET ekle (length: -1 ise tüm veriyi döndür)
    if ($length > 0) {
        $dataSql .= " LIMIT $length OFFSET $start";
    } elseif ($length == -1) {
        // Export için tüm veriyi döndür, LIMIT yok
        error_log("Exporting all data, no LIMIT applied");
    }

    // Debug için SQL'i logla
    error_log("Custom Report Count SQL: " . $countSql);
    error_log("Custom Report Data SQL: " . $dataSql);
    error_log("Search params: " . print_r($searchParams, true));

    // Toplam kayıt sayısını al
    $totalRecords = $DB->get_field_sql($countSql, $searchParams);
    
    // Verileri çek
    $recordset = $DB->get_recordset_sql($dataSql, $searchParams);
    
    // Output formatla
    $output = [];
    foreach ($recordset as $record) {
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
    
    // Recordset'i kapat
    $recordset->close();

    // Response
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => intval($totalRecords),
        'recordsFiltered' => intval($totalRecords), // Arama sonrası kayıt sayısı
        'data' => $output,
        'debug' => [
            'countSql' => $countSql,
            'dataSql' => $dataSql,
            'totalRecords' => $totalRecords,
            'start' => $start,
            'length' => $length,
            'search' => $search,
            'hasUserFields' => $hasUserFields,
            'hasActivityFields' => $hasActivityFields
        ]
    ]);

} catch (Exception $e) {
    error_log("Custom Report Error: " . $e->getMessage());
    echo json_encode([
        'draw' => 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => $e->getMessage()
    ]);
}
?>