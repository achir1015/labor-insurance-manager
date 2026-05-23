<?php
// ============================================================
// retirement_dashboard.php — 個人退休財務儀表板
// 放置路徑：fan_members/retirement_dashboard.php
// 需要 admin 角色才能存取
// ============================================================
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/Auth.php';

// 🔐 僅限 admin 進入
Auth::requireRole('admin');

$db   = Database::getInstance();
$user = Auth::currentUser();
$mid  = $user['id'];
$msg  = '';

// ── 1. 建立資料表（首次執行自動建立）────────────────────────
$db->exec("
CREATE TABLE IF NOT EXISTS `retirement_profiles` (
  `id`                           INT           AUTO_INCREMENT PRIMARY KEY,
  `member_id`                    INT           NOT NULL UNIQUE,
  `birth_year`                   SMALLINT      NOT NULL DEFAULT 1962,
  `birth_month`                  TINYINT       NOT NULL DEFAULT 1,
  `target_retire_age`            TINYINT       NOT NULL DEFAULT 65,
  `labor_insurance_years`        DECIMAL(5,2)  NOT NULL DEFAULT 0,
  `highest_60_months_avg`        INT           NOT NULL DEFAULT 0,
  `current_labor_pension_fund`   DECIMAL(14,0) NOT NULL DEFAULT 0,
  `monthly_contribution_wage`    INT           NOT NULL DEFAULT 0,
  `employer_pension_rate`        DECIMAL(5,4)  NOT NULL DEFAULT 0.0600,
  `self_pension_rate`            DECIMAL(5,4)  NOT NULL DEFAULT 0.0000,
  `expected_return_rate`         DECIMAL(5,4)  NOT NULL DEFAULT 0.0400,
  `securities_value`             DECIMAL(14,0) NOT NULL DEFAULT 0,
  `securities_return_rate`       DECIMAL(5,4)  NOT NULL DEFAULT 0.0700,
  `savings_insurance_value`      DECIMAL(14,0) NOT NULL DEFAULT 0,
  `other_assets_value`           DECIMAL(14,0) NOT NULL DEFAULT 0,
  `updated_at`                   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
                                               ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// ── 2. 預設值（依您提供的真實資料）──────────────────────────
$defaults = [
  'birth_year'                 => 1962,
  'birth_month'                => 6,      // 民國 069/06 → 1980/06
  'target_retire_age'          => 65,
  'labor_insurance_years'      => 34.70,  // 34年255天 ≈ 34.70年
  'highest_60_months_avg'      => 45800,
  'current_labor_pension_fund' => 978553, // 532024+446529
  'monthly_contribution_wage'  => 45800,
  'employer_pension_rate'      => 0.0600,
  'self_pension_rate'          => 0.0000,
  'expected_return_rate'       => 0.0400,
  'securities_value'           => 0,
  'securities_return_rate'     => 0.0700,
  'savings_insurance_value'    => 0,
  'other_assets_value'         => 0,
];

// ── 3. 處理 POST 儲存 ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $fields = array_keys($defaults);
    $vals   = [];
    foreach ($fields as $f) {
        $vals[$f] = isset($_POST[$f]) ? $_POST[$f] : $defaults[$f];
    }

    // 用 UPSERT — UPDATE 用 VALUES(col) 語法，避免 PDO 重複命名參數錯誤
    $col_list    = implode(', ', array_map(function($f){ return "`$f`"; }, $fields));
    $param_list  = implode(', ', array_map(function($f){ return ":$f"; }, $fields));
    $update_list = implode(', ', array_map(function($f){ return "`$f` = VALUES(`$f`)"; }, $fields));

    $params = array(':member_id' => $mid);
    foreach ($fields as $f) {
        $params[':' . $f] = $vals[$f];
    }

    $db->prepare(
        "INSERT INTO retirement_profiles (member_id, $col_list)
         VALUES (:member_id, $param_list)
         ON DUPLICATE KEY UPDATE $update_list"
    )->execute($params);
    $msg = 'success';
}

// ── 4. 讀取儲存的設定 ────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM retirement_profiles WHERE member_id = ? LIMIT 1");
$stmt->execute([$mid]);
$p    = $stmt->fetch() ?: array_merge(['member_id' => $mid], $defaults);
// 若欄位缺失，補預設值
foreach ($defaults as $k => $v) {
    if (!isset($p[$k])) $p[$k] = $v;
}

// ── 5. PHP 端預算（傳給 JS 初始值）──────────────────────────
$now_year  = (int)date('Y');
$now_month = (int)date('n');
$cur_age   = $now_year - $p['birth_year']
             + ($now_month >= $p['birth_month'] ? 0 : -1);
$yrs_left  = max(0, $p['target_retire_age'] - $cur_age);
$retire_yr = $now_year + $yrs_left;

// 目標退休日期（時間戳）
$retire_ts = mktime(0, 0, 0,
    $p['birth_month'],
    1,
    $p['birth_year'] + $p['target_retire_age']);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>退休財務儀表板 · <?= htmlspecialchars($user['display_name']) ?></title>

<!-- 字型：顯示用 Playfair Display（高雅襯線），數字用 JetBrains Mono，中文系統字 -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=JetBrains+Mono:wght@400;600&family=Nunito:wght@300;400;600&display=swap" rel="stylesheet">

<!-- Chart.js CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

<style>
/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   CSS 自訂變數 — 深海金融主題
   ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
:root {
  --bg:        #07091a;
  --surface:   #0e1229;
  --surface2:  #151933;
  --border:    rgba(212,175,55,0.18);
  --gold:      #d4af37;
  --gold-dim:  #9a7c1f;
  --green:     #10b981;
  --blue:      #3b9eff;
  --red:       #ef4444;
  --text:      #dde3f0;
  --text-dim:  #7a849e;
  --radius:    14px;
  --shadow:    0 8px 32px rgba(0,0,0,0.5);
  --mono:      'JetBrains Mono', monospace;
  --serif:     'Playfair Display', serif;
  --sans:      'Nunito', '微軟正黑體', sans-serif;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--sans);
  min-height: 100vh;
  overflow-x: hidden;
}

