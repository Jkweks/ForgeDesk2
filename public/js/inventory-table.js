(function () {
  function formatQuantity(value) {
    if (Number.isNaN(Number(value))) {
      return String(value);
    }

    const numeric = typeof value === 'number' ? value : parseFloat(value);
    return Number.isFinite(numeric)
      ? numeric.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 3 })
      : String(value);
  }

  function parseNumeric(value) {
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function closeReservationModal(modal) {
    if (!(modal instanceof HTMLElement)) {
      return;
    }

    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    modal.setAttribute('hidden', 'hidden');
    document.body.classList.remove('reservation-modal-open');
  }

  function renderReservationList(container, reservations) {
    if (!(container instanceof HTMLElement)) {
      return;
    }

    container.innerHTML = '';

    if (!Array.isArray(reservations) || reservations.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'muted';
      empty.textContent = 'No active job commitments for this part.';
      container.appendChild(empty);
      return;
    }

    const list = document.createElement('ul');
    list.className = 'reservation-breakdown-list';

    reservations.forEach((reservation) => {
      const row = document.createElement('li');

      const label = document.createElement('span');
      label.className = 'job-label';
      label.textContent = reservation && reservation.job_label ? String(reservation.job_label) : 'Job';

      const qty = document.createElement('span');
      qty.className = 'job-qty';
      qty.textContent = formatQuantity(reservation && typeof reservation.committed_qty !== 'undefined'
        ? reservation.committed_qty
        : 0);

      row.appendChild(label);
      row.appendChild(qty);
      list.appendChild(row);
    });

    container.appendChild(list);
  }

  function openReservationModal(options) {
    const {
      modal,
      titleElement,
      subtitleElement,
      metaElement,
      bodyElement,
      closeButton,
      title,
      subtitle,
      meta,
      reservations,
    } = options;

    if (!(modal instanceof HTMLElement)) {
      return;
    }

    if (titleElement instanceof HTMLElement) {
      titleElement.textContent = title || 'Job commitments';
    }

    if (subtitleElement instanceof HTMLElement) {
      subtitleElement.textContent = subtitle || 'Committed jobs';
    }

    if (metaElement instanceof HTMLElement) {
      metaElement.textContent = meta || '';
      metaElement.hidden = !meta;
    }

    renderReservationList(bodyElement, reservations);

    modal.classList.add('open');
    modal.removeAttribute('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('reservation-modal-open');

    if (closeButton instanceof HTMLElement) {
      closeButton.focus();
    }
  }

  function initReservationModal() {
    const modal = document.querySelector('[data-reservation-modal]');
    const body = modal instanceof HTMLElement ? modal.querySelector('[data-reservation-modal-body]') : null;
    const title = modal instanceof HTMLElement ? modal.querySelector('[data-reservation-modal-title]') : null;
    const subtitle = modal instanceof HTMLElement ? modal.querySelector('[data-reservation-modal-subtitle]') : null;
    const meta = modal instanceof HTMLElement ? modal.querySelector('[data-reservation-modal-meta]') : null;
    const closeButton = modal instanceof HTMLElement ? modal.querySelector('[data-reservation-modal-close]') : null;

    if (closeButton instanceof HTMLElement) {
      closeButton.addEventListener('click', (event) => {
        event.preventDefault();
        closeReservationModal(modal);
      });
    }

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && modal instanceof HTMLElement && modal.classList.contains('open')) {
        closeReservationModal(modal);
      }
    });

    return {
      modal,
      body,
      title,
      subtitle,
      meta,
      closeButton,
    };
  }

  function initInventoryTables() {
    const containers = Array.from(document.querySelectorAll('[data-inventory-table]'));
    if (containers.length === 0) {
      return;
    }

    const reservationModal = initReservationModal();
    const sortableColumns = [
      'item',
      'sku',
      'location',
      'stock',
      'committed',
      'available',
      'leadTime',
      'averageDailyUse',
      'status',
      'reservations',
    ];

    containers.forEach((container, containerIndex) => {
      const table = container.querySelector('table');
      if (!(table instanceof HTMLTableElement)) {
        return;
      }

      const tbody = table.querySelector('tbody');
      if (!(tbody instanceof HTMLTableSectionElement)) {
        return;
      }

      const rowElements = Array.from(tbody.querySelectorAll('tr')).filter((row) => row.dataset.item || row.dataset.itemId);
      if (rowElements.length === 0) {
        return;
      }

      const searchInput = container.querySelector('[data-filter-search]');
      const statusSelect = container.querySelector('[data-filter-status]');
      const locationSelect = container.querySelector('[data-filter-location]');

      const pagination = container.querySelector('[data-pagination]');
      const paginationStatus = pagination instanceof HTMLElement ? pagination.querySelector('[data-pagination-status]') : null;
      const paginationPrev = pagination instanceof HTMLElement ? pagination.querySelector('[data-pagination-prev]') : null;
      const paginationNext = pagination instanceof HTMLElement ? pagination.querySelector('[data-pagination-next]') : null;

      const defaultPageSize = table.dataset.defaultPageSize || container.dataset.pageSize || '50';
      const pageSize = Math.max(1, parseInt(defaultPageSize, 10));

      const headers = Array.from(table.querySelectorAll('thead th'));
      headers.forEach((header, index) => {
        const key = sortableColumns[index] || '';
        if (!key) {
          return;
        }

        header.dataset.sortKey = key;
        header.setAttribute('tabindex', '0');
        header.classList.add('sortable');
      });

      let sortKey = 'item';
      let sortDirection = 'asc';
      let filteredRows = [...rowElements];
      let currentPage = 1;

      function updateSortIndicators() {
        headers.forEach((header) => {
          const key = header.dataset.sortKey || '';
          if (key === sortKey) {
            header.setAttribute('data-sort-direction', sortDirection);
            header.setAttribute('aria-sort', sortDirection);
          } else if (key) {
            header.removeAttribute('data-sort-direction');
            header.setAttribute('aria-sort', 'none');
          }
        });
      }

      function applyFilters() {
        const searchTerm = searchInput instanceof HTMLInputElement ? searchInput.value.trim().toLowerCase() : '';
        const statusValue = statusSelect instanceof HTMLSelectElement ? statusSelect.value.trim().toLowerCase() : '';
        const locationValue = locationSelect instanceof HTMLSelectElement ? locationSelect.value.trim() : '';

        filteredRows = rowElements.filter((row) => {
          const combinedText = [row.dataset.item, row.dataset.sku, row.dataset.location]
            .map((value) => (value || '').toLowerCase())
            .join(' ');

          if (searchTerm && !combinedText.includes(searchTerm)) {
            return false;
          }

          if (statusValue && (row.dataset.status || '').toLowerCase() !== statusValue) {
            return false;
          }

          if (locationValue !== '') {
            const ids = (row.dataset.locationIds || '')
              .split(',')
              .map((value) => value.trim())
              .filter((value) => value !== '');

            if (!ids.includes(locationValue)) {
              return false;
            }
          }

          return true;
        });

        currentPage = 1;
      }

      function compareRows(a, b, key) {
        const numericKeys = new Set(['stock', 'committed', 'available', 'leadTime', 'averageDailyUse', 'reservations']);

        const aValue = a.dataset[key] || '';
        const bValue = b.dataset[key] || '';

        if (numericKeys.has(key)) {
          return parseNumeric(aValue) - parseNumeric(bValue);
        }

        return aValue.toLowerCase().localeCompare(bValue.toLowerCase(), undefined, {
          numeric: true,
          sensitivity: 'base',
        });
      }

      function updatePagination(totalRows, startIndex, visibleCount) {
        const startDisplay = totalRows === 0 ? 0 : startIndex + 1;
        const endDisplay = totalRows === 0 ? 0 : startIndex + visibleCount;

        if (paginationStatus instanceof HTMLElement) {
          const label = totalRows === 0
            ? 'No matching items'
            : `Showing ${startDisplay}–${endDisplay} of ${totalRows} items`;
          paginationStatus.textContent = label;
        }

        const onFirstPage = currentPage === 1;
        const onLastPage = startIndex + visibleCount >= totalRows;

        if (paginationPrev instanceof HTMLButtonElement) {
          paginationPrev.disabled = onFirstPage;
          const parent = paginationPrev.closest('.page-item');
          if (parent) {
            parent.classList.toggle('disabled', onFirstPage);
          }
        }

        if (paginationNext instanceof HTMLButtonElement) {
          paginationNext.disabled = onLastPage;
          const parent = paginationNext.closest('.page-item');
          if (parent) {
            parent.classList.toggle('disabled', onLastPage);
          }
        }
      }

      function render() {
        const sorted = [...filteredRows].sort((a, b) => compareRows(a, b, sortKey) * (sortDirection === 'desc' ? -1 : 1));
        const startIndex = (currentPage - 1) * pageSize;
        const pageRows = sorted.slice(startIndex, startIndex + pageSize);

        tbody.innerHTML = '';
        pageRows.forEach((row) => tbody.appendChild(row));
        updatePagination(sorted.length, startIndex, pageRows.length);
      }

      function handleReservationTrigger(event) {
        const trigger = event.target instanceof HTMLElement
          ? event.target.closest('[data-reservation-trigger]')
          : null;

        if (!trigger) {
          return;
        }

        event.preventDefault();

        const row = trigger.closest('tr');
        if (!row) {
          return;
        }

        const rawDetails = row.getAttribute('data-reservation-details') || '[]';
        let details = [];

        try {
          const parsed = JSON.parse(rawDetails);
          if (Array.isArray(parsed)) {
            details = parsed;
          }
        } catch (error) {
          details = [];
        }

        const subtitle = trigger.dataset.reservationTrigger === 'committed'
          ? 'Committed quantity by job'
          : 'Active job commitments';

        openReservationModal({
          modal: reservationModal.modal,
          titleElement: reservationModal.title,
          subtitleElement: reservationModal.subtitle,
          metaElement: reservationModal.meta,
          bodyElement: reservationModal.body,
          closeButton: reservationModal.closeButton,
          title: row.dataset.item || 'Job commitments',
          meta: row.dataset.sku ? `SKU ${row.dataset.sku}` : '',
          subtitle,
          reservations: details,
        });
      }

      function setSort(key) {
        if (!key) {
          return;
        }

        if (sortKey === key) {
          sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
          sortKey = key;
          sortDirection = 'asc';
        }

        currentPage = 1;
        updateSortIndicators();
        render();
      }

      headers.forEach((header) => {
        const key = header.dataset.sortKey || '';
        if (!key) {
          return;
        }

        header.addEventListener('click', () => setSort(key));
        header.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            setSort(key);
          }
        });
      });

      if (searchInput instanceof HTMLInputElement) {
        searchInput.addEventListener('input', () => {
          applyFilters();
          render();
        });
      }

      if (statusSelect instanceof HTMLSelectElement) {
        statusSelect.addEventListener('change', () => {
          applyFilters();
          render();
        });
      }

      if (locationSelect instanceof HTMLSelectElement) {
        locationSelect.addEventListener('change', () => {
          applyFilters();
          render();
        });
      }

      if (paginationPrev instanceof HTMLButtonElement) {
        paginationPrev.addEventListener('click', () => {
          if (currentPage > 1) {
            currentPage -= 1;
            render();
          }
        });
      }

      if (paginationNext instanceof HTMLButtonElement) {
        paginationNext.addEventListener('click', () => {
          const totalPages = Math.ceil(filteredRows.length / pageSize);
          if (currentPage < totalPages) {
            currentPage += 1;
            render();
          }
        });
      }

      container.addEventListener('click', handleReservationTrigger);

      applyFilters();
      updateSortIndicators();
      render();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initInventoryTables);
  } else {
    initInventoryTables();
  }
})();
