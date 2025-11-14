(function () {
  function initSidebarToggle() {
    const sidebar = document.getElementById('app-sidebar');
    if (!sidebar) {
      return;
    }

    const toggleButtons = Array.from(document.querySelectorAll('[data-sidebar-toggle]'));
    if (toggleButtons.length === 0) {
      return;
    }

    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const body = document.body;
    const mobileQuery = window.matchMedia('(max-width: 960px)');
    let lastFocusedButton = null;
    let backdropHideTimeout = null;

    function setExpanded(isExpanded) {
      toggleButtons.forEach((button) => {
        button.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
      });
    }

    function showBackdrop() {
      if (!(backdrop instanceof HTMLElement)) {
        return;
      }

      if (backdropHideTimeout !== null) {
        window.clearTimeout(backdropHideTimeout);
        backdropHideTimeout = null;
      }

      backdrop.hidden = false;
      requestAnimationFrame(() => {
        backdrop.classList.add('is-visible');
      });
    }

    function hideBackdrop() {
      if (!(backdrop instanceof HTMLElement)) {
        return;
      }

      backdrop.classList.remove('is-visible');
      backdropHideTimeout = window.setTimeout(() => {
        backdrop.hidden = true;
      }, 200);
    }

    function syncAriaHidden() {
      if (mobileQuery.matches) {
        sidebar.setAttribute('aria-hidden', body.classList.contains('sidebar-open') ? 'false' : 'true');
      } else {
        sidebar.removeAttribute('aria-hidden');
      }
    }

    function openSidebar() {
      if (body.classList.contains('sidebar-open')) {
        return;
      }

      lastFocusedButton = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      body.classList.add('sidebar-open');
      setExpanded(true);
      showBackdrop();
      syncAriaHidden();
      sidebar.focus({ preventScroll: true });
    }

    function closeSidebar(focusToggle) {
      if (!body.classList.contains('sidebar-open')) {
        return;
      }

      body.classList.remove('sidebar-open');
      setExpanded(false);
      hideBackdrop();
      syncAriaHidden();

      if (focusToggle && lastFocusedButton instanceof HTMLElement) {
        lastFocusedButton.focus();
      }
    }

    function toggleSidebar() {
      if (body.classList.contains('sidebar-open')) {
        closeSidebar(true);
      } else {
        openSidebar();
      }
    }

    toggleButtons.forEach((button) => {
      button.addEventListener('click', () => {
        toggleSidebar();
      });
    });

    if (backdrop instanceof HTMLElement) {
      backdrop.addEventListener('click', () => {
        closeSidebar(true);
      });
    }

    sidebar.addEventListener('click', (event) => {
      if (!(event.target instanceof HTMLElement)) {
        return;
      }

      if (event.target.closest('a')) {
        closeSidebar(false);
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeSidebar(true);
      }
    });

    mobileQuery.addEventListener('change', (event) => {
      if (!event.matches) {
        closeSidebar(false);
        if (backdrop instanceof HTMLElement) {
          backdrop.hidden = true;
          backdrop.classList.remove('is-visible');
        }
      }
      syncAriaHidden();
    });

    syncAriaHidden();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSidebarToggle);
  } else {
    initSidebarToggle();
  }
})();
