let dataTable = null;
let selectedFields = { user: [], activity: [] };

// Field labels mapping
const fieldLabels = {
  user: {
    username: "Kullanıcı Adı",
    email: "E-posta",
    firstname: "Ad",
    lastname: "Soyad",
    timespent: "Sitede Geçirilen Zaman",
    start: "Başlangıç Tarihi (Özel)",
    bolum: "Bölüm (Özel)",
    end: "Bitiş Tarihi (Özel)",
    department: "Departman",
    position: "Pozisyon (Özel)",
    institution: "Kurum",
    address: "Adres",
    city: "Şehir",
    lastaccess: "Son Erişim",
    firstaccess: "İlk Erişim",
    timecreated: "Hesap Oluşturma Tarihi",
  },
  activity: {
    activityname: "Etkinlik Adı",
    category: "Kategori",
    registrationdate: "Kayıt Tarihi",
    progress: "İlerleme (%)",
    completionstatus: "Tamamlanma Durumu",
    activitiescompleted: "Tamamlanan Aktiviteler",
    totalactivities: "Toplam Aktiviteler",
    completiontime: "Tamamlanma Süresi",
    activitytimespent: "Etkinlikte Geçirilen Zaman",
    startdate: "Başlangıç Tarihi",
    enddate: "Bitiş Tarihi",
    format: "Format",
    completionenabled: "Tamamlama Etkin",
    guestaccess: "Misafir Erişimi",
  },
};

