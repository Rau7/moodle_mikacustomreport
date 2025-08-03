let dataTable = null;

document.addEventListener("DOMContentLoaded", function () {
  const checkboxes = document.querySelectorAll(".field-checkbox");
  const table = document.getElementById("report-table");
  const thead = table.querySelector("thead tr");
  const tbody = table.querySelector("tbody");

  function getSelectedFields() {
    const userFields = [];
    const activityFields = [];

    checkboxes.forEach((cb) => {
      if (cb.checked) {
        if (cb.dataset.type === "user") userFields.push(cb.value);
        else if (cb.dataset.type === "activity") activityFields.push(cb.value);
      }
    });

    return { user: userFields, activity: activityFields };
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

    if (dataTable) {
      dataTable.destroy();
      thead.innerHTML = "";
      tbody.innerHTML = "";
    }

    if (allFields.length === 0) {
      return;
    }

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
        } else {
          renderTableHeader(["Sonuç"]);
          tbody.innerHTML =
            "<tr><td>Seçilen kriterlere uygun veri bulunamadı</td></tr>";
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

  checkboxes.forEach((cb) => {
    cb.addEventListener("change", rebuildDataTable);
  });
});
