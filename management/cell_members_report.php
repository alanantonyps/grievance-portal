<?php
/**
 * management/cell_members_report.php
 * ---------------------------------------------------------------------------
 * Management / Grievance Member — Cell Members Report
 * Rajagiri College Grievance Redressal Portal
 *
 * ROLE-BASED SCOPE:
 *   • MANAGEMENT          → sees ALL cell members
 *   • GRIEVANCE_MEMBER    → sees ONLY cell members whose grievance_type_id
 *                           matches their own cell_members.grievance_type_id
 *
 * Behavior:
 *   • Report table is shown ONLY after clicking Submit
 *   • Before submit, a landing hint card is shown
 *   • Printing uses a dedicated #print-area with inline styles
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
$allowedRoles = ['MANAGEMENT', 'GRIEVANCE_MEMBER'];

if (empty($_SESSION['user_id']) || !in_array($sessionRole, $allowedRoles, true)) {
    header('Location: ../login.php?role=management');
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
// MEMBER PROFILE
// ---------------------------------------------------------------------------
$memberData = [
    'username'            => $_SESSION['username'] ?? 'Member',
    'name'                => '',
    'email'               => '',
    'profile_image'       => '',
    'member_type'         => '',
    'grievance_type_id'   => 0,
    'grievance_type_name' => '',
    'has_cell_row'        => false,
];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.username,
                        cm.name               AS name,
                        cm.email              AS email,
                        cm.profile_image      AS profile_image,
                        cm.member_type        AS member_type,
                        cm.grievance_type_id  AS grievance_type_id,
                        gt.type_name          AS grievance_type_name
                FROM users u
                LEFT JOIN cell_members cm    ON cm.user_id = u.id
                LEFT JOIN grievance_types gt ON gt.id = cm.grievance_type_id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $memberData['username']            = $row['username']            ?? $memberData['username'];
                $memberData['name']                = $row['name']                ?? '';
                $memberData['email']               = $row['email']               ?? '';
                $memberData['profile_image']       = $row['profile_image']       ?? '';
                $memberData['member_type']         = $row['member_type']         ?? '';
                $memberData['grievance_type_id']   = (int) ($row['grievance_type_id'] ?? 0);
                $memberData['grievance_type_name'] = $row['grievance_type_name'] ?? '';
                $memberData['has_cell_row']        = ($row['member_type'] !== null);
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Cell Members Report Member Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($memberData['name']) ? $memberData['name'] : $memberData['username'];
$displayEmail = !empty($memberData['email']) ? $memberData['email'] : 'management@rajagiri.edu';

$memberTypeUpper         = strtoupper((string) $memberData['member_type']);
$memberGrievanceTypeId   = (int) $memberData['grievance_type_id'];
$memberGrievanceTypeName = (string) $memberData['grievance_type_name'];

$isManagement      = ($memberTypeUpper === 'MANAGEMENT');
$hasTypeAssignment = (!$isManagement && $memberGrievanceTypeId > 0);

$hasProfilePicture = false;
$profilePictureUrl = '';
if (!empty($memberData['profile_image'])) {
    $relative     = ltrim((string) $memberData['profile_image'], '/');
    $absolutePath = __DIR__ . '/../' . $relative;
    $browserPath  = '../' . $relative;

    if (file_exists($absolutePath) && is_file($absolutePath)) {
        $hasProfilePicture = true;
        $profilePictureUrl = $browserPath;
    }
}

// ---------------------------------------------------------------------------
// DROPDOWN OPTIONS
// ---------------------------------------------------------------------------
$classOptions         = [];
$departmentOptions    = [];
$designationOptions   = [];
$grievanceTypeOptions = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, class_name FROM classes WHERE status = 'Active' ORDER BY class_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $classOptions[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Classes] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, department_name FROM departments WHERE status = 'Active' ORDER BY department_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $departmentOptions[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Departments] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, designation_name FROM designations WHERE status = 'Active' ORDER BY designation_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $designationOptions[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Designations] ' . $ex->getMessage()); }

    try {
        if ($isManagement) {
            $res = $conn->query("SELECT id, type_name FROM grievance_types WHERE status = 'Active' ORDER BY type_name ASC");
        } elseif ($hasTypeAssignment) {
            $stmt = $conn->prepare("SELECT id, type_name FROM grievance_types WHERE status = 'Active' AND id = ? ORDER BY type_name ASC");
            if ($stmt) {
                $stmt->bind_param('i', $memberGrievanceTypeId);
                $stmt->execute();
                $res = $stmt->get_result();
            } else {
                $res = false;
            }
        } else {
            $res = false;
        }
        if ($res) {
            while ($row = $res->fetch_assoc()) $grievanceTypeOptions[] = $row;
            $res->free();
        }
        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            $stmt->close();
            $stmt = null;
        }
    } catch (Throwable $ex) { error_log('[Fetch Grievance Types] ' . $ex->getMessage()); }
}

// ---------------------------------------------------------------------------
// FILTERS
// ---------------------------------------------------------------------------
$today       = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-30 days'));

$filterFrom        = trim((string) ($_GET['from']            ?? $defaultFrom));
$filterTo          = trim((string) ($_GET['to']              ?? $today));
$filterClass       = (int) ($_GET['class_id']                ?? 0);
$filterDepartment  = (int) ($_GET['department_id']           ?? 0);
$filterDesignation = (int) ($_GET['designation_id']          ?? 0);
$filterType        = (int) ($_GET['grievance_type_id']       ?? 0);
$filterStatus      = trim((string) ($_GET['status']          ?? ''));

$validStatuses = ['', 'Approved', 'Pending', 'Rejected', 'Terminated'];
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
// REPORT QUERY (only when submitted)
// ---------------------------------------------------------------------------
$rows = [];

if ($isSubmitted && $conn instanceof mysqli) {
    try {
        $sql = "SELECT  cm.id,
                        cm.name,
                        cm.email,
                        cm.mobile_number,
                        cm.member_type,
                        cm.designation_id,
                        cm.department_id,
                        cm.grievance_type_id,
                        d.designation_name,
                        dept.department_name,
                        gt.type_name,
                        u.status AS user_status,
                        u.created_at AS user_created_at
                FROM cell_members cm
                LEFT JOIN users u            ON cm.user_id            = u.id
                LEFT JOIN designations d     ON cm.designation_id     = d.id
                LEFT JOIN departments dept   ON cm.department_id      = dept.id
                LEFT JOIN grievance_types gt ON cm.grievance_type_id  = gt.id
                WHERE DATE(u.created_at) BETWEEN ? AND ?";

        $params = [$filterFrom, $filterTo];
        $types  = 'ss';

        if (!$isManagement) {
            if ($hasTypeAssignment) {
                $sql .= " AND cm.grievance_type_id = ?";
                $params[] = $memberGrievanceTypeId;
                $types   .= 'i';
            } else {
                $sql .= " AND 1 = 0";
            }
        } elseif ($filterType > 0) {
            $sql .= " AND cm.grievance_type_id = ?";
            $params[] = $filterType;
            $types   .= 'i';
        }

        if ($filterDepartment > 0) {
            $sql .= " AND cm.department_id = ?";
            $params[] = $filterDepartment;
            $types   .= 'i';
        }

        if ($filterDesignation > 0) {
            $sql .= " AND cm.designation_id = ?";
            $params[] = $filterDesignation;
            $types   .= 'i';
        }

        if ($filterStatus !== '') {
            $sql .= " AND u.status = ?";
            $params[] = $filterStatus;
            $types   .= 's';
        }

        $sql .= " ORDER BY cm.id ASC";

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
        error_log('[Cell Members Report] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// LOOKUP HELPER
// ---------------------------------------------------------------------------
function findName(array $list, int $id, string $key): string
{
    foreach ($list as $item) {
        if ((int) $item['id'] === $id) return (string) $item[$key];
    }
    return 'All';
}

$summaryType = $filterType > 0 ? findName($grievanceTypeOptions, $filterType, 'type_name')
                               : (!$isManagement && $hasTypeAssignment ? $memberGrievanceTypeName : 'All');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Cell Members Report — <?= e($isManagement ? 'Management' : 'Grievance Member') ?> | Rajagiri College Grievance Portal</title>
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
            }
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
       PRINT STYLES
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
        font-size: 10px;
        color: #000;
      }

      @page {
        size: A4;
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
    <aside id="managementSidebar"
           class="w-20 bg-gradient-to-b from-[#4A154B] via-[#5A1B5C] to-[#006837]
                  flex flex-col py-4 shadow-2xl fixed inset-y-0 left-0 z-40
                  transition-all duration-300 ease-in-out overflow-hidden">

      <button id="sidebarToggle"
              class="text-white/80 hover:text-white mb-8 p-2 rounded-lg hover:bg-white/10 transition-colors
                     flex items-center justify-center w-14 mx-auto"
              aria-label="Toggle sidebar">
        <i data-lucide="menu" class="w-6 h-6 flex-shrink-0"></i>
      </button>

      <nav class="flex flex-col space-y-2 flex-1 w-full px-3">

        <!-- Dashboard -->
        <a href="dashboard.php"
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20
                  flex items-center text-white transition-all px-3">
          <i data-lucide="home" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap
                       opacity-0 w-0 overflow-hidden transition-all duration-200">Dashboard</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                       bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Dashboard</span>
        </a>

        <!-- My Profile -->
        <a href="profile.php"
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20
                  flex items-center text-white transition-all px-3">
          <i data-lucide="user" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap
                       opacity-0 w-0 overflow-hidden transition-all duration-200">My Profile</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                       bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">My Profile</span>
        </a>

        <!-- Grievance Reports (NEW) -->
        <a href="grievance_reports.php"
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20
                  flex items-center text-white transition-all px-3">
          <i data-lucide="file-bar-chart" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap
                       opacity-0 w-0 overflow-hidden transition-all duration-200">Grievance Reports</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                       bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Grievance Reports</span>
        </a>

        <!-- Change Password -->
        <a href="change_password.php"
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20
                  flex items-center text-white transition-all px-3">
          <i data-lucide="key" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap
                       opacity-0 w-0 overflow-hidden transition-all duration-200">Change Password</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                       bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Change Password</span>
        </a>
      </nav>

      <!-- Logout -->
      <a href="#" data-logout-trigger="1" id="sidebarLogoutBtn"
         class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-red-500/40
                flex items-center text-white transition-all mx-3 px-3"
         style="width: calc(100% - 1.5rem);" title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 flex-shrink-0"></i>
        <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap
                     opacity-0 w-0 overflow-hidden transition-all duration-200">Logout</span>
        <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                     bg-red-600 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Logout</span>
      </a>
    </aside>

    <!-- MAIN CONTENT -->
    <div id="managementMain" class="flex-1 ml-20 flex flex-col min-h-screen transition-all duration-300">

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

          <div class="relative" id="management-dropdown-container">
            <button id="management-dropdown-btn" type="button" aria-haspopup="true" aria-expanded="false"
                    class="flex items-center space-x-3 px-3 py-2 rounded-xl hover:bg-slate-100 transition-colors cursor-pointer">
              <?php if ($hasProfilePicture): ?>
                <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"
                     class="w-10 h-10 rounded-full object-cover border-2 border-[#C5A059] shadow-md ring-2 ring-purple-100" />
              <?php else: ?>
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E]
                            flex items-center justify-center text-white shadow-md ring-2 ring-purple-100">
                  <i data-lucide="user" class="w-5 h-5"></i>
                </div>
              <?php endif; ?>
              <span class="hidden sm:block text-sm font-semibold text-slate-700"><?= e($displayName) ?></span>
              <i data-lucide="chevron-down" id="management-chevron" class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <div id="management-dropdown-menu"
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

        <!-- Breadcrumb + Print Button -->
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2 tracking-tight">Cell Members Report</h1>
              <nav class="flex items-center space-x-2 text-sm text-slate-500">
                <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard
                </a>
                <span class="text-slate-300">/</span>
                <a href="grievance_reports.php" class="hover:text-[#8B1E7E] transition-colors">Grievance Reports</a>
                <span class="text-slate-300">/</span>
                <span class="text-[#E5097F] font-semibold">Cell Members Report</span>
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

        <!-- FILTER CARD -->
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up" style="animation-delay: 60ms;">
          <div class="bg-slate-100 border border-slate-200 rounded-xl px-6 py-6 shadow-sm">
            <form method="GET" action="cell_members_report.php" class="space-y-5">
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
                  <label for="class_id" class="block text-sm font-semibold text-slate-700">Class/Semester</label>
                  <select name="class_id" id="class_id"
                          class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 hover:border-[#4A154B]/40 transition-all">
                    <option value="0">All</option>
                    <?php foreach ($classOptions as $cl): ?>
                      <option value="<?= (int) $cl['id'] ?>" <?= $filterClass === (int) $cl['id'] ? 'selected' : '' ?>>
                        <?= e($cl['class_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="space-y-2">
                  <label for="department_id" class="block text-sm font-semibold text-slate-700">Department</label>
                  <select name="department_id" id="department_id"
                          class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 hover:border-[#4A154B]/40 transition-all">
                    <option value="0">All</option>
                    <?php foreach ($departmentOptions as $d): ?>
                      <option value="<?= (int) $d['id'] ?>" <?= $filterDepartment === (int) $d['id'] ? 'selected' : '' ?>>
                        <?= e($d['department_name']) ?>
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
                    <?php foreach ($designationOptions as $dg): ?>
                      <option value="<?= (int) $dg['id'] ?>" <?= $filterDesignation === (int) $dg['id'] ? 'selected' : '' ?>>
                        <?= e($dg['designation_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="space-y-2">
                  <label for="grievance_type_id" class="block text-sm font-semibold text-slate-700">
                    Grievance Type<span class="text-[#E5097F]">*</span>
                  </label>
                  <select name="grievance_type_id" id="grievance_type_id"
                          class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 hover:border-[#4A154B]/40 transition-all"
                          <?= !$isManagement ? 'disabled' : '' ?>>
                    <?php if ($isManagement): ?>
                      <option value="0">All</option>
                    <?php endif; ?>
                    <?php foreach ($grievanceTypeOptions as $gt): ?>
                      <option value="<?= (int) $gt['id'] ?>" <?= ($filterType === (int) $gt['id'] || !$isManagement) ? 'selected' : '' ?>>
                        <?= e($gt['type_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?php if (!$isManagement): ?>
                    <input type="hidden" name="grievance_type_id" value="<?= (int) $memberGrievanceTypeId ?>" />
                    <p class="text-[11px] text-slate-500 mt-1">Scoped to your assigned grievance type.</p>
                  <?php endif; ?>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="space-y-2">
                  <label for="status" class="block text-sm font-semibold text-slate-700">Status</label>
                  <select name="status" id="status"
                          class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 hover:border-[#4A154B]/40 transition-all">
                    <option value="">All</option>
                    <option value="Approved"   <?= $filterStatus === 'Approved'   ? 'selected' : '' ?>>Approved</option>
                    <option value="Pending"    <?= $filterStatus === 'Pending'    ? 'selected' : '' ?>>Pending</option>
                    <option value="Rejected"   <?= $filterStatus === 'Rejected'   ? 'selected' : '' ?>>Rejected</option>
                    <option value="Terminated" <?= $filterStatus === 'Terminated' ? 'selected' : '' ?>>Terminated</option>
                  </select>
                </div>
              </div>

              <div class="flex justify-center pt-3">
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
                  <p class="text-sm font-semibold text-slate-700">Date: <?= date('d/m/Y') ?></p>
                </div>
              </div>

              <h2 class="text-xl md:text-2xl font-bold text-slate-800 mt-5 tracking-tight">Cell Member Report</h2>

              <div class="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs text-slate-600">
                <p>
                  <span class="font-semibold text-slate-700">Period:</span>
                  <?= e(date('d/m/Y', strtotime($filterFrom))) ?> – <?= e(date('d/m/Y', strtotime($filterTo))) ?>
                </p>
                <p class="text-slate-600">
                  <?= e($filterDepartment > 0 ? findName($departmentOptions, $filterDepartment, 'department_name') : 'All') ?>
                  -
                  <?= e($summaryType) ?>
                  -
                  <?= e($filterStatus !== '' ? $filterStatus : 'All') ?>
                </p>
              </div>
            </div>

            <div class="bg-white border-x border-b border-slate-200 rounded-b-xl overflow-hidden">
              <div class="overflow-x-auto">
                <table class="w-full">
                  <thead>
                    <tr class="bg-[#4A154B] text-white">
                      <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                      <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                      <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">EmailId</th>
                      <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Mobile</th>
                      <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Designation</th>
                      <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 bg-white">
                    <?php if (empty($rows)): ?>
                      <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-slate-500">No cell members match the selected filters.</td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($rows as $i => $r): ?>
                        <tr class="hover:bg-slate-50/80 transition-colors align-top">
                          <td class="px-4 py-3 whitespace-nowrap text-sm text-slate-800"><?= $i + 1 ?></td>
                          <td class="px-4 py-3 text-sm font-medium text-slate-800"><?= e($r['name'] ?? '—') ?></td>
                          <td class="px-4 py-3 text-sm text-slate-600 break-all"><?= e($r['email'] ?? '—') ?></td>
                          <td class="px-4 py-3 whitespace-nowrap text-sm text-slate-700"><?= e($r['mobile_number'] ?? '—') ?></td>
                          <td class="px-4 py-3 text-sm text-slate-700"><?= e($r['designation_name'] ?? '—') ?></td>
                          <td class="px-4 py-3 whitespace-nowrap text-sm text-slate-700"><?= e($r['user_status'] ?? '—') ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <?php if (!empty($rows)): ?>
                <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 text-xs text-slate-600">
                  <p>
                    Total records: <span class="font-semibold text-slate-800"><?= count($rows) ?></span>
                    · Generated on <?= date('d/m/Y H:i') ?>
                  </p>
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
                Choose your filters above, then click <strong>Submit</strong> to view the cell members report.
              </p>
            </div>
          </div>
        <?php endif; ?>

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
              <span class="font-bold bg-gradient-to-r from-[#4A154B] to-[#E5097F] bg-clip-text text-transparent ml-1">Orell</span>
            </p>
          </div>
        </div>
      </footer>

    </div>
  </div>
  <!-- END .screen-only -->

  <!-- ============================================================
       PRINT-ONLY AREA (inline styles only)
       ============================================================ -->
  <?php if ($isSubmitted): ?>
  <div id="print-area">
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
          Date: <?= date('d/m/Y') ?>
        </td>
      </tr>
    </table>

    <div style="font-size:18px;font-weight:bold;color:#111;margin:0 0 8px 0;">Cell Member Report</div>

    <table style="width:100%;border-collapse:collapse;font-size:10.5px;color:#333;margin-bottom:10px;">
      <tr>
        <td style="padding-bottom:6px;">
          <strong style="color:#111;">Period:</strong>
          <?= e(date('d/m/Y', strtotime($filterFrom))) ?> - <?= e(date('d/m/Y', strtotime($filterTo))) ?>
        </td>
        <td style="padding-bottom:6px;text-align:right;">
          <strong style="color:#111;">Filters:</strong>
          Department: <?= e($filterDepartment > 0 ? findName($departmentOptions, $filterDepartment, 'department_name') : 'All') ?>
          - Type: <?= e($summaryType) ?>
          - Status: <?= e($filterStatus !== '' ? $filterStatus : 'All') ?>
        </td>
      </tr>
    </table>

    <table style="width:100%;border-collapse:collapse;table-layout:fixed;font-size:10px;color:#000;">
      <thead>
        <tr>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9.5px;font-weight:bold;width:6%;">Sl.No.</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9.5px;font-weight:bold;width:20%;">Name</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9.5px;font-weight:bold;width:24%;">EmailId</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9.5px;font-weight:bold;width:14%;">Mobile</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9.5px;font-weight:bold;width:24%;">Designation</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9.5px;font-weight:bold;width:12%;">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="6" style="border:1px solid #333;padding:10px;text-align:center;">
              No cell members match the selected filters.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $i => $r): ?>
            <tr>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;text-align:center;"><?= $i + 1 ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($r['name'] ?? '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;word-break:break-all;"><?= e($r['email'] ?? '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;white-space:nowrap;"><?= e($r['mobile_number'] ?? '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($r['designation_name'] ?? '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;white-space:nowrap;"><?= e($r['user_status'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div style="margin-top:10px;font-size:10px;color:#333;">
      <strong>Total records:</strong> <?= count($rows) ?>
      &nbsp;&nbsp;|&nbsp;&nbsp;
      <strong>Generated on:</strong> <?= date('d/m/Y H:i') ?>
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

    /* ---- Sidebar toggle ---- */
    (function () {
      const toggleBtn = document.getElementById('sidebarToggle');
      const sidebar   = document.getElementById('managementSidebar');
      const main      = document.getElementById('managementMain');
      if (!toggleBtn || !sidebar || !main) return;

      const labels   = sidebar.querySelectorAll('.sidebar-label');
      const tooltips = sidebar.querySelectorAll('.sidebar-tooltip');
      let expanded = false;

      toggleBtn.addEventListener('click', function () {
        expanded = !expanded;
        if (expanded) {
          sidebar.classList.remove('w-20'); sidebar.classList.add('w-64');
          main.classList.remove('ml-20'); main.classList.add('ml-64');
          labels.forEach(function (el) { el.classList.remove('opacity-0','w-0'); el.classList.add('opacity-100','w-auto'); });
          tooltips.forEach(function (el) { el.classList.add('hidden'); });
        } else {
          sidebar.classList.add('w-20'); sidebar.classList.remove('w-64');
          main.classList.add('ml-20'); main.classList.remove('ml-64');
          labels.forEach(function (el) { el.classList.add('opacity-0','w-0'); el.classList.remove('opacity-100','w-auto'); });
          tooltips.forEach(function (el) { el.classList.remove('hidden'); });
        }
        setTimeout(function () { if (typeof lucide !== 'undefined') lucide.createIcons(); }, 250);
      });
    })();

    /* ---- Profile dropdown ---- */
    (function () {
      const btn = document.getElementById('management-dropdown-btn');
      const menu = document.getElementById('management-dropdown-menu');
      const chevron = document.getElementById('management-chevron');
      const container = document.getElementById('management-dropdown-container');
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

    /* ---- Logout modal ---- */
    const logoutConfirmModal = document.getElementById('logoutConfirmModal');
    const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
    const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
    const LOGOUT_URL = '../logout.php?role=management';

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