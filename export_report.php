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
    // Basit yaklaşım: Eski export mantığını kullan ama sıralamayı düzelt
    // DataTable'da görünen veriyi export et
    
    // Hangi fieldlar seçilmiş kontrol et (header için)
    $hasUserFields = !empty($data['user']) && is_array($data['user']);
    $hasActivityFields = !empty($data['activity']) && is_array($data['activity']);
    
    // Date range parametrelerini al (activitytimespent için)
    $dateRange = isset($data['dateRange']) ? $data['dateRange'] : null;
    $hasDateRange = $dateRange !== null && !empty($dateRange['startDate']) && !empty($dateRange['endDate']);
    
    if ($hasDateRange) {
        error_log("Export date range detected: {$dateRange['startDate']} to {$dateRange['endDate']}");
    }

    // Get custom profile fields for dynamic mapping
    $profileFields = $DB->get_records('user_info_field', null, 'sortorder ASC', 'id, shortname, name, datatype');
    
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
            'enrollmentenddate' => 'CASE 
                WHEN ue.timeend IS NULL OR ue.timeend = 0 THEN "Süresiz"
                ELSE CONCAT(
                    CASE DAYOFWEEK(FROM_UNIXTIME(ue.timeend))
                        WHEN 1 THEN "Pazar"
                        WHEN 2 THEN "Pazartesi"
                        WHEN 3 THEN "Salı"
                        WHEN 4 THEN "Çarşamba"
                        WHEN 5 THEN "Perşembe"
                        WHEN 6 THEN "Cuma"
                        WHEN 7 THEN "Cumartesi"
                    END, ", ",
                    DAY(FROM_UNIXTIME(ue.timeend)), " ",
                    CASE MONTH(FROM_UNIXTIME(ue.timeend))
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
                    YEAR(FROM_UNIXTIME(ue.timeend)), ", ",
                    LPAD(HOUR(FROM_UNIXTIME(ue.timeend)), 2, "0"), ":",
                    LPAD(MINUTE(FROM_UNIXTIME(ue.timeend)), 2, "0")
                )
            END AS enrollmentenddate',
            'progress' => 'COALESCE(ROUND(
                100 * 
                COALESCE(comp.completed_activities, 0) 
                / NULLIF(COALESCE(tot.total_activities, 0), 0)
            , 2), 0) AS progress',
            'completionstatus' => 'u.id AS userid, c.id AS courseid, "Tamamlanmadı" AS completionstatus',  // Will be calculated in PHP
            'activitiescompleted' => 'COALESCE(comp.completed_activities, 0) AS activitiescompleted',
            'totalactivities' => 'COALESCE(tot.total_activities, 0) AS totalactivities',
            'completiontime' => 'SEC_TO_TIME(ccmp.timecompleted - ue.timecreated) AS completiontime',
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
        'enrollmentenddate' => 'Kayıt Bitiş Tarihi',
        'progress' => 'İlerleme %',
        'completionstatus' => 'Tamamlanma Durumu',
        'activitiescompleted' => 'Tamamlanan Aktiviteler',
        'totalactivities' => 'Toplam Aktiviteler',
        'completiontime' => 'Tamamlanma Süresi',
        'completiondate' => 'Tamamlanma Tarihi',
        'activitytimespent' => 'Eğitimde Geçirilen Süre',
        'dedicationtime' => 'Dedication Süresi',
        'startdate' => 'Başlangıç Tarihi',
        'enddate' => 'Bitiş Tarihi',
        'format' => 'Format',
        'completionenabled' => 'Tamamlanma Etkin',
        'guestaccess' => 'Misafir Erişim',
        'kayityontemi' => 'Kayıt Yöntemi'
    ];
    
    // Add dynamic profile field Turkish headers
    foreach ($profileFields as $field) {
        $profileFieldKey = 'profile_' . $field->shortname;
        $turkishHeaders[$profileFieldKey] = format_string($field->name);
    }
    
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
        case 'excel':
            exportExcel($sql, $searchParams, $headers, $filename, $hasDateRange, $dateRange);
            break;
        case 'pdf':
            exportPDF($sql, $searchParams, $headers, $filename, $hasDateRange, $dateRange);
            break;
        default:
            exportCSV($sql, $searchParams, $headers, $filename, $hasDateRange, $dateRange);
    }

} catch (Exception $e) {
    error_log("Export Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function exportCSV($sql, $params, $headers, $filename, $hasDateRange = false, $dateRange = null) {
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
        
        // Calculate activitytimespent if needed (same logic as get_report_data.php)
        $hasActivityTimeField = isset($record->activitytimespent);
        if ($hasActivityTimeField && isset($record->userid) && isset($record->courseid)) {
            $timestart = $hasDateRange ? strtotime($dateRange['startDate']) : null;
            $timeend = $hasDateRange ? strtotime($dateRange['endDate']) : null;
            
            // Calculate dedication time using block_dedication manager
            $dedicationResult = dedication_helper::calculate_dedication_time(
                $record->userid,
                $record->courseid,
                $timestart,
                $timeend,
                false  // No debug info for export
            );
            
            // Get actual seconds for formatting
            $dedicationSeconds = is_array($dedicationResult) ? $dedicationResult['finalResult'] : $dedicationResult;
            
            // Format the time
            $formattedTime = dedication_helper::format_dedication_time($dedicationSeconds);
            
            // Replace the default value with calculated value
            $record->activitytimespent = $formattedTime;
        }
        
        // Calculate completion status if needed (same logic as get_report_data.php)
        $hasCompletionStatusField = isset($record->completionstatus);
        if ($hasCompletionStatusField && isset($record->userid) && isset($record->courseid)) {
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
            
            // Calculate completion status using helper
            $completionStatus = dedication_helper::calculate_completion_status(
                $record->userid,
                $record->courseid,
                $progressPercentage,
                $timecompleted,
                $timestart,
                $timeend
            );
            
            // Replace the default value with calculated value
            $record->completionstatus = $completionStatus;
        }
        
        foreach ($record as $key => $value) {
            if ($key == 'userid' || $key == 'courseid') {
                continue;
            }
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

function exportExcel($sql, $params, $headers, $filename, $hasDateRange = false, $dateRange = null) {
    // Excel export placeholder - use CSV for now
    exportCSV($sql, $params, $headers, $filename, $hasDateRange, $dateRange);
}

function exportPDF($sql, $params, $headers, $filename, $hasDateRange = false, $dateRange = null) {
    // PDF export placeholder - use CSV for now
    exportCSV($sql, $params, $headers, $filename, $hasDateRange, $dateRange);
}
?>
