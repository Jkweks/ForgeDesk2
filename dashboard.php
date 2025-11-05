<?php
/* -------------------------------------------------------
   Luna Admin Theme — PHP/HTML/CSS Template (single-file)
   -------------------------------------------------------
   - Pure CSS (no frameworks) + semantic HTML
   - PHP arrays drive nav, metrics, and table
   - Easy to restyle via :root variables
   - Accessible, responsive, and keyboard-friendly
--------------------------------------------------------*/

// --------- DATA (replace with your own) ---------------
$app = [
  'name' => 'Luna',
  'version' => 'v1.4',
  'user' => ['email' => 'luna@company.io', 'avatar' => 'L'],
  'versions_count' => 2,
];

$nav = [
  'Main' => [
    ['icon' => 'grid', 'label' => 'Dashboard', 'active' => true],
    ['icon' => 'activity', 'label' => 'Monitoring'],
  ],
  'UI Elements' => [
    ['icon' => 'layers', 'label' => 'General'],
    ['icon' => 'table', 'label' => 'Tables design'],
    ['icon' => 'sliders', 'label' => 'Form controls'],
    ['icon' => 'bar', 'label' => 'Charts and graphs'],
  ],
  'App Pages' => [
    ['icon' => 'square', 'label' => 'Basic'],
    ['icon' => 'copy', 'label' => 'Common'],
    ['icon' => 'tag',  'label' => 'Versions', 'badge' => 2],
  ],
];

$metrics = [
  ['label' => 'New Sessions', 'value' => 206, 'delta' => '+20%', 'time' => '10:22pm'],
  ['label' => 'Total visitors', 'value' => 140, 'delta' => '▼ 5%', 'time' => '9:10am'],
  ['label' => 'Total users', 'value' => 262, 'delta' => '+56%', 'time' => '05:42pm'],
  ['label' => 'Bounce Rate', 'value' => '62%', 'delta' => '▲18%', 'time' => '04:00am', 'accent' => true],
];

$rows = [
  ['Abraham', '076 9477 4896', '294-318 Duis Ave', '6.2%', 'Vosselaar'],
  ['Phelan', '0500 034548', '680-1097 Mdl Rd', '5.7%', 'Lavoir'],
  ['Raya', '(01315) 276598', 'Ap #289-8611 In Avenue', '4.9%', 'Santonemna'],
  ['Azalia', '0500 8541918', '226E 486-451 St', '4.3%', 'Newtown'],
];

