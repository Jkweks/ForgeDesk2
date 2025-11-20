(function () {
  function parseNumber(value) {
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function initTables() {
    const tables = Array.from(document.querySelectorAll('[data-sortable-table]'));
    if (tables.length === 0) {
      return;
    }

    tables.forEach((table) => {
      if (!(table instanceof HTMLTableElement)) {
        return;
      }

      const tbody = table.querySelector('tbody');
      if (!(tbody instanceof HTMLTableSectionElement)) {
        return;
      }

      const rows = Array.from(tbody.querySelectorAll('tr[data-row]'));
      if (rows.length === 0) {
        return;
      }

      const headers = Array.from(table.querySelectorAll('thead th.sortable'));
      const filters = Array.from(table.querySelectorAll('.column-filter'));
      let sortKey = table.dataset.defaultSortKey || '';
      let sortDirection = 'asc';

      function updateHeaderIndicators() {
        headers.forEach((header) => {
          const key = header.dataset.sortKey || '';
          if (key === sortKey) {
            header.setAttribute('data-sort-direction', sortDirection);
            header.setAttribute('aria-sort', sortDirection);
          } else {
            header.removeAttribute('data-sort-direction');
            header.setAttribute('aria-sort', 'none');
          }
        });
      }

      function applyFilters(sourceRows) {
        if (filters.length === 0) {
          return [...sourceRows];
        }

        return sourceRows.filter((row) => {
          return filters.every((input) => {
            if (!(input instanceof HTMLInputElement)) {
              return true;
            }

            const key = input.dataset.key || '';
            const query = input.value.trim().toLowerCase();
            if (!key || query === '') {
              return true;
            }

            const value = (row.dataset[key] || '').toLowerCase();
            return value.includes(query);
          });
        });
      }

      function sortRows(sourceRows) {
        if (!sortKey) {
          return [...sourceRows];
        }

        const header = headers.find((item) => (item.dataset.sortKey || '') === sortKey);
        const sortType = header?.dataset.sortType === 'number' ? 'number' : 'string';
        const multiplier = sortDirection === 'desc' ? -1 : 1;

        return [...sourceRows].sort((a, b) => {
          const aValue = a.dataset[sortKey] || '';
          const bValue = b.dataset[sortKey] || '';

          if (sortType === 'number') {
            return (parseNumber(aValue) - parseNumber(bValue)) * multiplier;
          }

          return aValue.toLowerCase().localeCompare(bValue.toLowerCase(), undefined, {
            numeric: true,
            sensitivity: 'base',
          }) * multiplier;
        });
      }

      function render() {
        const filtered = applyFilters(rows);
        const sorted = sortRows(filtered);

        sorted.forEach((row) => tbody.appendChild(row));
      }

      headers.forEach((header) => {
        header.setAttribute('tabindex', '0');
        header.addEventListener('click', () => {
          const key = header.dataset.sortKey || '';
          if (!key) {
            return;
          }

          if (sortKey === key) {
            sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
          } else {
            sortKey = key;
            sortDirection = 'asc';
          }

          updateHeaderIndicators();
          render();
        });

        header.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            header.click();
          }
        });
      });

      filters.forEach((input) => {
        if (input instanceof HTMLInputElement) {
          input.addEventListener('input', render);
        }
      });

      updateHeaderIndicators();
      render();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTables);
  } else {
    initTables();
  }
})();