/* ── 背景網格裝飾 ── */
body::before {
  content: '';
  position: fixed; inset: 0; z-index: 0;
  background-image:
    linear-gradient(rgba(212,175,55,0.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(212,175,55,0.03) 1px, transparent 1px);
  background-size: 40px 40px;
  pointer-events: none;
}

/* ━━ 頁首 ━━ */
header {
  position: relative; z-index: 10;
  display: flex; align-items: center; justify-content: space-between;
  padding: 20px 40px;
  border-bottom: 1px solid var(--border);
  background: rgba(14,18,41,0.8);
  backdrop-filter: blur(12px);
}
.logo { display: flex; align-items: center; gap: 14px; }
.logo-icon {
  width: 40px; height: 40px;
  background: linear-gradient(135deg, var(--gold), var(--gold-dim));
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px;
}
.logo-text { font-family: var(--serif); font-size: 1.3rem; color: var(--gold); }
.logo-sub  { font-size: 0.75rem; color: var(--text-dim); }
.user-badge {
  display: flex; align-items: center; gap: 10px;
  background: var(--surface2); border: 1px solid var(--border);
  padding: 8px 16px; border-radius: 30px;
  font-size: 0.85rem;
}
.user-badge .role { color: var(--gold); font-size: 0.7rem; text-transform: uppercase; }

/* ━━ 主容器 ━━ */
main {
  position: relative; z-index: 5;
  max-width: 1380px; margin: 0 auto;
  padding: 32px 24px 60px;
}

/* ━━ 通知條 ━━ */
.notice {
  display: flex; align-items: center; gap: 10px;
  background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3);
  color: var(--green); border-radius: 10px;
  padding: 12px 20px; margin-bottom: 28px;
  font-size: 0.9rem;
  animation: slideIn 0.4s ease;
}
@keyframes slideIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:none} }

/* ━━ 倒數計時英雄區塊 ━━ */
.hero {
  text-align: center;
  padding: 48px 20px 36px;
  margin-bottom: 36px;
  position: relative;
}
.hero::after {
  content: '';
  position: absolute; bottom: 0; left: 10%; right: 10%; height: 1px;
  background: linear-gradient(90deg, transparent, var(--gold), transparent);
}
.hero-label {
  font-family: var(--mono); font-size: 0.72rem;
  color: var(--gold); letter-spacing: 3px; text-transform: uppercase;
  margin-bottom: 12px;
}
.hero-title {
  font-family: var(--serif); font-size: 2rem; color: var(--text);
  margin-bottom: 28px; font-weight: 400;
}
.hero-title span { color: var(--gold); }

/* 倒數時鐘 */
.countdown {
  display: flex; justify-content: center; gap: 16px;
  flex-wrap: wrap; margin-bottom: 20px;
}
.cd-unit {
  display: flex; flex-direction: column; align-items: center;
  min-width: 72px;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 12px; padding: 12px 10px;
}
.cd-num {
  font-family: var(--mono); font-size: 2rem; font-weight: 600;
  color: var(--gold); line-height: 1;
}
.cd-label { font-size: 0.65rem; color: var(--text-dim); margin-top: 4px; }
.hero-sub { font-size: 0.85rem; color: var(--text-dim); }

/* ━━ 摘要卡片 ━━ */
.summary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px; margin-bottom: 32px;
}
.scard {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 20px 24px;
  transition: border-color .25s, transform .2s;
  animation: fadeUp .5s ease both;
}
.scard:hover { border-color: var(--gold-dim); transform: translateY(-2px); }
.scard-label { font-size: 0.75rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; }
.scard-val {
  font-family: var(--mono); font-size: 1.55rem;
  color: var(--text); margin: 6px 0 2px; font-weight: 600;
}
.scard-val.gold  { color: var(--gold); }
.scard-val.green { color: var(--green); }
.scard-val.blue  { color: var(--blue); }
.scard-sub { font-size: 0.75rem; color: var(--text-dim); }
.scard-icon {
  float: right; font-size: 1.6rem; opacity: 0.35;
  margin-top: -2px;
}
@keyframes fadeUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:none} }