// small utility: inline svg icon (stroke currentColor)
function ico($name) {
  $map = [
    'grid' => 'M3 3h7v7H3V3zm11 0h7v7h-7V3zM3 14h7v7H3v-7zm11 7v-7h7v7h-7z',
    'activity' => 'M22 12h-4l-3 7L9 5l-3 7H2',
    'layers' => 'M12 2l9 5-9 5-9-5 9-5zm0 9l9 5-9 5-9-5 9-5z',
    'table' => 'M3 4h18v16H3V4zm0 4h18M9 4v16m6-16v16',
    'sliders' => 'M4 21v-7M4 10V3m8 18v-9m0-6V3m8 18v-3m0-7V3',
    'bar' => 'M4 20h4V8H4v12zm6 0h4V4h-4v16zm6 0h4v-6h-4v6z',
    'square' => 'M4 4h16v16H4z',
    'copy' => 'M8 8h12v12H8zM4 4h12v12',
    'tag' => 'M20 12l-8 8-8-8V4h8l8 8z',
    'shield' => 'M12 2l8 4v6c0 5-3.5 9.5-8 10-4.5-.5-8-5-8-10V6l8-4z',
    'search' => 'M21 21l-4.3-4.3M10 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16z',
    'bell' => 'M18 8a6 6 0 10-12 0v5l-2 3h16l-2-3V8M9 21h6',
    'chev' => 'M9 6l6 6-6 6',
  ];
  $d = $map[$name] ?? '';
  return "<svg width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><path d='$d'/></svg>";
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars($app['name']) ?> Admin Theme</title>
<style>
:root{
  --bg:#0f1216;
  --panel:#171b21;
  --panel-2:#1e232b;
  --panel-3:#252b35;
  --text:#e8edf2;
  --muted:#a5afbd;
  --brand:#ff9f43;
  --accent:#5ac8a6;
  --danger:#ff6b6b;
  --shadow: 0 10px 20px rgba(0,0,0,.35);
  --radius:14px;
  --gap:18px;
  --font: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, "Helvetica Neue", Arial, "Apple Color Emoji","Segoe UI Emoji";
}

*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  background:linear-gradient(180deg, var(--bg), #0b0e11 260px);
  color:var(--text);
  font: 14px/1.5 var(--font);
}

a{color:inherit;text-decoration:none}
.small{font-size:.85rem;color:var(--muted)}

.layout{
  display:grid;
  grid-template-columns: 260px 1fr;
  grid-template-rows: 64px 1fr;
  grid-template-areas:
    "sidebar topbar"
    "sidebar content";
  min-height:100vh;
}

/* Sidebar */
.sidebar{
  grid-area:sidebar;
  background:linear-gradient(180deg,#12161b,#0f1318);
  border-right:1px solid rgba(255,255,255,.06);
  position:sticky; top:0; height:100vh;
  padding:14px 12px;
}
.brand{
  display:flex; align-items:center; gap:10px;
  padding:10px 12px; margin-bottom:8px;
}
.brand-badge{
  width:36px; height:36px; border-radius:10px;
  background:linear-gradient(180deg,#ffb14a,#ff8b25);
  display:grid; place-items:center; color:#231d14; font-weight:700;
}
.brand-version{
  margin-left:auto; font-size:.75rem; color:#111; background:linear-gradient(180deg,#f0f4ff,#dfe5ff);
  padding:4px 8px; border-radius:8px; color:#26324d; font-weight:600;
}

.nav-group{margin-top:18px}
.nav-group h6{
  margin:12px 12px 8px; color:var(--muted); font-size:.75rem; letter-spacing:.08em; text-transform:uppercase;
}
.nav-item{
  display:flex; align-items:center; gap:10px;
  padding:10px 12px; margin:4px 0; border-radius:10px; color:#cfd6e2;
}
.nav-item.active, .nav-item:hover{
  background:var(--panel-3);
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.06);
}
.nav-item .badge{
  margin-left:auto; background:#2b3340; color:#b8c2d4; font-weight:600; font-size:.75rem; padding:2px 8px; border-radius:999px;
}

/* Topbar */
.topbar{
  grid-area:topbar;
  display:flex; align-items:center; gap:12px;
  padding:12px 16px; border-bottom:1px solid rgba(255,255,255,.06);
  background:linear-gradient(180deg, var(--panel), #141820);
}
.search{
  display:flex; align-items:center; gap:8px; background:var(--panel-3);
  border-radius:999px; padding:8px 12px; flex:1; max-width:720px;
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.06);
}
.search input{
  border:0; outline:none; flex:1; background:transparent; color:var(--text); font-size:.95rem;
}
.pill{
  display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px;
  background:#2b3340; color:#cfd6e2; font-weight:600; font-size:.85rem;
}
.user{
  margin-left:auto; display:flex; align-items:center; gap:10px;
}
.avatar{
  width:34px; height:34px; border-radius:999px; background:#2c3442; display:grid; place-items:center; font-weight:700;
  box-shadow:inset 0 0 0 2px rgba(255,255,255,.08);
}

/* Content grid */
.content{
  grid-area:content;
  padding:22px 24px 40px;
}
.page-header{
  display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:18px;
}
.h-title{
  display:flex; gap:12px; align-items:center;
  padding:14px 16px; background:linear-gradient(180deg, var(--panel), #131821);
  border-radius:var(--radius); box-shadow:var(--shadow);
}
.h-title .icon{
  width:34px; height:34px; display:grid; place-items:center; border-radius:10px;
  background:rgba(255,255,255,.04); color:var(--brand);
}

.grid{
  display:grid;
  grid-template-columns: repeat(12, 1fr);
  gap:var(--gap);
}

/* Cards */
.card{
  background:linear-gradient(180deg, var(--panel), var(--panel-2));
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  padding:16px;
  border:1px solid rgba(255,255,255,.06);
}
.stat{
  display:flex; flex-direction:column; gap:6px; min-height:110px;
}
.stat .value{ font-size:1.75rem; font-weight:800; letter-spacing:.3px}
.stat .delta{ color:#9fe2bf; font-weight:700; font-size:.9rem}
.stat .delta.down{ color:var(--danger)}
.stat .ts{ color:var(--muted); font-size:.8rem}
.stat.accent{ outline:1px solid rgba(90,200,166,.35); box-shadow:0 0 0 1px rgba(90,200,166,.25) inset}

/* Traffic */
.section-title{display:flex; align-items:center; gap:8px; margin-bottom:8px; color:#cfd6e2}
.spark{
  height:56px; width:100%; background:
    linear-gradient(180deg, rgba(255,255,255,.06), transparent),
    linear-gradient(90deg, rgba(255,255,255,.04) 1px, transparent 1px);
  background-size:100% 100%, 60px 100%;
  border-radius:10px; position:relative; overflow:hidden;
}
.spark:before{
  content:""; position:absolute; inset:auto 0 0; height:3px; background:rgba(255,255,255,.06);
}
.area{
  height:160px; width:100%; border-radius:12px; background:
    linear-gradient(180deg, rgba(255,159,67,.4), rgba(255,159,67,.08));
  position:relative; overflow:hidden;
}
.area:after{
  /* pseudo "area chart" ridge */
  content:""; position:absolute; inset:0; background:
    radial-gradient(120px 40px at 6% 70%, rgba(255,255,255,.18), transparent 60%),
    radial-gradient(200px 60px at 30% 75%, rgba(255,255,255,.15), transparent 60%),
    radial-gradient(220px 60px at 53% 60%, rgba(255,255,255,.12), transparent 60%),
    radial-gradient(260px 70px at 78% 50%, rgba(255,255,255,.14), transparent 60%),
    radial-gradient(260px 70px at 96% 40%, rgba(255,255,255,.16), transparent 60%);
  mix-blend-mode: overlay; opacity:.55;
}
.axis{
  display:flex; justify-content:space-between; color:#9aa6b6; font-size:.75rem; margin-top:6px;
}

/* Table */
.table{
  width:100%; border-collapse:collapse; margin-top:8px;
}
.table th, .table td{
  text-align:left; padding:12px 10px; border-bottom:1px solid rgba(255,255,255,.06);
}
.table th{ color:#aeb8c7; font-weight:700; font-size:.85rem; letter-spacing:.02em}
.table tr:hover td{ background:rgba(255,255,255,.03)}

/* Promo */
.cta{
  display:flex; flex-direction:column; gap:8px; min-height:220px;
  background:linear-gradient(180deg, #232a35, #1b212a);
  border:1px dashed rgba(255,255,255,.12);
}
.cta .big{ font-size:1.4rem; font-weight:800; color:#ffd18e}
.cta .cta-spark{height:72px; border-radius:8px; background:
  linear-gradient(180deg, rgba(255,255,255,.06), transparent),
  linear-gradient(90deg, rgba(255,255,255,.05) 1px, transparent 1px);
  background-size:100% 100%, 26px 100%;
}

/* Responsive */
@media (max-width: 1100px){
  .layout{ grid-template-columns: 88px 1fr }
  .brand span:not(.brand-badge), .nav-group h6, .nav-item span, .brand-version{ display:none }
  .nav-item{ justify-content:center }
}
@media (max-width: 860px){
  .grid{ grid-template-columns: repeat(6,1fr) }
}
@media (max-width: 640px){
  .layout{ grid-template-columns: 1fr; grid-template-rows: 64px auto auto; grid-template-areas:"topbar" "content" "sidebar"}
  .sidebar{ position:relative; height:auto; border-right:0; border-top:1px solid rgba(255,255,255,.08)}
}
</style>
</head>
<body>
<div class="layout">

  <!-- Sidebar -->
  <aside class="sidebar" aria-label="Sidebar">
    <div class="brand">
      <div class="brand-badge">L</div>
      <span style="font-weight:800; letter-spacing:.2px"><?= htmlspecialchars($app['name']) ?></span>
      <span class="brand-version"><?= htmlspecialchars($app['version']) ?></span>
    </div>

    <?php foreach($nav as $group => $items): ?>
      <div class="nav-group">
        <h6><?= htmlspecialchars($group) ?></h6>
        <?php foreach($items as $it): ?>
          <a class="nav-item <?= !empty($it['active']) ? 'active' : '' ?>" href="#">
            <span aria-hidden="true"><?= ico($it['icon']) ?></span>
            <span><?= htmlspecialchars($it['label']) ?></span>
            <?php if(!empty($it['badge'])): ?>
              <span class="badge"><?= (int)$it['badge'] ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <div style="margin-top:18px; border:1px solid rgba(255,255,255,.06); border-radius:12px; padding:12px; color:#b2bccb; background:linear-gradient(180deg,#161b22,#14181f)">
      <div style="font-weight:700; margin-bottom:6px">Luna admin theme</div>
      <div class="small">Dark UI style for monitoring and administration web applications.</div>
    </div>
  </aside>

  <!-- Topbar -->
  <header class="topbar">
    <div class="pill" title="Back">
      <?= ico('chev') ?> <span>Back</span>
    </div>
    <form class="search" role="search" action="#" method="get">
      <span aria-hidden="true"><?= ico('search') ?></span>
      <input name="q" placeholder="Search data for analysis" aria-label="Search data" />
    </form>
    <span class="pill" title="Versions">VERSIONS <strong style="margin-left:6px; background:#3a4353; padding:2px 8px; border-radius:999px; font-size:.8rem"><?= (int)$app['versions_count'] ?></strong></span>
    <div class="user" title="<?= htmlspecialchars($app['user']['email']) ?>">
      <span class="small"><?= htmlspecialchars($app['user']['email']) ?></span>
      <button class="pill" aria-label="Notifications" style="background:#2b3340"><?= ico('bell') ?></button>
      <div class="avatar"><?= htmlspecialchars($app['user']['avatar']) ?></div>
    </div>
  </header>

  <!-- Content -->
  <main class="content">
    <div class="page-header">
      <div class="h-title">
        <div class="icon"><?= ico('shield') ?></div>
        <div>
          <div style="font-size:1.2rem; font-weight:800">Luna Admin Theme</div>
          <div class="small">Special minimal admin theme with Dark UI style for monitoring and administration web applications.</div>
        </div>
      </div>
      <div class="small" style="opacity:.8; padding:8px 12px">Luna Admin Theme Dashboard<br><?= htmlspecialchars($app['version']) ?></div>
    </div>

    <section class="grid" aria-label="KPI Cards">
      <?php foreach($metrics as $i => $m): ?>
        <article class="card stat <?= !empty($m['accent']) ? 'accent' : '' ?>" style="grid-column: span 3">
          <div class="small"><?= htmlspecialchars($m['label']) ?></div>
          <div class="value"><?= htmlspecialchars($m['value']) ?></div>
          <div class="delta <?= (strpos($m['delta'],'▼')!==false)?'down':'' ?>"><?= htmlspecialchars($m['delta']) ?></div>
          <div class="ts">Updated: <?= htmlspecialchars($m['time']) ?></div>
          <div class="spark" aria-hidden="true" style="margin-top:auto"></div>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="card" style="margin-top:var(--gap)">
      <div class="section-title"><?= ico('bar') ?> <strong>Traffic source</strong></div>
      <div class="small" style="margin-bottom:10px; color:#b9c3d2">
        Totals from the beginning of activity. See detailed charts for more information: locations and traffic sources.
      </div>
      <div class="grid">
        <div style="grid-column: span 7">
          <div class="area" aria-label="All active users from last month"></div>
          <div class="axis">
            <span>0.0</span><span>1.0</span><span>2.0</span><span>3.0</span><span>4.0</span><span>5.0</span><span>6.0</span><span>7.0</span>
          </div>
        </div>
        <div style="grid-column: span 5">
          <div class="grid" style="grid-template-columns: repeat(3,1fr)">
            <div>
              <div class="small">Today</div>
              <div style="font-weight:800">170,20 ⤴</div>
            </div>
            <div>
              <div class="small">Last month %</div>
              <div style="font-weight:800">%20,20 ⤴</div>
            </div>
            <div>
              <div class="small">Year</div>
              <div style="font-weight:800">2180,50 ⤴</div>
            </div>
          </div>
          <div class="card" style="margin-top:var(--gap)">
            <table class="table" aria-label="Users table">
              <thead>
                <tr><th>Name</th><th>Phone</th><th>Street Address</th><th>% Share</th><th>City</th><th>Action</th></tr>
              </thead>
              <tbody>
                <?php foreach($rows as $r): ?>
                  <tr>
                    <td><?= htmlspecialchars($r[0]) ?></td>
                    <td><?= htmlspecialchars($r[1]) ?></td>
                    <td><?= htmlspecialchars($r[2]) ?></td>
                    <td><?= htmlspecialchars($r[3]) ?></td>
                    <td><?= htmlspecialchars($r[4]) ?></td>
                    <td><a class="pill" href="#" style="padding:4px 10px">View</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>

    <section class="grid" style="margin-top:var(--gap)">
      <div class="card cta" style="grid-column: span 5">
        <div class="big">+280k downloads</div>
        <div class="small">New downloads from the last month.</div>
        <div class="cta-spark" aria-hidden="true"></div>
        <div class="small">120,312 ▲ 22%</div>
      </div>
      <div class="card" style="grid-column: span 7">
        <div class="section-title"><?= ico('activity') ?> <strong>New visitor</strong> <span class="pill" style="margin-left:8px">+45</span> <a class="pill" href="#" style="margin-left:auto">See locations</a></div>
        <div class="spark" style="height:120px"></div>
      </div>
    </section>
  </main>
</div>
</body>
</html>
