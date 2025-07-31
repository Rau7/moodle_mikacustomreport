<?php
require('../../config.php');
require_login();
require_capability('local/mikacustomreport:view', context_system::instance());

$PAGE->set_url(new moodle_url('/local/mikacustomreport/index.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Mika Custom Report');
$PAGE->set_heading('Mika Custom Report');

// DataTables CDN
$PAGE->requires->css(new moodle_url('https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css'));
$PAGE->requires->js(new moodle_url('https://code.jquery.com/jquery-3.7.0.min.js'), true);
$PAGE->requires->js(new moodle_url('https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js'), true);

// Custom assets
$PAGE->requires->css(new moodle_url('/local/mikacustomreport/styles.css'));
$PAGE->requires->js(new moodle_url('/local/mikacustomreport/script.js'), true);

echo $OUTPUT->header();
?>

<div class="mikacustomreport-container">
    <h3>Kullanıcı Alanı</h3>
    <div class="checkbox-group" data-group="user">
        <label><input type="checkbox" class="field-checkbox" data-type="user" value="username"> Kullanıcı adı</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="user" value="email"> Kullanıcı E-Postası</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="user" value="firstname"> Ad</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="user" value="lastname"> Soyad</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="user" value="timespent"> Sitede Geçirilen Zaman</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="user" value="start"> Start (Özel)</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="user" value="bolum"> Bölüm (Özel)</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="user" value="end"> End (Özel)</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="user" value="department"> Departman</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="user" value="position"> Pozisyon (Özel)</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="user" value="institution"> Kurum</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="user" value="address"> Adres</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="user" value="city"> Şehir</label>
    </div>

    <h3>Etkinlik Alanı</h3>
    <div class="checkbox-group" data-group="activity">
        <label><input type="checkbox" class="field-checkbox" data-type="activity" value="activityname"> Etkinlik Adı</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="activity" value="category"> Etkinlik Kategorisi</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="activity" value="registrationdate"> Etkinlik Kayıt Tarihi</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="activity" value="progress"> Etkinlik İlerlemesi</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="activity" value="completionstatus"> Etkinlik Tamamlanma Durumu</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="activity" value="activitiescompleted"> Faaliyetler tamamlandı</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="activity" value="totalactivities"> Toplam Aktiviteler</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="activity" value="completiontime"> Tamamlanma Süresi</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="activity" value="activitytimespent"> Etkinlikte Harcanan Zaman</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="activity" value="startdate"> Etkinlik Başlangıç Tarihi</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="activity" value="enddate"> Bitiş Tarihi</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="activity" value="format"> Etkinlik Formatı</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="activity" value="completionenabled"> Etkinlik Tamamlamayı Etkinleştir</label><br>
        <label><input type="checkbox" class="field-checkbox" data-type="activity" value="guestaccess"> Etkinlik Konuğu Erişimi</label>
    </div>

    <table id="report-table" class="display" style="width:100%">
        <thead><tr id="report-head-row"></tr></thead>
        <tbody></tbody>
    </table>
</div>

<?php
echo $OUTPUT->footer();