/* ━━ 圖表區 ━━ */
.charts-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 20px; margin-bottom: 24px;
}
.chart-full { grid-column: 1 / -1; }
.chart-panel {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 24px;
  animation: fadeUp .6s ease both;
}
.panel-title {
  font-family: var(--serif); font-size: 1.05rem;
  color: var(--gold); margin-bottom: 6px;
}
.panel-sub { font-size: 0.78rem; color: var(--text-dim); margin-bottom: 20px; }

/* ━━ 參數表單 ━━ */
.form-section {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); margin-bottom: 24px;
  overflow: hidden;
}
.form-header {
  display: flex; justify-content: space-between; align-items: center;
  padding: 18px 24px; cursor: pointer;
  border-bottom: 1px solid transparent;
  transition: border-color .25s;
}
.form-header:hover, .form-section.open .form-header {
  border-color: var(--border);
}
.form-header-title {
  display: flex; align-items: center; gap: 10px;
  font-family: var(--serif); font-size: 1.05rem; color: var(--gold);
}
.toggle-icon { transition: transform .3s; color: var(--text-dim); }
.form-section.open .toggle-icon { transform: rotate(180deg); }

.form-body {
  padding: 24px; display: none;
}
.form-section.open .form-body { display: block; }

.form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 16px 24px;
}
.fg-group { display: flex; flex-direction: column; gap: 6px; }
.fg-group label {
  font-size: 0.78rem; color: var(--text-dim); letter-spacing: 0.5px;
}
.fg-group input {
  background: var(--surface2); border: 1px solid var(--border);
  color: var(--text); padding: 10px 14px; border-radius: 8px;
  font-family: var(--mono); font-size: 0.9rem;
  outline: none; transition: border-color .2s;
}
.fg-group input:focus { border-color: var(--gold); }
.fg-group .hint { font-size: 0.7rem; color: var(--text-dim); }

.form-divider {
  grid-column: 1 / -1;
  border: none; border-top: 1px solid var(--border);
  margin: 4px 0;
}
.form-section-label {
  grid-column: 1 / -1;
  font-size: 0.72rem; color: var(--gold-dim);
  text-transform: uppercase; letter-spacing: 2px; padding-top: 6px;
}
.save-btn {
  margin-top: 20px;
  background: linear-gradient(135deg, var(--gold), var(--gold-dim));
  color: #000; font-weight: 700; font-family: var(--sans);
  border: none; padding: 12px 32px; border-radius: 8px;
  cursor: pointer; font-size: 0.95rem;
  transition: opacity .2s, transform .15s;
}
.save-btn:hover { opacity: 0.88; transform: translateY(-1px); }

/* ━━ 勞保年金試算表 ━━ */
.pension-table {
  width: 100%; border-collapse: collapse; font-size: 0.85rem;
  margin-top: 12px;
}
.pension-table th {
  font-family: var(--mono); font-size: 0.72rem;
  color: var(--text-dim); text-align: left;
  padding: 8px 12px; border-bottom: 1px solid var(--border);
  text-transform: uppercase; letter-spacing: 1px;
}
.pension-table td {
  padding: 10px 12px; border-bottom: 1px solid rgba(255,255,255,0.04);
}
.pension-table tr:last-child td { border-bottom: none; }
.pension-table .highlight { color: var(--gold); font-family: var(--mono); font-weight: 600; }
.pension-table .best-row td { background: rgba(212,175,55,0.06); }

/* ━━ 頁腳 ━━ */
footer {
  text-align: center; color: var(--text-dim); font-size: 0.75rem;
  padding: 24px; border-top: 1px solid var(--border);
  position: relative; z-index: 5;
}

/* ━━ 響應式 ━━ */
@media(max-width: 900px) {
  .charts-grid { grid-template-columns: 1fr; }
  header { padding: 16px 20px; }
  main { padding: 20px 14px 48px; }
  .countdown { gap: 10px; }
}
</style>
</head>
<body>

<!-- ── 頁首 ── -->
<header>
  <div class="logo">
    <div class="logo-icon">🏦</div>
    <div>
      <div class="logo-text">退休財務儀表板</div>
      <div class="logo-sub">Retirement Financial Dashboard</div>
    </div>
  </div>
  <div class="user-badge">
    <span>👤 <?= htmlspecialchars($user['display_name']) ?></span>
    <span class="role">admin</span>
    <span style="color:var(--text-dim)">|</span>
    <a href="dashboard.php" style="color:var(--text-dim);text-decoration:none;font-size:0.8rem">← 返回</a>
  </div>
</header>

<main>

<?php if ($msg === 'success'): ?>
<div class="notice">✅ 參數已儲存，圖表已即時更新</div>
<?php endif; ?>

