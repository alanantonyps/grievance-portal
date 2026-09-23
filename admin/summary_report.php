<?php
/**
 * admin/summary_report.php
 * ---------------------------------------------------------------------------
 * Admin — Summary Report (Category-wise Grievance Summary)
 * Rajagiri College Grievance Redressal Portal
 *
 * Features:
 *   • Filter by date range (From / To)
 *   • Category-wise aggregation (Total / Pending / In Progress / Closed+Disposed)
 *   • "No records found !!!" alert when nothing matches
 *   • Report + footer totals shown ONLY after Generate Number is clicked
 *   • Dedicated print-only report section (inline styles, A4 portrait)
 *   • Themed logout confirmation modal
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// SESSION START
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// AUTH GUARD
// ---------------------------------------------------------------------------
$sessionRole  = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';
$allowedRoles = ['ADMIN', 'MANAGEMENT', 'TEACHER'];

if (empty($_SESSION['user_id']) || !in_array($sessionRole, $allowedRoles, true)) {
    header('Location: ../login.php?role=admin');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------------
// DATABASE
// ---------------------------------------------------------------------------
$dbFile  = __DIR__ . '/../db_connect.php';
$dbError = null;
$conn    = null;

if (!file_exists($dbFile)) {
    $dbError = 'Database configuration file (db_connect.php) not found.';
} else {
    require_once $dbFile;

    if (!isset($conn) || !($conn instanceof mysqli)) {
        $conn = @new mysqli('localhost', 'root', '', 'grievance_db');
        if ($conn->connect_error) {
            $dbError = 'Database connection failed.';
            $conn    = null;
        } else {
            $conn->set_charset('utf8mb4');
        }
    }

    if ($conn && $conn->connect_errno) {
        $dbError = 'Database connection failed.';
        $conn    = null;
    }
}

// ---------------------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isValidDate(string $d): bool
{
    if ($d === '') return false;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

// ---------------------------------------------------------------------------
// ADMIN PROFILE
// ---------------------------------------------------------------------------
$adminData = [
    'username'        => $_SESSION['username'] ?? 'Admin',
    'name'            => '',
    'email'           => '',
    'profile_picture' => '',
];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT u.username, ap.name, ap.email, ap.profile_picture
                FROM users u
                LEFT JOIN admin_profiles ap ON ap.user_id = u.id
                WHERE u.id = ?
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $adminData['username']        = $row['username']        ?? $adminData['username'];
                $adminData['name']            = $row['name']            ?? '';
                $adminData['email']           = $row['email']           ?? '';
                $adminData['profile_picture'] = $row['profile_picture'] ?? '';
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Summary Report Admin Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($adminData['name']) ? $adminData['name'] : $adminData['username'];
$displayEmail = !empty($adminData['email']) ? $adminData['email'] : 'admin@rajagiri.edu';

$hasProfilePicture = false;
$profilePictureUrl = '';
if (!empty($adminData['profile_picture'])) {
    $rel = ltrim((string) $adminData['profile_picture'], '/');
    if (file_exists(__DIR__ . '/../' . $rel) && is_file(__DIR__ . '/../' . $rel)) {
        $hasProfilePicture = true;
        $profilePictureUrl = '../' . $rel;
    }
}

// ---------------------------------------------------------------------------
// FILTERS
// ---------------------------------------------------------------------------
$today       = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-30 days'));

// Report submitted when either date param is present
$reportSubmitted = isset($_GET['from']) || isset($_GET['to']);

$filterFrom = trim((string) ($_GET['from'] ?? $defaultFrom));
$filterTo   = trim((string) ($_GET['to']   ?? $today));

if (!isValidDate($filterFrom)) $filterFrom = $defaultFrom;
if (!isValidDate($filterTo))   $filterTo   = $today;

if (strtotime($filterFrom) > strtotime($filterTo)) {
    [$filterFrom, $filterTo] = [$filterTo, $filterFrom];
}

// ---------------------------------------------------------------------------
// AGGREGATION
// ---------------------------------------------------------------------------
$summaryRows = [];
$grandTotals = [
    'total'       => 0,
    'pending'     => 0,
    'in_progress' => 0,
    'closed'      => 0,
];

if ($reportSubmitted && $conn instanceof mysqli) {
    try {
        $sql = "SELECT  gt.id,
                        gt.type_name,
                        COUNT(g.id) AS total,
                        SUM(CASE WHEN g.status = 'Pending'     THEN 1 ELSE 0 END) AS pending,
                        SUM(CASE WHEN g.status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress,
                        SUM(CASE WHEN g.status IN ('Closed', 'Disposed') THEN 1 ELSE 0 END) AS closed
                FROM grievance_types gt
                LEFT JOIN grievances g
                    ON gt.id = g.grievance_type_id
                    AND DATE(g.created_at) BETWEEN ? AND ?
                GROUP BY gt.id, gt.type_name
                HAVING total > 0
                ORDER BY gt.type_name ASC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $filterFrom, $filterTo);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $summaryRows[] = $row;
                $grandTotals['total']       += (int) $row['total'];
                $grandTotals['pending']     += (int) $row['pending'];
                $grandTotals['in_progress'] += (int) $row['in_progress'];
                $grandTotals['closed']      += (int) $row['closed'];
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Summary Report] ' . $ex->getMessage());
    }
}

$categoryCount = count($summaryRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Summary Report — Admin | Rajagiri College Grievance Portal</title>
  <link rel="icon" type="image/svg+xml" href="../public/favicon.svg" />

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>

  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brandPurple: '#4A154B',
            brandPink:   '#E5097F',
            brandGreen:  '#006837',
            brandGold:   '#C5A059'
          },
          keyframes: {
            fadeInUp: { '0%': { opacity: '0', transform: 'translateY(12px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
            dropdownFade: { '0%': { opacity: '0', transform: 'translateY(-8px) scale(0.98)' }, '100%': { opacity: '1', transform: 'translateY(0) scale(1)' } },
            modalFadeIn: { '0%': { opacity: '0', transform: 'scale(0.96)' }, '100%': { opacity: '1', transform: 'scale(1)' } },
            confirmShake: { '0%, 100%': { transform: 'translateX(0)' }, '20%': { transform: 'translateX(-6px)' }, '40%': { transform: 'translateX(6px)' }, '60%': { transform: 'translateX(-4px)' }, '80%': { transform: 'translateX(4px)' } }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':   'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'modal-in':   'modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'confirm-shake': 'confirmShake 0.5s cubic-bezier(0.16, 1, 0.3, 1)'
          }
        }
      }
    };
  </script>

  <link rel="stylesheet" href="../assets/css/index.css" />

  <!-- ============================================================
       PRINT STYLES — dedicated #print-area with inline styles only
       ============================================================ -->
  <style>
    #print-area { display: none; }

    @media print {
      body > .screen-only,
      body > .screen-only * { display: none !important; }
      .no-print { display: none !important; }

      html, body {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        color: #000 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      #print-area {
        display: block !important;
        position: static !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 11px;
        color: #000;
      }

      @page {
        size: A4 portrait;
        margin: 12mm;
      }
    }
  </style>
</head>

<body class="min-h-screen bg-slate-50 text-slate-800 antialiased flex flex-col">

  <!-- ============================================================
       SCREEN-ONLY WRAPPER
       ============================================================ -->
  <div class="screen-only flex min-h-screen flex-1">

    <!-- SIDEBAR -->
    <aside class="w-20 bg-gradient-to-b from-[#4A154B] via-[#5A1B5C] to-[#006837] flex flex-col items-center py-4 shadow-2xl fixed inset-y-0 left-0 z-40 no-print">

      <button class="text-white/80 hover:text-white mb-8 p-2 rounded-lg hover:bg-white/10 transition-colors" aria-label="Toggle sidebar">
        <i data-lucide="menu" class="w-6 h-6"></i>
      </button>

      <nav class="flex flex-col items-center space-y-6 flex-1">
        <a href="dashboard.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Dashboard">
          <i data-lucide="home" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Dashboard</span>
        </a>

        <a href="profile.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Profile">
          <i data-lucide="user" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Profile</span>
        </a>

        <a href="grievance_report.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Back to Reports Hub">
          <i data-lucide="clipboard-list" class="w-6 h-6 group-hover:scale-110 transition-transform duration-300"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Back to Reports Hub</span>
        </a>
      </nav>

      <a href="#" data-logout-trigger="1" id="sidebarLogoutBtn"
         class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-red-500/40 flex items-center justify-center text-white transition-all hover:scale-110"
         title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 group-hover:translate-x-0.5 transition-transform"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-red-600 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Logout</span>
      </a>
    </aside>

    <!-- MAIN CONTENT -->
    <div class="flex-1 ml-20 flex flex-col min-h-screen">

      <header class="bg-white border-b border-slate-200 shadow-sm sticky top-0 z-30 no-print">
        <div class="flex items-center justify-between px-6 py-4">
          <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="flex items-center group">
              <img src="../public/rcss-logo.png" alt="RCSS Logo"
                   class="h-10 md:h-11 w-auto transition-transform group-hover:scale-105" />
            </a>
            <div class="hidden sm:flex items-center h-10">
              <div class="w-px h-full bg-gradient-to-b from-transparent via-slate-300 to-transparent"></div>
            </div>
            <img src="../public/orel-grievance.png" alt="Oréll Grievance"
                 class="hidden sm:block h-8 md:h-9 w-auto object-contain" />
          </div>

          <div class="relative" id="admin-dropdown-container">
            <button id="admin-dropdown-btn" type="button" aria-haspopup="true" aria-expanded="false"
                    class="flex items-center space-x-3 px-3 py-2 rounded-xl hover:bg-slate-100 transition-colors cursor-pointer">
              <?php if ($hasProfilePicture): ?>
                <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"
                     class="w-10 h-10 rounded-full object-cover border-2 border-[#C5A059] shadow-md ring-2 ring-purple-100" />
              <?php else: ?>
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E] flex items-center justify-center text-white shadow-md ring-2 ring-purple-100">
                  <i data-lucide="user" class="w-5 h-5"></i>
                </div>
              <?php endif; ?>
              <span class="hidden sm:block text-sm font-semibold text-slate-700"><?= e($displayName) ?></span>
              <i data-lucide="chevron-down" id="admin-chevron" class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <div id="admin-dropdown-menu"
                 class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-2xl border border-slate-200 py-2 z-50 overflow-hidden">
              <div class="px-4 py-3 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                <div class="flex items-center space-x-3">
                  <?php if ($hasProfilePicture): ?>
                    <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"
                         class="w-12 h-12 rounded-full object-cover border-2 border-[#C5A059]" />
                  <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E] flex items-center justify-center text-white">
                      <i data-lucide="user" class="w-6 h-6 text-white"></i>
                    </div>
                  <?php endif; ?>
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-800 truncate"><?= e($displayName) ?></p>
                    <p class="text-xs text-slate-500 truncate"><?= e($displayEmail) ?></p>
                  </div>
                </div>
              </div>

              <a href="dashboard.php" class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="layout-dashboard" class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover/item:scale-110 transition-transform"></i>
                <span class="font-medium">Dashboard</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover/item:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>
              <a href="profile.php" class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="user" class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover/item:scale-110 transition-transform"></i>
                <span class="font-medium">My Profile</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover/item:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>
              <a href="change_password.php" class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="key" class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover/item:scale-110 transition-transform"></i>
                <span class="font-medium">Change Password</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover/item:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>
              <div class="border-t border-slate-100 mt-2 pt-2">
                <a href="#" data-logout-trigger="1" id="dropdownLogoutBtn" class="flex items-center px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-all duration-200 group/item">
                  <i data-lucide="log-out" class="w-4 h-4 mr-3 group-hover/item:scale-110 transition-transform"></i>
                  <span class="font-medium">Logout</span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </header>

      <main class="flex-1 px-6 py-8">

        <!-- Breadcrumb + Print -->
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up no-print">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2 tracking-tight">Summary Report</h1>
              <nav class="flex items-center space-x-2 text-sm text-slate-500">
                <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard
                </a>
                <span class="text-slate-300">/</span>
                <a href="grievance_report.php" class="hover:text-[#8B1E7E] transition-colors">Grievance Reports</a>
                <span class="text-slate-300">/</span>
                <span class="text-[#E5097F] font-semibold">Summary Report</span>
              </nav>
            </div>

            <?php if ($reportSubmitted && !empty($summaryRows)): ?>
              <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-purple-50 text-[#4A154B] border border-purple-200">
                  <i data-lucide="list-checks" class="w-3.5 h-3.5"></i>
                  <?= (int) $categoryCount ?> categor<?= $categoryCount === 1 ? 'y' : 'ies' ?>
                </span>
                <button type="button" onclick="window.print();" title="Print Report"
                        class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-[#4A154B] hover:bg-[#5A1B5C]
                               text-white shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                               transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
                  <i data-lucide="printer" class="w-5 h-5"></i>
                </button>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- FILTER CARD -->
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up no-print" style="animation-delay: 60ms;">
          <div class="bg-slate-100 border border-slate-200 rounded-xl px-6 py-6 shadow-sm">
            <form method="GET" action="summary_report.php" class="space-y-5">
              <div class="grid grid-cols-1 md:grid-cols-3 gap-5 items-end">
                <div class="space-y-2">
                  <label for="from" class="block text-sm font-semibold text-slate-700">From Date</label>
                  <input type="date" name="from" id="from" value="<?= e($filterFrom) ?>" required
                         class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                hover:border-[#4A154B]/40 transition-all" />
                </div>
                <div class="space-y-2">
                  <label for="to" class="block text-sm font-semibold text-slate-700">To Date</label>
                  <input type="date" name="to" id="to" value="<?= e($filterTo) ?>" required
                         class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                hover:border-[#4A154B]/40 transition-all" />
                </div>
                <div>
                  <button type="submit"
                          class="w-full px-8 py-2.5 rounded-lg
                                 bg-[#4A154B] hover:bg-[#5A1B5C]
                                 text-white font-semibold shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                                 transition-all duration-300 active:scale-95
                                 flex items-center justify-center gap-2">
                    <i data-lucide="calculator" class="w-4 h-4"></i>
                    <span>Generate Number</span>
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>

        <!-- NO RECORDS ALERT -->
        <?php if ($reportSubmitted && empty($summaryRows)): ?>
          <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up" style="animation-delay: 100ms;">
            <div class="rounded-xl bg-gradient-to-r from-[#E5097F] via-[#C41574] to-[#A80E5F]
                        text-white px-5 py-4 flex items-center gap-3 shadow-lg">
              <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0"></i>
              <p class="text-sm font-semibold tracking-wide">no records found !!!</p>
            </div>
          </div>
        <?php endif; ?>

        <!-- ON-SCREEN REPORT -->
        <?php if ($reportSubmitted && !empty($summaryRows)): ?>
          <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 120ms;">

            <!-- Report Header -->
            <div class="bg-white border border-slate-200 rounded-t-xl px-6 py-5">
              <div class="flex items-center justify-between flex-wrap gap-4">
                <div class="flex items-center gap-4">
                  <img src="../public/rcss-logo.png" alt="RCSS Logo" class="h-12 w-auto" />
                  <div>
                    <p class="text-sm font-bold text-[#006837] uppercase tracking-wider">Rajagiri College of Social Sciences</p>
                    <p class="text-xs text-slate-500">Grievance Redressal Portal</p>
                  </div>
                </div>
                <div class="text-right">
                  <p class="text-sm font-semibold text-slate-700">Date: <?= date('d-m-Y') ?></p>
                </div>
              </div>

              <h2 class="text-xl md:text-2xl font-bold text-slate-800 mt-5 tracking-tight">Summary Report</h2>

              <div class="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs text-slate-600">
                <p>
                  <span class="font-semibold text-slate-700">Period:</span>
                  <?= e(date('d/m/Y', strtotime($filterFrom))) ?> – <?= e(date('d/m/Y', strtotime($filterTo))) ?>
                </p>
                <p>
                  <span class="font-semibold text-slate-700">Total Categories:</span>
                  <?= (int) $categoryCount ?>
                </p>
              </div>
            </div>

            <!-- Table -->
            <div class="bg-white border-x border-b border-slate-200 rounded-b-xl overflow-hidden">
              <div class="overflow-x-auto">
                <table class="w-full">
                  <thead>
                    <tr class="bg-[#4A154B] text-white">
                      <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider" style="width: 80px;">Sl No</th>
                      <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Grievance Type / Category</th>
                      <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider">Total Received</th>
                      <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider">Pending</th>
                      <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider">In Progress</th>
                      <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider">Closed / Disposed</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 bg-white">
                    <?php foreach ($summaryRows as $i => $r): ?>
                      <tr class="hover:bg-slate-50/80 transition-colors">
                        <td class="px-4 py-3 text-sm text-slate-800"><?= $i + 1 ?></td>
                        <td class="px-4 py-3 text-sm text-slate-800 font-medium"><?= e($r['type_name'] ?? '—') ?></td>
                        <td class="px-4 py-3 text-sm text-slate-800 text-right font-semibold"><?= (int) $r['total'] ?></td>
                        <td class="px-4 py-3 text-sm text-slate-700 text-right"><?= (int) $r['pending'] ?></td>
                        <td class="px-4 py-3 text-sm text-slate-700 text-right"><?= (int) $r['in_progress'] ?></td>
                        <td class="px-4 py-3 text-sm text-slate-700 text-right"><?= (int) $r['closed'] ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                  <tfoot>
                    <tr class="bg-slate-100 border-t-2 border-slate-300">
                      <td class="px-4 py-3 text-sm font-bold text-slate-800" colspan="2" style="text-align: right;">
                        Grand Total
                      </td>
                      <td class="px-4 py-3 text-sm font-bold text-slate-900 text-right"><?= (int) $grandTotals['total'] ?></td>
                      <td class="px-4 py-3 text-sm font-bold text-slate-900 text-right"><?= (int) $grandTotals['pending'] ?></td>
                      <td class="px-4 py-3 text-sm font-bold text-slate-900 text-right"><?= (int) $grandTotals['in_progress'] ?></td>
                      <td class="px-4 py-3 text-sm font-bold text-slate-900 text-right"><?= (int) $grandTotals['closed'] ?></td>
                    </tr>
                  </tfoot>
                </table>
              </div>
              <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 text-xs text-slate-600">
                <p>Generated on <?= date('d-m-Y H:i') ?></p>
              </div>
            </div>

          </div>
        <?php endif; ?>

      </main>

      <footer class="bg-gradient-to-r from-purple-200 via-pink-100 to-purple-200 border-t border-purple-200/60 mt-auto no-print">
        <div class="px-6 py-6">
          <div class="max-w-7xl mx-auto text-center">
            <p class="text-xs text-slate-700">
              Copyright &copy; <?= date('Y') ?>
              <span class="font-bold text-[#006837]">Rajagiri College of Social Sciences</span>.
              All rights reserved.
            </p>
            <p class="text-xs text-slate-700 mt-1">
              Powered by
              <span class="font-bold bg-gradient-to-r from-[#4A154B] to-[#E5097F] bg-clip-text text-transparent ml-1">Orell</span>
            </p>
          </div>
        </div>
      </footer>

    </div>
  </div>
  <!-- END .screen-only -->

  <!-- ============================================================
       PRINT-ONLY AREA — inline styles, A4 portrait
       ============================================================ -->
  <?php if ($reportSubmitted && !empty($summaryRows)): ?>
  <div id="print-area">
    <!-- Header -->
    <table style="width:100%;border-collapse:collapse;margin-bottom:10px;">
      <tr>
        <td style="vertical-align:middle;width:65%;">
          <table style="border-collapse:collapse;">
            <tr>
              <td style="vertical-align:middle;padding-right:10px;">
                <img src="../public/rcss-logo.png" alt="RCSS" style="height:46px;width:auto;" />
              </td>
              <td style="vertical-align:middle;">
                <div style="font-size:14px;font-weight:bold;color:#006837;text-transform:uppercase;letter-spacing:0.6px;">
                  Rajagiri College of Social Sciences
                </div>
                <div style="font-size:10px;color:#555;padding-top:2px;">
                  Grievance Redressal Portal
                </div>
              </td>
            </tr>
          </table>
        </td>
        <td style="vertical-align:middle;text-align:right;width:35%;font-size:11px;font-weight:bold;color:#333;">
          Date: <?= date('d-m-Y') ?>
        </td>
      </tr>
    </table>

    <div style="border-top:1.5px solid #333;margin-bottom:10px;"></div>

    <div style="font-size:18px;font-weight:bold;color:#111;margin:0 0 8px 0;">Summary Report</div>

    <table style="width:100%;border-collapse:collapse;font-size:10.5px;color:#333;margin-bottom:10px;">
      <tr>
        <td style="padding-bottom:6px;">
          <strong style="color:#111;">Period:</strong>
          <?= e(date('d/m/Y', strtotime($filterFrom))) ?> - <?= e(date('d/m/Y', strtotime($filterTo))) ?>
        </td>
        <td style="padding-bottom:6px;text-align:right;">
          <strong style="color:#111;">Total Categories:</strong> <?= (int) $categoryCount ?>
        </td>
      </tr>
    </table>

    <table style="width:100%;border-collapse:collapse;table-layout:fixed;font-size:10px;color:#000;">
      <thead>
        <tr>
          <th style="border:1px solid #333;background:#eaeaea;padding:6px 6px;text-align:left;font-size:10px;font-weight:bold;width:7%;">Sl No</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:6px 6px;text-align:left;font-size:10px;font-weight:bold;width:38%;">Grievance Type / Category</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:6px 6px;text-align:right;font-size:10px;font-weight:bold;width:14%;">Total Received</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:6px 6px;text-align:right;font-size:10px;font-weight:bold;width:13%;">Pending</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:6px 6px;text-align:right;font-size:10px;font-weight:bold;width:14%;">In Progress</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:6px 6px;text-align:right;font-size:10px;font-weight:bold;width:14%;">Closed / Disposed</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($summaryRows as $i => $r): ?>
          <tr>
            <td style="border:1px solid #333;padding:5px 6px;vertical-align:top;text-align:center;"><?= $i + 1 ?></td>
            <td style="border:1px solid #333;padding:5px 6px;vertical-align:top;word-wrap:break-word;"><?= e($r['type_name'] ?? '—') ?></td>
            <td style="border:1px solid #333;padding:5px 6px;vertical-align:top;text-align:right;font-weight:bold;"><?= (int) $r['total'] ?></td>
            <td style="border:1px solid #333;padding:5px 6px;vertical-align:top;text-align:right;"><?= (int) $r['pending'] ?></td>
            <td style="border:1px solid #333;padding:5px 6px;vertical-align:top;text-align:right;"><?= (int) $r['in_progress'] ?></td>
            <td style="border:1px solid #333;padding:5px 6px;vertical-align:top;text-align:right;"><?= (int) $r['closed'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2" style="border:1px solid #333;padding:6px;text-align:right;font-weight:bold;background:#eaeaea;">Grand Total</td>
          <td style="border:1px solid #333;padding:6px;text-align:right;font-weight:bold;background:#eaeaea;"><?= (int) $grandTotals['total'] ?></td>
          <td style="border:1px solid #333;padding:6px;text-align:right;font-weight:bold;background:#eaeaea;"><?= (int) $grandTotals['pending'] ?></td>
          <td style="border:1px solid #333;padding:6px;text-align:right;font-weight:bold;background:#eaeaea;"><?= (int) $grandTotals['in_progress'] ?></td>
          <td style="border:1px solid #333;padding:6px;text-align:right;font-weight:bold;background:#eaeaea;"><?= (int) $grandTotals['closed'] ?></td>
        </tr>
      </tfoot>
    </table>

    <div style="margin-top:12px;font-size:10.5px;color:#333;">
      <strong>Generated on:</strong> <?= date('d-m-Y H:i') ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- LOGOUT MODAL -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4 no-print">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeLogoutModal()"></div>

    <div id="logoutConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4
                    bg-gradient-to-br from-red-100 to-pink-100 ring-4 ring-red-50">
          <i data-lucide="log-out" class="w-8 h-8 text-red-500"></i>
        </div>
        <h3 class="text-xl font-bold text-slate-800 mb-2">Log Out?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to log out of
          <span class="font-bold text-[#8B1E7E] break-words"><?= e($displayName) ?></span>.
          Any unsaved changes will be lost.
        </p>
        <p class="text-xs text-slate-400 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="info" class="w-3.5 h-3.5"></i> You can log back in anytime.
        </p>
      </div>
      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeLogoutModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700
                       bg-slate-100 hover:bg-slate-200 border border-slate-200
                       transition-all duration-200 active:scale-95">
          Cancel
        </button>
        <button type="button" id="confirmLogoutBtn"
                class="flex-1 px-5 py-3 rounded-xl font-bold text-white
                       bg-gradient-to-r from-red-500 via-red-600 to-rose-600
                       hover:from-red-600 hover:via-red-700 hover:to-rose-700
                       shadow-lg shadow-red-500/30 hover:shadow-red-500/50
                       transition-all duration-300 hover:-translate-y-0.5 active:scale-95
                       flex items-center justify-center gap-2">
          <i data-lucide="log-out" class="w-4 h-4"></i>
          <span>Log Out</span>
        </button>
      </div>
    </div>
  </div>

  <script>
    if (typeof lucide !== 'undefined') { lucide.createIcons(); }

    // Admin profile dropdown
    (function () {
      const btn       = document.getElementById('admin-dropdown-btn');
      const menu      = document.getElementById('admin-dropdown-menu');
      const chevron   = document.getElementById('admin-chevron');
      const container = document.getElementById('admin-dropdown-container');
      if (!btn || !menu || !container) return;

      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        const isOpen = !menu.classList.contains('hidden');
        if (isOpen) {
          menu.classList.add('hidden'); menu.classList.remove('animate-dropdown');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        } else {
          menu.classList.remove('hidden'); menu.classList.add('animate-dropdown');
          if (chevron) chevron.classList.add('rotate-180');
          btn.setAttribute('aria-expanded', 'true');
        }
      });

      document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) {
          menu.classList.add('hidden'); menu.classList.remove('animate-dropdown');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          menu.classList.add('hidden'); menu.classList.remove('animate-dropdown');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        }
      });
    })();

    // Logout modal
    const logoutConfirmModal = document.getElementById('logoutConfirmModal');
    const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
    const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
    const LOGOUT_URL = '../logout.php?role=admin';

    function openLogoutModal() {
      logoutConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (logoutConfirmPanel) {
        logoutConfirmPanel.classList.remove('animate-confirm-shake');
        void logoutConfirmPanel.offsetWidth;
        logoutConfirmPanel.classList.add('animate-confirm-shake');
      }
      setTimeout(function () { if (confirmLogoutBtn) confirmLogoutBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeLogoutModal() {
      logoutConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }
    (function () {
      const triggers = [
        document.getElementById('sidebarLogoutBtn'),
        document.getElementById('dropdownLogoutBtn'),
      ];
      triggers.forEach(function (btn) {
        if (!btn) return;
        btn.addEventListener('click', function (e) {
          e.preventDefault(); e.stopPropagation();
          openLogoutModal();
        });
      });
    })();
    if (confirmLogoutBtn) {
      confirmLogoutBtn.addEventListener('click', function () {
        confirmLogoutBtn.classList.add('opacity-50', 'pointer-events-none');
        window.location.href = LOGOUT_URL;
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) {
        closeLogoutModal();
      }
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>