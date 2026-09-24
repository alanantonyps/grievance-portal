<?php
/**
 * admin/departments.php
 * ---------------------------------------------------------------------------
 * Admin — Department Management
 * Rajagiri College Grievance Redressal Portal
 *
 * Features:
 *   • View all departments in a searchable table
 *   • Create / Edit / Delete departments
 *   • Toggle status (Active / Inactive)
 *   • Live search + entries-per-page dropdown
 *   • Themed delete & toggle confirmation modals
 *   • Themed logout confirmation modal
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

if (empty($_SESSION['user_id']) || $sessionRole !== 'ADMIN') {
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
// 4. HELPER
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
        error_log('[Departments Admin Profile] ' . $ex->getMessage());
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
// 6. HANDLE FORM SUBMISSIONS
// ---------------------------------------------------------------------------
$flashSuccess = '';
$flashError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    $action = $_POST['action'] ?? '';

    // -------- CREATE DEPARTMENT --------
    if ($action === 'create_department') {
        $departmentName = trim((string) ($_POST['department_name'] ?? ''));
        $description    = trim((string) ($_POST['description']     ?? ''));

        if ($departmentName === '') {
            $flashError = 'Department name is required.';
        }

        if ($flashError === '') {
            try {
                $stmt = $conn->prepare("INSERT INTO departments (department_name, description, status) VALUES (?, ?, 'Active')");
                $stmt->bind_param('ss', $departmentName, $description);
                if ($stmt->execute()) {
                    $flashSuccess = 'Department created successfully.';
                } else {
                    $flashError = 'Failed to create department.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Create Department] ' . $ex->getMessage());
                $flashError = 'A system error occurred while creating the department.';
            }
        }
    }

    // -------- EDIT DEPARTMENT --------
    if ($action === 'edit_department') {
        $departmentId   = (int) ($_POST['department_id']   ?? 0);
        $departmentName = trim((string) ($_POST['department_name'] ?? ''));
        $description    = trim((string) ($_POST['description']     ?? ''));
        $status         = trim((string) ($_POST['status']          ?? 'Active'));

        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        if ($departmentId <= 0) {
            $flashError = 'Invalid department.';
        } elseif ($departmentName === '') {
            $flashError = 'Department name is required.';
        }

        if ($flashError === '') {
            try {
                $stmt = $conn->prepare("UPDATE departments SET department_name = ?, description = ?, status = ? WHERE id = ?");
                $stmt->bind_param('sssi', $departmentName, $description, $status, $departmentId);
                if ($stmt->execute()) {
                    $flashSuccess = 'Department updated successfully.';
                } else {
                    $flashError = 'Failed to update department.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Edit Department] ' . $ex->getMessage());
                $flashError = 'A system error occurred while updating the department.';
            }
        }
    }

    // -------- DELETE DEPARTMENT --------
    if ($action === 'delete_department') {
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        if ($departmentId > 0) {
            try {
                $stmt = $conn->prepare("DELETE FROM departments WHERE id = ?");
                $stmt->bind_param('i', $departmentId);
                if ($stmt->execute()) {
                    $flashSuccess = 'Department deleted successfully.';
                } else {
                    $flashError = 'Failed to delete department.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Delete Department] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the department.';
            }
        }
    }

    // -------- TOGGLE STATUS --------
    if ($action === 'toggle_status') {
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        if ($departmentId > 0) {
            try {
                $stmtG = $conn->prepare("SELECT status FROM departments WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $departmentId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $currentStatus = $resG && $resG->num_rows > 0 ? (string) ($resG->fetch_assoc()['status'] ?? 'Active') : 'Active';
                $stmtG->close();

                $newStatus = (strcasecmp($currentStatus, 'Active') === 0) ? 'Inactive' : 'Active';

                $stmt = $conn->prepare("UPDATE departments SET status = ? WHERE id = ?");
                $stmt->bind_param('si', $newStatus, $departmentId);
                if ($stmt->execute()) {
                    $flashSuccess = 'Department status updated to ' . $newStatus . '.';
                } else {
                    $flashError = 'Failed to update department status.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Toggle Status] ' . $ex->getMessage());
                $flashError = 'A system error occurred while updating the status.';
            }
        }
    }

    if ($flashSuccess !== '' || $flashError !== '') {
        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: departments.php');
        exit;
    }
}

if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ---------------------------------------------------------------------------
// 7. FETCH DEPARTMENTS
// ---------------------------------------------------------------------------
$departments = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, department_name, description, status, created_at FROM departments ORDER BY id ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $departments[] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Departments] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Department — Admin | Rajagiri College Grievance Portal</title>
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
            confirmShake: { '0%, 100%': { transform: 'translateX(0)' }, '20%': { transform: 'translateX(-6px)' }, '40%': { transform: 'translateX(6px)' }, '60%': { transform: 'translateX(-4px)' }, '80%': { transform: 'translateX(4px)' } },
            flashIn: { '0%': { opacity: '0', transform: 'translateY(-10px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
            flashOut: { '0%': { opacity: '1', transform: 'translateY(0)', maxHeight: '200px' }, '100%': { opacity: '0', transform: 'translateY(-10px)', maxHeight: '0px' } }
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
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Dashboard</span>
        </a>

        <a href="profile.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Profile">
          <i data-lucide="user" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Profile</span>
        </a>

        <a href="settings.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Settings">
          <i data-lucide="settings" class="w-6 h-6 group-hover:rotate-90 transition-transform duration-500"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Settings</span>
        </a>

      </nav>

      <!-- Logout Trigger -->
      <a href="#" data-logout-trigger="1"
         id="sidebarLogoutBtn"
         class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-red-500/40 flex items-center justify-center text-white transition-all hover:scale-110"
         title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 group-hover:translate-x-0.5 transition-transform"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-red-600 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Logout</span>
      </a>

    </aside>

    <!-- MAIN CONTENT -->
    <div class="flex-1 ml-20 flex flex-col min-h-screen">

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

              <a href="settings.php" class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="settings" class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover/item:scale-110 transition-transform"></i>
                <span class="font-medium">Settings</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover/item:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>

              <a href="change_password.php" class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="key" class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover/item:scale-110 transition-transform"></i>
                <span class="font-medium">Change Password</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover/item:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>

              <div class="border-t border-slate-100 mt-2 pt-2">
                <a href="#" data-logout-trigger="1"
                   id="dropdownLogoutBtn"
                   class="flex items-center px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-all duration-200 group/item">
                  <i data-lucide="log-out" class="w-4 h-4 mr-3 group-hover/item:scale-110 transition-transform"></i>
                  <span class="font-medium">Logout</span>
                </a>
              </div>
            </div>
          </div>

        </div>
      </header>

      <main class="flex-1 px-6 py-8">

        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

            <div>
              <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2 tracking-tight">Department</h1>
              <nav class="flex items-center space-x-2 text-sm text-slate-500">
                <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard
                </a>
                <span class="text-slate-300">/</span>
                <a href="settings.php" class="hover:text-[#8B1E7E] transition-colors">Settings</a>
                <span class="text-slate-300">/</span>
                <span class="text-[#E5097F] font-semibold">Department</span>
              </nav>
            </div>

            <button type="button"
                    onclick="openDepartmentModal('add')"
                    title="Add Department"
                    class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-[#4A154B] hover:bg-[#5A1B5C]
                           text-white shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                           transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
              <i data-lucide="plus" class="w-5 h-5"></i>
            </button>

          </div>
        </div>

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

        <div class="max-w-6xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 60ms;">
          <div class="bg-white rounded-xl shadow-sm border border-slate-200/70 px-5 py-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">

              <div class="flex items-center space-x-3">
                <span class="text-sm text-slate-600">Show</span>
                <select id="entriesPerPage"
                        class="px-3 py-1.5 border-2 border-slate-200 rounded-lg text-sm font-medium text-slate-700
                               focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                               hover:border-[#4A154B]/40 transition-colors bg-white">
                  <option value="10" selected>10</option>
                  <option value="25">25</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
                <span class="text-sm text-slate-600">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text" id="searchInput" placeholder="Search.."
                       class="w-full pl-10 pr-4 py-2 border-2 border-slate-200 rounded-lg text-sm
                              focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                              hover:border-[#4A154B]/40 transition-all bg-white" />
              </div>

            </div>
          </div>
        </div>

        <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 100ms;">
          <div class="bg-white rounded-2xl shadow-lg border border-slate-200/70 overflow-hidden">

            <div class="overflow-x-auto">
              <table class="w-full" id="departmentsTable">
                <thead>
                  <tr class="bg-[#4A154B] text-white">
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Department</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Description</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                    <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="departmentsTableBody">

                  <?php if (empty($departments)): ?>
                    <tr>
                      <td colspan="5" class="px-6 py-16 text-center text-slate-500">
                        <div class="flex flex-col items-center justify-center">
                          <div class="w-16 h-16 bg-purple-50 rounded-full flex items-center justify-center mb-4">
                            <i data-lucide="building-2" class="w-8 h-8 text-[#8B1E7E]"></i>
                          </div>
                          <p class="text-lg font-semibold text-slate-700">No departments yet</p>
                          <p class="text-sm text-slate-500 mt-1 mb-4">
                            Click "Add Department" to create your first department.
                          </p>
                        </div>
                      </td>
                    </tr>
                  <?php else: ?>

                    <?php foreach ($departments as $index => $department): ?>
                      <?php
                        $departmentId   = (int) $department['id'];
                        $departmentName = (string) ($department['department_name'] ?? '');
                        $departmentDesc = (string) ($department['description']     ?? '');
                        $status         = (string) ($department['status']          ?? 'Active');
                        $isActive       = (strcasecmp($status, 'Active') === 0);

                        $statusCls = $isActive
                          ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
                          : 'bg-slate-100 text-slate-700 border-slate-200';
                      ?>
                      <tr class="hover:bg-slate-50/80 transition-colors group">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900"><?= $index + 1 ?></td>
                        <td class="px-6 py-4 text-sm font-semibold text-slate-800"><?= e($departmentName) ?></td>
                        <td class="px-6 py-4 text-sm text-slate-600 max-w-[320px]">
                          <?= e($departmentDesc !== '' ? $departmentDesc : '—') ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <span class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full border <?= $statusCls ?>">
                            <?= e($status) ?>
                          </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="flex items-center justify-center gap-1.5">

                            <button type="button"
                                    title="Edit department"
                                    onclick='openDepartmentModal("edit", <?= $departmentId ?>, <?= json_encode($departmentName) ?>, <?= json_encode($departmentDesc) ?>, <?= json_encode($status) ?>)'
                                    class="w-9 h-9 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                           flex items-center justify-center text-[#4A154B] hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="pencil" class="w-4 h-4"></i>
                            </button>

                            <button type="button"
                                    title="Delete department"
                                    onclick='confirmDeleteDepartment(<?= $departmentId ?>, <?= json_encode($departmentName) ?>)'
                                    class="w-9 h-9 rounded-full bg-purple-50 hover:bg-red-500
                                           flex items-center justify-center text-[#4A154B] hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>

                            <button type="button"
                                    title="<?= $isActive ? 'Deactivate department' : 'Activate department' ?>"
                                    onclick='confirmToggleStatus(<?= $departmentId ?>, <?= json_encode($departmentName) ?>, <?= json_encode($status) ?>)'
                                    class="w-9 h-9 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                           flex items-center justify-center text-[#4A154B] hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="x" class="w-4 h-4"></i>
                            </button>

                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>

                  <?php endif; ?>

                </tbody>
              </table>
            </div>

            <?php if (!empty($departments)): ?>
              <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-sm text-slate-600" id="tableInfo">
                  Showing <span class="font-semibold text-slate-900">1</span> to
                  <span class="font-semibold text-slate-900"><?= count($departments) ?></span> of
                  <span class="font-semibold text-slate-900"><?= count($departments) ?></span> entries
                </p>
                <div class="flex items-center space-x-2">
                  <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-500 hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors" disabled>Previous</button>
                  <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-[#4A154B] text-white text-sm font-bold shadow-md">1</span>
                  <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-500 hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors" disabled>Next</button>
                </div>
              </div>
            <?php endif; ?>

          </div>
        </div>

      </main>

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

  <!-- ADD / EDIT DEPARTMENT MODAL -->
  <div id="departmentModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeDepartmentModal()"></div>

    <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 id="departmentModalTitle" class="text-lg font-bold text-slate-800">Add Department</h3>
        <button type="button" onclick="closeDepartmentModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="departmentForm" method="POST" action="departments.php" class="p-6 space-y-5">
        <input type="hidden" name="action" id="formAction" value="create_department" />
        <input type="hidden" name="department_id" id="formDepartmentId" value="" />

        <div class="space-y-2">
          <label for="department_name" class="block text-sm font-semibold text-slate-700">Department Name <span class="text-[#E5097F]">*</span></label>
          <input type="text" name="department_name" id="department_name" required placeholder="e.g. Computer Science"
                 class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400
                        focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                        hover:border-[#4A154B]/40 transition-all" />
        </div>

        <div class="space-y-2">
          <label for="description" class="block text-sm font-semibold text-slate-700">Description</label>
          <textarea name="description" id="description" rows="3" placeholder="e.g. Department of Computer Science"
                    class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400 resize-none
                           focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                           hover:border-[#4A154B]/40 transition-all"></textarea>
        </div>

        <div class="space-y-2" id="statusFieldWrapper" style="display: none;">
          <label for="status" class="block text-sm font-semibold text-slate-700">Status <span class="text-[#E5097F]">*</span></label>
          <select name="status" id="status"
                  class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl appearance-none bg-white text-slate-800 font-medium
                         focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                         hover:border-[#4A154B]/40 transition-all">
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </div>

        <div class="flex justify-center pt-3 gap-3">
          <button type="button" onclick="closeDepartmentModal()"
                  class="px-6 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">
            Cancel
          </button>
          <button type="submit"
                  class="px-8 py-3 rounded-xl bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A] hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A] text-white font-bold shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
            Save
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- DELETE CONFIRMATION MODAL -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>

    <div id="deleteConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-gradient-to-br from-red-100 to-pink-100 ring-4 ring-red-50">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 class="text-xl font-bold text-slate-800 mb-2">Delete Department?</h3>

        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to permanently delete
          <span id="deleteDepartmentNameDisplay" class="font-bold text-[#8B1E7E] break-words">this department</span>.
        </p>

        <p class="text-xs text-red-500 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>
          This action cannot be undone.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeDeleteModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">
          Cancel
        </button>
        <button type="button" id="confirmDeleteBtn"
                class="flex-1 px-5 py-3 rounded-xl font-bold text-white bg-gradient-to-r from-red-500 via-red-600 to-rose-600 hover:from-red-600 hover:via-red-700 hover:to-rose-700 shadow-lg shadow-red-500/30 hover:shadow-red-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95 flex items-center justify-center gap-2">
          <i data-lucide="trash-2" class="w-4 h-4"></i>
          <span>Delete</span>
        </button>
      </div>
    </div>
  </div>

  <!-- TOGGLE STATUS CONFIRMATION MODAL -->
  <div id="toggleConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeToggleModal()"></div>

    <div id="toggleConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-gradient-to-br from-purple-100 to-pink-100 ring-4 ring-purple-50">
          <i id="toggleIcon" data-lucide="power" class="w-8 h-8 text-[#8B1E7E]"></i>
        </div>

        <h3 id="toggleModalTitle" class="text-xl font-bold text-slate-800 mb-2">Change Status?</h3>

        <p id="toggleModalDescription" class="text-sm text-slate-500 leading-relaxed">
          The status of
          <span class="font-bold text-[#8B1E7E] break-words">this department</span>
          will be changed.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeToggleModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">
          Cancel
        </button>
        <button type="button" id="confirmToggleBtn"
                class="flex-1 px-5 py-3 rounded-xl font-bold text-white bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A] hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A] shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95 flex items-center justify-center gap-2">
          <i data-lucide="refresh-cw" class="w-4 h-4"></i>
          <span id="confirmToggleBtnLabel">Confirm</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ============================================================= -->
  <!-- LOGOUT CONFIRMATION MODAL                                     -->
  <!-- ============================================================= -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
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

  <!-- HIDDEN FORMS -->
  <form id="deleteForm" method="POST" action="departments.php" class="hidden">
    <input type="hidden" name="action" value="delete_department" />
    <input type="hidden" name="department_id" id="deleteDepartmentId" value="" />
  </form>

  <form id="toggleForm" method="POST" action="departments.php" class="hidden">
    <input type="hidden" name="action" value="toggle_status" />
    <input type="hidden" name="department_id" id="toggleDepartmentId" value="" />
  </form>

  <script>
    document.addEventListener('DOMContentLoaded', function () {

      if (typeof lucide !== 'undefined') {
        lucide.createIcons();
      }

      // ---- Auto-dismiss flash ----
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

      // ---- Department Add/Edit Modal ----
      const departmentModal      = document.getElementById('departmentModal');
      const departmentModalTitle = document.getElementById('departmentModalTitle');
      const departmentForm       = document.getElementById('departmentForm');
      const formAction           = document.getElementById('formAction');
      const formDepartmentId     = document.getElementById('formDepartmentId');
      const nameInput            = document.getElementById('department_name');
      const descriptionInput     = document.getElementById('description');
      const statusInput          = document.getElementById('status');
      const statusFieldWrapper   = document.getElementById('statusFieldWrapper');

      window.openDepartmentModal = function (mode, id, name, description, status) {
        departmentModal.classList.remove('hidden');

        if (mode === 'edit') {
          departmentModalTitle.textContent = 'Edit Department';
          formAction.value         = 'edit_department';
          formDepartmentId.value   = id || '';
          nameInput.value          = name        || '';
          descriptionInput.value   = description || '';
          statusInput.value        = status      || 'Active';

          if (statusFieldWrapper) statusFieldWrapper.style.display = '';
          if (statusInput) statusInput.setAttribute('required', 'required');
        } else {
          departmentModalTitle.textContent = 'Add Department';
          formAction.value         = 'create_department';
          formDepartmentId.value   = '';
          departmentForm.reset();

          if (statusFieldWrapper) statusFieldWrapper.style.display = 'none';
          if (statusInput) statusInput.removeAttribute('required');
        }

        setTimeout(() => nameInput && nameInput.focus(), 50);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };

      window.closeDepartmentModal = function () {
        departmentModal.classList.add('hidden');
        departmentForm.reset();
        formAction.value       = 'create_department';
        formDepartmentId.value = '';
      };

      // ---- Delete Confirmation Modal ----
      const deleteConfirmModal     = document.getElementById('deleteConfirmModal');
      const deleteConfirmPanel     = document.getElementById('deleteConfirmPanel');
      const deleteDepartmentNameEl = document.getElementById('deleteDepartmentNameDisplay');
      const confirmDeleteBtn       = document.getElementById('confirmDeleteBtn');

      let pendingDeleteId = null;

      window.confirmDeleteDepartment = function (departmentId, departmentName) {
        pendingDeleteId = departmentId;
        if (deleteDepartmentNameEl) deleteDepartmentNameEl.textContent = '"' + departmentName + '"';

        deleteConfirmModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');

        if (deleteConfirmPanel) {
          deleteConfirmPanel.classList.remove('animate-confirm-shake');
          void deleteConfirmPanel.offsetWidth;
          deleteConfirmPanel.classList.add('animate-confirm-shake');
        }

        setTimeout(function () { if (confirmDeleteBtn) confirmDeleteBtn.focus(); }, 80);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };

      window.closeDeleteModal = function () {
        deleteConfirmModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        pendingDeleteId = null;
      };

      if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', function () {
          if (pendingDeleteId === null || pendingDeleteId === undefined) {
            window.closeDeleteModal();
            return;
          }
          const delIdInput = document.getElementById('deleteDepartmentId');
          const delForm    = document.getElementById('deleteForm');
          if (delIdInput && delForm) {
            delIdInput.value = String(pendingDeleteId);
            delForm.submit();
          } else {
            window.closeDeleteModal();
          }
        });
      }

      // ---- Toggle Status Confirmation Modal ----
      const toggleConfirmModal     = document.getElementById('toggleConfirmModal');
      const toggleConfirmPanel     = document.getElementById('toggleConfirmPanel');
      const toggleModalTitle       = document.getElementById('toggleModalTitle');
      const toggleModalDescription = document.getElementById('toggleModalDescription');
      const toggleIcon             = document.getElementById('toggleIcon');
      const confirmToggleBtn       = document.getElementById('confirmToggleBtn');
      const confirmToggleBtnLabel  = document.getElementById('confirmToggleBtnLabel');

      let pendingToggleId = null;

      window.confirmToggleStatus = function (departmentId, departmentName, currentStatus) {
        pendingToggleId = departmentId;

        const isCurrentlyActive = (String(currentStatus).toLowerCase() === 'active');

        if (isCurrentlyActive) {
          if (toggleModalTitle) toggleModalTitle.textContent = 'Deactivate Department?';
          if (toggleModalDescription) {
            toggleModalDescription.innerHTML = 'The department <span class="font-bold text-[#8B1E7E] break-words">"' + departmentName + '"</span> will be marked as <span class="font-bold text-[#8B1E7E]">Inactive</span>.';
          }
          if (confirmToggleBtnLabel) confirmToggleBtnLabel.textContent = 'Deactivate';
          if (toggleIcon) toggleIcon.setAttribute('data-lucide', 'power-off');
        } else {
          if (toggleModalTitle) toggleModalTitle.textContent = 'Activate Department?';
          if (toggleModalDescription) {
            toggleModalDescription.innerHTML = 'The department <span class="font-bold text-[#8B1E7E] break-words">"' + departmentName + '"</span> will be marked as <span class="font-bold text-[#8B1E7E]">Active</span>.';
          }
          if (confirmToggleBtnLabel) confirmToggleBtnLabel.textContent = 'Activate';
          if (toggleIcon) toggleIcon.setAttribute('data-lucide', 'power');
        }

        toggleConfirmModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');

        if (toggleConfirmPanel) {
          toggleConfirmPanel.classList.remove('animate-confirm-shake');
          void toggleConfirmPanel.offsetWidth;
          toggleConfirmPanel.classList.add('animate-confirm-shake');
        }

        setTimeout(function () { if (confirmToggleBtn) confirmToggleBtn.focus(); }, 80);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };

      window.closeToggleModal = function () {
        toggleConfirmModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        pendingToggleId = null;
      };

      if (confirmToggleBtn) {
        confirmToggleBtn.addEventListener('click', function () {
          if (pendingToggleId === null || pendingToggleId === undefined) {
            window.closeToggleModal();
            return;
          }
          const toggleIdInput = document.getElementById('toggleDepartmentId');
          const toggleForm    = document.getElementById('toggleForm');
          if (toggleIdInput && toggleForm) {
            toggleIdInput.value = String(pendingToggleId);
            toggleForm.submit();
          } else {
            window.closeToggleModal();
          }
        });
      }

      // ---- Logout Confirmation Modal ----
      (function () {
        const logoutConfirmModal = document.getElementById('logoutConfirmModal');
        const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
        const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
        const LOGOUT_URL         = '../logout.php?role=admin';

        if (!logoutConfirmModal) return;

        window.openLogoutModal = function () {
          logoutConfirmModal.classList.remove('hidden');
          document.body.classList.add('overflow-hidden');
          if (logoutConfirmPanel) {
            logoutConfirmPanel.classList.remove('animate-confirm-shake');
            void logoutConfirmPanel.offsetWidth;
            logoutConfirmPanel.classList.add('animate-confirm-shake');
          }
          setTimeout(function () { if (confirmLogoutBtn) confirmLogoutBtn.focus(); }, 80);
          if (typeof lucide !== 'undefined') lucide.createIcons();
        };
        window.closeLogoutModal = function () {
          logoutConfirmModal.classList.add('hidden');
          document.body.classList.remove('overflow-hidden');
        };

        [document.getElementById('sidebarLogoutBtn'), document.getElementById('dropdownLogoutBtn')].forEach(function (btn) {
          if (!btn) return;
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            window.openLogoutModal();
          });
        });

        if (confirmLogoutBtn) {
          confirmLogoutBtn.addEventListener('click', function () {
            confirmLogoutBtn.classList.add('opacity-50', 'pointer-events-none');
            window.location.href = LOGOUT_URL;
          });
        }
      })();

      // ---- Escape key: close any open modal ----
      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (departmentModal && !departmentModal.classList.contains('hidden'))       window.closeDepartmentModal();
        if (deleteConfirmModal && !deleteConfirmModal.classList.contains('hidden')) window.closeDeleteModal();
        if (toggleConfirmModal && !toggleConfirmModal.classList.contains('hidden')) window.closeToggleModal();
        if (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) window.closeLogoutModal();
      });

      // ---- Live Search ----
      (function () {
        const searchInput = document.getElementById('searchInput');
        const tableBody   = document.getElementById('departmentsTableBody');
        if (!searchInput || !tableBody) return;

        searchInput.addEventListener('input', function () {
          const term = this.value.toLowerCase().trim();
          tableBody.querySelectorAll('tr').forEach(function (row) {
            row.style.display = (term === '' || row.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
          });
        });
      })();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>