<!-- ━━ 倒數英雄區 ━━ -->
<div class="hero">
  <div class="hero-label">Distance to Freedom · 距離退休</div>
  <div class="hero-title">
    目標：<span id="retire-year"><?= $retire_yr ?></span> 年退休
    （<?= $p['target_retire_age'] ?> 歲）
  </div>
  <div class="countdown">
    <div class="cd-unit"><div class="cd-num" id="cd-y">--</div><div class="cd-label">年</div></div>
    <div class="cd-unit"><div class="cd-num" id="cd-d">--</div><div class="cd-label">天</div></div>
    <div class="cd-unit"><div class="cd-num" id="cd-h">--</div><div class="cd-label">時</div></div>
    <div class="cd-unit"><div class="cd-num" id="cd-m">--</div><div class="cd-label">分</div></div>
    <div class="cd-unit"><div class="cd-num" id="cd-s">--</div><div class="cd-label">秒</div></div>
  </div>
  <div class="hero-sub">目前年齡：<?= $cur_age ?> 歲 ／ 距退休還有 <strong id="months-left">--</strong> 個月</div>
</div>

<!-- ━━ 摘要卡片 ━━ -->
<div class="summary-grid">
  <div class="scard" style="animation-delay:.05s">
    <span class="scard-icon">🏛️</span>
    <div class="scard-label">勞退專戶（現值）</div>
    <div class="scard-val gold" id="sum-fund">--</div>
    <div class="scard-sub">雇主 + 個人自提累積</div>
  </div>
  <div class="scard" style="animation-delay:.1s">
    <span class="scard-icon">📈</span>
    <div class="scard-label">退休時預估勞退基金</div>
    <div class="scard-val green" id="sum-future-fund">--</div>
    <div class="scard-sub" id="sum-return-rate">年化報酬率 <span>--</span></div>
  </div>
  <div class="scard" style="animation-delay:.15s">
    <span class="scard-icon">🎖️</span>
    <div class="scard-label">勞保月退年金（估算）</div>
    <div class="scard-val blue" id="sum-li-pension">--</div>
    <div class="scard-sub">取公式一、二較高值</div>
  </div>
  <div class="scard" style="animation-delay:.2s">
    <span class="scard-icon">💰</span>
    <div class="scard-label">退休後預估月收入</div>
    <div class="scard-val gold" id="sum-monthly-income">--</div>
    <div class="scard-sub">勞保年金 + 勞退月撥</div>
  </div>
  <div class="scard" style="animation-delay:.25s">
    <span class="scard-icon">🗂️</span>
    <div class="scard-label">資產總覽（非勞保退）</div>
    <div class="scard-val" id="sum-personal-assets">--</div>
    <div class="scard-sub">證券 + 儲蓄險 + 其他</div>
  </div>
  <div class="scard" style="animation-delay:.3s">
    <span class="scard-icon">⏳</span>
    <div class="scard-label">勞保年資</div>
    <div class="scard-val" id="sum-li-years">--</div>
    <div class="scard-sub">換算月資：<span id="sum-li-months">--</span></div>
  </div>
</div>

<!-- ━━ 圖表區 ━━ -->
<div class="charts-grid">

  <!-- 勞退基金複利成長曲線（全寬） -->
  <div class="chart-panel chart-full" style="animation-delay:.1s">
    <div class="panel-title">📊 勞退個人專戶增長模擬</div>
    <div class="panel-sub">複利滾動曲線：現有本金 + 每月提繳，按設定年化報酬率成長至退休年</div>
    <canvas id="growthChart" height="280"></canvas>
  </div>

  <!-- 資產配置圓餅 -->
  <div class="chart-panel" style="animation-delay:.15s">
    <div class="panel-title">🗂️ 退休資產配置</div>
    <div class="panel-sub">退休時各類資產佔比</div>
    <canvas id="allocChart" height="260"></canvas>
    <div id="alloc-legend" style="margin-top:16px;display:flex;flex-wrap:wrap;gap:8px 16px;font-size:0.78rem;"></div>
  </div>

  <!-- 月收入拆解 -->
  <div class="chart-panel" style="animation-delay:.2s">
    <div class="panel-title">💵 退休月收入來源</div>
    <div class="panel-sub">退休後每月可領金額拆解</div>
    <canvas id="incomeChart" height="260"></canvas>
  </div>

</div>

<!-- ━━ 勞保年金詳細試算 ━━ -->
<div class="chart-panel" style="margin-bottom:24px;animation-delay:.25s">
  <div class="panel-title">🎖️ 勞保老年年金給付 — 公式試算</div>
  <div class="panel-sub">依勞保條例第 58-2 條：取以下兩公式較高值（依設定投保年資計算）</div>
  <table class="pension-table" id="li-table">
    <thead>
      <tr>
        <th>公式</th><th>計算說明</th><th>月領金額（估）</th><th>選擇</th>
      </tr>
    </thead>
    <tbody>
      <tr id="li-row1"><td>公式一</td><td id="li-f1-desc">-</td><td class="highlight" id="li-f1">-</td><td>-</td></tr>
      <tr id="li-row2"><td>公式二</td><td id="li-f2-desc">-</td><td class="highlight" id="li-f2">-</td><td>-</td></tr>
    </tbody>
  </table>
  <p style="font-size:0.75rem;color:var(--text-dim);margin-top:12px;">
    ⚠️ 以上為估算值，實際金額以勞動部勞工保險局核定為準。
    實際最高60個月平均投保薪資、年資採計請至
    <a href="https://www.bli.gov.tw" target="_blank" style="color:var(--gold)">勞保局</a>查詢。
  </p>
