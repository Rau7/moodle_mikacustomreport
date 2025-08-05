<?php
require('../../config.php');
require_login();
require_capability('local/mikacustomreport:view', context_system::instance());

$PAGE->set_url(new moodle_url('/local/mikacustomreport/index.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Mika Custom Report');
$PAGE->set_heading('Mika Custom Report');

// jQuery CDN
$PAGE->requires->js(new moodle_url('https://code.jquery.com/jquery-3.7.0.min.js'), true);

// DataTables CDN
$PAGE->requires->css(new moodle_url('https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css'));
$PAGE->requires->js(new moodle_url('https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js'), true);

// DataTables Buttons eklentisi
$PAGE->requires->css(new moodle_url('https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css'));
$PAGE->requires->js(new moodle_url('https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js'), true);
$PAGE->requires->js(new moodle_url('https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js'), true);
$PAGE->requires->js(new moodle_url('https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js'), true);
$PAGE->requires->js(new moodle_url('https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js'), true);
$PAGE->requires->js(new moodle_url('https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js'), true);
$PAGE->requires->js(new moodle_url('https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js'), true);

// Select2 CDN
$PAGE->requires->css(new moodle_url('https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css'));
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js'), true);

// FontAwesome for icons
$PAGE->requires->css(new moodle_url('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css'));

// Custom assets
$PAGE->requires->css(new moodle_url('/local/mikacustomreport/styles.css'));
$PAGE->requires->js(new moodle_url('/local/mikacustomreport/script.js'), true);

echo $OUTPUT->header();
?>

<div class="mikacustomreport-container">
    <div class="field-selection-container">
        <div class="row">
            <div class="col-md-6">
                <div class="field-group">
                    <h4><i class="fa fa-user"></i> Kullanıcı Alanları</h4>
                    <select id="user-fields-select" class="field-select" multiple="multiple" data-type="user" style="width: 100%;">
                        <option value="username">Kullanıcı Adı</option>
                        <option value="email">E-posta</option>
                        <option value="firstname">Ad</option>
                        <option value="lastname">Soyad</option>
                        <option value="timespent">Sitede Geçirilen Zaman</option>
                        <option value="start">Başlangıç Tarihi (Özel)</option>
                        <option value="bolum">Bölüm (Özel)</option>
                        <option value="end">Bitiş Tarihi (Özel)</option>
                        <option value="department">Departman</option>
                        <option value="position">Pozisyon (Özel)</option>
                        <option value="institution">Kurum</option>
                        <option value="address">Adres</option>
                        <option value="city">Şehir</option>
                        <option value="lastaccess">Son Erişim</option>
                        <option value="firstaccess">İlk Erişim</option>
                        <option value="timecreated">Hesap Oluşturma Tarihi</option>
                        <option value="durum">Durum (Aktif/Pasif)</option>
                    </select>
                </div>
            </div>
            <div class="col-md-6">
                <div class="field-group">
                    <h4><i class="fa fa-graduation-cap"></i> Etkinlik Alanları</h4>
                    <select id="activity-fields-select" class="field-select" multiple="multiple" data-type="activity" style="width: 100%;">
                        <option value="activityname">Etkinlik Adı</option>
                        <option value="category">Kategori</option>
                        <option value="registrationdate">Kayıt Tarihi</option>
                        <option value="progress">İlerleme (%)</option>
                        <option value="completionstatus">Tamamlanma Durumu</option>
                        <option value="activitiescompleted">Tamamlanan Aktiviteler</option>
                        <option value="totalactivities">Toplam Aktiviteler</option>
                        <option value="completiontime">Tamamlanma Süresi</option>
                        <option value="activitytimespent">Etkinlikte Geçirilen Zaman</option>
                        <option value="startdate">Başlangıç Tarihi</option>
                        <option value="enddate">Bitiş Tarihi</option>
                        <option value="format">Format</option>
                        <!--<option value="completionenabled">Tamamlama Etkin</option>-->
                        <!--<option value="guestaccess">Misafir Erişimi</option>-->
                    </select>
                </div>
            </div>
        </div>
        
        <div class="selected-fields-container">
            <h4><i class="fa fa-list"></i> Seçilen Alanlar</h4>
            <div id="selected-fields-display" class="selected-fields-display">
                <p class="no-fields-message">Henüz alan seçilmedi. Yukarıdaki dropdown'lardan alanları seçin.</p>
            </div>
            <button id="clear-all-fields" class="btn btn-warning" style="display: none;">
                <i class="fa fa-trash"></i> Tüm Alanları Temizle
            </button>
        </div>
        
        <!-- Date Range Filter for Activity Time Spent -->
        <div id="date-range-container" class="date-range-container" style="display: none;">
            <h4><i class="fa fa-calendar"></i> Eğitimde Geçirilen Süre - Tarih Aralığı</h4>
            <div class="row">
                <div class="col-md-6">
                    <label for="start-year">Başlangıç Yılı:</label>
                    <select id="start-year" class="form-control">
                        <option value="2023">2023</option>
                        <option value="2024">2024</option>
                        <option value="2025" selected>2025</option>
                        <option value="2026">2026</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="start-month">Başlangıç Ayı:</label>
                    <select id="start-month" class="form-control">
                        <option value="01">Ocak</option>
                        <option value="02">Şubat</option>
                        <option value="03">Mart</option>
                        <option value="04">Nisan</option>
                        <option value="05">Mayıs</option>
                        <option value="06" selected>Haziran</option>
                        <option value="07">Temmuz</option>
                        <option value="08">Ağustos</option>
                        <option value="09">Eylül</option>
                        <option value="10">Ekim</option>
                        <option value="11">Kasım</option>
                        <option value="12">Aralık</option>
                    </select>
                </div>
            </div>
            <div class="row" style="margin-top: 10px;">
                <div class="col-md-6">
                    <label for="end-year">Bitiş Yılı:</label>
                    <select id="end-year" class="form-control">
                        <option value="2023">2023</option>
                        <option value="2024">2024</option>
                        <option value="2025" selected>2025</option>
                        <option value="2026">2026</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="end-month">Bitiş Ayı:</label>
                    <select id="end-month" class="form-control">
                        <option value="01">Ocak</option>
                        <option value="02">Şubat</option>
                        <option value="03">Mart</option>
                        <option value="04">Nisan</option>
                        <option value="05">Mayıs</option>
                        <option value="06">Haziran</option>
                        <option value="07" selected>Temmuz</option>
                        <option value="08">Ağustos</option>
                        <option value="09">Eylül</option>
                        <option value="10">Ekim</option>
                        <option value="11">Kasım</option>
                        <option value="12">Aralık</option>
                    </select>
                </div>
            </div>
            <div class="row" style="margin-top: 10px;">
                <div class="col-md-12">
                    <small class="text-muted">
                        <i class="fa fa-info-circle"></i> 
                        Bu tarih aralığı sadece "Etkinlikte Geçirilen Zaman" alanı için geçerlidir.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <table id="report-table" class="display" style="width:100%">
        <thead>
            <tr></tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<?php
echo $OUTPUT->footer();