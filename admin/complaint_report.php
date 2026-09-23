<?php
/**
 * admin/complaint_report.php
 * ---------------------------------------------------------------------------
 * Admin — Complaint Report (Filtered Grievance Report)
 * Rajagiri College Grievance Redressal Portal
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// AUTH GUARD
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';
$allowedRoles = ['ADMIN', 'MANAGEMENT', 'TEACHER'];

if (empty($_SESSION['user_id']) || !in_array($sessionRole, $allowedRoles, true)) {
    header('Location: ../login.php?role=admin');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------------
// DATABASE
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
        $stmt = $conn->prepare(
            "SELECT u.username, ap.name, ap.email, ap.profile_picture
             FROM users u
             LEFT JOIN admin_profiles ap ON ap.user_id = u.id
             WHERE u.id = ? LIMIT 1"
        );
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
        error_log('[Complaint Report Admin Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($adminData['name']) ? $adminData['name'] : $adminData['username'];
$displayEmail = !empty($adminData['email']) ? $adminData['email'] : 'admin@rajagiri.edu';

$hasProfilePicture = false;
$profilePictureUrl = '';
if (!empty($adminData['profile_picture'])) {
    $rel = ltrim((string) $adminData['profile_picture'], '/');
    if (file_exists(__DIR__ . '/../' . $rel)) {
        $hasProfilePicture = true;
        $profilePictureUrl = '../' . $rel;
    }
}

// ---------------------------------------------------------------------------
// DROPDOWN OPTIONS
// ---------------------------------------------------------------------------
$grievanceTypes = [];
$cellMembers    = [];
$courses        = [];
$classes        = [];
$departments    = [];
$designations   = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, type_name FROM grievance_types WHERE status = 'Active' ORDER BY type_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $grievanceTypes[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Grievance Types] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, name FROM cell_members ORDER BY name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $cellMembers[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Cell Members] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, course_name FROM courses WHERE status = 'Active' ORDER BY course_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $courses[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Courses] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, class_name FROM classes WHERE status = 'Active' ORDER BY class_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $classes[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Classes] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, department_name FROM departments WHERE status = 'Active' ORDER BY department_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $departments[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Departments] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, designation_name FROM designations WHERE status = 'Active' ORDER BY designation_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $designations[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Designations] ' . $ex->getMessage()); }
}

// ---------------------------------------------------------------------------
// FILTERS
// ---------------------------------------------------------------------------
$today       = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-30 days'));

$filterFrom        = trim((string) ($_GET['from']                ?? $defaultFrom));
$filterTo          = trim((string) ($_GET['to']                  ?? $today));
$filterType        = (int) ($_GET['grievance_type_id']           ?? 0);
$filterMember      = (int) ($_GET['grievance_member_id']         ?? 0);
$filterCourse      = (int) ($_GET['course_id']                   ?? 0);
$filterClass       = (int) ($_GET['class_id']                    ?? 0);
$filterDepartment  = (int) ($_GET['department_id']               ?? 0);
$filterDesignation = (int) ($_GET['designation_id']              ?? 0);
$filterStatus      = trim((string) ($_GET['status']              ?? ''));
$filterSearch      = trim((string) ($_GET['q']                   ?? ''));

$validStatuses = ['', 'Pending', 'In Progress', 'Disposed', 'Closed', 'Reopened'];
if (!in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = '';
}

if (!isValidDate($filterFrom)) $filterFrom = $defaultFrom;
if (!isValidDate($filterTo))   $filterTo   = $today;

if (strtotime($filterFrom) > strtotime($filterTo)) {
    [$filterFrom, $filterTo] = [$filterTo, $filterFrom];
}

$isSubmitted = isset($_GET['submit']) && (string) $_GET['submit'] === '1';

// ---------------------------------------------------------------------------
// REPORT QUERY
// ---------------------------------------------------------------------------
$rows = [];

if ($isSubmitted && $conn instanceof mysqli) {
    try {
        $sql = "SELECT  g.id,
                        g.grievance_number,
                        g.subject,
                        g.description,
                        g.status,
                        g.created_at,
                        g.updated_at,
                        g.reply_details,
                        g.feedback_details,
                        gt.type_name,
                        COALESCE(s.name, p.name, sf.name, cm.name, ap.name, u.username) AS complainant_name,
                        COALESCE(s.email, p.email, sf.email, cm.email, ap.email, u.username) AS complainant_email,
                        u.role AS complainant_role,
                        assign_cm.name AS attended_by_name
                FROM grievances g
                LEFT JOIN grievance_types gt   ON g.grievance_type_id    = gt.id
                LEFT JOIN users u              ON g.complainant_user_id  = u.id
                LEFT JOIN students s           ON u.id = s.user_id
                LEFT JOIN parents p            ON u.id = p.user_id
                LEFT JOIN staff sf             ON u.id = sf.user_id
                LEFT JOIN cell_members cm      ON u.id = cm.user_id
                LEFT JOIN admin_profiles ap    ON u.id = ap.user_id
                LEFT JOIN cell_members assign_cm ON g.attended_by = assign_cm.id
                WHERE DATE(g.created_at) BETWEEN ? AND ?";

        $params = [$filterFrom, $filterTo];
        $types  = 'ss';

        if ($filterType > 0) {
            $sql .= " AND g.grievance_type_id = ?";
            $params[] = $filterType;
            $types   .= 'i';
        }

        if ($filterMember > 0) {
            $sql .= " AND (g.attended_by = ? OR g.assigned_member_id = ?)";
            $params[] = $filterMember;
            $params[] = $filterMember;
            $types   .= 'ii';
        }

        if ($filterCourse > 0) {
            $sql .= " AND (s.class_id IS NULL OR s.class_id IN (SELECT id FROM classes WHERE course_id = ?))";
            $params[] = $filterCourse;
            $types   .= 'i';
        }

        if ($filterClass > 0) {
            $sql .= " AND (s.class_id IS NULL OR s.class_id = ?)";
            $params[] = $filterClass;
            $types   .= 'i';
        }

        if ($filterDepartment > 0) {
            $sql .= " AND assign_cm.department_id = ?";
            $params[] = $filterDepartment;
            $types   .= 'i';
        }

        if ($filterDesignation > 0) {
            $sql .= " AND assign_cm.designation_id = ?";
            $params[] = $filterDesignation;
            $types   .= 'i';
        }

        if ($filterStatus !== '') {
            $sql .= " AND g.status = ?";
            $params[] = $filterStatus;
            $types   .= 's';
        }

        if ($filterSearch !== '') {
            $sql .= " AND (
                        g.grievance_number LIKE ?
                        OR g.subject LIKE ?
                        OR g.description LIKE ?
                        OR gt.type_name LIKE ?
                        OR COALESCE(s.name, p.name, sf.name, cm.name, ap.name, u.username) LIKE ?
                        OR assign_cm.name LIKE ?
                      )";
            $like = '%' . $filterSearch . '%';
            for ($i = 0; $i < 6; $i++) { $params[] = $like; }
            $types .= 'ssssss';
        }

        $sql .= " ORDER BY g.id DESC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Complaint Report] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// LOOKUP HELPERS
// ---------------------------------------------------------------------------
function findName(array $list, int $id, string $key): string
{
    foreach ($list as $item) {
        if ((int) $item['id'] === $id) return (string) $item[$key];
    }
    return 'All';
}

$summaryType        = $filterType > 0 ? findName($grievanceTypes, $filterType, 'type_name') : 'All';
$summaryMember      = $filterMember > 0 ? findName($cellMembers, $filterMember, 'name') : 'All';
$summaryCourse      = $filterCourse > 0 ? findName($courses, $filterCourse, 'course_name') : 'All';
$summaryClass       = $filterClass > 0 ? findName($classes, $filterClass, 'class_name') : 'All';
$summaryDepartment  = $filterDepartment > 0 ? findName($departments, $filterDepartment, 'department_name') : 'All';
$summaryDesignation = $filterDesignation > 0 ? findName($designations, $filterDesignation, 'designation_name') : 'All';
$summaryStatus      = $filterStatus !== '' ? $filterStatus : 'All';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Complaint Report — Admin | Rajagiri College Grievance Portal</title>
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
       PRINT STYLES — clean, isolated print block
       ============================================================ -->
  <style>
    /* Hide print block on screen */
    #print-area { display: none; }

    @media print {
      /* Fully remove on-screen UI (display:none, not visibility) */
      body > .screen-only,
      body > .screen-only * { display: none !important; }

      .no-print { display: none !important; }

      /* Clean page reset */
      html, body {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        color: #000 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      /* Reveal the print-only block */
      #print-area {
        display: block !important;
        position: static !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 10px;
        color: #000;
      }

      @page {
        size: A4 landscape;
        margin: 10mm;
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
    <aside class="w-20 bg-gradient-to-b from-[#4A154B] via-[#5A1B5C] to-[#006837] flex flex-col items-center py-4 shadow-2xl fixed inset-y-0 left-0 z-40">
      <button class="text-white/80 hover:text-white mb-8 p-2 rounded-lg hover:bg-white/10 transition-colors" aria-label="Toggle sidebar">
        <i data-lucide="menu" class="w-6 h-6"></i>
      </button>

      <nav class="flex flex-col items-center space-y-6 flex-1">
        <a href="dashboard.php" class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110" title="Dashboard">
          <i data-lucide="home" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Dashboard</span>
        </a>
        <a href="profile.php" class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110" title="Profile">
          <i data-lucide="user" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Profile</span>
        </a>
        <a href="grievance_report.php" class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110" title="Back to Reports Hub">
          <i data-lucide="clipboard-list" class="w-6 h-6 group-hover:scale-110 transition-transform duration-300"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Back to Reports Hub</span>
        </a>
      </nav>

      <a href="#" data-logout-trigger="1" id="sidebarLogoutBtn"
         class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-red-500/40 flex items-center justify-center text-white transition-all hover:scale-110" title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 group-hover:translate-x-0.5 transition-transform"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-red-600 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Logout</span>
      </a>
    </aside>

    <!-- MAIN -->
    <div class="flex-1 ml-20 flex flex-col min-h-screen">

      <header class="bg-white border-b border-slate-200 shadow-sm sticky top-0 z-30">
        <div class="flex items-center justify-between px-6 py-4">
          <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="flex items-center group">
              <img src="../public/rcss-logo.png" alt="RCSS Logo" class="h-10 md:h-11 w-auto transition-transform group-hover:scale-105" />
            </a>
            <div class="hidden sm:flex items-center h-10">
              <div class="w-px h-full bg-gradient-to-b from-transparent via-slate-300 to-transparent"></div>
            </div>
            <img src="../public/orel-grievance.png" alt="Oréll Grievance" class="hidden sm:block h-8 md:h-9 w-auto object-contain" />
          </div>

          <div class="relative" id="admin-dropdown-container">
            <button id="admin-dropdown-btn" type="button" aria-haspopup="true" aria-expanded="false"
                    class="flex items-center space-x-3 px-3 py-2 rounded-xl hover:bg-slate-100 transition-colors cursor-pointer">
              <?php if ($hasProfilePicture): ?>
                <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>" class="w-10 h-10 rounded-full object-cover border-2 border-[#C5A059] shadow-md ring-2 ring-purple-100" />
              <?php else: ?>
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E] flex items-center justify-center text-white shadow-md ring-2 ring-purple-100">
                  <i data-lucide="user" class="w-5 h-5"></i>
                </div>
              <?php endif; ?>
              <span class="hidden sm:block text-sm font-semibold text-slate-700"><?= e($displayName) ?></span>
              <i data-lucide="chevron-down" id="admin-chevron" class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <div id="admin-dropdown-menu" class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-2xl border border-slate-200 py-2 z-50 overflow-hidden">
              <div class="px-4 py-3 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                <div class="flex items-center space-x-3">
                  <?php if ($hasProfilePicture): ?>
                    <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>" class="w-12 h-12 rounded-full object-cover border-2 border-[#C5A059]" />
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
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2 tracking-tight">Complaint Report</h1>
              <nav class="flex items-center space-x-2 text-sm text-slate-500">
                <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard
                </a>
                <span class="text-slate-300">/</span>
                <a href="grievance_reports.php" class="hover:text-[#8B1E7E] transition-colors">Grievance Reports</a>
                <span class="text-slate-300">/</span>
                <span class="text-[#E5097F] font-semibold">Complaint Report</span>
              </nav>
            </div>

            <?php if ($isSubmitted): ?>
              <button type="button" onclick="window.print();" title="Print Report"
                      class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-[#4A154B] hover:bg-[#5A1B5C]
                             text-white shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                             transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="printer" class="w-5 h-5"></i>
              </button>
            <?php endif; ?>
          </div>
        </div>

        <!-- FILTER FORM -->
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up" style="animation-delay: 60ms;">
          <div class="bg-slate-100 border border-slate-200 rounded-xl px-6 py-6 shadow-sm">
            <form method="GET" action="complaint_report.php" class="space-y-5">
              <input type="hidden" name="submit" value="1" />

              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="space-y-2">
                  <label for="from" class="block text-sm font-semibold text-slate-700">From Date</label>
                  <input type="date" name="from" id="from" value="<?= e($filterFrom) ?>"
                         class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                hover:border-[#4A154B]/40 transition-all" />
                </div>
                <div class="space-y-2">
                  <label for="to" class="block text-sm font-semibold text-slate-700">To Date</label>
                  <input type="date" name="to" id="to" value="<?= e($filterTo) ?>"
                         class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                hover:border-[#4A154B]/40 transition-all" />
                </div>
                <div class="space-y-2">
                  <label for="grievance_type_id" class="block text-sm font-semibold text-slate-700">Grievance Type</label>
                  <select name="grievance_type_id" id="grievance_type_id"
                          class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 hover:border-[#4A154B]/40 transition-all">
                    <option value="0">All</option>
                    <?php foreach ($grievanceTypes as $gt): ?>
                      <option value="<?= (int) $gt['id'] ?>" <?= $filterType === (int) $gt['id'] ? 'selected' : '' ?>>
                        <?= e($gt['type_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="space-y-2">
                  <label for="grievance_member_id" class="block text-sm font-semibold text-slate-700">Grievance Member</label>
                  <select name="grievance_member_id" id="grievance_member_id"
                          class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 hover:border-[#4A154B]/40 transition-all">
                    <option value="0">All</option>
                    <?php foreach ($cellMembers as $cm): ?>
                      <option value="<?= (int) $cm['id'] ?>" <?= $filterMember === (int) $cm['id'] ? 'selected' : '' ?>>
                        <?= e($cm['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="space-y-2">
                  <label for="course_id" class="block text-sm font-semibold text-slate-700">Course</label>
                  <select name="course_id" id="course_id"
                          class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 hover:border-[#4A154B]/40 transition-all">
                    <option value="0">All</option>
                    <?php foreach ($courses as $c): ?>
                      <option value="<?= (int) $c['id'] ?>" <?= $filterCourse === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['course_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="space-y-2">
                  <label for="department_id" class="block text-sm font-semibold text-slate-700">Department</label>
                  <select name="department_id" id="department_id"
                          class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 hover:border-[#4A154B]/40 transition-all">
                    <option value="0">All</option>
                    <?php foreach ($departments as $d): ?>
                      <option value="<?= (int) $d['id'] ?>" <?= $filterDepartment === (int) $d['id'] ? 'selected' : '' ?>>
                        <?= e($d['department_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="space-y-2">
                  <label for="class_id" class="block text-sm font-semibold text-slate-700">Class</label>
                  <select name="class_id" id="class_id"
                          class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 hover:border-[#4A154B]/40 transition-all">
                    <option value="0">All</option>
                    <?php foreach ($classes as $cl): ?>
                      <option value="<?= (int) $cl['id'] ?>" <?= $filterClass === (int) $cl['id'] ? 'selected' : '' ?>>
                        <?= e($cl['class_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="space-y-2">
                  <label for="designation_id" class="block text-sm font-semibold text-slate-700">Designation</label>
                  <select name="designation_id" id="designation_id"
                          class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 hover:border-[#4A154B]/40 transition-all">
                    <option value="0">All</option>
                    <?php foreach ($designations as $dg): ?>
                      <option value="<?= (int) $dg['id'] ?>" <?= $filterDesignation === (int) $dg['id'] ? 'selected' : '' ?>>
                        <?= e($dg['designation_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="space-y-2">
                  <label for="status" class="block text-sm font-semibold text-slate-700">Status</label>
                  <select name="status" id="status"
                          class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 hover:border-[#4A154B]/40 transition-all">
                    <option value="">All</option>
                    <option value="Pending"     <?= $filterStatus === 'Pending'     ? 'selected' : '' ?>>Pending</option>
                    <option value="In Progress" <?= $filterStatus === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="Disposed"    <?= $filterStatus === 'Disposed'    ? 'selected' : '' ?>>Disposed</option>
                    <option value="Closed"      <?= $filterStatus === 'Closed'      ? 'selected' : '' ?>>Closed</option>
                    <option value="Reopened"    <?= $filterStatus === 'Reopened'    ? 'selected' : '' ?>>Reopened</option>
                  </select>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="space-y-2 md:col-span-3">
                  <label for="q" class="block text-sm font-semibold text-slate-700">Search</label>
                  <div class="relative">
                    <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                    <input type="text" name="q" id="q" value="<?= e($filterSearch) ?>"
                           placeholder="Grievance number, subject, description, name…"
                           autocomplete="off"
                           class="w-full pl-10 pr-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                  focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                  hover:border-[#4A154B]/40 transition-all" />
                  </div>
                </div>
              </div>

              <div class="flex justify-center pt-3 gap-3">
                <a href="complaint_report.php"
                   class="px-6 py-3 rounded-xl border-2 border-slate-200 text-slate-700 font-bold
                          bg-white hover:bg-slate-50 transition-all duration-200 active:scale-95
                          inline-flex items-center justify-center">
                  Reset
                </a>
                <button type="submit"
                        class="px-10 py-3 rounded-xl
                               bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]
                               hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A]
                               text-white font-bold shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                               transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
                  Submit
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- ON-SCREEN REPORT -->
        <?php if ($isSubmitted): ?>
          <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 120ms;">
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

              <h2 class="text-xl md:text-2xl font-bold text-slate-800 mt-5 tracking-tight">Complaint Report</h2>

              <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                <span class="font-semibold text-slate-700">Period:</span>
                <span class="px-2 py-0.5 bg-slate-100 rounded-md border border-slate-200">
                  <?= e(date('d/m/Y', strtotime($filterFrom))) ?> – <?= e(date('d/m/Y', strtotime($filterTo))) ?>
                </span>
                <span class="font-semibold text-slate-700 ml-2">Filters:</span>
                <span class="px-2 py-0.5 bg-slate-100 rounded-md border border-slate-200">Type: <?= e($summaryType) ?></span>
                <span class="px-2 py-0.5 bg-slate-100 rounded-md border border-slate-200">Member: <?= e($summaryMember) ?></span>
                <?php if ($filterCourse > 0): ?>
                  <span class="px-2 py-0.5 bg-slate-100 rounded-md border border-slate-200">Course: <?= e($summaryCourse) ?></span>
                <?php endif; ?>
                <?php if ($filterClass > 0): ?>
                  <span class="px-2 py-0.5 bg-slate-100 rounded-md border border-slate-200">Class: <?= e($summaryClass) ?></span>
                <?php endif; ?>
                <?php if ($filterDepartment > 0): ?>
                  <span class="px-2 py-0.5 bg-slate-100 rounded-md border border-slate-200">Department: <?= e($summaryDepartment) ?></span>
                <?php endif; ?>
                <?php if ($filterDesignation > 0): ?>
                  <span class="px-2 py-0.5 bg-slate-100 rounded-md border border-slate-200">Designation: <?= e($summaryDesignation) ?></span>
                <?php endif; ?>
                <span class="px-2 py-0.5 bg-slate-100 rounded-md border border-slate-200">Status: <?= e($summaryStatus) ?></span>
                <?php if ($filterSearch !== ''): ?>
                  <span class="px-2 py-0.5 bg-slate-100 rounded-md border border-slate-200">Search: <?= e($filterSearch) ?></span>
                <?php endif; ?>
              </div>
            </div>

            <div class="bg-white border-x border-b border-slate-200 rounded-b-xl overflow-hidden">
              <div class="overflow-x-auto">
                <table class="w-full">
                  <thead>
                    <tr class="bg-[#4A154B] text-white">
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Sl.No.</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Name</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Date Of Posting</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Grievance Type</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Subject</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Description</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Status</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Attended by</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Reply Details</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Feedback Details</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 bg-white">
                    <?php if (empty($rows)): ?>
                      <tr>
                        <td colspan="10" class="px-4 py-12 text-center text-slate-500">No grievances match the selected filters.</td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($rows as $i => $r): ?>
                        <?php
                          $rDate = !empty($r['created_at']) ? date('Y-m-d', strtotime((string) $r['created_at'])) : '—';
                          $fullDesc     = (string) ($r['description'] ?? '');
                          $descPreview  = mb_strlen($fullDesc, 'UTF-8') > 120 ? mb_substr($fullDesc, 0, 120, 'UTF-8') . '…' : $fullDesc;
                          $replyFull    = (string) ($r['reply_details'] ?? '');
                          $replyPreview = mb_strlen($replyFull, 'UTF-8') > 120 ? mb_substr($replyFull, 0, 120, 'UTF-8') . '…' : $replyFull;
                          $fbFull       = (string) ($r['feedback_details'] ?? '');
                          $fbPreview    = mb_strlen($fbFull, 'UTF-8') > 120 ? mb_substr($fbFull, 0, 120, 'UTF-8') . '…' : $fbFull;
                          $statusCls = match (strtolower((string) ($r['status'] ?? ''))) {
                              'pending'     => 'bg-amber-100 text-amber-800 border-amber-200',
                              'in progress' => 'bg-sky-100 text-sky-800 border-sky-200',
                              'disposed'    => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                              'closed'      => 'bg-slate-100 text-slate-700 border-slate-200',
                              'reopened'    => 'bg-pink-100 text-pink-800 border-pink-200',
                              default       => 'bg-slate-100 text-slate-700 border-slate-200',
                          };
                        ?>
                        <tr class="hover:bg-slate-50/80 transition-colors align-top">
                          <td class="px-3 py-3 text-xs text-slate-800"><?= $i + 1 ?></td>
                          <td class="px-3 py-3 text-xs font-medium text-slate-800 break-words"><?= e($r['complainant_name'] ?? 'N/A') ?></td>
                          <td class="px-3 py-3 text-xs text-slate-600 whitespace-nowrap"><?= e($rDate) ?></td>
                          <td class="px-3 py-3 text-xs text-slate-700 break-words"><?= e($r['type_name'] ?? '—') ?></td>
                          <td class="px-3 py-3 text-xs text-slate-700 break-words"><?= e($r['subject'] ?? '—') ?></td>
                          <td class="px-3 py-3 text-xs text-slate-600 break-words" title="<?= e($fullDesc) ?>"><?= e($descPreview !== '' ? $descPreview : '—') ?></td>
                          <td class="px-3 py-3 text-xs whitespace-nowrap">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border <?= $statusCls ?>">
                              <?= e($r['status'] ?? '—') ?>
                            </span>
                          </td>
                          <td class="px-3 py-3 text-xs text-slate-700 break-words"><?= e(($r['attended_by_name'] ?? '') !== '' ? $r['attended_by_name'] : '—') ?></td>
                          <td class="px-3 py-3 text-xs text-slate-600 break-words" title="<?= e($replyFull) ?>"><?= e($replyPreview !== '' ? $replyPreview : '—') ?></td>
                          <td class="px-3 py-3 text-xs text-slate-600 break-words" title="<?= e($fbFull) ?>"><?= e($fbPreview !== '' ? $fbPreview : '—') ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <?php if (!empty($rows)): ?>
                <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 text-xs text-slate-600">
                  <p>Total records: <span class="font-semibold text-slate-800"><?= count($rows) ?></span> · Generated on <?= date('d-m-Y H:i') ?></p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php else: ?>
          <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 120ms;">
            <div class="bg-white border border-slate-200 rounded-2xl px-6 py-12 text-center shadow-sm">
              <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-purple-50 mb-4">
                <i data-lucide="file-search" class="w-8 h-8 text-[#8B1E7E]"></i>
              </div>
              <h3 class="text-lg font-semibold text-slate-700">Apply filters to generate the report</h3>
              <p class="text-sm text-slate-500 mt-1 max-w-md mx-auto">
                Choose your date range and any additional filters above, then click <strong>Submit</strong> to view the complaint report.
              </p>
            </div>
          </div>
        <?php endif; ?>

      </main>

      <footer class="bg-gradient-to-r from-purple-200 via-pink-100 to-purple-200 border-t border-purple-200/60 mt-auto">
        <div class="px-6 py-6">
          <div class="max-w-7xl mx-auto text-center">
            <p class="text-xs text-slate-700">
              Copyright &copy; <?= date('Y') ?>
              <span class="font-bold text-[#006837]">Rajagiri College of Social Sciences</span>. All rights reserved.
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
       PRINT-ONLY AREA — inline styles only, no Tailwind
       ============================================================ -->
  <?php if ($isSubmitted): ?>
  <div id="print-area">
    <!-- Header -->
    <table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
      <tr>
        <td style="vertical-align:middle;width:60%;">
          <table style="border-collapse:collapse;">
            <tr>
              <td style="vertical-align:middle;padding-right:10px;">
                <img src="../public/rcss-logo.png" alt="RCSS" style="height:44px;width:auto;" />
              </td>
              <td style="vertical-align:middle;">
                <div style="font-size:13px;font-weight:bold;color:#006837;text-transform:uppercase;letter-spacing:0.5px;">
                  Rajagiri College of Social Sciences
                </div>
                <div style="font-size:10px;color:#555;padding-top:2px;">
                  Grievance Redressal Portal
                </div>
              </td>
            </tr>
          </table>
        </td>
        <td style="vertical-align:middle;text-align:right;width:40%;font-size:11px;font-weight:bold;color:#333;">
          Date: <?= date('d-m-Y') ?>
        </td>
      </tr>
    </table>

    <!-- Title -->
    <div style="font-size:18px;font-weight:bold;color:#111;margin:0 0 8px 0;">Complaint Report</div>

    <!-- Meta -->
    <table style="width:100%;border-collapse:collapse;font-size:10.5px;color:#333;margin-bottom:10px;">
      <tr>
        <td style="padding-bottom:6px;">
          <strong style="color:#111;">Period:</strong>
          <?= e(date('d/m/Y', strtotime($filterFrom))) ?> - <?= e(date('d/m/Y', strtotime($filterTo))) ?>
        </td>
        <td style="padding-bottom:6px;text-align:right;">
          <strong style="color:#111;">Filters:</strong>
          Type: <?= e($summaryType) ?>
          - Member: <?= e($summaryMember) ?>
          <?php if ($filterCourse > 0): ?>- Course: <?= e($summaryCourse) ?><?php endif; ?>
          <?php if ($filterClass > 0): ?>- Class: <?= e($summaryClass) ?><?php endif; ?>
          <?php if ($filterDepartment > 0): ?>- Dept: <?= e($summaryDepartment) ?><?php endif; ?>
          <?php if ($filterDesignation > 0): ?>- Desig: <?= e($summaryDesignation) ?><?php endif; ?>
          - Status: <?= e($summaryStatus) ?>
          <?php if ($filterSearch !== ''): ?>- Search: <?= e($filterSearch) ?><?php endif; ?>
        </td>
      </tr>
    </table>

    <!-- Report Table -->
    <table style="width:100%;border-collapse:collapse;table-layout:fixed;font-size:9px;color:#000;">
      <thead>
        <tr>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:4%;">Sl.No.</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:10%;">Name</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:8%;">Date Of Posting</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:11%;">Grievance Type</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:10%;">Subject</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:17%;">Description</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:7%;">Status</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:10%;">Attended by</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:11%;">Reply Details</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:12%;">Feedback Details</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="10" style="border:1px solid #333;padding:10px;text-align:center;">
              No grievances match the selected filters.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $i => $r): ?>
            <?php
              $rDate = !empty($r['created_at']) ? date('Y-m-d', strtotime((string) $r['created_at'])) : '—';
              // Collapse whitespace for cleaner wrap
              $printDesc  = trim(preg_replace('/\s+/', ' ', (string) ($r['description'] ?? '')));
              $printReply = trim(preg_replace('/\s+/', ' ', (string) ($r['reply_details'] ?? '')));
              $printFb    = trim(preg_replace('/\s+/', ' ', (string) ($r['feedback_details'] ?? '')));
            ?>
            <tr>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;text-align:center;"><?= $i + 1 ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($r['complainant_name'] ?? 'N/A') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;white-space:nowrap;"><?= e($rDate) ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($r['type_name'] ?? '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($r['subject'] ?? '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($printDesc !== '' ? $printDesc : '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;text-transform:uppercase;font-weight:bold;"><?= e($r['status'] ?? '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e(($r['attended_by_name'] ?? '') !== '' ? $r['attended_by_name'] : '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($printReply !== '' ? $printReply : '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($printFb !== '' ? $printFb : '—') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- Footer -->
    <div style="margin-top:10px;font-size:10px;color:#333;">
      <strong>Total records:</strong> <?= count($rows) ?>
      &nbsp;&nbsp;|&nbsp;&nbsp;
      <strong>Generated on:</strong> <?= date('d-m-Y H:i') ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ============================================================= -->
  <!-- LOGOUT MODAL -->
  <!-- ============================================================= -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4 no-print">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeLogoutModal()"></div>

    <div id="logoutConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-gradient-to-br from-red-100 to-pink-100 ring-4 ring-red-50">
          <i data-lucide="log-out" class="w-8 h-8 text-red-500"></i>
        </div>
        <h3 class="text-xl font-bold text-slate-800 mb-2">Log Out?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to log out of <span class="font-bold text-[#8B1E7E] break-words"><?= e($displayName) ?></span>.
          Any unsaved changes will be lost.
        </p>
        <p class="text-xs text-slate-400 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="info" class="w-3.5 h-3.5"></i> You can log back in anytime.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeLogoutModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">
          Cancel
        </button>
        <button type="button" id="confirmLogoutBtn"
                class="flex-1 px-5 py-3 rounded-xl font-bold text-white bg-gradient-to-r from-red-500 via-red-600 to-rose-600 hover:from-red-600 hover:via-red-700 hover:to-rose-700 shadow-lg shadow-red-500/30 hover:shadow-red-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95 flex items-center justify-center gap-2">
          <i data-lucide="log-out" class="w-4 h-4"></i>
          <span>Log Out</span>
        </button>
      </div>
    </div>
  </div>

  <script>
    if (typeof lucide !== 'undefined') { lucide.createIcons(); }

    (function () {
      const btn = document.getElementById('admin-dropdown-btn');
      const menu = document.getElementById('admin-dropdown-menu');
      const chevron = document.getElementById('admin-chevron');
      const container = document.getElementById('admin-dropdown-container');
      if (!btn || !menu || !container) return;

      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        const isOpen = !menu.classList.contains('hidden');
        if (isOpen) {
          menu.classList.add('hidden'); menu.classList.remove('animate-dropdown');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded','false');
        } else {
          menu.classList.remove('hidden'); menu.classList.add('animate-dropdown');
          if (chevron) chevron.classList.add('rotate-180');
          btn.setAttribute('aria-expanded','true');
        }
      });

      document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) {
          menu.classList.add('hidden'); menu.classList.remove('animate-dropdown');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded','false');
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          menu.classList.add('hidden'); menu.classList.remove('animate-dropdown');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded','false');
        }
      });
    })();

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