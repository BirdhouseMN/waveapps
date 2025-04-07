jQuery(document).ready(function ($) {
  $('#wave-export-csv').on('click', function () {
    const rows = [];
    const table = $('.wave-invoice-table');

    if (!table.length) return;

    // Header row
    table.find('thead tr').each(function () {
      const headers = [];
      $(this).find('th').each(function () {
        headers.push($(this).text().trim());
      });
      rows.push(headers);
    });

    // Data rows
    table.find('tbody tr').each(function () {
      const data = [];
      $(this).find('td').each(function () {
        data.push($(this).text().trim());
      });
      rows.push(data);
    });

    // Convert to CSV
    let csvContent = '';
    rows.forEach(function (rowArray) {
      const row = rowArray.map((r) => `"${r.replace(/"/g, '""')}"`).join(',');
      csvContent += row + '\r\n';
    });

    // Trigger download
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'wave-invoices.csv';
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  });
});
