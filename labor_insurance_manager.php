<?php
// ============================================================
// labor_insurance_manager.php — 勞保投保資料管理（輕量版）
// 架構：儲存只寫主紀錄表，60月平均即時PHP計算，不寫月份展開表
// ============================================================
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/Auth.php';

Auth::requireRole('admin');

$db   = Database::getInstance();
$user = Auth::currentUser();
$mid  = $user['id'];
$msg  = '';
$err  = '';

// 資料表已由 wu_full_setup.sql 建立，此處不再重複 CREATE TABLE

// ══════════════════════════════════════════════════════════
// 核心計算：從稀疏主紀錄直接算60月平均（純PHP，不寫DB）
// 每筆紀錄涵蓋從 start_date 到 end_date（或下一筆前一月）
// ══════════════════════════════════════════════════════════
function calc_retirement_data($db, $member_id) {
    $stmt = $db->prepare("SELECT * FROM `labor_insurance_records`
                          WHERE `member_id`=? ORDER BY `start_date` ASC");
    $stmt->execute(array($member_id));
    $records = $stmt->fetchAll();

    if (empty($records)) {
        return array('avg60'=>0, 'top60_groups'=>array(), 'total_months'=>0, 'records_with_months'=>array());
    }

    $today = new DateTime('first day of this month');

    // 計算每筆紀錄涵蓋的月數（稀疏→月份數量，不展開）
    $rec_months = array();
    foreach ($records as $idx => $rec) {
        $start = new DateTime($rec['start_date']);
        $start->modify('first day of this month');

        if ($rec['end_date']) {
            $end = new DateTime($rec['end_date']);
            $end->modify('first day of this month');
        } else {
            $end = clone $today;
        }

        // 月數 = 差距月份 + 1
        $diff   = $start->diff($end);
        $months = $diff->y * 12 + $diff->m + 1;
        if ($months < 1) $months = 1;

        $rec_months[] = array(
            'id'         => $rec['id'],
            'seq'        => $rec['seq'],
            'ins_num'    => $rec['ins_num'],
            'company'    => $rec['company'],
            'salary'     => (int)$rec['labor_salary'],
            'start_date' => $rec['start_date'],
            'end_date'   => $rec['end_date'],
            'months'     => $months,
            'remark'     => $rec['remark'],
        );
    }

    $total_months = array_sum(array_map(function($r){ return $r['months']; }, $rec_months));

    // 依薪資由高至低排序
    usort($rec_months, function($a, $b){ return $b['salary'] - $a['salary']; });

    // 取前60個月（依薪資群組累積）
    $top60_groups = array();
    $remaining    = 60;
    $sum60        = 0;
    $count60      = 0;

    foreach ($rec_months as $r) {
        if ($remaining <= 0) break;
        $take     = min($remaining, $r['months']);
        $top60_groups[] = array_merge($r, array('take' => $take));
        $sum60   += $r['salary'] * $take;
        $count60 += $take;
        $remaining -= $take;
    }

    $avg60 = ($count60 > 0) ? (int)round($sum60 / $count60) : 0;

    return array(
        'avg60'             => $avg60,
        'top60_groups'      => $top60_groups,
        'total_months'      => $total_months,
        'records_with_months' => $rec_months,  // 已排序（高薪在前）
    );
}

// ══════════════════════════════════════════════════════════
// POST 處理：新增 / 修改 / 刪除（只動主紀錄表）
// ══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'add') {
        $company      = trim($_POST['company']);
        $ins_num      = trim(isset($_POST['ins_num'])      ? $_POST['ins_num']      : '');
        $labor_salary = (int)$_POST['labor_salary'];
        $dis_salary   = (int)(isset($_POST['disaster_salary']) && $_POST['disaster_salary']
                              ? $_POST['disaster_salary'] : $labor_salary);
        $start_date   = trim($_POST['start_date']);
        $end_date     = trim(isset($_POST['end_date']) ? $_POST['end_date'] : '');
        $remark       = trim(isset($_POST['remark'])   ? $_POST['remark']   : '');

        if (!$company || !$labor_salary || !$start_date) {
            $err = '公司名稱、投保薪資、生效日期為必填';
        } else {
            $s = $db->prepare("SELECT COALESCE(MAX(`seq`),0)+1 FROM `labor_insurance_records` WHERE `member_id`=?");
            $s->execute(array($mid));
            $next_seq = (int)$s->fetchColumn();
            $end_val  = $end_date ? $end_date : null;

            $db->prepare("INSERT INTO `labor_insurance_records`
                (`member_id`,`seq`,`ins_num`,`company`,`labor_salary`,`disaster_salary`,`start_date`,`end_date`,`remark`)
                VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute(array($mid,$next_seq,$ins_num,$company,$labor_salary,$dis_salary,$start_date,$end_val,$remark));

            // POST 後才更新退休參數表
            $tmp = calc_retirement_data($db, $mid);
            try {
                $db->prepare("UPDATE `retirement_profiles` SET `highest_60_months_avg`=? WHERE `member_id`=?")
                   ->execute(array($tmp['avg60'], $mid));
            } catch (Exception $e) {}
            $msg = '✅ 已新增，60月平均薪資已即時更新';
        }
    }

    if ($action === 'edit') {
        $id           = (int)$_POST['id'];
        $company      = trim($_POST['company']);
        $ins_num      = trim(isset($_POST['ins_num'])      ? $_POST['ins_num']      : '');
        $labor_salary = (int)$_POST['labor_salary'];
        $dis_salary   = (int)(isset($_POST['disaster_salary']) && $_POST['disaster_salary']
                              ? $_POST['disaster_salary'] : $labor_salary);
        $start_date   = trim($_POST['start_date']);
        $end_date     = trim(isset($_POST['end_date']) ? $_POST['end_date'] : '');
        $remark       = trim(isset($_POST['remark'])   ? $_POST['remark']   : '');

        if (!$company || !$labor_salary || !$start_date) {
            $err = '公司名稱、投保薪資、生效日期為必填';
        } else {
            $end_val = $end_date ? $end_date : null;
            $db->prepare("UPDATE `labor_insurance_records`
                SET `ins_num`=?,`company`=?,`labor_salary`=?,`disaster_salary`=?,
                    `start_date`=?,`end_date`=?,`remark`=?
                WHERE `id`=? AND `member_id`=?")
               ->execute(array($ins_num,$company,$labor_salary,$dis_salary,$start_date,$end_val,$remark,$id,$mid));

            $tmp = calc_retirement_data($db, $mid);
            try {
                $db->prepare("UPDATE `retirement_profiles` SET `highest_60_months_avg`=? WHERE `member_id`=?")
                   ->execute(array($tmp['avg60'], $mid));
            } catch (Exception $e) {}
            $msg = '✅ 已更新，60月平均薪資已即時更新';
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM `labor_insurance_records` WHERE `id`=? AND `member_id`=?")
           ->execute(array($id, $mid));
        $tmp = calc_retirement_data($db, $mid);
        try {
            $db->prepare("UPDATE `retirement_profiles` SET `highest_60_months_avg`=? WHERE `member_id`=?")
               ->execute(array($tmp['avg60'], $mid));
        } catch (Exception $e) {}
        $msg = '🗑️ 已刪除，60月平均薪資已即時更新';
    }
}

// ── 即時計算（不寫DB，純PHP）──────────────────────────────
$calc = calc_retirement_data($db, $mid);

// ── 讀取主紀錄（依生效日期排序，供表格顯示）─────────────
$stmt = $db->prepare("SELECT * FROM `labor_insurance_records` WHERE `member_id`=? ORDER BY `start_date` ASC");
$stmt->execute(array($mid));
$records = $stmt->fetchAll();

// ── 編輯模式 ─────────────────────────────────────────────
$editing = null;
if (isset($_GET['edit'])) {
    $s = $db->prepare("SELECT * FROM `labor_insurance_records` WHERE `id`=? AND `member_id`=?");
    $s->execute(array((int)$_GET['edit'], $mid));
    $editing = $s->fetch();
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>勞保資料管理</title>
<!-- Google Fonts 移除，避免伺服器端外部連線延遲 -->
<style>
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1a2236;--border:rgba(212,175,55,.2);
  --gold:#d4af37;--green:#10b981;--blue:#3b9eff;--red:#ef4444;
  --text:#dde3f0;--dim:#7a849e;--radius:10px;--mono:'Courier New',monospace}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'Microsoft JhengHei','微軟正黑體',sans-serif;font-size:14px;min-height:100vh}
a{color:var(--gold);text-decoration:none}
header{display:flex;align-items:center;justify-content:space-between;padding:16px 32px;
  border-bottom:1px solid var(--border);background:rgba(14,18,41,.9);
  backdrop-filter:blur(8px);position:sticky;top:0;z-index:100}
.logo{font-size:1.1rem;color:var(--gold);font-weight:700}
.nav-links a{margin-left:16px;font-size:0.85rem;color:var(--dim)}
.nav-links a:hover{color:var(--gold)}
main{max-width:1200px;margin:0 auto;padding:24px 20px 60px}
.msg{padding:12px 20px;border-radius:var(--radius);margin-bottom:20px;font-size:0.9rem}
.msg.ok{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:var(--green)}
.msg.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:var(--red)}
.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:28px}
.scard{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px}
.scard-label{font-size:0.72rem;color:var(--dim);text-transform:uppercase;letter-spacing:1px}
.scard-val{font-family:var(--mono);font-size:1.6rem;color:var(--gold);margin:8px 0 4px}
.scard-sub{font-size:0.75rem;color:var(--dim)}
.form-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:28px}
.form-card h2{font-size:1rem;color:var(--gold);margin-bottom:20px}
.form-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px 16px}
.fg{display:flex;flex-direction:column;gap:5px}
.fg label{font-size:0.75rem;color:var(--dim)}
.fg input{background:var(--surface2);border:1px solid var(--border);color:var(--text);
  padding:9px 12px;border-radius:7px;font-family:var(--mono);font-size:0.88rem;outline:none;transition:border-color .2s}
