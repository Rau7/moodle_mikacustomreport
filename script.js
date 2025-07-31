let datatable;

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
    thead.innerHTML = ""; // Tüm başlıkları sil
    const tr = document.createElement("tr");
    columns.forEach((col) => {
      const th = document.createElement("th");
      th.textContent = col.title || col.data;
      tr.appendChild(th);
    });
    thead.appendChild(tr);
  }

  function rebuildDataTable() {
    const selected = getSelectedFields();
    const allFields = [...selected.user, ...selected.activity];

    if (datatable) {
      datatable.destroy();
      tbody.innerHTML = "";
    }

    if (allFields.length === 0) {
      return;
    }

    datatable = new DataTable("#report-table", {
      processing: true,
      serverSide: true,
      ajax: {
        url: M.cfg.wwwroot + "/local/mikacustomreport/get_report_data.php",
        type: "POST",
        contentType: "application/json",
        data: function (d) {
          const fields = getSelectedFields();
          return JSON.stringify({
            draw: d.draw,
            start: d.start,
            length: d.length,
            user: fields.user,
            activity: fields.activity,
          });
        },
        dataSrc: function (json) {
          if (!datatable && json.data.length > 0) {
            const dynamicCols = Object.keys(json.data[0]).map((key) => ({
              data: key,
              title: key,
            }));
            renderTableHeader(dynamicCols);
          } else if (json.data.length === 0) {
            renderTableHeader([{ data: "No Data" }]);
          }
          return json.data;
        },
        error: function (xhr, error, thrown) {
          console.error("AJAX Error:", error, thrown);
          console.log(xhr.responseText);
        },
      },
      columns: allFields.map((f) => ({ data: f, title: f })),
    });
  }

  checkboxes.forEach((cb) => {
    cb.addEventListener("change", rebuildDataTable);
  });
});
