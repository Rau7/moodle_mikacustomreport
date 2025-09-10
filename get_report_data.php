<?php
require('../../config.php');
require_login();
require_capability('local/mikacustomreport:view', context_system::instance());

// Include dedication helper
require_once(__DIR__ . '/dedication_helper.php');

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
            'completiontime' => 'Tamamlanma Süresi',
            'completiondate' => 'Tamamlanma Tarihi',
            'activitytimespent' => 'Eğitimde Geçirilen Süre',
            'startdate' => 'Başlangıç Tarihi',
            'enddate' => 'Bitiş Tarihi',
            'kayityontemi' => 'Kayıt Yöntemi'
        ]
    ];
    
    // Get custom profile fields for dynamic mapping
    $profileFields = $DB->get_records('user_info_field', null, 'sortorder ASC', 'id, shortname, name, datatype');
    
    // Add dynamic profile field labels
    foreach ($profileFields as $field) {
        $profileFieldKey = 'profile_' . $field->shortname;
        $fieldLabels['user'][$profileFieldKey] = format_string($field->name);
    }
    
    // Field mappings
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
            'completiontime' => 'CASE 
                WHEN ccmp.timecompleted IS NULL OR ccmp.timecompleted = 0 THEN "Tamamlanmadı"
                WHEN ccmp.timecompleted <= ue.timecreated THEN "00:00:00"
                ELSE SEC_TO_TIME(ccmp.timecompleted - ue.timecreated)
            END AS completiontime',
            'completiondate' => 'CASE 
                WHEN ccmp.timecompleted IS NULL OR ccmp.timecompleted = 0 THEN "Tamamlanmadı"
                ELSE CONCAT(
                    CASE DAYOFWEEK(FROM_UNIXTIME(ccmp.timecompleted))
                        WHEN 1 THEN "Pazar"
                        WHEN 2 THEN "Pazartesi"
                        WHEN 3 THEN "Salı"
                        WHEN 4 THEN "Çarşamba"
                        WHEN 5 THEN "Perşembe"
                        WHEN 6 THEN "Cuma"
                        WHEN 7 THEN "Cumartesi"
                    END, ", ",
                    DAY(FROM_UNIXTIME(ccmp.timecompleted)), " ",
                    CASE MONTH(FROM_UNIXTIME(ccmp.timecompleted))
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
                    YEAR(FROM_UNIXTIME(ccmp.timecompleted)), ", ",
                    LPAD(HOUR(FROM_UNIXTIME(ccmp.timecompleted)), 2, "0"), ":",
                    LPAD(MINUTE(FROM_UNIXTIME(ccmp.timecompleted)), 2, "0")
                )
            END AS completiondate',
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
    
    // Add dynamic profile fields to user fieldmaps
    foreach ($profileFields as $field) {
        $profileFieldKey = 'profile_' . $field->shortname;
        
        // Check if this is a datetime field and format accordingly
        if ($field->datatype === 'datetime') {
            // Special formatting for birth date (doğum tarihi) - short format DD/MM/YYYY
            if (stripos($field->name, 'doğum') !== false || stripos($field->shortname, 'birth') !== false || stripos($field->shortname, 'dogum') !== false) {
                $fieldmaps['user'][$profileFieldKey] = "CASE 
                    WHEN (SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}') IS NULL OR (SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}') = '' OR (SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}') = '0' THEN 'Belirtilmemiş'
                    ELSE CONCAT(
                        LPAD(DAY(FROM_UNIXTIME((SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}'))), 2, '0'), '/',
                        LPAD(MONTH(FROM_UNIXTIME((SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}'))), 2, '0'), '/',
                        YEAR(FROM_UNIXTIME((SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}')))
                    )
                END AS {$profileFieldKey}";
            } else {
                // For other datetime fields - long Turkish format with day name and time
                $fieldmaps['user'][$profileFieldKey] = "CASE 
                    WHEN (SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}') IS NULL OR (SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}') = '' OR (SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}') = '0' THEN 'Belirtilmemiş'
                    ELSE CONCAT(
                        CASE DAYOFWEEK(FROM_UNIXTIME((SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}')))
                            WHEN 1 THEN 'Pazar'
                            WHEN 2 THEN 'Pazartesi'
                            WHEN 3 THEN 'Salı'
                            WHEN 4 THEN 'Çarşamba'
                            WHEN 5 THEN 'Perşembe'
                            WHEN 6 THEN 'Cuma'
                            WHEN 7 THEN 'Cumartesi'
                        END, ', ',
                        DAY(FROM_UNIXTIME((SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}'))), ' ',
                        CASE MONTH(FROM_UNIXTIME((SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}')))
                            WHEN 1 THEN 'Ocak'
                            WHEN 2 THEN 'Şubat'
                            WHEN 3 THEN 'Mart'
                            WHEN 4 THEN 'Nisan'
                            WHEN 5 THEN 'Mayıs'
                            WHEN 6 THEN 'Haziran'
                            WHEN 7 THEN 'Temmuz'
                            WHEN 8 THEN 'Ağustos'
                            WHEN 9 THEN 'Eylül'
                            WHEN 10 THEN 'Ekim'
                            WHEN 11 THEN 'Kasım'
                            WHEN 12 THEN 'Aralık'
                        END, ' ',
                        YEAR(FROM_UNIXTIME((SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}'))), ', ',
                        LPAD(HOUR(FROM_UNIXTIME((SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}'))), 2, '0'), ':',
                        LPAD(MINUTE(FROM_UNIXTIME((SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}'))), 2, '0')
                    )
                END AS {$profileFieldKey}";
            }
        } else {
            // For non-datetime fields, use raw data
            $fieldmaps['user'][$profileFieldKey] = "(SELECT data FROM cbd_user_info_data d JOIN cbd_user_info_field f ON f.id = d.fieldid WHERE d.userid = u.id AND f.shortname = '{$field->shortname}') AS {$profileFieldKey}";
        }
    }

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
        
        // User-provided completion statistics calculation (separate JOINs as per user SQL)
        if (in_array('progress', $data['activity']) || in_array('activitiescompleted', $data['activity']) || in_array('totalactivities', $data['activity']) || in_array('completionpercentage', $data['activity'])) {
            
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
    $hasDedicationField = $hasActivityFields && in_array('dedicationtime', $data['activity']);
    $hasActivityTimeField = $hasActivityFields && in_array('activitytimespent', $data['activity']);
    
    // Prepare debug info for JSON response
    $debugInfo = [
        'hasActivityFields' => $hasActivityFields,
        'hasActivityTimeField' => $hasActivityTimeField,
        'activityFields' => isset($data['activity']) ? $data['activity'] : [],
        'dedicationFunctionCalls' => 0,
        'recordsProcessed' => 0
    ];
    
    foreach ($recordset as $record) {
        $debugInfo['recordsProcessed']++;
        
        $row = [];
        foreach ($record as $key => $value) {
            // Timestamp alanlarını formatla
            if (in_array($key, ['timecreated', 'lastaccess', 'firstaccess', 'registrationdate', 'startdate', 'enddate']) && $value > 0) {
                $row[$key] = userdate($value);
            } else {
                $row[$key] = $value;
            }
        }
        
        // Track record data for debug
        $debugInfo['sampleRecord'] = [
            'hasUserid' => isset($record->userid),
            'hasCourseid' => isset($record->courseid),
            'userid' => isset($record->userid) ? $record->userid : 'NOT SET',
            'courseid' => isset($record->courseid) ? $record->courseid : 'NOT SET'
        ];
        
        // Dedication time hesapla (eğer seçilmişse ve block_dedication mevcutsa)
        if (($hasDedicationField || $hasActivityTimeField) && isset($record->userid) && isset($record->courseid)) {
            $debugInfo['dedicationFunctionCalls']++;
            
            $timestart = $hasDateRange ? strtotime($dateRange['startDate']) : null;
            $timeend = $hasDateRange ? strtotime($dateRange['endDate']) : null;
            
            // Calculate dedication time using block_dedication manager with debug info
            $dedicationResult = dedication_helper::calculate_dedication_time(
                $record->userid,
                $record->courseid,
                $timestart,
                $timeend,
                true  // Return debug info
            );
            
            // Store debug info for first record
            if (!isset($debugInfo['dedicationDebug'])) {
                $debugInfo['dedicationDebug'] = $dedicationResult;
            }
            
            // Get actual seconds for formatting
            $dedicationSeconds = is_array($dedicationResult) ? $dedicationResult['finalResult'] : $dedicationResult;
            
            // Format the time
            $formattedTime = dedication_helper::format_dedication_time($dedicationSeconds);
            
            if ($hasDedicationField) {
                $row['dedicationtime'] = $formattedTime;
            }
            if ($hasActivityTimeField) {
                $row['activitytimespent'] = $formattedTime;
            }
            
        }
        
        // Calculate completion status if completionstatus field is selected
        $hasCompletionStatusField = $hasActivityFields && in_array('completionstatus', $data['activity']);
        if ($hasCompletionStatusField) {
            // Get userid and courseid from row (like activitytimespent does)
            $userid = isset($row['userid']) ? $row['userid'] : null;
            $courseid = isset($row['courseid']) ? $row['courseid'] : null;
            
            // Get values from existing fields
            $timecompleted = null;
            $progressPercentage = 0;
            
            // Check if completiontime field exists and has value (means completed)
            if (isset($row['completiontime']) && $row['completiontime'] !== null && $row['completiontime'] !== '00:00:00') {
                $timecompleted = 1; // Mark as completed
            }
            
            // Get progress percentage if available
            if (isset($row['progress'])) {
                $progressPercentage = floatval($row['progress']);
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
            
            $row['completionstatus'] = $completionStatus;
            
            // Add debug info
            if (!isset($debugInfo['completionStatusDebug'])) {
                $debugInfo['completionStatusDebug'] = [];
            }
            $debugInfo['completionStatusDebug'][] = [
                'userid' => $userid,
                'courseid' => $courseid,
                'progressPercentage' => $progressPercentage,
                'timecompleted' => $timecompleted,
                'completionStatus' => $completionStatus,
                'completiontime_field' => isset($row['completiontime']) ? $row['completiontime'] : 'not_set',
                'progress_field' => isset($row['progress']) ? $row['progress'] : 'not_set',
                'timestart' => $timestart,
                'timeend' => $timeend,
                'logic' => 'progress + internal activitytimespent calculation'
            ];
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
        'debug' => array_merge([
            'countSql' => $countSql,
            'dataSql' => $dataSql,
            'totalRecords' => $totalRecords,
            'start' => $start,
            'length' => $length,
            'search' => $search,
            'hasUserFields' => $hasUserFields,
            'hasActivityFields' => $hasActivityFields
        ], $debugInfo)
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