</div>

<!-- ━━ 參數設定表單 ━━ -->
<div class="form-section open">
  <div class="form-header" onclick="toggleForm(this)">
    <div class="form-header-title">⚙️ 個人退休參數設定</div>
    <span class="toggle-icon">▼</span>
  </div>
  <div class="form-body">
    <form method="POST" action="">
      <div class="form-grid">

        <div class="form-section-label">👤 基本資料</div>

        <div class="fg-group">
          <label>出生西元年</label>
          <input type="number" name="birth_year" value="<?= $p['birth_year'] ?>" min="1940" max="2010">
        </div>
        <div class="fg-group">
          <label>出生月份（1–12）</label>
          <input type="number" name="birth_month" value="<?= $p['birth_month'] ?>" min="1" max="12">
        </div>
        <div class="fg-group">
          <label>預計退休年齡（歲）</label>
          <input type="number" name="target_retire_age" value="<?= $p['target_retire_age'] ?>" min="50" max="75">
        </div>

        <hr class="form-divider">
        <div class="form-section-label">🎖️ 勞工保險（勞保）</div>

        <div class="fg-group">
          <label>目前勞保累積年資（年）</label>
          <input type="number" name="labor_insurance_years" value="<?= $p['labor_insurance_years'] ?>" step="0.01">
          <span class="hint">例：34年255天 ≈ 34.70，年金給付年資取月數無條件捨去</span>
        </div>
        <div class="fg-group">
          <label>最高60個月平均投保薪資（元）</label>
          <input type="number" name="highest_60_months_avg" value="<?= $p['highest_60_months_avg'] ?>">
          <span class="hint">由勞保局「e 化服務系統」查詢</span>
        </div>

        <hr class="form-divider">
        <div class="form-section-label">🏛️ 勞工退休金新制（勞退）</div>

        <div class="fg-group">
          <label>目前勞退專戶累積本金（元）</label>
          <input type="number" name="current_labor_pension_fund" value="<?= $p['current_labor_pension_fund'] ?>">
          <span class="hint">雇主提繳 + 個人自提 + 累計收益</span>
        </div>
        <div class="fg-group">
          <label>目前每月提繳工資（元）</label>
          <input type="number" name="monthly_contribution_wage" value="<?= $p['monthly_contribution_wage'] ?>">
          <span class="hint">依勞退月提繳工資分級表</span>
        </div>
        <div class="fg-group">
          <label>雇主提繳比例（0.06 = 6%）</label>
          <input type="number" name="employer_pension_rate" value="<?= $p['employer_pension_rate'] ?>" step="0.001" min="0.06" max="0.15">
        </div>
        <div class="fg-group">
          <label>個人自提比例（0–0.06）</label>
          <input type="number" name="self_pension_rate" value="<?= $p['self_pension_rate'] ?>" step="0.01" min="0" max="0.06">
          <span class="hint">自提享所得稅優惠，最高6%</span>
        </div>
        <div class="fg-group">
          <label>預估勞退基金年化報酬率</label>
          <input type="number" name="expected_return_rate" value="<?= $p['expected_return_rate'] ?>" step="0.001" min="0.01" max="0.15">
          <span class="hint">歷年勞退平均 ≈ 3~4%；全球指數基金預期 7%</span>
        </div>

        <hr class="form-divider">
        <div class="form-section-label">📦 個人資產（非勞保退）</div>

        <div class="fg-group">
          <label>證券投資現值（元）</label>
          <input type="number" name="securities_value" value="<?= $p['securities_value'] ?>">
        </div>
        <div class="fg-group">
          <label>證券預期年化報酬率</label>
          <input type="number" name="securities_return_rate" value="<?= $p['securities_return_rate'] ?>" step="0.001">
        </div>
        <div class="fg-group">
          <label>儲蓄險保單現值（元）</label>
          <input type="number" name="savings_insurance_value" value="<?= $p['savings_insurance_value'] ?>">
        </div>
        <div class="fg-group">
          <label>其他資產（現金、不動產等，元）</label>
          <input type="number" name="other_assets_value" value="<?= $p['other_assets_value'] ?>">
        </div>

      </div><!-- /form-grid -->

      <button type="submit" name="save_profile" class="save-btn">
        💾 儲存並更新試算
      </button>
    </form>
  </div>
</div>

</main>

<footer>
  退休財務儀表板 · <?= htmlspecialchars(SITE_NAME) ?> ·
  本頁試算結果僅供參考，不構成任何財務建議
</footer>

<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     JavaScript — 倒數計時 + 動態試算 + 圖表
     ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<script>
