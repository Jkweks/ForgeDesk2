(function () {
  function normalize(value) {
    return String(value ?? '').trim();
  }

  function resolvePagination(container) {
    if (!(container instanceof HTMLElement)) {
      return {};
    }

    return {
      status: container.querySelector('[data-pagination-status]'),
      prev: container.querySelector('[data-pagination-prev]'),
      next: container.querySelector('[data-pagination-next]'),
    };
  }

  function initInventoryTable(table) {
    const container = table.closest('[data-inventory-table]') || table.closest('[data-datatable-wrapper]');
    const searchInput = container?.querySelector('[data-filter-search]');
    const statusSelect = container?.querySelector('[data-filter-status]');
    const locationSelect = container?.querySelector('[data-filter-location]');
    const pageSize = Number.parseInt(container?.dataset.pageSize || table.dataset.defaultPageSize || '50', 10);
    const pagination = resolvePagination(container);

    const instance = window.ForgeDataTables?.create(table, {
      pageSize,
      defaultSortKey: 'item',
      columns: {
        stock: { type: 'number' },
        committed: { type: 'number' },
        available: { type: 'number' },
        leadTime: { type: 'number' },
        averageDailyUse: { type: 'number' },
        reservations: { type: 'number' },
      },
      search: (row) => [row.dataset.item, row.dataset.sku, row.dataset.location]
        .map((value) => normalize(value).toLowerCase())
        .join(' '),
      pagination,
      searchInput,
    });

    if (!instance) {
      return;
    }

    function applyStatusFilter() {
      const value = normalize(statusSelect?.value || '').toLowerCase();
      if (!value) {
        instance.setFilter('status', null);
        return;
      }

      instance.setFilter('status', (row) => normalize(row.dataset.status || '').toLowerCase() === value);
    }

    function applyLocationFilter() {
      const value = normalize(locationSelect?.value || '');
      if (!value) {
        instance.setFilter('location', null);
        return;
      }

      instance.setFilter('location', (row) => {
        const ids = normalize(row.dataset.locationIds || '')
          .split(',')
          .map((part) => part.trim())
          .filter(Boolean);

        return ids.includes(value);
      });
    }

    if (statusSelect instanceof HTMLSelectElement) {
      statusSelect.addEventListener('change', applyStatusFilter);
      applyStatusFilter();
    }

    if (locationSelect instanceof HTMLSelectElement) {
      locationSelect.addEventListener('change', applyLocationFilter);
      applyLocationFilter();
    }

    initReservationModal(container);
  }

  function initPurchaseOrderTable(table) {
    const container = table.closest('[data-datatable-wrapper]') || table.parentElement;
    const pagination = resolvePagination(container);
    const columnFilters = {};

    container?.querySelectorAll('.column-filter').forEach((input) => {
      const key = input instanceof HTMLInputElement ? input.dataset.key || '' : '';
      if (key) {
        columnFilters[key] = input;
      }
    });

    window.ForgeDataTables?.create(table, {
      pageSize: Number.parseInt(table.dataset.defaultPageSize || '25', 10),
      defaultSortKey: 'sku',
      columns: {
        ordered: { type: 'number' },
        received: { type: 'number' },
        cancelled: { type: 'number' },
        outstanding: { type: 'number' },
        unitCost: { type: 'number' },
        lineTotal: { type: 'number' },
      },
      search: (row) => [row.dataset.sku, row.dataset.description]
        .map((value) => normalize(value).toLowerCase())
        .join(' '),
      pagination,
      columnFilters,
    });
  }

  function initCycleCountTable(table) {
    const container = table.closest('[data-datatable-wrapper]') || table.parentElement;
    const pagination = resolvePagination(container);
    const searchInput = container?.querySelector('[data-filter-search]');

    window.ForgeDataTables?.create(table, {
      pageSize: Number.parseInt(table.dataset.defaultPageSize || '25', 10),
      defaultSortKey: 'location',
      columns: {
        counted: { type: 'number' },
        expected: { type: 'number' },
        variance: { type: 'number' },
      },
      search: (row) => [row.dataset.location, row.dataset.sku, row.dataset.item]
        .map((value) => normalize(value).toLowerCase())
        .join(' '),
      pagination,
      searchInput,
    });
  }

  function formatQuantity(value) {
    const numeric = Number.parseFloat(value);
    if (!Number.isFinite(numeric)) {
      return String(value);
    }

    return numeric.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 3 });
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

  function initReservationModal(container) {
    if (!(container instanceof HTMLElement)) {
      return;
    }

    const modal = document.querySelector('[data-reservation-modal]');
    const body = modal instanceof HTMLElement ? modal.querySelector('[data-reservation-modal-body]') : null;
    const title = modal instanceof HTMLElement ? modal.querySelector('[data-reservation-modal-title]') : null;
    const subtitle = modal instanceof HTMLElement ? modal.querySelector('[data-reservation-modal-subtitle]') : null;
    const meta = modal instanceof HTMLElement ? modal.querySelector('[data-reservation-modal-meta]') : null;
    const closeButton = modal instanceof HTMLElement ? modal.querySelector('[data-reservation-modal-close]') : null;

    function closeModal() {
      if (!(modal instanceof HTMLElement)) {
        return;
      }

      modal.classList.remove('open');
      modal.setAttribute('aria-hidden', 'true');
      modal.setAttribute('hidden', 'hidden');
      document.body.classList.remove('reservation-modal-open');
    }

    function openModal(data) {
      if (!(modal instanceof HTMLElement)) {
        return;
      }

      if (title instanceof HTMLElement) {
        title.textContent = data.title || 'Job commitments';
      }

      if (subtitle instanceof HTMLElement) {
        subtitle.textContent = data.subtitle || 'Committed jobs';
      }

      if (meta instanceof HTMLElement) {
        meta.textContent = data.meta || '';
        meta.hidden = !data.meta;
      }

      renderReservationList(body, data.reservations);

      modal.classList.add('open');
      modal.removeAttribute('hidden');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('reservation-modal-open');

      if (closeButton instanceof HTMLElement) {
        closeButton.focus();
      }
    }

    if (closeButton instanceof HTMLElement) {
      closeButton.addEventListener('click', (event) => {
        event.preventDefault();
        closeModal();
      });
    }

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && modal instanceof HTMLElement && modal.classList.contains('open')) {
        closeModal();
      }
    });

    container.addEventListener('click', (event) => {
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

      const subtitleText = trigger.dataset.reservationTrigger === 'committed'
        ? 'Committed quantity by job'
        : 'Active job commitments';

      openModal({
        title: row.dataset.item || 'Job commitments',
        subtitle: subtitleText,
        meta: row.dataset.sku ? `SKU ${row.dataset.sku}` : '',
        reservations: details,
      });
    });
  }

  function init() {
    const tables = document.querySelectorAll('table[data-datatable]');
    if (tables.length === 0) {
      return;
    }

    tables.forEach((table) => {
      const type = table.dataset.datatable || '';
      switch (type) {
        case 'inventory':
          initInventoryTable(table);
          break;
        case 'purchase-order-lines':
          initPurchaseOrderTable(table);
          break;
        case 'cycle-count':
          initCycleCountTable(table);
          break;
        default:
          window.ForgeDataTables?.create(table, {
            pagination: resolvePagination(table.closest('[data-datatable-wrapper]') || table.parentElement),
            searchInput: (table.closest('[data-datatable-wrapper]') || table.parentElement)?.querySelector('[data-filter-search]'),
          });
          break;
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
