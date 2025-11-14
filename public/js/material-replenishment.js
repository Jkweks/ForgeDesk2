(function () {
  function formatNumber(value) {
    if (!Number.isFinite(value)) {
      return '0';
    }

    const absolute = Math.abs(value);
    const minimumFractionDigits = absolute >= 1 ? 0 : 2;

    return value.toLocaleString(undefined, {
      minimumFractionDigits,
      maximumFractionDigits: 3,
    });
  }

  function initTabs() {
    const containers = document.querySelectorAll('[data-replenishment-tabs]');

    containers.forEach((container) => {
      const tabButtons = Array.from(container.querySelectorAll('[role="tab"]'));
      if (tabButtons.length === 0) {
        return;
      }

      const panels = new Map();
      tabButtons.forEach((button) => {
        const panelId = button.getAttribute('data-replenishment-target');
        if (panelId) {
          const panel = document.getElementById(panelId);
          if (panel) {
            panels.set(button, panel);
          }
        }
      });

      function activate(button) {
        if (!button || !panels.has(button)) {
          return;
        }

        tabButtons.forEach((tab) => {
          const panel = panels.get(tab);
          const isActive = tab === button;
          tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
          tab.setAttribute('tabindex', isActive ? '0' : '-1');
          if (panel) {
            if (isActive) {
              panel.removeAttribute('hidden');
            } else {
              panel.setAttribute('hidden', '');
            }
          }
        });

        button.focus();
      }

      tabButtons.forEach((button, index) => {
        button.addEventListener('click', () => activate(button));
        button.addEventListener('keydown', (event) => {
          if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
            return;
          }

          event.preventDefault();
          const direction = event.key === 'ArrowLeft' ? -1 : 1;
          const nextIndex = (index + direction + tabButtons.length) % tabButtons.length;
          activate(tabButtons[nextIndex]);
        });
      });

      const initiallyActive = tabButtons.find((button) => button.getAttribute('aria-selected') === 'true');
      if (initiallyActive) {
        activate(initiallyActive);
      }
    });
  }

  function initForm(form) {
    const summaryContainer = form.querySelector('[data-summary]');
    const selectedCountEl = summaryContainer ? summaryContainer.querySelector('[data-selected-count]') : null;
    const recommendedTotalEl = summaryContainer ? summaryContainer.querySelector('[data-recommended-total]') : null;
    const orderTotalEl = summaryContainer ? summaryContainer.querySelector('[data-selected-quantity]') : null;
    const checkboxes = Array.from(form.querySelectorAll('.js-line-select'));
    const quantityInputs = Array.from(form.querySelectorAll('.js-quantity-input'));

    function getQuantityInput(lineId) {
      return form.querySelector('.js-quantity-input[data-line-id="' + lineId + '"]');
    }

    function updateSummary() {
      let selectedCount = 0;
      let recommendedTotal = 0;
      let orderTotal = 0;

      checkboxes.forEach((checkbox) => {
        const lineId = checkbox.getAttribute('data-line-id') || checkbox.value;
        const quantityInput = getQuantityInput(lineId);
        const recommendedValue = quantityInput ? Number.parseFloat(quantityInput.getAttribute('data-recommended') || '0') : 0;

        if (checkbox.checked) {
          selectedCount += 1;
          if (Number.isFinite(recommendedValue)) {
            recommendedTotal += recommendedValue;
          }

          if (quantityInput) {
            const currentValue = Number.parseFloat(quantityInput.value || '0');
            if (Number.isFinite(currentValue)) {
              orderTotal += currentValue;
              quantityInput.removeAttribute('aria-invalid');
            } else {
              quantityInput.setAttribute('aria-invalid', 'true');
            }
          }
        }
      });

      if (selectedCountEl) {
        selectedCountEl.textContent = selectedCount.toString();
      }
      if (recommendedTotalEl) {
        recommendedTotalEl.textContent = formatNumber(recommendedTotal);
      }
      if (orderTotalEl) {
        orderTotalEl.textContent = formatNumber(orderTotal);
      }
    }

    checkboxes.forEach((checkbox) => {
      const lineId = checkbox.getAttribute('data-line-id') || checkbox.value;
      const quantityInput = getQuantityInput(lineId);

      if (checkbox.checked && quantityInput && quantityInput.value.trim() === '') {
        const recommended = quantityInput.getAttribute('data-recommended');
        if (recommended && Number.parseFloat(recommended) > 0) {
          quantityInput.value = recommended;
        }
      }

      checkbox.addEventListener('change', () => {
        if (!quantityInput) {
          updateSummary();
          return;
        }

        if (checkbox.checked && quantityInput.value.trim() === '') {
          const recommended = quantityInput.getAttribute('data-recommended');
          if (recommended && Number.parseFloat(recommended) > 0) {
            quantityInput.value = recommended;
          }
        }

        updateSummary();
      });
    });

    quantityInputs.forEach((input) => {
      input.addEventListener('input', () => {
        if (input.value.trim() === '') {
          input.removeAttribute('aria-invalid');
          updateSummary();
          return;
        }

        const numericValue = Number.parseFloat(input.value);
        if (!Number.isFinite(numericValue) || numericValue < 0) {
          input.setAttribute('aria-invalid', 'true');
        } else {
          input.removeAttribute('aria-invalid');
        }

        updateSummary();
      });
    });

    form.addEventListener('submit', (event) => {
      const selected = checkboxes.filter((checkbox) => checkbox.checked);
      if (selected.length === 0) {
        event.preventDefault();
        window.alert('Select at least one inventory item before generating an order.');
        return;
      }

      let hasInvalid = false;
      selected.forEach((checkbox) => {
        const lineId = checkbox.getAttribute('data-line-id') || checkbox.value;
        const quantityInput = getQuantityInput(lineId);
        if (!quantityInput) {
          hasInvalid = true;
          return;
        }

        const numericValue = Number.parseFloat(quantityInput.value);
        if (!Number.isFinite(numericValue) || numericValue <= 0) {
          quantityInput.setAttribute('aria-invalid', 'true');
          hasInvalid = true;
        }
      });

      if (hasInvalid) {
        event.preventDefault();
        window.alert('Enter a positive order quantity for each selected item.');
      }
    });

    updateSummary();
  }

  function initForms() {
    const forms = document.querySelectorAll('.js-replenishment-form');
    forms.forEach((form) => initForm(form));
  }

  function init() {
    initTabs();
    initForms();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