// ── 從 PHP 帶入初始參數 ──────────────────────────────────────
const P = {
  birthYear:           <?= (int)$p['birth_year'] ?>,
  birthMonth:          <?= (int)$p['birth_month'] ?>,
  targetRetireAge:     <?= (int)$p['target_retire_age'] ?>,
  laborInsuranceYears: <?= (float)$p['labor_insurance_years'] ?>,
  highest60MonthsAvg:  <?= (int)$p['highest_60_months_avg'] ?>,
  currentFund:         <?= (float)$p['current_labor_pension_fund'] ?>,
  monthlyWage:         <?= (int)$p['monthly_contribution_wage'] ?>,
  employerRate:        <?= (float)$p['employer_pension_rate'] ?>,
  selfRate:            <?= (float)$p['self_pension_rate'] ?>,
  returnRate:          <?= (float)$p['expected_return_rate'] ?>,
  securitiesValue:     <?= (float)$p['securities_value'] ?>,
  securitiesReturn:    <?= (float)$p['securities_return_rate'] ?>,
  savingsInsurance:    <?= (float)$p['savings_insurance_value'] ?>,
  otherAssets:         <?= (float)$p['other_assets_value'] ?>,
};

// ── 工具函數 ─────────────────────────────────────────────────

/** 格式化金額（加千分位）*/
function fmt(n, unit='元') {
  return Math.round(n).toLocaleString('zh-TW') + ' ' + unit;
}
function fmtM(n) { return fmt(n, '元/月'); }

/** 倒數目標時間戳 */
const retireTs = new Date(
  P.birthYear + P.targetRetireAge,
  P.birthMonth - 1,  // JS month 0-based
  1, 0, 0, 0
).getTime();

// ── 1. 倒數計時 ────────────────────────────────────────────
function tick() {
  const now  = Date.now();
  const diff = retireTs - now;
  if (diff <= 0) {
    document.querySelector('.countdown').innerHTML =
      '<div style="color:var(--gold);font-family:var(--serif);font-size:1.4rem">🎉 恭喜退休！</div>';
    return;
  }
  const totalDays = Math.floor(diff / 86400000);
  const years     = Math.floor(totalDays / 365.25);
  const days      = Math.floor(totalDays - years * 365.25);
  const hours     = Math.floor((diff % 86400000) / 3600000);
  const mins      = Math.floor((diff % 3600000)  / 60000);
  const secs      = Math.floor((diff % 60000)    / 1000);
  const months    = years * 12 + Math.floor(days / 30.44);

  document.getElementById('cd-y').textContent = String(years).padStart(2,'0');
  document.getElementById('cd-d').textContent = String(days).padStart(3,'0');
  document.getElementById('cd-h').textContent = String(hours).padStart(2,'0');
  document.getElementById('cd-m').textContent = String(mins).padStart(2,'0');
  document.getElementById('cd-s').textContent = String(secs).padStart(2,'0');
  document.getElementById('months-left').textContent = months;
}
tick();
setInterval(tick, 1000);

// ── 2. 試算引擎 ────────────────────────────────────────────

/** 計算退休時勞退基金終值（FV 現值複利）
 *  FV = PV×(1+r)^n + PMT×((1+r)^n - 1)/r
 *  PV  = 現有基金本金 (current_labor_pension_fund)
 *  PMT = 每月提繳額  (月工資 × (雇主率+自提率))
 *  r   = 月利率
 *  n   = 距退休月數
 */
function calcFutureLabPension(pv, monthlyPmt, annualRate, months) {
  if (months <= 0) return pv;
  if (annualRate === 0) return pv + monthlyPmt * months;
  const r = annualRate / 12;
  const g = Math.pow(1 + r, months);
  return pv * g + monthlyPmt * (g - 1) / r;
}

/** 逐年成長曲線（供折線圖用）*/
function growthCurve(pv, monthlyPmt, annualRate, yearsLeft) {
  const labels = [], vals = [], pmt = monthlyPmt;
  const now = new Date().getFullYear();
  let fund = pv;
  const r = annualRate / 12;
  for (let y = 0; y <= yearsLeft; y++) {
    labels.push(now + y + '年');
    vals.push(Math.round(fund));
    // 推進 12 個月
    if (r === 0) { fund += pmt * 12; }
    else {
      const g = Math.pow(1 + r, 12);
      fund = fund * g + pmt * (g - 1) / r;
    }
  }
  return { labels, vals };
}

/** 勞保老年年金試算
 *  公式一：平均月投保薪資 × 年資 × 0.775% + 3,000
 *  公式二：平均月投保薪資 × 年資 × 1.55%
 *  取較高者（年資採年金給付年資，以年月捨去計算）
 */
function calcLaborInsurancePension(avgSalary, years) {
  const f1 = avgSalary * years * 0.00775 + 3000;
  const f2 = avgSalary * years * 0.0155;
  return { f1, f2, best: Math.max(f1, f2) };
}

// ── 3. 主計算 & 更新畫面 ─────────────────────────────────
function nowAge() {
  const n = new Date();
  const yr = n.getFullYear(), mo = n.getMonth() + 1;
  return yr - P.birthYear + (mo >= P.birthMonth ? 0 : -1);
}

