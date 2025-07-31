let datatable = null;

document.addEventListener("DOMContentLoaded", function () {
  const checkboxes = document.querySelectorAll(".field-checkbox");
  const table = document.getElementById("report-table");
  const thead = table.querySelector("thead");
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
    const tr = document.createElement("tr");
    columns.forEach((col) => {
      const th = document.createElement("th");
      th.textContent = col;
      tr.appendChild(th);
    });
    thead.appendChild(tr);
  }

  function renderTableData(data) {
    tbody.innerHTML = "";

    if (data.length === 0) {
      const tr = document.createElement("tr");
      const td = document.createElement("td");
      td.textContent = "Veri bulunamadı";
      td.colSpan = thead.querySelectorAll("th").length || 1;
      tr.appendChild(td);
      tbody.appendChild(tr);
      return;
    }

    data.forEach((row) => {
      const tr = document.createElement("tr");
      Object.values(row).forEach((value) => {
        const td = document.createElement("td");
        td.textContent = value !== null ? value : "N/A";
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
  }

  function rebuildDataTable() {
    const selected = getSelectedFields();
    const allFields = [...selected.user, ...selected.activity];

    if (allFields.length === 0) {
      thead.innerHTML = "";
      tbody.innerHTML = "";
      return;
    }

    // DataTables'ı kullanmadan manuel olarak tablo oluşturalım
    $.ajax({
      url: M.cfg.wwwroot + "/local/mikacustomreport/get_report_data.php",
      type: "POST",
      contentType: "application/json",
      data: JSON.stringify({
        draw: 1,
        start: 0,
        length: 100, // Daha fazla veri göster
        user: selected.user,
        activity: selected.activity,
      }),
      success: function (response) {
        console.log("Received data:", response);

        if (response.data && response.data.length > 0) {
          // Başlıkları oluştur
          const headers = Object.keys(response.data[0]);
          renderTableHeader(headers);

          // Verileri oluştur
          renderTableData(response.data);
        } else {
          renderTableHeader(["Sonuç"]);
          renderTableData([]);
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