document.addEventListener("DOMContentLoaded", function () {
  const table = document.getElementById("report-table");
  const thead = table.querySelector("thead tr");
  const tbody = table.querySelector("tbody");

  // Initialize Select2 dropdowns
  initializeSelect2();

  function getSelectedFields() {
    return selectedFields;
  }

  // Initialize Select2 dropdowns
  function initializeSelect2() {
    // User fields dropdown
    $("#user-fields-select")
      .select2({
        placeholder: "Kullanıcı alanlarını seçin...",
        allowClear: true,
        width: "100%",
      })
      .on("change", function () {
        selectedFields.user = $(this).val() || [];
        updateSelectedFieldsDisplay();
        rebuildDataTable();
      });

    // Activity fields dropdown
    $("#activity-fields-select")
      .select2({
        placeholder: "Etkinlik alanlarını seçin...",
        allowClear: true,
        width: "100%",
      })
      .on("change", function () {
        selectedFields.activity = $(this).val() || [];
        updateSelectedFieldsDisplay();
        rebuildDataTable();
      });

    // Clear all fields button
    $("#clear-all-fields").on("click", function () {
      clearAllFields();
    });
  }

  // Update selected fields display
  function updateSelectedFieldsDisplay() {
    const displayContainer = document.getElementById("selected-fields-display");
    const clearButton = document.getElementById("clear-all-fields");

    // Get all selected fields
    const allSelected = [...selectedFields.user, ...selectedFields.activity];

    if (allSelected.length === 0) {
      displayContainer.innerHTML =
        '<p class="no-fields-message">Henüz alan seçilmedi. Yukarıdaki dropdown\'lardan alanları seçin.</p>';
      clearButton.style.display = "none";
      return;
    }

    clearButton.style.display = "inline-block";

    let html = "";

    // Add user fields
    selectedFields.user.forEach((field) => {
      const label = fieldLabels.user[field] || field;
      html += `
        <span class="selected-field-tag">
          <span class="field-type">Kullanıcı</span>
          ${label}
          <button class="remove-field" onclick="removeField('user', '${field}')" title="Kaldır">
            ×
          </button>
        </span>
      `;
    });

    // Add activity fields
    selectedFields.activity.forEach((field) => {
      const label = fieldLabels.activity[field] || field;
      html += `
        <span class="selected-field-tag">
          <span class="field-type">Etkinlik</span>
          ${label}
          <button class="remove-field" onclick="removeField('activity', '${field}')" title="Kaldır">
            ×
          </button>
        </span>
      `;
    });

    displayContainer.innerHTML = html;
  }

  // Remove individual field
  window.removeField = function (type, field) {
    const index = selectedFields[type].indexOf(field);
    if (index > -1) {
      selectedFields[type].splice(index, 1);

      // Update Select2 selection
      $(`#${type}-fields-select`).val(selectedFields[type]).trigger("change");
    }
  };

  // Clear all fields
  function clearAllFields() {
    selectedFields.user = [];
    selectedFields.activity = [];

    // Clear Select2 selections
    $("#user-fields-select").val(null).trigger("change");
    $("#activity-fields-select").val(null).trigger("change");
  }

  function renderTableHeader(columns) {
    thead.innerHTML = "";
    columns.forEach((col) => {
      const th = document.createElement("th");
      th.textContent = col;
      thead.appendChild(th);
    });
  }

  function rebuildDataTable() {
    const selected = getSelectedFields();
    const allFields = [...selected.user, ...selected.activity];

    console.log("rebuildDataTable called with:", {
      userFields: selected.user,
      activityFields: selected.activity,
      totalFields: allFields.length,
    });

    // DataTable'ı temizle
    if (dataTable) {
      console.log("Destroying existing DataTable");
      dataTable.destroy();
      dataTable = null;
    }

    // Tablo içeriğini temizle
    thead.innerHTML = "";
    tbody.innerHTML = "";

    // Eğer hiç alan seçilmemişse tabloyu gizle
    if (allFields.length === 0) {
      console.log("No fields selected, hiding table");
      $("#report-table").hide();
      return;
    }

    console.log("Fields selected, showing table and fetching data");
    // Tabloyu göster
    $("#report-table").show();

    // İlk olarak sütun başlıklarını almak için küçük bir örnek veri çekelim
    $.ajax({
      url: M.cfg.wwwroot + "/local/mikacustomreport/get_report_data.php",
      type: "POST",
      contentType: "application/json",
      data: JSON.stringify({
        draw: 1,
        start: 0,
        length: 1,
        user: selected.user,
        activity: selected.activity,
        getColumns: true, // Sadece sütun bilgilerini al
      }),
      success: function (response) {
        console.log("Column info received:", response);

        if (response.columns && response.columns.length > 0) {
          // Sütun başlıklarını oluştur
          renderTableHeader(response.columns.map((col) => col.title));

          // DataTable'ı server-side processing ile başlat
          try {
            dataTable = $("#report-table").DataTable({
              processing: true,
              serverSide: true,
              ajax: {
                url:
                  M.cfg.wwwroot + "/local/mikacustomreport/get_report_data.php",
                type: "POST",
                contentType: "application/json",
                data: function (d) {
                  // DataTables parametrelerini backend'e gönder
                  return JSON.stringify({
                    draw: d.draw,
                    start: d.start,
                    length: d.length,
                    search: d.search,
                    order: d.order,
                    columns: d.columns,
                    user: selected.user,
                    activity: selected.activity,
                  });
                },
                dataSrc: function (json) {
                  // Backend'den gelen veriyi DataTables formatına çevir
                  return json.data;
                },
              },
              columns: response.columns,
              responsive: true,
              pageLength: 10,
              lengthMenu: [
                [5, 10, 25, 50, 100],
                [5, 10, 25, 50, 100],
              ],
              language: {
                url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json",
                processing: "Veriler yükleniyor...",
                loadingRecords: "Kayıtlar yükleniyor...",
              },
              dom: "Blfrtip",
              buttons: ["copy", "csv", "excel", "pdf", "print"],
              // Sütun bazlı arama için footer ekle
              initComplete: function () {
                var api = this.api();

                // Her sütun için arama kutusu ekle
                api.columns().every(function (index) {
                  var column = this;
                  var title = $(column.header()).text();

                  var input = $(
                    '<input type="text" placeholder="' + title + ' ara" />'
                  )
                    .appendTo($(column.footer()).empty())
                    .on("keyup change clear", function () {
                      if (column.search() !== this.value) {
                        column.search(this.value).draw();
                      }
                    });
                });
              },
            });
          } catch (error) {
            console.error("DataTable creation error:", error);
            renderTableHeader(["Hata"]);
            tbody.innerHTML = `<tr><td>Tablo oluşturulurken hata oluştu: ${error.message}</td></tr>`;
          }
        } else {
          console.warn("No columns received from server");
          renderTableHeader(["Uyarı"]);
          tbody.innerHTML =
            "<tr><td>Seçilen kriterlere uygun sütun bulunamadı</td></tr>";
        }
      },
      error: function (xhr, error, thrown) {
        console.error("AJAX Error:", error, thrown);
        console.log(xhr.responseText);

        renderTableHeader(["Hata"]);
        tbody.innerHTML = `<tr><td>Veri alınırken bir hata oluştu: ${error}</td></tr>`;
      },
    });
  }

  // Initialize the interface
  updateSelectedFieldsDisplay();
});
