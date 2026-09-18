<?php
/**
 * admin/grievance_details.php
 * ---------------------------------------------------------------------------
 * Admin — Grievance Details (list all filed grievances)
 * Rajagiri College Grievance Redressal Portal
 *
 * Features:
 *   • Lists all grievances with joins across users / grievance_types / role tables
 *   • Live search + entries-per-page dropdown
 *   • Server-side pagination (Previous / Next)
 *   • Dynamic status badge colours
 *   • Eye icon  → opens full "View Grievance" modal (in-page)
 *   • List icon → opens "Action Summary" modal (Date + Attendee)
 *   • Custom themed logout confirmation modal
 *   • Flash messages auto-dismiss after 3 seconds
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. SESSION START
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// 2. AUTH GUARD
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || ($sessionRole !== 'ADMIN' && $sessionRole !== 'MANAGEMENT' && $sessionRole !== 'TEACHER')) {
    header('Location: ../login.php?role=admin');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------------
// 3. DATABASE CONNECTION
// ---------------------------------------------------------------------------
$dbFile = __DIR__ . '/../db_connect.php';

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
// 4. HELPERS
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// 5. FETCH ADMIN PROFILE
// ---------------------------------------------------------------------------
$adminData = [
    'username'        => $_SESSION['username'] ?? 'Admin',
    'name'            => '',
    'email'           => '',
    'profile_picture' => '',
];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.username,
                        ap.name,
                        ap.email,
                        ap.profile_picture
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
        error_log('[Grievance Details Admin Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($adminData['name']) ? $adminData['name'] : $adminData['username'];
$displayEmail = !empty($adminData['email']) ? $adminData['email'] : 'admin@rajagiri.edu';

$hasProfilePicture = false;
$profilePictureUrl = '';
if (!empty($adminData['profile_picture'])) {
    $relativeFromAdmin = '../' . ltrim((string) $adminData['profile_picture'], '/');
    if (file_exists(__DIR__ . '/../' . ltrim((string) $adminData['profile_picture'], '/'))) {
        $hasProfilePicture = true;
        $profilePictureUrl = $relativeFromAdmin;
    }
}

// ---------------------------------------------------------------------------
// 6. FLASH MESSAGES
// ---------------------------------------------------------------------------
$flashSuccess = '';
$flashError   = '';

if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ---------------------------------------------------------------------------
// 7. QUERY PARAMS — search, pagination, per-page
// ---------------------------------------------------------------------------
$search  = trim((string) ($_GET['q']       ?? ''));
$entries = (int) ($_GET['entries']          ?? 10);
$page    = (int) ($_GET['page']             ?? 1);

if (!in_array($entries, [10, 25, 50, 100], true)) {
    $entries = 10;
}
if ($page < 1) $page = 1;

$offset = ($page - 1) * $entries;

// ---------------------------------------------------------------------------
// 8. FETCH GRIEVANCES (with joins & complainant name resolution)
//    Includes full detail columns needed by the View modal.
// ---------------------------------------------------------------------------
$grievances = [];
$totalRows  = 0;
$totalPages = 1;

if ($conn instanceof mysqli) {
    try {
        // ---- Base WHERE clause with search support ----
        $whereSql = " WHERE 1=1";
        $params   = [];
        $types    = '';

        if ($search !== '') {
            $whereSql .= " AND (
                g.grievance_number LIKE ?
                OR g.subject LIKE ?
                OR gt.type_name LIKE ?
                OR COALESCE(s.name, p.name, cm.name, ap.name) LIKE ?
            )";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types   .= 'ssss';
        }

        // ---- Count query ----
        $countSql = "SELECT COUNT(*) AS c
                     FROM grievances g
                     LEFT JOIN grievance_types gt ON g.grievance_type_id   = gt.id
                     LEFT JOIN users u            ON g.complainant_user_id = u.id
                     LEFT JOIN students s         ON u.id = s.user_id
                     LEFT JOIN parents p          ON u.id = p.user_id
                     LEFT JOIN cell_members cm    ON u.id = cm.user_id
                     LEFT JOIN admin_profiles ap  ON u.id = ap.user_id
                     $whereSql";

        $stmt = $conn->prepare($countSql);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res       = $stmt->get_result();
            $totalRows = (int) ($res ? ($res->fetch_assoc()['c'] ?? 0) : 0);
            $stmt->close();
        }

        $totalPages = max(1, (int) ceil($totalRows / $entries));

        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $entries;
        }

        // ---- Data query (with all details for View modal) ----
        $dataSql = "SELECT  g.id,
                            g.grievance_number,
                            g.subject,
                            g.description,
                            g.status,
                            g.reply_details,
                            g.feedback_details,
                            g.created_at,
                            g.updated_at,
                            g.attended_by,
                            gt.type_name,
                            u.role AS complainant_role,
                            COALESCE(s.name, p.name, cm.name, ap.name, u.username) AS complainant_name,
                            COALESCE(s.email, p.email, cm.email, ap.email) AS complainant_email,
                            att_cm.name AS attendee_name
                    FROM grievances g
                    LEFT JOIN grievance_types gt ON g.grievance_type_id   = gt.id
                    LEFT JOIN users u            ON g.complainant_user_id = u.id
                    LEFT JOIN students s         ON u.id = s.user_id
                    LEFT JOIN parents p          ON u.id = p.user_id
                    LEFT JOIN cell_members cm    ON u.id = cm.user_id
                    LEFT JOIN admin_profiles ap  ON u.id = ap.user_id
                    LEFT JOIN cell_members att_cm ON g.attended_by = att_cm.id
                    $whereSql
                    ORDER BY g.id DESC
                    LIMIT ? OFFSET ?";

        $dataParams   = $params;
        $dataTypes    = $types . 'ii';
        $dataParams[] = $entries;
        $dataParams[] = $offset;

        $stmt = $conn->prepare($dataSql);
        if ($stmt) {
            $stmt->bind_param($dataTypes, ...$dataParams);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $grievances[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Grievances] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 9. HELPER — STATUS BADGE CLASSES
// ---------------------------------------------------------------------------
function statusBadgeClass(string $status): string
{
    return match (strtolower($status)) {
        'pending'     => 'bg-amber-100 text-amber-800 border-amber-200',
        'in progress' => 'bg-sky-100 text-sky-800 border-sky-200',
        'disposed'    => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'closed'      => 'bg-slate-100 text-slate-700 border-slate-200',
        'reopened'    => 'bg-pink-100 text-pink-800 border-pink-200',
        default       => 'bg-slate-100 text-slate-700 border-slate-200',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Grievance Details — Admin | Rajagiri College Grievance Portal</title>
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
            fadeInUp: {
              '0%':   { opacity: '0', transform: 'translateY(12px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' }
            },
            dropdownFade: {
              '0%':   { opacity: '0', transform: 'translateY(-8px) scale(0.98)' },
              '100%': { opacity: '1', transform: 'translateY(0) scale(1)' }
            },
            modalFadeIn: {
              '0%':   { opacity: '0', transform: 'scale(0.96)' },
              '100%': { opacity: '1', transform: 'scale(1)' }
            },
            confirmShake: {
              '0%, 100%': { transform: 'translateX(0)' },
              '20%':      { transform: 'translateX(-6px)' },
              '40%':      { transform: 'translateX(6px)' },
              '60%':      { transform: 'translateX(-4px)' },
              '80%':      { transform: 'translateX(4px)' }
            },
            flashIn: {
              '0%':   { opacity: '0', transform: 'translateY(-10px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' }
            },
            flashOut: {
              '0%':   { opacity: '1', transform: 'translateY(0)', maxHeight: '200px' },
              '100%': { opacity: '0', transform: 'translateY(-10px)', maxHeight: '0px' }
            }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':   'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'modal-in':   'modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'confirm-shake': 'confirmShake 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
            'flash-in':   'flashIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'flash-out':  'flashOut 0.45s cubic-bezier(0.4, 0, 1, 1) forwards'
          }
        }
      }
    };
  </script>

  <link rel="stylesheet" href="../assets/css/index.css" />
</head>

<body class="min-h-screen bg-slate-50 text-slate-800 antialiased flex flex-col">

  <div class="flex min-h-screen flex-1">

    <!-- SIDEBAR -->
    <aside class="w-20 bg-gradient-to-b from-[#4A154B] via-[#5A1B5C] to-[#006837] flex flex-col items-center py-4 shadow-2xl fixed inset-y-0 left-0 z-40">

      <button class="text-white/80 hover:text-white mb-8 p-2 rounded-lg hover:bg-white/10 transition-colors" aria-label="Toggle sidebar">
        <i data-lucide="menu" class="w-6 h-6"></i>
      </button>

      <nav class="flex flex-col items-center space-y-6 flex-1">

        <a href="dashboard.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Dashboard">
          <i data-lucide="home" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Dashboard
          </span>
        </a>

        <a href="profile.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Profile">
          <i data-lucide="user" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Profile
          </span>
        </a>

        <a href="grievances.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Back to Grievance Hub">
          <i data-lucide="file-text" class="w-6 h-6 group-hover:scale-110 transition-transform duration-300"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Back to Grievance Hub
          </span>
        </a>

      </nav>

      <a href="#"
         data-logout-trigger="1"
         id="sidebarLogoutBtn"
         class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-red-500/40 flex items-center justify-center text-white transition-all hover:scale-110"
         title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 group-hover:translate-x-0.5 transition-transform"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-red-600 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
          Logout
        </span>
      </a>

    </aside>

    <!-- MAIN CONTENT -->
    <div class="flex-1 ml-20 flex flex-col min-h-screen">

      <!-- HEADER -->
      <header class="bg-white border-b border-slate-200 shadow-sm sticky top-0 z-30">
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
            <button id="admin-dropdown-btn"
                    type="button"
                    aria-haspopup="true"
                    aria-expanded="false"
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

      <!-- PAGE CONTENT -->
      <main class="flex-1 px-6 py-8">

        <!-- Breadcrumb -->
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

            <div>
              <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2 tracking-tight">
                Grievance Details
              </h1>
              <nav class="flex items-center space-x-2 text-sm text-slate-500">
                <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i>
                  Dashboard
                </a>
                <span class="text-slate-300">/</span>
                <a href="grievances.php" class="hover:text-[#8B1E7E] transition-colors">
                  Grievance
                </a>
                <span class="text-slate-300">/</span>
                <span class="text-[#E5097F] font-semibold">Grievance Details</span>
              </nav>
            </div>

          </div>
        </div>

        <!-- Flash Messages -->
        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox"
               class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3 flex items-start space-x-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-[#006837] flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-emerald-800 font-medium"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox"
               class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3 flex items-start space-x-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-medium"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <!-- TABLE CONTROLS -->
        <div class="max-w-6xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 60ms;">
          <form method="GET" action="grievance_details.php" id="filterForm" class="bg-white rounded-xl shadow-sm border border-slate-200/70 px-5 py-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">

              <div class="flex items-center space-x-3">
                <span class="text-sm text-slate-600">Show</span>
                <select name="entries" id="entriesPerPage"
                        class="px-3 py-1.5 border-2 border-slate-200 rounded-lg text-sm font-medium text-slate-700
                               focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                               hover:border-[#4A154B]/40 transition-colors bg-white">
                  <option value="10"  <?= $entries === 10  ? 'selected' : '' ?>>10</option>
                  <option value="25"  <?= $entries === 25  ? 'selected' : '' ?>>25</option>
                  <option value="50"  <?= $entries === 50  ? 'selected' : '' ?>>50</option>
                  <option value="100" <?= $entries === 100 ? 'selected' : '' ?>>100</option>
                </select>
                <span class="text-sm text-slate-600">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text"
                       name="q"
                       id="searchInput"
                       value="<?= e($search) ?>"
                       placeholder="Search.."
                       autocomplete="off"
                       class="w-full pl-10 pr-4 py-2 border-2 border-slate-200 rounded-lg text-sm
                              focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                              hover:border-[#4A154B]/40 transition-all bg-white" />
              </div>

            </div>
          </form>
        </div>

        <!-- DATA TABLE -->
        <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 100ms;">
          <div class="bg-white rounded-2xl shadow-lg border border-slate-200/70 overflow-hidden">

            <div class="overflow-x-auto">
              <table class="w-full" id="grievancesTable">
                <thead>
                  <tr class="bg-[#4A154B] text-white">
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Grievance Number</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Grievance Type</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Date</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Subject</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                    <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Remainder/ Reopen</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="grievancesTableBody">

                  <?php if (empty($grievances)): ?>

                    <tr>
                      <td colspan="9" class="px-6 py-16 text-center text-slate-500">
                        <div class="flex flex-col items-center justify-center">
                          <div class="w-16 h-16 bg-purple-50 rounded-full flex items-center justify-center mb-4">
                            <i data-lucide="inbox" class="w-8 h-8 text-[#8B1E7E]"></i>
                          </div>
                          <p class="text-lg font-semibold text-slate-700">No grievances found</p>
                          <p class="text-sm text-slate-500 mt-1 mb-4">
                            <?= $search !== '' ? 'Try adjusting your search.' : 'No grievances have been filed yet.' ?>
                          </p>
                        </div>
                      </td>
                    </tr>

                  <?php else: ?>

                    <?php foreach ($grievances as $index => $gr): ?>
                      <?php
                        $grId         = (int) $gr['id'];
                        $grNumber     = (string) ($gr['grievance_number']  ?? '');
                        $grType       = (string) ($gr['type_name']         ?? 'N/A');
                        $grName       = (string) ($gr['complainant_name']  ?? 'N/A');
                        $grEmail      = (string) ($gr['complainant_email'] ?? '');
                        $grRole       = (string) ($gr['complainant_role']  ?? '');
                        $grDate       = (string) ($gr['created_at']        ?? '');
                        $grUpdated    = (string) ($gr['updated_at']        ?? '');
                        $grSubject    = (string) ($gr['subject']           ?? '');
                        $grDesc       = (string) ($gr['description']       ?? '');
                        $grReply      = (string) ($gr['reply_details']     ?? '');
                        $grFeedback   = (string) ($gr['feedback_details']  ?? '');
                        $grStatus     = (string) ($gr['status']            ?? 'Pending');
                        $grAttendee   = (string) ($gr['attendee_name']     ?? '');
                        $statusCls    = statusBadgeClass($grStatus);

                        // Format dates
                        $formattedDate = '—';
                        if ($grDate !== '') {
                            $ts = strtotime($grDate);
                            if ($ts !== false) {
                                $formattedDate = date('Y-m-d', $ts);
                            }
                        }

                        $formattedUpdated = '—';
                        if ($grUpdated !== '') {
                            $ts2 = strtotime($grUpdated);
                            if ($ts2 !== false) {
                                $formattedUpdated = date('d M Y, h:i A', $ts2);
                            }
                        }

                        $globalIndex = $offset + $index + 1;
                      ?>
                      <tr class="hover:bg-slate-50/80 transition-colors group">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900">
                          <?= $globalIndex ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-[#4A154B]">
                          <?= e($grNumber) ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-slate-700 max-w-[220px]">
                          <?= e($grType) ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-slate-800 font-medium max-w-[180px]">
                          <?= e($grName) ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                          <?= e($formattedDate) ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-slate-700 max-w-[220px]">
                          <?= e($grSubject !== '' ? $grSubject : '—') ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <span class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full border <?= $statusCls ?>">
                            <?= e($grStatus) ?>
                          </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="flex items-center justify-center gap-2">

                            <!-- View (Eye) — opens View Grievance modal -->
                            <button type="button"
                                    title="View grievance"
                                    onclick='openViewGrievanceModal(
                                        <?= json_encode($grNumber) ?>,
                                        <?= json_encode($grType) ?>,
                                        <?= json_encode($grName) ?>,
                                        <?= json_encode($grEmail) ?>,
                                        <?= json_encode($grRole) ?>,
                                        <?= json_encode($grSubject) ?>,
                                        <?= json_encode($grDesc) ?>,
                                        <?= json_encode($grStatus) ?>,
                                        <?= json_encode($grReply) ?>,
                                        <?= json_encode($grFeedback) ?>,
                                        <?= json_encode($formattedDate) ?>,
                                        <?= json_encode($formattedUpdated) ?>,
                                        <?= json_encode($grAttendee) ?>
                                    )'
                                    class="w-9 h-9 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                           flex items-center justify-center text-[#4A154B] hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>

                            <!-- Action Summary (Three lines / list) -->
                            <button type="button"
                                    title="Action summary"
                                    onclick='openActionSummary(
                                        <?= json_encode($grNumber) ?>,
                                        <?= json_encode($grSubject) ?>,
                                        <?= json_encode($formattedDate) ?>,
                                        <?= json_encode($grAttendee !== '' ? $grAttendee : 'No Data') ?>
                                    )'
                                    class="w-9 h-9 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                           flex items-center justify-center text-[#4A154B] hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="list" class="w-4 h-4"></i>
                            </button>

                          </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <?php
                            $needsAttention = in_array(strtolower($grStatus), ['reopened', 'pending'], true);
                          ?>
                          <?php if ($needsAttention): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-semibold rounded-full
                                         bg-pink-100 text-pink-800 border border-pink-200">
                              <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                              Needs Attention
                            </span>
                          <?php else: ?>
                            <span class="text-sm text-slate-400">—</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>

                  <?php endif; ?>

                </tbody>
              </table>
            </div>

            <!-- Footer Info & Pagination -->
            <?php if ($totalRows > 0): ?>
              <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">

                <p class="text-sm text-slate-600" id="tableInfo">
                  Showing
                  <span class="font-semibold text-slate-900"><?= $offset + 1 ?></span>
                  to
                  <span class="font-semibold text-slate-900"><?= min($offset + count($grievances), $totalRows) ?></span>
                  of
                  <span class="font-semibold text-slate-900"><?= $totalRows ?></span>
                  entries
                </p>

                <div class="flex items-center space-x-2">

                  <?php
                    $qsBase = 'grievance_details.php?entries=' . $entries;
                    if ($search !== '') {
                        $qsBase .= '&q=' . urlencode($search);
                    }
                  ?>

                  <!-- Previous -->
                  <?php if ($page > 1): ?>
                    <a href="<?= e($qsBase . '&page=' . ($page - 1)) ?>"
                       class="px-4 py-2 rounded-lg text-sm font-medium text-slate-700 bg-white hover:bg-slate-100 border border-slate-200 transition-colors">
                      Previous
                    </a>
                  <?php else: ?>
                    <button type="button"
                            class="px-4 py-2 rounded-lg text-sm font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed"
                            disabled>
                      Previous
                    </button>
                  <?php endif; ?>

                  <!-- Page numbers -->
                  <?php
                    $pageWindow = 2;
                    $startPage  = max(1, $page - $pageWindow);
                    $endPage    = min($totalPages, $page + $pageWindow);

                    if ($startPage > 1) {
                        echo '<a href="' . e($qsBase . '&page=1') . '" class="px-3 py-2 rounded-lg text-sm font-medium text-slate-700 bg-white hover:bg-slate-100 border border-slate-200 transition-colors">1</a>';
                        if ($startPage > 2) {
                            echo '<span class="px-2 text-slate-400">…</span>';
                        }
                    }

                    for ($p = $startPage; $p <= $endPage; $p++) {
                        if ($p === $page) {
                            echo '<span class="inline-flex items-center justify-center min-w-[36px] h-9 px-3 rounded-lg bg-[#4A154B] text-white text-sm font-bold shadow-md">' . $p . '</span>';
                        } else {
                            echo '<a href="' . e($qsBase . '&page=' . $p) . '" class="px-3 py-2 rounded-lg text-sm font-medium text-slate-700 bg-white hover:bg-slate-100 border border-slate-200 transition-colors">' . $p . '</a>';
                        }
                    }

                    if ($endPage < $totalPages) {
                        if ($endPage < $totalPages - 1) {
                            echo '<span class="px-2 text-slate-400">…</span>';
                        }
                        echo '<a href="' . e($qsBase . '&page=' . $totalPages) . '" class="px-3 py-2 rounded-lg text-sm font-medium text-slate-700 bg-white hover:bg-slate-100 border border-slate-200 transition-colors">' . $totalPages . '</a>';
                    }
                  ?>

                  <!-- Next -->
                  <?php if ($page < $totalPages): ?>
                    <a href="<?= e($qsBase . '&page=' . ($page + 1)) ?>"
                       class="px-4 py-2 rounded-lg text-sm font-medium text-slate-700 bg-white hover:bg-slate-100 border border-slate-200 transition-colors">
                      Next
                    </a>
                  <?php else: ?>
                    <button type="button"
                            class="px-4 py-2 rounded-lg text-sm font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed"
                            disabled>
                      Next
                    </button>
                  <?php endif; ?>

                </div>

              </div>
            <?php endif; ?>

          </div>
        </div>

      </main>

      <!-- FOOTER -->
      <footer class="bg-gradient-to-r from-purple-200 via-pink-100 to-purple-200 border-t border-purple-200/60 mt-auto">
        <div class="px-6 py-6">
          <div class="max-w-7xl mx-auto text-center">
            <p class="text-xs text-slate-700">
              Copyright &copy; <?= date('Y') ?>
              <span class="font-bold text-[#006837]">Rajagiri College of Social Sciences</span>.
              All rights reserved.
            </p>
            <p class="text-xs text-slate-700 mt-1">
              Powered by
              <span class="font-bold bg-gradient-to-r from-[#4A154B] to-[#E5097F] bg-clip-text text-transparent ml-1">
                Orell
              </span>
            </p>
          </div>
        </div>
      </footer>

    </div>
  </div>

  <!-- ============================================================= -->
  <!-- VIEW GRIEVANCE MODAL (opened by the eye icon)                 -->
  <!-- ============================================================= -->
  <div id="viewGrievanceModal" class="hidden fixed inset-0 z-[65] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeViewGrievanceModal()"></div>

    <div class="relative w-full max-w-3xl bg-white rounded-2xl shadow-2xl animate-modal-in
                overflow-hidden max-h-[92vh] flex flex-col">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="text-lg md:text-xl font-bold text-slate-800">Grievance Details</h3>
        <button type="button" onclick="closeViewGrievanceModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="p-6 overflow-y-auto flex-1 space-y-5">

        <!-- Header block: number + subject + status -->
        <div class="flex items-start space-x-4 pb-4 border-b border-slate-100">
          <div class="w-14 h-14 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E]
                      flex items-center justify-center text-white shadow-md flex-shrink-0">
            <i data-lucide="file-text" class="w-7 h-7"></i>
          </div>
          <div class="min-w-0 flex-1">
            <p id="vgNumber" class="text-sm font-bold text-[#4A154B] break-words">—</p>
            <p id="vgSubject" class="text-base font-bold text-slate-800 break-words mt-0.5">—</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <span id="vgStatus"></span>
              <span class="text-xs text-slate-500">·</span>
              <span class="text-xs text-slate-500" id="vgDate">—</span>
            </div>
          </div>
        </div>

        <!-- Meta grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Grievance Type</p>
            <p id="vgType" class="text-sm font-semibold text-slate-800 break-words">—</p>
          </div>
          <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Complainant</p>
            <p id="vgComplainant" class="text-sm font-semibold text-slate-800 break-words">—</p>
            <p id="vgComplainantMeta" class="text-xs text-slate-500 break-all mt-0.5">—</p>
          </div>
          <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Submitted On</p>
            <p id="vgSubmitted" class="text-sm font-semibold text-slate-800">—</p>
          </div>
          <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Last Updated</p>
            <p id="vgUpdated" class="text-sm font-semibold text-slate-800">—</p>
          </div>
          <div class="bg-slate-50 rounded-xl p-3 border border-slate-100 sm:col-span-2">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Attendee</p>
            <p id="vgAttendee" class="text-sm font-semibold text-slate-800 break-words">No Data</p>
          </div>
        </div>

        <!-- Description -->
        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Description</p>
          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
            <p id="vgDescription" class="text-sm text-slate-700 leading-relaxed whitespace-pre-line break-words">—</p>
          </div>
        </div>

        <!-- Reply -->
        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Reply / Response</p>
          <div class="bg-emerald-50 rounded-xl p-4 border border-emerald-100">
            <p id="vgReply" class="text-sm text-emerald-800 leading-relaxed whitespace-pre-line break-words">—</p>
          </div>
        </div>

        <!-- Feedback -->
        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Feedback</p>
          <div class="bg-amber-50 rounded-xl p-4 border border-amber-100">
            <p id="vgFeedback" class="text-sm text-amber-800 leading-relaxed whitespace-pre-line break-words">—</p>
          </div>
        </div>

      </div>

      <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end">
        <button type="button" onclick="closeViewGrievanceModal()"
                class="px-5 py-2.5 rounded-xl font-semibold text-slate-700
                       bg-white hover:bg-slate-100 border border-slate-200
                       transition-all duration-200 active:scale-95">
          Close
        </button>
      </div>

    </div>
  </div>

  <!-- ============================================================= -->
  <!-- ACTION SUMMARY MODAL (opened by the list icon)                -->
  <!-- ============================================================= -->
  <div id="actionSummaryModal" class="hidden fixed inset-0 z-[65] flex items-start justify-center p-4 pt-20">
    <div class="absolute inset-0 bg-black/30 backdrop-blur-sm" onclick="closeActionSummary()"></div>

    <div class="relative w-full max-w-2xl bg-white rounded-xl shadow-2xl animate-modal-in overflow-hidden">

      <!-- Title banner -->
      <div class="bg-pink-100 text-pink-900 px-5 py-2.5 border-b border-pink-200">
        <p id="asTitle" class="text-sm font-semibold truncate">—</p>
      </div>

      <div class="flex items-center justify-between px-6 pt-5 pb-3">
        <h3 class="text-xl font-bold text-slate-800">Action Summary</h3>
        <button type="button" onclick="closeActionSummary()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="px-6 pb-6">
        <div class="border border-slate-200 rounded-lg overflow-hidden">
          <div class="flex items-center justify-between px-4 py-4 border-b border-slate-200 bg-slate-50/40">
            <span class="text-sm font-medium text-slate-700">Date</span>
            <span id="asDate" class="text-sm font-medium text-slate-800">No Date</span>
          </div>

          <div class="flex items-center justify-between px-4 py-4 bg-white">
            <span class="text-sm font-medium text-slate-700">Attendee</span>
            <span id="asAttendee" class="text-sm font-medium text-slate-800">No Data</span>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- ============================================================= -->
  <!-- CUSTOM LOGOUT CONFIRMATION MODAL                              -->
  <!-- ============================================================= -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
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
          <i data-lucide="info" class="w-3.5 h-3.5"></i>
          You can log back in anytime.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button"
                onclick="closeLogoutModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700
                       bg-slate-100 hover:bg-slate-200 border border-slate-200
                       transition-all duration-200 active:scale-95">
          Cancel
        </button>

        <button type="button"
                id="confirmLogoutBtn"
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
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }

    // ---- Auto-dismiss flash messages after 3 seconds ----
    (function () {
      ['flashSuccessBox', 'flashErrorBox'].forEach(function (id) {
        const box = document.getElementById(id);
        if (!box) return;
        setTimeout(function () {
          box.classList.remove('animate-flash-in');
          box.classList.add('animate-flash-out');
          setTimeout(function () { if (box.parentNode) box.parentNode.removeChild(box); }, 500);
        }, 3000);
      });
    })();

    // ---- Admin profile dropdown ----
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
          menu.classList.add('hidden');
          menu.classList.remove('animate-dropdown');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        } else {
          menu.classList.remove('hidden');
          menu.classList.add('animate-dropdown');
          if (chevron) chevron.classList.add('rotate-180');
          btn.setAttribute('aria-expanded', 'true');
        }
      });

      document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) {
          menu.classList.add('hidden');
          menu.classList.remove('animate-dropdown');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          menu.classList.add('hidden');
          menu.classList.remove('animate-dropdown');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        }
      });
    })();

    // ============================================================
    // VIEW GRIEVANCE MODAL (triggered by the eye icon)
    // ============================================================
    const viewGrievanceModal = document.getElementById('viewGrievanceModal');

    function statusBadgeHtml(status) {
      const s = (status || '').trim();
      const map = {
        'Pending':     'bg-amber-100 text-amber-800 border-amber-200',
        'In Progress': 'bg-sky-100 text-sky-800 border-sky-200',
        'Disposed':    'bg-emerald-100 text-emerald-800 border-emerald-200',
        'Closed':      'bg-slate-100 text-slate-700 border-slate-200',
        'Reopened':    'bg-pink-100 text-pink-800 border-pink-200'
      };
      const cls = map[s] || 'bg-slate-100 text-slate-700 border-slate-200';
      return '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider border ' + cls + '">' + s + '</span>';
    }

    function openViewGrievanceModal(number, type, name, email, role, subject, description,
                                    status, reply, feedback, date, updated, attendee) {
      if (!viewGrievanceModal) return;

      document.getElementById('vgNumber').textContent    = number || '—';
      document.getElementById('vgSubject').textContent   = subject || '—';
      document.getElementById('vgStatus').innerHTML      = statusBadgeHtml(status);
      document.getElementById('vgDate').textContent      = date || '—';
      document.getElementById('vgType').textContent      = type || '—';

      // Complainant block
      document.getElementById('vgComplainant').textContent = name || '—';
      const metaParts = [];
      if (role)  metaParts.push(role);
      if (email) metaParts.push(email);
      document.getElementById('vgComplainantMeta').textContent = metaParts.length ? metaParts.join(' · ') : '—';

      document.getElementById('vgSubmitted').textContent = date || '—';
      document.getElementById('vgUpdated').textContent   = updated || '—';
      document.getElementById('vgAttendee').textContent  = (attendee && attendee.trim() !== '') ? attendee : 'No Data';

      document.getElementById('vgDescription').textContent =
        (description && description.trim() !== '') ? description : 'No description provided.';
      document.getElementById('vgReply').textContent =
        (reply && reply.trim() !== '') ? reply : 'No reply yet from the committee.';
      document.getElementById('vgFeedback').textContent =
        (feedback && feedback.trim() !== '') ? feedback : 'No feedback recorded.';

      viewGrievanceModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeViewGrievanceModal() {
      if (!viewGrievanceModal) return;
      viewGrievanceModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    // ============================================================
    // ACTION SUMMARY MODAL (triggered by the list icon)
    // ============================================================
    const actionSummaryModal = document.getElementById('actionSummaryModal');

    function openActionSummary(grievanceNumber, subject, date, attendee) {
      if (!actionSummaryModal) return;

      const titleEl    = document.getElementById('asTitle');
      const dateEl     = document.getElementById('asDate');
      const attendeeEl = document.getElementById('asAttendee');

      const bannerText = (subject && subject.trim() !== '')
        ? grievanceNumber + ' | ' + subject
        : grievanceNumber;
      titleEl.textContent = bannerText || '—';

      dateEl.textContent     = (date && date.trim() !== '') ? date : 'No Date';
      attendeeEl.textContent = (attendee && attendee.trim() !== '') ? attendee : 'No Data';

      actionSummaryModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeActionSummary() {
      if (!actionSummaryModal) return;
      actionSummaryModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    // ============================================================
    // LOGOUT CONFIRMATION MODAL
    // ============================================================
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

      setTimeout(function () {
        if (confirmLogoutBtn) confirmLogoutBtn.focus();
      }, 80);

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeLogoutModal() {
      logoutConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    // Bind both logout triggers
    (function () {
      const triggers = [
        document.getElementById('sidebarLogoutBtn'),
        document.getElementById('dropdownLogoutBtn'),
      ];

      triggers.forEach(function (btn) {
        if (!btn) return;
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
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

    // ---- Escape key: close whichever modal is open ----
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (viewGrievanceModal && !viewGrievanceModal.classList.contains('hidden')) closeViewGrievanceModal();
      if (actionSummaryModal && !actionSummaryModal.classList.contains('hidden')) closeActionSummary();
      if (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) closeLogoutModal();
    });

    // ---- Entries dropdown: auto-submit the filter form ----
    (function () {
      const entriesSelect = document.getElementById('entriesPerPage');
      const filterForm    = document.getElementById('filterForm');
      if (!entriesSelect || !filterForm) return;
      entriesSelect.addEventListener('change', function () {
        filterForm.submit();
      });
    })();

    // ---- Live client-side search + debounced server-side search ----
    (function () {
      const searchInput = document.getElementById('searchInput');
      const tableBody   = document.getElementById('grievancesTableBody');
      if (!searchInput || !tableBody) return;

      let debounceTimer = null;
      searchInput.addEventListener('input', function () {
        const term = this.value.toLowerCase().trim();
        tableBody.querySelectorAll('tr').forEach(function (row) {
          row.style.display = (term === '' || row.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
        });

        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
          const form = document.getElementById('filterForm');
          if (form) form.submit();
        }, 600);
      });
    })();
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>