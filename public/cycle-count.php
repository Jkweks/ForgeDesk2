<?php

declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/data/cycle_counts.php';
require_once __DIR__ . '/../app/data/storage_locations.php';

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] === 'Cycle Counts');
    }
}
unset($groupItems, $item);

$databaseConfig = $app['database'];
$dbError = null;
$errors = [];
$startErrors = [];
$countErrors = [];
$successMessage = null;
$sessions = [];
$activeSession = null;
$currentStep = 1;
$lineView = null;
$storageLocations = [];
$locationHierarchy = [];
$selectedLocationIds = [];

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

if ($dbError === null) {
    try {
        $storageLocations = storageLocationsList($db);
        $locationHierarchy = storageLocationsHierarchy($db);
    } catch (\Throwable $exception) {
        $startErrors['general'] = 'Unable to load storage locations: ' . $exception->getMessage();
        $storageLocations = [];
        $locationHierarchy = [];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'start') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $selectedLocationIds = isset($_POST['location_ids']) && is_array($_POST['location_ids'])
                ? array_values(array_unique(array_filter(
                    array_map(static fn ($value) => (int) $value, $_POST['location_ids']),
                    static fn (int $value): bool => $value > 0
                )))
                : [];

            if ($selectedLocationIds !== []) {
                $validIds = array_column($storageLocations, 'id');
                $validMap = array_flip($validIds);
                $selectedLocationIds = array_values(array_filter(
                    $selectedLocationIds,
                    static fn (int $value): bool => $value > 0 && isset($validMap[$value])
                ));
            }

            try {
                $sessionId = createCycleCountSession($db, [
                    'name' => $name,
                    'location_ids' => $selectedLocationIds,
                ]);

                if ($sessionId > 0) {
                    header('Location: cycle-count.php?session=' . $sessionId . '&step=1&notice=started');
                    exit;
                }
            } catch (\Throwable $exception) {
                $startErrors['general'] = 'Unable to create a cycle count session: ' . $exception->getMessage();
            }
        } elseif ($action === 'record') {
            $lineIdRaw = $_POST['line_id'] ?? '';
            $sessionIdRaw = $_POST['session_id'] ?? '';
            $stepRaw = $_POST['step'] ?? '';
            $qtyRaw = trim((string) ($_POST['counted_qty'] ?? ''));
            $note = trim((string) ($_POST['note'] ?? ''));

            if (!ctype_digit($lineIdRaw)) {
                $countErrors['general'] = 'Invalid count line submitted.';
            }

            if (!ctype_digit($sessionIdRaw)) {
                $countErrors['general'] = 'Invalid cycle count session provided.';
            }

            if (!ctype_digit((string) $stepRaw)) {
                $countErrors['general'] = 'Invalid step reference.';
            }

            $countedQty = filter_var($qtyRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($countedQty === false) {
                $countErrors['counted_qty'] = 'Enter the counted quantity as a non-negative number.';
            }

            if ($countErrors === []) {
                $lineId = (int) $lineIdRaw;
                $sessionId = (int) $sessionIdRaw;
                $currentStep = max(1, (int) $stepRaw);
                $noteValue = $note !== '' ? $note : null;

                try {
                    recordCycleCount($db, $lineId, (int) $countedQty, $noteValue);

                    $navigate = $_POST['navigate'] ?? 'stay';

                    $totalStatement = $db->prepare('SELECT total_lines, status FROM cycle_count_sessions WHERE id = :id');
                    $totalStatement->execute([':id' => $sessionId]);
                    $sessionRow = $totalStatement->fetch();
                    $totalLines = $sessionRow !== false ? (int) $sessionRow['total_lines'] : 0;
                    $statusAfter = $sessionRow !== false ? (string) $sessionRow['status'] : 'in_progress';
                    $nextStep = $currentStep;

                    if ($navigate === 'next') {
                        if ($totalLines === 0) {
                            $nextStep = 1;
                        } else {
                            $nextStep = min($currentStep + 1, $totalLines);
                        }
                    }

                    $notice = $statusAfter === 'completed' ? 'completed' : 'saved';

                    header('Location: cycle-count.php?session=' . $sessionId . '&step=' . $nextStep . '&notice=' . $notice);
                    exit;
                } catch (\Throwable $exception) {
                    $countErrors['general'] = 'Unable to save the count: ' . $exception->getMessage();
                }
            }
        } elseif ($action === 'complete') {
            $sessionIdRaw = $_POST['session_id'] ?? '';

            if (!ctype_digit($sessionIdRaw)) {
                $errors[] = 'Invalid session reference.';
            } else {
                $sessionId = (int) $sessionIdRaw;

                try {
                    completeCycleCountSession($db, $sessionId);
                    header('Location: cycle-count.php?notice=completed');
                    exit;
                } catch (\Throwable $exception) {
                    $errors[] = 'Unable to complete the session: ' . $exception->getMessage();
                }
            }
        }
    }

    try {
        $sessions = loadCycleCountSessions($db);
    } catch (\Throwable $exception) {
        $errors[] = 'Failed to load cycle count sessions: ' . $exception->getMessage();
    }

    if (isset($_GET['session']) && ctype_digit((string) $_GET['session'])) {
        $sessionId = (int) $_GET['session'];
        $currentStep = isset($_GET['step']) && ctype_digit((string) $_GET['step']) ? max(1, (int) $_GET['step']) : 1;

        foreach ($sessions as $session) {
            if ($session['id'] === $sessionId) {
                $activeSession = $session;
                break;
            }
        }

        if ($activeSession !== null) {
            $lineView = loadCycleCountLine($db, $sessionId, $currentStep);

            if ($lineView === null && $activeSession['total_lines'] > 0 && $currentStep > $activeSession['total_lines']) {
                $lineView = loadCycleCountLine($db, $sessionId, $activeSession['total_lines']);
                $currentStep = $activeSession['total_lines'];
            }
        }
    }

    if (isset($_GET['notice'])) {
        $notice = $_GET['notice'];
        if ($notice === 'started') {
            $successMessage = 'Cycle count session created. Begin counting below.';
        } elseif ($notice === 'saved') {
            $successMessage = 'Count saved successfully.';
        } elseif ($notice === 'completed') {
            $successMessage = 'Cycle count session marked as completed.';
        }
    }
}