.fg input:focus{border-color:var(--gold)}
.fg .hint{font-size:0.68rem;color:var(--dim)}
.form-actions{margin-top:18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.btn{padding:9px 24px;border:none;border-radius:7px;cursor:pointer;font-size:0.88rem;font-weight:600;transition:opacity .2s;display:inline-block}
.btn:hover{opacity:.85}
.btn-gold{background:linear-gradient(135deg,var(--gold),#9a7c1f);color:#000}
.btn-gray{background:var(--surface2);color:var(--dim);border:1px solid var(--border)}
.btn-red{background:rgba(239,68,68,.15);color:var(--red);border:1px solid rgba(239,68,68,.3)}
.btn-sm{padding:5px 12px;font-size:0.78rem}
.table-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:28px;overflow-x:auto}
.table-card h2{font-size:1rem;color:var(--gold);margin-bottom:16px}
table{width:100%;border-collapse:collapse;font-size:0.82rem}
th{font-family:var(--mono);font-size:0.7rem;color:var(--dim);text-align:left;
   padding:8px 10px;border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.5px}
td{padding:9px 10px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(255,255,255,.02)}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:0.7rem}
.badge-on{background:rgba(16,185,129,.15);color:var(--green);border:1px solid rgba(16,185,129,.3)}
.badge-off{background:rgba(100,100,120,.15);color:var(--dim);border:1px solid rgba(100,100,120,.3)}
.del-form{display:inline}
/* 60月群組卡片 */
.top60-wrap{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-top:14px}
.top60-card{background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:12px 14px}
.top60-card.active{border-color:rgba(212,175,55,.5);background:rgba(212,175,55,.05)}
.t60-sal{font-family:var(--mono);font-size:1.1rem;color:var(--gold);font-weight:600}
.t60-info{font-size:0.75rem;color:var(--dim);margin-top:4px;line-height:1.6}
.t60-bar{height:4px;background:var(--surface);border-radius:2px;margin-top:8px}
.t60-bar-fill{height:4px;background:var(--gold);border-radius:2px;transition:width .5s}
@media(max-width:600px){.summary{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header>
  <div class="logo">🎖️ 勞保資料管理</div>
  <div class="nav-links">
    <a href="retirement_dashboard.php">← 退休儀表板</a>
    <a href="dashboard.php">首頁</a>
  </div>
</header>
<main>

<?php if ($msg): ?><div class="msg ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="msg err">❌ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- ── 摘要卡片 ── -->
<div class="summary">
  <div class="scard">
    <div class="scard-label">投保主紀錄</div>
    <div class="scard-val"><?= count($records) ?></div>
    <div class="scard-sub">筆（含在保中）</div>
  </div>
  <div class="scard">
    <div class="scard-label">累積投保月份</div>
    <div class="scard-val"><?= number_format($calc['total_months']) ?></div>
    <div class="scard-sub">筆估算月份（依紀錄計算）</div>
  </div>
  <div class="scard">
    <div class="scard-label">最高60月平均薪資</div>
    <div class="scard-val"><?= $calc['avg60'] ? number_format($calc['avg60']) : '---' ?></div>
    <div class="scard-sub">元（勞保年金計算基礎）</div>
  </div>
</div>

<!-- ── 新增 / 修改表單 ── -->
<div class="form-card">
  <h2><?= $editing ? '✏️ 修改投保紀錄' : '➕ 新增投保紀錄' ?></h2>
  <form method="POST">
    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
    <div class="form-grid">
      <div class="fg">
        <label>投保單位名稱 *</label>
        <input type="text" name="company" required maxlength="60"
               value="<?= htmlspecialchars($editing ? $editing['company'] : '') ?>" placeholder="公司名稱">
      </div>
      <div class="fg">
        <label>保險證號</label>
        <input type="text" name="ins_num" maxlength="14"
               value="<?= htmlspecialchars($editing ? $editing['ins_num'] : '') ?>" placeholder="選填">
      </div>
      <div class="fg">
        <label>勞就保投保薪資（元）*</label>
        <input type="number" name="labor_salary" required min="1"
               value="<?= $editing ? $editing['labor_salary'] : '' ?>" placeholder="例：45800">
        <span class="hint">依勞保薪資分級表</span>
      </div>
      <div class="fg">
        <label>災保投保薪資（元）</label>
        <input type="number" name="disaster_salary" min="0"
               value="<?= $editing ? $editing['disaster_salary'] : '' ?>" placeholder="留空同勞就保">
      </div>
      <div class="fg">
        <label>生效日期 *</label>
        <input type="date" name="start_date" required
               value="<?= $editing ? $editing['start_date'] : '' ?>">
        <span class="hint">薪資變更生效月份1日</span>
      </div>
      <div class="fg">
        <label>退保日期</label>
        <input type="date" name="end_date"
               value="<?= ($editing && $editing['end_date']) ? $editing['end_date'] : '' ?>">
        <span class="hint">留空＝目前仍在保中</span>
      </div>
      <div class="fg">
        <label>備註</label>
        <input type="text" name="remark" maxlength="100"
               value="<?= htmlspecialchars($editing ? $editing['remark'] : '') ?>" placeholder="選填">
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-gold">
        <?= $editing ? '💾 儲存修改' : '➕ 新增' ?>
      </button>
      <?php if ($editing): ?>
      <a href="labor_insurance_manager.php" class="btn btn-gray">取消</a>
      <?php endif; ?>
      <span style="font-size:0.75rem;color:var(--dim)">儲存即時更新60月平均（無延遲）</span>
    </div>
  </form>
</div>

<!-- ── 主紀錄列表 ── -->
<div class="table-card">
  <h2>📋 投保主紀錄（共 <?= count($records) ?> 筆，依生效日期排序）</h2>
  <?php if (empty($records)): ?>
  <p style="color:var(--dim);padding:20px 0">尚未有投保紀錄，請使用上方表單新增。</p>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>#</th><th>保險證號</th><th>投保單位</th>
        <th>勞就保薪資</th><th>生效日期</th><th>退保日期</th>
        <th>月數</th><th>狀態</th><th>備註</th><th>操作</th>
      </tr>
    </thead>
    <tbody>
    <?php
    // 建立月數查找表（依 id）
    $months_map = array();
    foreach ($calc['records_with_months'] as $r) {
        $months_map[$r['id']] = $r['months'];
    }
    foreach ($records as $i => $r):
    ?>
    <tr>
      <td style="color:var(--dim)"><?= $i+1 ?></td>
      <td style="font-family:var(--mono);font-size:0.78rem"><?= htmlspecialchars($r['ins_num']) ?: '-' ?></td>
      <td><?= htmlspecialchars($r['company']) ?></td>
      <td style="font-family:var(--mono)"><?= number_format($r['labor_salary']) ?> 元</td>
      <td style="font-family:var(--mono)"><?= $r['start_date'] ?></td>
      <td style="font-family:var(--mono)"><?= $r['end_date'] ?: '—' ?></td>
      <td style="font-family:var(--mono);color:var(--blue)"><?= isset($months_map[$r['id']]) ? $months_map[$r['id']] : '?' ?> 月</td>
      <td>
        <?php if (!$r['end_date']): ?>
        <span class="badge badge-on">在保中</span>
        <?php else: ?>
        <span class="badge badge-off">已退保</span>
        <?php endif; ?>
      </td>
      <td style="color:var(--dim);font-size:0.78rem"><?= htmlspecialchars($r['remark']) ?></td>
      <td style="white-space:nowrap">
        <a href="?edit=<?= $r['id'] ?>" class="btn btn-sm btn-gray">✏️ 編輯</a>
        &nbsp;
        <form class="del-form" method="POST"
              onsubmit="return confirm('確定刪除「<?= htmlspecialchars(addslashes($r['company'])) ?>」\n(<?= $r['start_date'] ?> 起)？')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button type="submit" class="btn btn-sm btn-red">🗑️</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- ── 最高60月分析 ── -->
<?php if (!empty($calc['top60_groups'])): ?>
<div class="table-card">
  <h2>🏆 最高60月投保薪資分析（月平均 = <?= number_format($calc['avg60']) ?> 元）</h2>
  <p style="font-size:0.78rem;color:var(--dim);margin-bottom:4px">
    從 <?= number_format($calc['total_months']) ?> 個月份中，依薪資高→低取前60月加權平均
  </p>
  <p style="font-size:0.75rem;color:rgba(212,175,55,.6);margin-bottom:16px">
    ※ 顯示的是薪資群組取用月數，相同薪資期間合併顯示
  </p>
  <div class="top60-wrap">
  <?php
  $used_so_far = 0;
  foreach ($calc['top60_groups'] as $g):
      $used_so_far += $g['take'];
      $pct = round($g['take'] / 60 * 100);
  ?>
  <div class="top60-card active">
    <div class="t60-sal"><?= number_format($g['salary']) ?> 元</div>
    <div class="t60-info">
      取 <strong style="color:var(--text)"><?= $g['take'] ?></strong> 月
      （共 <?= $g['months'] ?> 月可用）<br>
      <?= htmlspecialchars(mb_substr($g['company'], 0, 16)) ?><br>
      <span style="color:var(--gold)"><?= $g['start_date'] ?> 起</span>
    </div>
    <div class="t60-bar"><div class="t60-bar-fill" style="width:<?= $pct ?>%"></div></div>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── 說明 ── -->
<div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:20px;font-size:0.82rem;color:var(--dim);line-height:1.9">
  <strong style="color:var(--text)">📌 操作說明</strong><br>
  每筆紀錄代表<strong style="color:var(--gold)">薪資變更時間點</strong>，不需逐月輸入。<br>
  系統自動計算每筆紀錄涵蓋的月數，取最高薪資群組優先累計至60個月。<br><br>
  <strong style="color:var(--text)">範例：</strong>
  輸入「2022-01 薪資43,000」與「2022-05 薪資45,800」<br>
  → 系統推算：2022-01~04共4個月為43,000，2022-05起為45,800
</div>

</main>
</body>
</html>