function compute() {
  const age       = nowAge();
  const yrsLeft   = Math.max(0, P.targetRetireAge - age);
  const mthsLeft  = yrsLeft * 12;
  const monthlyPmt = P.monthlyWage * (P.employerRate + P.selfRate);

  // 勞退基金終值
  const futureFund = calcFutureLabPension(
    P.currentFund, monthlyPmt, P.returnRate, mthsLeft);

  // 勞退月領（以20年 = 240個月 簡易拆分；可依財政部生命表調整）
  const lifeMonths = 240;
  const labPensionMonthly = futureFund / lifeMonths;

  // 勞保年金（年資 = 現在年資；到退休日期不變，以提問時數值計）
  const liP = calcLaborInsurancePension(P.highest60MonthsAvg, P.laborInsuranceYears);

  // 個人資產（證券+儲蓄險+其他也複利成長到退休）
  let secFuture = P.securitiesValue;
  if (yrsLeft > 0 && P.securitiesReturn > 0)
    secFuture = P.securitiesValue * Math.pow(1 + P.securitiesReturn, yrsLeft);
  const totalPersonal = secFuture + P.savingsInsurance + P.otherAssets;

  // 月總收入
  const totalMonthly = labPensionMonthly + liP.best;

  return { futureFund, monthlyPmt, labPensionMonthly, liP,
           totalPersonal, secFuture, totalMonthly, yrsLeft, mthsLeft };
}

function updateUI() {
  const c = compute();

  // 卡片
  document.getElementById('sum-fund').textContent        = fmt(P.currentFund);
  document.getElementById('sum-future-fund').textContent = fmt(c.futureFund);
  document.getElementById('sum-return-rate').innerHTML   =
    `年化報酬率 <strong style="color:var(--gold)">${(P.returnRate*100).toFixed(1)}%</strong>`;
  document.getElementById('sum-li-pension').textContent  = fmtM(c.liP.best);
  document.getElementById('sum-monthly-income').textContent = fmtM(c.totalMonthly);
  document.getElementById('sum-personal-assets').textContent = fmt(c.totalPersonal);
  document.getElementById('sum-li-years').textContent    = P.laborInsuranceYears.toFixed(2) + ' 年';
  document.getElementById('sum-li-months').textContent   = Math.floor(P.laborInsuranceYears * 12) + ' 月';

  // 勞保試算表
  const avgS = P.highest60MonthsAvg;
  const yrs  = P.laborInsuranceYears;
  document.getElementById('li-f1-desc').textContent =
    `${avgS.toLocaleString()} × ${yrs.toFixed(2)} × 0.775% + 3,000`;
  document.getElementById('li-f2-desc').textContent =
    `${avgS.toLocaleString()} × ${yrs.toFixed(2)} × 1.55%`;
  document.getElementById('li-f1').textContent = fmtM(c.liP.f1);
  document.getElementById('li-f2').textContent = fmtM(c.liP.f2);

  const row1 = document.getElementById('li-row1');
  const row2 = document.getElementById('li-row2');
  if (c.liP.f1 >= c.liP.f2) {
    row1.classList.add('best-row');   row2.classList.remove('best-row');
    row1.cells[3].textContent = '✅ 較高，採用';
    row2.cells[3].textContent = '';
  } else {
    row2.classList.add('best-row');   row1.classList.remove('best-row');
    row2.cells[3].textContent = '✅ 較高，採用';
    row1.cells[3].textContent = '';
  }

  // 更新圖表
  updateGrowthChart(c);
  updateAllocChart(c);
  updateIncomeChart(c);
}

// ── 4. Chart.js 圖表 ───────────────────────────────────────

/* 共用配置 */
Chart.defaults.color = '#7a849e';
Chart.defaults.font.family = "'JetBrains Mono', monospace";

let growthChart, allocChart, incomeChart;

function initGrowthChart() {
  const ctx = document.getElementById('growthChart').getContext('2d');
  growthChart = new Chart(ctx, {
    type: 'line',
    data: { labels: [], datasets: [
      {
        label: '勞退基金 (複利)',
        data: [], fill: true,
        borderColor: '#d4af37',
        backgroundColor: 'rgba(212,175,55,0.08)',
        borderWidth: 2.5,
        pointRadius: 4, pointBackgroundColor: '#d4af37',
        tension: 0.35,
      },
      {
        label: '純本金（不含收益）',
        data: [], fill: false,
        borderColor: 'rgba(255,255,255,0.15)',
        borderDash: [6,4],
        borderWidth: 1.5,
        pointRadius: 0,
        tension: 0.35,
      }
    ]},
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { color: '#7a849e', font: { size: 11 } } },
        tooltip: {
          callbacks: {
            label: ctx => ' ' + ctx.dataset.label + '：' +
              Math.round(ctx.raw).toLocaleString('zh-TW') + ' 元'
          }
        }
      },
      scales: {
        x: { grid: { color: 'rgba(255,255,255,0.04)' },
             ticks: { maxRotation: 45, font: { size: 10 } } },
        y: { grid: { color: 'rgba(255,255,255,0.04)' },
             ticks: {
               callback: v => (v >= 1e6 ? (v/1e4).toFixed(0)+'萬' :
                               v >= 1e3 ? (v/1e3).toFixed(0)+'千' : v)
             }}
      }
    }
  });
}