$modalOpen = $activeSession !== null && $lineView !== null && $activeSession['status'] === 'in_progress';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> · Cycle Counts</title>
  <link rel="stylesheet" href="css/dashboard.css" />
</head>
<?php
$bodyClasses = ['has-sidebar-toggle'];
if ($modalOpen) {
    $bodyClasses[] = 'modal-open';
}
$bodyClassAttribute = ' class="' . implode(' ', $bodyClasses) . '"';
?>
<body<?= $bodyClassAttribute ?>>
  <div class="layout">
    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>

    <header class="topbar">
      <button
        class="topbar-toggle"
        type="button"
        data-sidebar-toggle
        aria-controls="app-sidebar"
        aria-expanded="false"
        aria-label="Toggle navigation"
      >
        <span aria-hidden="true"><?= icon('menu') ?></span>
      </button>
      <form class="search" role="search" aria-label="Inventory search">
        <span aria-hidden="true"><?= icon('search') ?></span>
        <input type="search" name="q" placeholder="Search SKUs, bins, or components" />
      </form>
      <button class="user" type="button">
        <span class="user-avatar" aria-hidden="true"><?= e($app['user']['avatar']) ?></span>
        <span class="user-email"><?= e($app['user']['email']) ?></span>
        <span aria-hidden="true"><?= icon('chev') ?></span>
      </button>
    </header>

    <main class="content">
      <section class="panel" aria-labelledby="cycle-count-title">
        <header class="panel-header">
          <div>
            <h1 id="cycle-count-title">Cycle Count Sessions</h1>
            <p class="small">Start a count and walk location by location on any device.</p>
          </div>
        </header>

        <?php if ($dbError !== null): ?>
          <div class="alert error" role="alert">
            <strong>Database connection issue:</strong> <?= e($dbError) ?>
          </div>
        <?php endif; ?>

        <?php foreach ($errors as $message): ?>
          <div class="alert error" role="alert">
            <?= e($message) ?>
          </div>
        <?php endforeach; ?>

        <?php if ($successMessage !== null): ?>
          <div class="alert success" role="status">
            <?= e($successMessage) ?>
          </div>
        <?php endif; ?>

        <div class="session-grid">
          <section class="session-start" aria-labelledby="start-count-title">
            <h2 id="start-count-title">Start a cycle count</h2>
            <p class="small">Filter by one or more storage locations or leave blank to include the full warehouse.</p>

            <?php if (!empty($startErrors['general'])): ?>
              <div class="alert error" role="alert">
                <?= e($startErrors['general']) ?>
              </div>
            <?php endif; ?>

            <form method="post" class="form" novalidate>
              <input type="hidden" name="action" value="start" />
              <div class="field">
                <label for="session-name">Session name</label>
                <input type="text" id="session-name" name="name" placeholder="Cycle count for Aisle 4" />
              </div>
              <div class="field">
                <label for="session-location-picker">Storage locations <span class="optional">Optional</span></label>
                <div class="location-filter" data-location-filter>
                  <button
                    type="button"
                    class="location-filter__toggle"
                    id="session-location-picker"
                    data-location-filter-toggle
                    aria-expanded="false"
                  >
                    <?= $selectedLocationIds === [] ? 'All locations' : count($selectedLocationIds) . ' selected' ?>
                  </button>
                  <div class="location-filter__menu" data-location-filter-menu hidden>
                    <?php if ($locationHierarchy === []): ?>
                      <p class="small">No storage locations configured yet. Add them from the admin dashboard to filter counts.</p>
                    <?php else: ?>
                      <div class="location-hierarchy" data-location-hierarchy>
                        <?php foreach ($locationHierarchy as $aisle): ?>
                          <?php $aisleIds = implode(',', $aisle['location_ids']); ?>
                          <div class="location-branch" data-level="aisle">
                            <label class="checkbox-option">
                              <input type="checkbox" data-location-group data-child-ids="<?= e($aisleIds) ?>" />
                              <span><?= e($aisle['label']) ?></span>
                            </label>
                            <?php foreach ($aisle['racks'] as $rack): ?>
                              <?php $rackIds = implode(',', $rack['location_ids']); ?>
                              <div class="location-branch" data-level="rack">
                                <label class="checkbox-option">
                                  <input type="checkbox" data-location-group data-child-ids="<?= e($rackIds) ?>" />
                                  <span><?= e($rack['label']) ?></span>
                                </label>
                                <?php foreach ($rack['shelves'] as $shelf): ?>
                                  <?php $shelfIds = implode(',', $shelf['location_ids']); ?>
                                  <div class="location-branch" data-level="shelf">
                                    <label class="checkbox-option">
                                      <input type="checkbox" data-location-group data-child-ids="<?= e($shelfIds) ?>" />
                                      <span><?= e($shelf['label']) ?></span>
                                    </label>
                                    <div class="location-branch" data-level="bin">
                                      <?php foreach ($shelf['bins'] as $bin): ?>
                                        <?php $isChecked = in_array($bin['id'], $selectedLocationIds, true); ?>
                                        <label class="checkbox-option">
                                          <input
                                            type="checkbox"
                                            name="location_ids[]"
                                            value="<?= e((string) $bin['id']) ?>"
                                            data-location-node="bin"
                                            <?= $isChecked ? 'checked' : '' ?>
                                          />
                                          <span class="location-leaf__label"><?= e($bin['label']) ?></span>
                                          <?php if (!empty($bin['path_label']) && $bin['path_label'] !== $bin['label']): ?>
                                            <span class="location-leaf__path"><?= e($bin['path_label']) ?></span>
                                          <?php endif; ?>
                                        </label>
                                      <?php endforeach; ?>
                                    </div>
                                  </div>
                                <?php endforeach; ?>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                    <button type="button" class="button ghost" data-location-filter-close>Done</button>
                  </div>
                </div>
                <p class="field-help">Use the dropdown to include an aisle, a rack within an aisle, or individual bins.</p>
              </div>
              <button type="submit" class="button primary">Start counting</button>
            </form>
          </section>

          <section class="session-list" aria-labelledby="session-history-title">
            <h2 id="session-history-title">Session history</h2>
            <?php if ($sessions === []): ?>
              <p class="small">No cycle counts yet. Create your first session to get started.</p>
            <?php else: ?>
              <ul class="session-cards">
                <?php foreach ($sessions as $session): ?>
                  <?php
                    $statusLabel = $session['status'] === 'completed' ? 'Completed' : 'In progress';
                    $progress = $session['total_lines'] > 0
                      ? round(($session['completed_lines'] / $session['total_lines']) * 100)
                      : ($session['status'] === 'completed' ? 100 : 0);
                  ?>
                  <li class="session-card">
                    <div class="session-card-header">
                      <h3><?= e($session['name']) ?></h3>
                      <span class="status badge <?= $session['status'] === 'completed' ? 'status-complete' : 'status-open' ?>">
                        <?= e($statusLabel) ?>
                      </span>
                    </div>
                    <dl class="session-meta">
                      <div>
                        <dt>Started</dt>
                        <dd><?= e(date('M j, Y g:ia', strtotime($session['started_at']))) ?></dd>
                      </div>
                      <div>
                        <dt>Locations</dt>
                        <dd><?= $session['location_filter'] !== null ? e($session['location_filter']) : 'All' ?></dd>
                      </div>
                      <div>
                        <dt>Progress</dt>
                        <dd><?= e($session['completed_lines'] . '/' . $session['total_lines']) ?></dd>
                      </div>
                    </dl>
                    <div class="progress">
                      <span style="width: <?= (int) $progress ?>%"></span>
                    </div>
                    <div class="session-actions">
                      <?php if ($session['status'] === 'completed'): ?>
                        <span class="small">Finished <?= $session['completed_at'] !== null ? e(date('M j, Y g:ia', strtotime($session['completed_at']))) : '' ?></span>
                      <?php else: ?>
                        <a class="button secondary" href="cycle-count.php?session=<?= e((string) $session['id']) ?>&step=1">Resume count</a>
                        <form method="post">
                          <input type="hidden" name="action" value="complete" />
                          <input type="hidden" name="session_id" value="<?= e((string) $session['id']) ?>" />
                          <button type="submit" class="button ghost">Complete session</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        </div>
      </section>

      <?php if ($activeSession !== null && $activeSession['status'] === 'completed'): ?>
        <section class="panel" aria-labelledby="count-step-title">
          <header class="panel-header">
            <div>
              <h2 id="count-step-title">Cycle count complete</h2>
              <p class="small">All items in this session have been counted.</p>
            </div>
          </header>
          <p>Your selected session has been completed. Review the history above or start a new cycle count.</p>
        </section>
      <?php endif; ?>
    </main>
    <?php if ($modalOpen): ?>
      <div class="modal open" id="count-modal" role="dialog" aria-modal="true" aria-labelledby="count-step-title">
        <div class="modal-dialog">
          <header>
            <div>
              <h2 id="count-step-title">Counting <?= e($lineView['item']['item']) ?></h2>
              <p class="small">Location <?= e($lineView['item']['location']) ?> · SKU <?= e($lineView['item']['sku']) ?></p>
            </div>
            <div class="modal-header-actions">
              <div class="progress-indicator">
                Step <?= e((string) $lineView['line']['sequence']) ?> of <?= e((string) $activeSession['total_lines']) ?>
              </div>
              <a class="modal-close" href="cycle-count.php" aria-label="Close count window">&times;</a>
            </div>
          </header>

          <?php if (!empty($countErrors['general'])): ?>
            <div class="alert error" role="alert">
              <?= e($countErrors['general']) ?>
            </div>
          <?php endif; ?>

          <form method="post" class="count-form" novalidate>
            <input type="hidden" name="action" value="record" />
            <input type="hidden" name="session_id" value="<?= e((string) $activeSession['id']) ?>" />
            <input type="hidden" name="line_id" value="<?= e((string) $lineView['line']['id']) ?>" />
            <input type="hidden" name="step" value="<?= e((string) $lineView['line']['sequence']) ?>" />

            <div class="count-field">
              <label for="counted-qty">Counted quantity</label>
              <input
                type="number"
                id="counted-qty"
                name="counted_qty"
                inputmode="numeric"
                pattern="[0-9]*"
                min="0"
                value="<?= e($lineView['line']['counted_qty'] !== null ? (string) $lineView['line']['counted_qty'] : '') ?>"
                required
              />
              <p class="small">Expected <?= e((string) $lineView['line']['expected_qty']) ?> units on hand.</p>
              <?php if (!empty($countErrors['counted_qty'])): ?>
                <p class="field-error"><?= e($countErrors['counted_qty']) ?></p>
              <?php endif; ?>
            </div>

            <div class="field">
              <label for="count-note">Notes <span class="optional">Optional</span></label>
              <textarea id="count-note" name="note" rows="3" placeholder="Damage, adjustments, etc."><?= e($lineView['line']['note'] ?? '') ?></textarea>
            </div>

            <footer class="count-actions">
              <div class="count-nav">
                <?php if ($lineView['line']['sequence'] > 1): ?>
                  <a class="button ghost" href="cycle-count.php?session=<?= e((string) $activeSession['id']) ?>&step=<?= e((string) ($lineView['line']['sequence'] - 1)) ?>">Previous</a>
                <?php endif; ?>
                <?php if ($lineView['line']['sequence'] < $activeSession['total_lines']): ?>
                  <a class="button ghost" href="cycle-count.php?session=<?= e((string) $activeSession['id']) ?>&step=<?= e((string) ($lineView['line']['sequence'] + 1)) ?>">Skip</a>
                <?php endif; ?>
              </div>
              <div class="count-submit">
                <button type="submit" name="navigate" value="stay" class="button secondary">Save</button>
                <button type="submit" name="navigate" value="next" class="button primary">Save &amp; next</button>
              </div>
            </footer>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="modal" id="count-modal" hidden></div>
    <?php endif; ?>
  </div>
  <script>
  (function () {
    const container = document.querySelector('[data-location-filter]');
    if (!container) {
      return;
    }

    const toggle = container.querySelector('[data-location-filter-toggle]');
    const menu = container.querySelector('[data-location-filter-menu]');
    const closeButton = container.querySelector('[data-location-filter-close]');
    const binCheckboxes = menu ? Array.from(menu.querySelectorAll('input[type="checkbox"][data-location-node="bin"]')) : [];
    const groupCheckboxes = menu ? Array.from(menu.querySelectorAll('input[type="checkbox"][data-location-group]')) : [];

    if (!(toggle instanceof HTMLButtonElement) || !(menu instanceof HTMLElement)) {
      return;
    }

    function getChildIds(input) {
      const raw = input.dataset.childIds;
      if (!raw) {
        return [];
      }

      return raw
        .split(',')
        .map((value) => value.trim())
        .filter((value) => value !== '');
    }

    function updateGroupStates() {
      groupCheckboxes.forEach((group) => {
        const childIds = getChildIds(group);
        if (childIds.length === 0) {
          group.checked = false;
          group.indeterminate = false;
          return;
        }

        const matchingBins = binCheckboxes.filter((bin) => childIds.includes(bin.value));
        const checkedCount = matchingBins.filter((bin) => bin.checked).length;

        group.checked = checkedCount === matchingBins.length && matchingBins.length > 0;
        group.indeterminate = checkedCount > 0 && checkedCount < matchingBins.length;
      });
    }

    function updateLabel() {
      const active = binCheckboxes.filter((input) => input.checked);
      toggle.textContent = active.length === 0 ? 'All locations' : active.length + ' selected';
    }

    function setMenu(open) {
      if (open) {
        menu.removeAttribute('hidden');
        toggle.setAttribute('aria-expanded', 'true');
      } else {
        menu.setAttribute('hidden', 'hidden');
        toggle.setAttribute('aria-expanded', 'false');
      }
    }

    toggle.addEventListener('click', function (event) {
      event.preventDefault();
      const isOpen = toggle.getAttribute('aria-expanded') === 'true';
      setMenu(!isOpen);
    });

    if (closeButton instanceof HTMLElement) {
      closeButton.addEventListener('click', function (event) {
        event.preventDefault();
        setMenu(false);
      });
    }

    document.addEventListener('click', function (event) {
      if (!container.contains(event.target)) {
        setMenu(false);
      }
    });

    groupCheckboxes.forEach((input) => {
      input.addEventListener('change', function (event) {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) {
          return;
        }

        const childIds = getChildIds(target);
        const shouldCheck = target.checked;

        binCheckboxes.forEach((bin) => {
          if (childIds.includes(bin.value)) {
            bin.checked = shouldCheck;
          }
        });

        updateGroupStates();
        updateLabel();
      });
    });

    binCheckboxes.forEach((input) => {
      input.addEventListener('change', function () {
        updateGroupStates();
        updateLabel();
      });
    });

    updateGroupStates();
    updateLabel();
  })();
  </script>
  <script src="js/dashboard.js"></script>
</body>
</html>
