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
              scrollX: true,
              scrollCollapse: true,
              autoWidth: false,
              columnDefs: [
                {
                  targets: "_all",
                  className: "dt-nowrap",
                  width: "auto",
                },
              ],
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
              buttons: [
                {
                  extend: "copy",
                  text: "Kopyala",
                  action: function (e, dt, node, config) {
                    exportAllData("copy");
                  },
                },
                {
                  extend: "csv",
                  text: "CSV İndir",
                  action: function (e, dt, node, config) {
                    exportAllData("csv");
                  },
                },
                {
                  extend: "excel",
                  text: "Excel İndir",
                  action: function (e, dt, node, config) {
                    exportAllData("excel");
                  },
                },
                {
                  extend: "pdf",
                  text: "PDF İndir",
                  action: function (e, dt, node, config) {
                    exportAllData("pdf");
                  },
                },
                {
                  extend: "print",
                  text: "Yazdır",
                  action: function (e, dt, node, config) {
                    exportAllData("print");
                  },
                },
              ],
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

  // Export all data function
  window.exportAllData = function (format) {
    const selected = getSelectedFields();
    const allFields = [...selected.user, ...selected.activity];

    if (allFields.length === 0) {
      alert("Lütfen önce export edilecek alanları seçin.");
      return;
    }

    console.log("Exporting all data in format:", format);

    // Get current search value from DataTable
    let searchValue = "";
    if (dataTable) {
      searchValue = dataTable.search();
    }

    const exportData = {
      user: selected.user,
      activity: selected.activity,
      format: format,
      search: searchValue,
    };

    if (format === "copy") {
      // Copy için özel işlem
      copyAllDataToClipboard(exportData);
    } else if (format === "print") {
      // Print için özel işlem
      printAllData(exportData);
    } else {
      // File download için
      downloadAllData(exportData);
    }
  };

  // Download all data
  function downloadAllData(exportData) {
    // Loading göster
    console.log("Starting download export...");

    // Export butonunu geçici olarak disable et
    $(".dt-button").prop("disabled", true).addClass("disabled");

    // Loading mesajı göster
    if (dataTable) {
      const tableContainer = $(dataTable.table().container());
      tableContainer.find(".dataTables_processing").show();
    }

    // Create a temporary form for file download
    const form = document.createElement("form");
    form.method = "POST";
    form.action = M.cfg.wwwroot + "/local/mikacustomreport/export_report.php";
    form.style.display = "none";

    const input = document.createElement("input");
    input.type = "hidden";
    input.name = "data";
    input.value = JSON.stringify(exportData);

    form.appendChild(input);
    document.body.appendChild(form);

    form.submit();

    // Form'u temizle ve loading'i kapat
    setTimeout(() => {
      document.body.removeChild(form);

      // Export butonlarını tekrar aktif et
      $(".dt-button").prop("disabled", false).removeClass("disabled");

      // Loading mesajını gizle
      if (dataTable) {
        const tableContainer = $(dataTable.table().container());
        tableContainer.find(".dataTables_processing").hide();
      }

      console.log("Export completed");
    }, 1000);
  }

  // Copy all data to clipboard
  function copyAllDataToClipboard(exportData) {
    // AJAX ile tüm veriyi al ve clipboard'a kopyala
    $.ajax({
      url: M.cfg.wwwroot + "/local/mikacustomreport/get_report_data.php",
      type: "POST",
      contentType: "application/json",
      data: JSON.stringify({
        draw: 1,
        start: 0,
        length: -1, // Tüm veriyi al
        search: { value: exportData.search },
        user: exportData.user,
        activity: exportData.activity,
      }),
      success: function (response) {
        if (response.data && response.data.length > 0) {
          // CSV formatında string oluştur
          const headers = Object.keys(response.data[0]);
          let csvContent = headers.join("\t") + "\n";

          response.data.forEach((row) => {
            const values = headers.map((header) => row[header] || "");
            csvContent += values.join("\t") + "\n";
          });

          // Clipboard'a kopyala
          navigator.clipboard
            .writeText(csvContent)
            .then(() => {
              alert(`${response.data.length} kayıt panoya kopyalandı!`);
            })
            .catch((err) => {
              console.error("Clipboard error:", err);
              alert("Panoya kopyalama başarısız!");
            });
        } else {
          alert("Kopyalanacak veri bulunamadı!");
        }
      },
      error: function (xhr, error) {
        console.error("Copy export error:", error);
        alert("Veri kopyalanırken hata oluştu!");
      },
    });
  }

  // Print all data
  function printAllData(exportData) {
    // AJAX ile tüm veriyi al ve print et
    $.ajax({
      url: M.cfg.wwwroot + "/local/mikacustomreport/get_report_data.php",
      type: "POST",
      contentType: "application/json",
      data: JSON.stringify({
        draw: 1,
        start: 0,
        length: -1, // Tüm veriyi al
        search: { value: exportData.search },
        user: exportData.user,
        activity: exportData.activity,
      }),
      success: function (response) {
        if (response.data && response.data.length > 0) {
          // Print penceresi oluştur
          const printWindow = window.open("", "_blank");
          const headers = Object.keys(response.data[0]);

          let htmlContent = `
            <html>
              <head>
                <title>Mika Custom Report</title>
                <style>
                  body { font-family: Arial, sans-serif; margin: 20px; }
                  table { border-collapse: collapse; width: 100%; }
                  th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                  th { background-color: #f2f2f2; font-weight: bold; }
                  tr:nth-child(even) { background-color: #f9f9f9; }
                  h1 { color: #333; }
                </style>
              </head>
              <body>
                <h1>Mika Custom Report</h1>
                <p>Toplam Kayıt: ${response.data.length}</p>
                <p>Tarih: ${new Date().toLocaleString("tr-TR")}</p>
                <table>
                  <thead>
                    <tr>
          `;

          headers.forEach((header) => {
            htmlContent += `<th>${header}</th>`;
          });

          htmlContent += `
                    </tr>
                  </thead>
                  <tbody>
          `;

          response.data.forEach((row) => {
            htmlContent += "<tr>";
            headers.forEach((header) => {
              htmlContent += `<td>${row[header] || ""}</td>`;
            });
            htmlContent += "</tr>";
          });

          htmlContent += `
                  </tbody>
                </table>
              </body>
            </html>
          `;

          printWindow.document.write(htmlContent);
          printWindow.document.close();
          printWindow.print();
        } else {
          alert("Yazdırılacak veri bulunamadı!");
        }
      },
      error: function (xhr, error) {
        console.error("Print export error:", error);
        alert("Yazdırma sırasında hata oluştu!");
      },
    });
  }

  // Initialize the interface
  updateSelectedFieldsDisplay();
});