function updateGrowthChart(c) {
  const curve   = growthCurve(P.currentFund, c.monthlyPmt, P.returnRate, c.yrsLeft);
  // 純本金曲線（收益率=0）
  const noRetCurve = growthCurve(P.currentFund, c.monthlyPmt, 0, c.yrsLeft);

  growthChart.data.labels             = curve.labels;
  growthChart.data.datasets[0].data   = curve.vals;
  growthChart.data.datasets[1].data   = noRetCurve.vals;
  growthChart.update('none');
}

function initAllocChart() {
  const ctx = document.getElementById('allocChart').getContext('2d');
  allocChart = new Chart(ctx, {
    type: 'doughnut',
    data: { labels: [], datasets: [{ data: [],
      backgroundColor: ['#d4af37','#3b9eff','#10b981','#a78bfa'],
      borderColor: '#0e1229', borderWidth: 3,
      hoverOffset: 8,
    }]},
    options: {
      cutout: '60%',
      plugins: { legend: { display: false },
        tooltip: { callbacks: {
          label: ctx => ' ' + ctx.label + '：' +
            Math.round(ctx.raw).toLocaleString('zh-TW') + ' 元'
        }}
      }
    }
  });
}

function updateAllocChart(c) {
  const labels = ['勞退基金（退休時）','證券投資','儲蓄險','其他資產'];
  const vals   = [c.futureFund, c.secFuture, P.savingsInsurance, P.otherAssets];
  const colors = ['#d4af37','#3b9eff','#10b981','#a78bfa'];

  // 過濾掉 0
  const filtered = labels.map((l,i)=>({l,v:vals[i],c:colors[i]})).filter(x=>x.v>0);
  allocChart.data.labels                      = filtered.map(x=>x.l);
  allocChart.data.datasets[0].data            = filtered.map(x=>x.v);
  allocChart.data.datasets[0].backgroundColor = filtered.map(x=>x.c);
  allocChart.update('none');

  // 自製圖例
  const leg = document.getElementById('alloc-legend');
  const total = filtered.reduce((s,x)=>s+x.v, 0);
  leg.innerHTML = filtered.map(x=>
    `<span style="display:flex;align-items:center;gap:5px;">
       <span style="width:10px;height:10px;border-radius:50%;background:${x.c};flex-shrink:0"></span>
       ${x.l}：${(x.v/total*100).toFixed(1)}%
     </span>`
  ).join('');
}

function initIncomeChart() {
  const ctx = document.getElementById('incomeChart').getContext('2d');
  incomeChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: ['勞保月退年金','勞退月撥付','合計月收入'],
      datasets: [{
        data: [],
        backgroundColor: ['#3b9eff','#d4af37','#10b981'],
        borderRadius: 8, borderSkipped: false,
      }]
    },
    options: {
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: {
          label: ctx => ' 每月：' + Math.round(ctx.raw).toLocaleString('zh-TW') + ' 元'
        }}
      },
      scales: {
        x: { grid: { display: false } },
        y: { grid: { color: 'rgba(255,255,255,0.04)' },
             ticks: { callback: v => v.toLocaleString('zh-TW') }}
      }
    }
  });
}

function updateIncomeChart(c) {
  incomeChart.data.datasets[0].data =
    [c.liP.best, c.labPensionMonthly, c.totalMonthly];
  incomeChart.update('none');
}

// ── 5. 表單即時預覽（輸入時即時更新卡片）─────────────────
function bindFormInputs() {
  const map = {
    birth_year:                   v => { P.birthYear = +v; },
    birth_month:                  v => { P.birthMonth = +v; },
    target_retire_age:            v => { P.targetRetireAge = +v; },
    labor_insurance_years:        v => { P.laborInsuranceYears = +v; },
    highest_60_months_avg:        v => { P.highest60MonthsAvg = +v; },
    current_labor_pension_fund:   v => { P.currentFund = +v; },
    monthly_contribution_wage:    v => { P.monthlyWage = +v; },
    employer_pension_rate:        v => { P.employerRate = +v; },
    self_pension_rate:            v => { P.selfRate = +v; },
    expected_return_rate:         v => { P.returnRate = +v; },
    securities_value:             v => { P.securitiesValue = +v; },
    securities_return_rate:       v => { P.securitiesReturn = +v; },
    savings_insurance_value:      v => { P.savingsInsurance = +v; },
    other_assets_value:           v => { P.otherAssets = +v; },
  };
  document.querySelectorAll('form input').forEach(inp => {
    inp.addEventListener('input', () => {
      const fn = map[inp.name];
      if (fn) { fn(inp.value); updateUI(); }
    });
  });
}

// ── 6. 表單展開/收合 ──────────────────────────────────────
function toggleForm(header) {
  header.parentElement.classList.toggle('open');
}

// ── 7. 初始化 ──────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', () => {
  initGrowthChart();
  initAllocChart();
  initIncomeChart();
  updateUI();
  bindFormInputs();
});
</script>
</body>
</html>
