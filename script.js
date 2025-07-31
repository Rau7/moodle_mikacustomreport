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

    // Önce AJAX çağrısı yaparak verileri alalım
    $.ajax({
      url: M.cfg.wwwroot + "/local/mikacustomreport/get_report_data.php",
      type: "POST",
      contentType: "application/json",
      data: JSON.stringify({
        draw: 1,
        start: 0,
        length: 1000,
        user: selected.user,
        activity: selected.activity,
      }),
      success: function (response) {
        console.log("Received data:", response);

        if (response.data && response.data.length > 0) {
          // Sütun başlıklarını oluştur
          const headers = Object.keys(response.data[0]);
          renderTableHeader(headers);

          // DataTable'ı başlat
          dataTable = $("#report-table").DataTable({
            data: response.data,
            columns: headers.map((header) => ({ title: header, data: header })),
            responsive: true,
            pageLength: 10,
            lengthMenu: [
              [5, 10, 25, 50, 100, -1],
              [5, 10, 25, 50, 100, "Tümü"],
            ],
            language: {
              url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json",
            },
            dom: "Blfrtip", // Buttons, length, filter, table, info, pagination
            buttons: ["copy", "csv", "excel", "pdf", "print"],
            // Her sütun için arama kutusu ekle
            initComplete: function () {
              var api = this.api();

              // Her sütun için arama kutusu ekle
              api.columns().every(function (index) {
                var column = this;
                var title = $(column.header()).text();

                // Sütun başlığının altına arama kutusu ekle
                var input = $(
                  '<input type="text" placeholder="' + title + ' ara" />'
                )
                  .appendTo($(column.footer()).empty())
                  .on("keyup change", function () {
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
