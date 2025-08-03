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

    // Field mappings - SQL ifadeleri
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
                    $columns[] = ['data' => $field, 'title' => ucfirst(str_replace('_', ' ', $field))];
                }
            }
        }
        if ($hasActivityFields) {
            foreach ($data['activity'] as $field) {
                if (isset($fieldmaps['activity'][$field])) {
                    $columns[] = ['data' => $field, 'title' => ucfirst(str_replace('_', ' ', $field))];
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
    
    // Activity fields varsa JOIN ekle
    if ($hasActivityFields) {
        $joins = ' JOIN cbd_user_enrolments ue ON ue.userid = u.id';
        $joins .= ' JOIN cbd_enrol e ON e.id = ue.enrolid';
        $joins .= ' JOIN cbd_course c ON c.id = e.courseid';
        $joins .= ' LEFT JOIN cbd_course_categories cc ON cc.id = c.category';
        $joins .= ' LEFT JOIN cbd_course_completions ccmp ON ccmp.userid = u.id AND ccmp.course = c.id';
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
                $orderConditions[] = "$orderField $direction";
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

    // Final SQL
    $selectClause = implode(', ', $selects);
    $countSql = "SELECT COUNT(*) as total FROM $from$joins WHERE $where$searchWhere";
    $dataSql = "SELECT $selectClause FROM $from$joins WHERE $where$searchWhere$orderBy";
    
    // LIMIT ve OFFSET ekle
    if ($length > 0) {
        $dataSql .= " LIMIT $length OFFSET $start";
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