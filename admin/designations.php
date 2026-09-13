<?php
/**
 * admin/designations.php
 * ---------------------------------------------------------------------------
 * Admin — Designation Management
 * Rajagiri College Grievance Redressal Portal
 *
 * Features:
 *   • View all designations in a searchable table
 *   • Create / Edit / View / Delete designations
 *   • Toggle status (Active / Inactive)
 *   • Live search + entries-per-page dropdown
 *   • Themed delete & toggle confirmation modals
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
// 4. HELPERS
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// 5. FETCH ADMIN PROFILE (for header)
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
        error_log('[Designations Admin Profile] ' . $ex->getMessage());
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

    // -------- CREATE DESIGNATION --------
    if ($action === 'create_designation') {
        $designationName = trim((string) ($_POST['designation_name'] ?? ''));
        $description     = trim((string) ($_POST['description']      ?? ''));
        $postOccupied    = trim((string) ($_POST['post_occupied']    ?? 'Teaching'));

        if (!in_array($postOccupied, ['Teaching', 'Non Teaching'], true)) {
            $postOccupied = 'Teaching';
        }

        if ($designationName === '') {
            $flashError = 'Designation name is required.';
        }

        if ($flashError === '') {
            try {
                $stmt = $conn->prepare("INSERT INTO designations (designation_name, description, post_occupied, status) VALUES (?, ?, ?, 'Active')");
                $stmt->bind_param('sss', $designationName, $description, $postOccupied);
                if ($stmt->execute()) {
                    $flashSuccess = 'Designation created successfully.';
                } else {
                    $flashError = 'Failed to create designation.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Create Designation] ' . $ex->getMessage());
                $flashError = 'A system error occurred while creating the designation.';
            }
        }
    }

    // -------- EDIT DESIGNATION --------
    if ($action === 'edit_designation') {
        $designationId   = (int) ($_POST['designation_id']   ?? 0);
        $designationName = trim((string) ($_POST['designation_name'] ?? ''));
        $description     = trim((string) ($_POST['description']      ?? ''));
        $postOccupied    = trim((string) ($_POST['post_occupied']    ?? 'Teaching'));
        $status          = trim((string) ($_POST['status']           ?? 'Active'));

        if (!in_array($postOccupied, ['Teaching', 'Non Teaching'], true)) {
            $postOccupied = 'Teaching';
        }
        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        if ($designationId <= 0) {
            $flashError = 'Invalid designation.';
        } elseif ($designationName === '') {
            $flashError = 'Designation name is required.';
        }

        if ($flashError === '') {
            try {
                $stmt = $conn->prepare("UPDATE designations SET designation_name = ?, description = ?, post_occupied = ?, status = ? WHERE id = ?");
                $stmt->bind_param('ssssi', $designationName, $description, $postOccupied, $status, $designationId);
                if ($stmt->execute()) {
                    $flashSuccess = 'Designation updated successfully.';
                } else {
                    $flashError = 'Failed to update designation.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Edit Designation] ' . $ex->getMessage());
                $flashError = 'A system error occurred while updating the designation.';
            }
        }
    }

    // -------- DELETE DESIGNATION --------
    if ($action === 'delete_designation') {
        $designationId = (int) ($_POST['designation_id'] ?? 0);
        if ($designationId > 0) {
            try {
                $stmt = $conn->prepare("DELETE FROM designations WHERE id = ?");
                $stmt->bind_param('i', $designationId);
                if ($stmt->execute()) {
                    $flashSuccess = 'Designation deleted successfully.';
                } else {
                    $flashError = 'Failed to delete designation.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Delete Designation] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the designation.';
            }
        }
    }

    // -------- TOGGLE STATUS --------
    if ($action === 'toggle_status') {
        $designationId = (int) ($_POST['designation_id'] ?? 0);
        if ($designationId > 0) {
            try {
                // Fetch current status
                $stmtG = $conn->prepare("SELECT status FROM designations WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $designationId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $currentStatus = $resG && $resG->num_rows > 0 ? (string) ($resG->fetch_assoc()['status'] ?? 'Active') : 'Active';
                $stmtG->close();

                $newStatus = (strcasecmp($currentStatus, 'Active') === 0) ? 'Inactive' : 'Active';

                $stmt = $conn->prepare("UPDATE designations SET status = ? WHERE id = ?");
                $stmt->bind_param('si', $newStatus, $designationId);
                if ($stmt->execute()) {
                    $flashSuccess = 'Designation status updated to ' . $newStatus . '.';
                } else {
                    $flashError = 'Failed to update designation status.';
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
        header('Location: designations.php');
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
// 7. FETCH DESIGNATIONS
// ---------------------------------------------------------------------------
$designations = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, designation_name, description, post_occupied, status FROM designations ORDER BY id ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $designations[] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Designations] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Designation — Admin | Rajagiri College Grievance Portal</title>
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

    <!-- ============================================================
         SIDEBAR
         ============================================================ -->
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

        <a href="settings.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Settings">
          <i data-lucide="settings" class="w-6 h-6 group-hover:rotate-90 transition-transform duration-500"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Settings
          </span>
        </a>

      </nav>

      <a href="../logout.php?role=admin"
         id="sidebarLogoutBtn"
         class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-red-500/40 flex items-center justify-center text-white transition-all hover:scale-110"
         title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 group-hover:translate-x-0.5 transition-transform"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-red-600 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
          Logout
        </span>
      </a>

    </aside>

    <!-- ============================================================
         MAIN CONTENT
         ============================================================ -->
    <div class="flex-1 ml-20 flex flex-col min-h-screen">

      <!-- ============ TOP HEADER ============ -->
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
                <a href="../logout.php?role=admin" id="dropdownLogoutBtn" class="flex items-center px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-all duration-200 group/item">
                  <i data-lucide="log-out" class="w-4 h-4 mr-3 group-hover/item:scale-110 transition-transform"></i>
                  <span class="font-medium">Logout</span>
                </a>
              </div>
            </div>
          </div>

        </div>
      </header>

      <!-- ============ PAGE CONTENT ============ -->
      <main class="flex-1 px-6 py-8">

        <!-- Breadcrumb + Action Button -->
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

            <div>
              <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2 tracking-tight">
                Designation
              </h1>
              <nav class="flex items-center space-x-2 text-sm text-slate-500">
                <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i>
                  Dashboard
                </a>
                <span class="text-slate-300">/</span>
                <a href="settings.php" class="hover:text-[#8B1E7E] transition-colors">
                  Settings
                </a>
                <span class="text-slate-300">/</span>
                <span class="text-[#E5097F] font-semibold">Designation</span>
              </nav>
            </div>

            <button type="button"
                    onclick="openDesignationModal('add')"
                    title="Add Designation"
                    class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-[#4A154B] hover:bg-[#5A1B5C]
                           text-white shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                           transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
              <i data-lucide="plus" class="w-5 h-5"></i>
            </button>

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

        <!-- ============ TABLE CONTROLS ============ -->
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
                <input type="text"
                       id="searchInput"
                       placeholder="Search.."
                       class="w-full pl-10 pr-4 py-2 border-2 border-slate-200 rounded-lg text-sm
                              focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                              hover:border-[#4A154B]/40 transition-all bg-white" />
              </div>

            </div>
          </div>
        </div>

        <!-- ============ DATA TABLE ============ -->
        <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 100ms;">
          <div class="bg-white rounded-2xl shadow-lg border border-slate-200/70 overflow-hidden">

            <div class="overflow-x-auto">
              <table class="w-full" id="designationsTable">
                <thead>
                  <tr class="bg-[#4A154B] text-white">
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Designation Name</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Description</th>
                    <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="designationsTableBody">

                  <?php if (empty($designations)): ?>

                    <tr>
                      <td colspan="5" class="px-6 py-16 text-center text-slate-500">
                        <div class="flex flex-col items-center justify-center">
                          <div class="w-16 h-16 bg-purple-50 rounded-full flex items-center justify-center mb-4">
                            <i data-lucide="briefcase" class="w-8 h-8 text-[#8B1E7E]"></i>
                          </div>
                          <p class="text-lg font-semibold text-slate-700">No designations yet</p>
                          <p class="text-sm text-slate-500 mt-1 mb-4">
                            Click "Add Designation" to create your first designation.
                          </p>
                        </div>
                      </td>
                    </tr>

                  <?php else: ?>

                    <?php foreach ($designations as $index => $designation): ?>
                      <?php
                        $designationId    = (int) $designation['id'];
                        $designationName  = (string) ($designation['designation_name'] ?? '');
                        $designationDesc  = (string) ($designation['description']      ?? '');
                        $postOccupied     = (string) ($designation['post_occupied']    ?? 'Teaching');
                        $status           = (string) ($designation['status']           ?? 'Active');
                        $isActive         = (strcasecmp($status, 'Active') === 0);

                        $statusCls = $isActive
                          ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
                          : 'bg-slate-100 text-slate-700 border-slate-200';
                      ?>
                      <tr class="hover:bg-slate-50/80 transition-colors group">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900">
                          <?= $index + 1 ?>
                        </td>
                        <td class="px-6 py-4 text-sm font-semibold text-slate-800">
                          <?= e($designationName) ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-slate-600 max-w-[320px]">
                          <?= e($designationDesc !== '' ? $designationDesc : '—') ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="flex items-center justify-center gap-1.5">

                            <!-- Edit -->
                            <button type="button"
                                    title="Edit designation"
                                    onclick='openDesignationModal("edit", <?= $designationId ?>, <?= json_encode($designationName) ?>, <?= json_encode($designationDesc) ?>, <?= json_encode($postOccupied) ?>, <?= json_encode($status) ?>)'
                                    class="w-9 h-9 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                           flex items-center justify-center text-[#4A154B] hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="pencil" class="w-4 h-4"></i>
                            </button>

                            <!-- View -->
                            <button type="button"
                                    title="View details"
                                    onclick='openViewModal(<?= json_encode($designationName) ?>, <?= json_encode($designationDesc) ?>, <?= json_encode($postOccupied) ?>, <?= json_encode($status) ?>)'
                                    class="w-9 h-9 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                           flex items-center justify-center text-[#4A154B] hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>

                            <!-- Delete -->
                            <button type="button"
                                    title="Delete designation"
                                    onclick='confirmDeleteDesignation(<?= $designationId ?>, <?= json_encode($designationName) ?>)'
                                    class="w-9 h-9 rounded-full bg-purple-50 hover:bg-red-500
                                           flex items-center justify-center text-[#4A154B] hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>

                            <!-- Toggle Status -->
                            <button type="button"
                                    title="<?= $isActive ? 'Deactivate designation' : 'Activate designation' ?>"
                                    onclick='confirmToggleStatus(<?= $designationId ?>, <?= json_encode($designationName) ?>, <?= json_encode($status) ?>)'
                                    class="w-9 h-9 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                           flex items-center justify-center text-[#4A154B] hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="x" class="w-4 h-4"></i>
                            </button>

                          </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <span class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full border <?= $statusCls ?>">
                            <?= e($status) ?>
                          </span>
                        </td>
                      </tr>
                    <?php endforeach; ?>

                  <?php endif; ?>

                </tbody>
              </table>
            </div>

            <!-- Footer Info & Pagination -->
            <?php if (!empty($designations)): ?>
              <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">

                <p class="text-sm text-slate-600" id="tableInfo">
                  Showing <span class="font-semibold text-slate-900">1</span> to
                  <span class="font-semibold text-slate-900"><?= count($designations) ?></span> of
                  <span class="font-semibold text-slate-900"><?= count($designations) ?></span> entries
                </p>

                <div class="flex items-center space-x-2">
                  <button type="button"
                          class="px-4 py-2 rounded-lg text-sm font-medium text-slate-500 hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                          disabled>
                    Previous
                  </button>

                  <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-[#4A154B] text-white text-sm font-bold shadow-md">
                    1
                  </span>

                  <button type="button"
                          class="px-4 py-2 rounded-lg text-sm font-medium text-slate-500 hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                          disabled>
                    Next
                  </button>
                </div>

              </div>
            <?php endif; ?>

          </div>
        </div>

      </main>

      <!-- ============ FOOTER ============ -->
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

  <!-- ============================================================
       ADD / EDIT DESIGNATION MODAL
       ============================================================ -->
  <div id="designationModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeDesignationModal()"></div>

    <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 id="designationModalTitle" class="text-lg font-bold text-slate-800">Add Designation</h3>
        <button type="button" onclick="closeDesignationModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="designationForm" method="POST" action="designations.php" class="p-6 space-y-5">
        <input type="hidden" name="action" id="formAction" value="create_designation" />
        <input type="hidden" name="designation_id" id="formDesignationId" value="" />

        <div class="space-y-2">
          <label for="designation_name" class="block text-sm font-semibold text-slate-700">
            Designation Name <span class="text-[#E5097F]">*</span>
          </label>
          <input type="text" name="designation_name" id="designation_name" required
                 placeholder="e.g. Principal"
                 class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                        placeholder-slate-400
                        focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                        hover:border-[#4A154B]/40 transition-all" />
        </div>

        <div class="space-y-2">
          <label for="description" class="block text-sm font-semibold text-slate-700">
            Description
          </label>
          <textarea name="description" id="description" rows="3"
                    placeholder="e.g. Head of the institution"
                    class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                           placeholder-slate-400 resize-none
                           focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                           hover:border-[#4A154B]/40 transition-all"></textarea>
        </div>

        <div class="space-y-2">
          <label for="post_occupied" class="block text-sm font-semibold text-slate-700">
            Post Occupied <span class="text-[#E5097F]">*</span>
          </label>
          <select name="post_occupied" id="post_occupied" required
                  class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl appearance-none bg-white text-slate-800 font-medium
                         focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                         hover:border-[#4A154B]/40 transition-all">
            <option value="Teaching">Teaching</option>
            <option value="Non Teaching">Non Teaching</option>
          </select>
        </div>

        <div class="space-y-2" id="statusFieldWrapper" style="display: none;">
          <label for="status" class="block text-sm font-semibold text-slate-700">
            Status <span class="text-[#E5097F]">*</span>
          </label>
          <select name="status" id="status"
                  class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl appearance-none bg-white text-slate-800 font-medium
                         focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                         hover:border-[#4A154B]/40 transition-all">
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </div>

        <div class="flex justify-center pt-3 gap-3">
          <button type="button"
                  onclick="closeDesignationModal()"
                  class="px-6 py-3 rounded-xl font-semibold text-slate-700
                         bg-slate-100 hover:bg-slate-200 border border-slate-200
                         transition-all duration-200 active:scale-95">
            Cancel
          </button>
          <button type="submit"
                  class="px-8 py-3 rounded-xl
                         bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]
                         hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A]
                         text-white font-bold shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                         transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
            Save
          </button>
        </div>
      </form>

    </div>
  </div>

  <!-- ============================================================
       VIEW DESIGNATION MODAL
       ============================================================ -->
  <div id="viewModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeViewModal()"></div>

    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="text-lg font-bold text-slate-800">Designation Details</h3>
        <button type="button" onclick="closeViewModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="p-6 space-y-4">

        <div class="flex items-center space-x-4 pb-4 border-b border-slate-100">
          <div class="w-14 h-14 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E] flex items-center justify-center text-white shadow-md">
            <i data-lucide="briefcase" class="w-7 h-7"></i>
          </div>
          <div class="min-w-0">
            <p id="viewDesignationName" class="text-base font-bold text-slate-800 break-words">—</p>
            <p id="viewPostOccupied" class="text-xs text-slate-500 truncate">—</p>
          </div>
        </div>

        <div class="space-y-3">
          <div class="flex items-start gap-3">
            <i data-lucide="file-text" class="w-4 h-4 text-[#8B1E7E] mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Description</p>
              <p id="viewDescription" class="text-sm text-slate-700 break-words">—</p>
            </div>
          </div>

          <div class="flex items-start gap-3">
            <i data-lucide="badge-check" class="w-4 h-4 text-[#8B1E7E] mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Status</p>
              <p id="viewStatus" class="text-sm text-slate-700">—</p>
            </div>
          </div>
        </div>

      </div>

      <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end">
        <button type="button" onclick="closeViewModal()"
                class="px-5 py-2.5 rounded-xl font-semibold text-slate-700
                       bg-white hover:bg-slate-100 border border-slate-200
                       transition-all duration-200 active:scale-95">
          Close
        </button>
      </div>

    </div>
  </div>

  <!-- ============================================================= -->
  <!-- CUSTOM DELETE CONFIRMATION MODAL                              -->
  <!-- ============================================================= -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>

    <div id="deleteConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4
                    bg-gradient-to-br from-red-100 to-pink-100 ring-4 ring-red-50">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 class="text-xl font-bold text-slate-800 mb-2">Delete Designation?</h3>

        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to permanently delete
          <span id="deleteDesignationNameDisplay"
                class="font-bold text-[#8B1E7E] break-words">this designation</span>.
        </p>

        <p class="text-xs text-red-500 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>
          This action cannot be undone.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button"
                onclick="closeDeleteModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700
                       bg-slate-100 hover:bg-slate-200 border border-slate-200
                       transition-all duration-200 active:scale-95">
          Cancel
        </button>

        <button type="button"
                id="confirmDeleteBtn"
                class="flex-1 px-5 py-3 rounded-xl font-bold text-white
                       bg-gradient-to-r from-red-500 via-red-600 to-rose-600
                       hover:from-red-600 hover:via-red-700 hover:to-rose-700
                       shadow-lg shadow-red-500/30 hover:shadow-red-500/50
                       transition-all duration-300 hover:-translate-y-0.5 active:scale-95
                       flex items-center justify-center gap-2">
          <i data-lucide="trash-2" class="w-4 h-4"></i>
          <span>Delete</span>
        </button>
      </div>

    </div>
  </div>

  <!-- ============================================================= -->
  <!-- CUSTOM TOGGLE STATUS CONFIRMATION MODAL                       -->
  <!-- ============================================================= -->
  <div id="toggleConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeToggleModal()"></div>

    <div id="toggleConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div id="toggleIconWrapper"
             class="w-16 h-16 rounded-full flex items-center justify-center mb-4
                    bg-gradient-to-br from-purple-100 to-pink-100 ring-4 ring-purple-50">
          <i id="toggleIcon" data-lucide="power" class="w-8 h-8 text-[#8B1E7E]"></i>
        </div>

        <h3 id="toggleModalTitle" class="text-xl font-bold text-slate-800 mb-2">Change Status?</h3>

        <p id="toggleModalDescription" class="text-sm text-slate-500 leading-relaxed">
          The status of
          <span id="toggleDesignationNameDisplay"
                class="font-bold text-[#8B1E7E] break-words">this designation</span>
          will be changed.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button"
                onclick="closeToggleModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700
                       bg-slate-100 hover:bg-slate-200 border border-slate-200
                       transition-all duration-200 active:scale-95">
          Cancel
        </button>

        <button type="button"
                id="confirmToggleBtn"
                class="flex-1 px-5 py-3 rounded-xl font-bold text-white
                       bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]
                       hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A]
                       shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                       transition-all duration-300 hover:-translate-y-0.5 active:scale-95
                       flex items-center justify-center gap-2">
          <i data-lucide="refresh-cw" class="w-4 h-4"></i>
          <span id="confirmToggleBtnLabel">Confirm</span>
        </button>
      </div>

    </div>
  </div>

  <!-- HIDDEN FORMS -->
  <form id="deleteForm" method="POST" action="designations.php" class="hidden">
    <input type="hidden" name="action" value="delete_designation" />
    <input type="hidden" name="designation_id" id="deleteDesignationId" value="" />
  </form>

  <form id="toggleForm" method="POST" action="designations.php" class="hidden">
    <input type="hidden" name="action" value="toggle_status" />
    <input type="hidden" name="designation_id" id="toggleDesignationId" value="" />
  </form>

  <script>
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }

    // ---- Auto-dismiss flash messages after 3 seconds ----
    (function () {
      const flashBoxes = [
        document.getElementById('flashSuccessBox'),
        document.getElementById('flashErrorBox'),
      ];

      flashBoxes.forEach(function (box) {
        if (!box) return;

        setTimeout(function () {
          box.classList.remove('animate-flash-in');
          box.classList.add('animate-flash-out');

          setTimeout(function () {
            if (box && box.parentNode) {
              box.parentNode.removeChild(box);
            }
          }, 500);
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

    // ---- Logout confirmation ----
    (function () {
      const logoutButtons = [
        document.getElementById('sidebarLogoutBtn'),
        document.getElementById('dropdownLogoutBtn'),
      ];
      logoutButtons.forEach(function (btn) {
        if (!btn) return;
        btn.addEventListener('click', function (e) {
          const confirmed = window.confirm('Are you sure you want to log out?');
          if (!confirmed) {
            e.preventDefault();
            e.stopPropagation();
            return false;
          }
          btn.classList.add('opacity-50', 'pointer-events-none');
        });
      });
    })();

    // ---- Designation Add/Edit Modal ----
    const designationModal      = document.getElementById('designationModal');
    const designationModalTitle = document.getElementById('designationModalTitle');
    const designationForm       = document.getElementById('designationForm');
    const formAction            = document.getElementById('formAction');
    const formDesignationId     = document.getElementById('formDesignationId');
    const nameInput             = document.getElementById('designation_name');
    const descriptionInput      = document.getElementById('description');
    const postOccupiedInput     = document.getElementById('post_occupied');
    const statusInput           = document.getElementById('status');
    const statusFieldWrapper    = document.getElementById('statusFieldWrapper');

    function openDesignationModal(mode, id, name, description, postOccupied, status) {
      designationModal.classList.remove('hidden');

      if (mode === 'edit') {
        designationModalTitle.textContent = 'Edit Designation';
        formAction.value        = 'edit_designation';
        formDesignationId.value = id || '';
        nameInput.value         = name        || '';
        descriptionInput.value  = description || '';
        postOccupiedInput.value = postOccupied || 'Teaching';
        statusInput.value       = status       || 'Active';

        if (statusFieldWrapper) {
          statusFieldWrapper.style.display = '';
        }
        if (statusInput) {
          statusInput.setAttribute('required', 'required');
        }
      } else {
        designationModalTitle.textContent = 'Add Designation';
        formAction.value        = 'create_designation';
        formDesignationId.value = '';
        designationForm.reset();
        postOccupiedInput.value = 'Teaching';

        if (statusFieldWrapper) {
          statusFieldWrapper.style.display = 'none';
        }
        if (statusInput) {
          statusInput.removeAttribute('required');
        }
      }

      setTimeout(() => nameInput && nameInput.focus(), 50);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeDesignationModal() {
      designationModal.classList.add('hidden');
      designationForm.reset();
      formAction.value        = 'create_designation';
      formDesignationId.value = '';
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && designationModal && !designationModal.classList.contains('hidden')) {
        closeDesignationModal();
      }
    });

    // ---- View Modal ----
    const viewModal            = document.getElementById('viewModal');
    const viewDesignationName  = document.getElementById('viewDesignationName');
    const viewPostOccupied     = document.getElementById('viewPostOccupied');
    const viewDescription      = document.getElementById('viewDescription');
    const viewStatus           = document.getElementById('viewStatus');

    function openViewModal(name, description, postOccupied, status) {
      viewDesignationName.textContent = name        || '—';
      viewPostOccupied.textContent    = postOccupied || '—';
      viewDescription.textContent     = description || '—';
      viewStatus.textContent          = status      || '—';

      viewModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeViewModal() {
      viewModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && viewModal && !viewModal.classList.contains('hidden')) {
        closeViewModal();
      }
    });

    // ---- Delete Confirmation Modal ----
    const deleteConfirmModal         = document.getElementById('deleteConfirmModal');
    const deleteConfirmPanel         = document.getElementById('deleteConfirmPanel');
    const deleteDesignationNameEl    = document.getElementById('deleteDesignationNameDisplay');
    const confirmDeleteBtn           = document.getElementById('confirmDeleteBtn');

    let pendingDeleteId = null;

    function confirmDeleteDesignation(designationId, designationName) {
      pendingDeleteId = designationId;

      if (deleteDesignationNameEl) {
        deleteDesignationNameEl.textContent = '"' + designationName + '"';
      }

      deleteConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      if (deleteConfirmPanel) {
        deleteConfirmPanel.classList.remove('animate-confirm-shake');
        void deleteConfirmPanel.offsetWidth;
        deleteConfirmPanel.classList.add('animate-confirm-shake');
      }

      setTimeout(function () {
        if (confirmDeleteBtn) confirmDeleteBtn.focus();
      }, 80);

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeDeleteModal() {
      deleteConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      pendingDeleteId = null;
    }

    if (confirmDeleteBtn) {
      confirmDeleteBtn.addEventListener('click', function () {
        if (pendingDeleteId === null || pendingDeleteId === undefined) {
          closeDeleteModal();
          return;
        }

        const delIdInput = document.getElementById('deleteDesignationId');
        const delForm    = document.getElementById('deleteForm');

        if (delIdInput && delForm) {
          delIdInput.value = String(pendingDeleteId);
          delForm.submit();
        } else {
          closeDeleteModal();
        }
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && deleteConfirmModal && !deleteConfirmModal.classList.contains('hidden')) {
        closeDeleteModal();
      }
    });

    // ---- Toggle Status Confirmation Modal ----
    const toggleConfirmModal     = document.getElementById('toggleConfirmModal');
    const toggleConfirmPanel     = document.getElementById('toggleConfirmPanel');
    const toggleModalTitle       = document.getElementById('toggleModalTitle');
    const toggleModalDescription = document.getElementById('toggleModalDescription');
    const toggleDesignationNameEl= document.getElementById('toggleDesignationNameDisplay');
    const toggleIcon             = document.getElementById('toggleIcon');
    const toggleIconWrapper      = document.getElementById('toggleIconWrapper');
    const confirmToggleBtn       = document.getElementById('confirmToggleBtn');
    const confirmToggleBtnLabel  = document.getElementById('confirmToggleBtnLabel');

    let pendingToggleId     = null;
    let pendingToggleAction = '';

    function confirmToggleStatus(designationId, designationName, currentStatus) {
      pendingToggleId = designationId;

      const isCurrentlyActive = (String(currentStatus).toLowerCase() === 'active');
      const newStatus         = isCurrentlyActive ? 'Inactive' : 'Active';
      pendingToggleAction     = newStatus;

      if (toggleDesignationNameEl) {
        toggleDesignationNameEl.textContent = '"' + designationName + '"';
      }

      if (isCurrentlyActive) {
        // Deactivating
        if (toggleModalTitle) toggleModalTitle.textContent = 'Deactivate Designation?';
        if (toggleModalDescription) {
          toggleModalDescription.innerHTML = 'The designation <span id="toggleDesignationNameDisplay" class="font-bold text-[#8B1E7E] break-words">"' + designationName + '"</span> will be marked as <span class="font-bold text-[#8B1E7E]">Inactive</span>.';
        }
        if (confirmToggleBtnLabel) confirmToggleBtnLabel.textContent = 'Deactivate';
        if (toggleIcon) toggleIcon.setAttribute('data-lucide', 'power-off');
      } else {
        // Activating
        if (toggleModalTitle) toggleModalTitle.textContent = 'Activate Designation?';
        if (toggleModalDescription) {
          toggleModalDescription.innerHTML = 'The designation <span id="toggleDesignationNameDisplay" class="font-bold text-[#8B1E7E] break-words">"' + designationName + '"</span> will be marked as <span class="font-bold text-[#8B1E7E]">Active</span>.';
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

      setTimeout(function () {
        if (confirmToggleBtn) confirmToggleBtn.focus();
      }, 80);

      if (typeof lucide !== 'undefined') {
        lucide.createIcons();
      }
    }

    function closeToggleModal() {
      toggleConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      pendingToggleId     = null;
      pendingToggleAction = '';
    }

    if (confirmToggleBtn) {
      confirmToggleBtn.addEventListener('click', function () {
        if (pendingToggleId === null || pendingToggleId === undefined) {
          closeToggleModal();
          return;
        }

        const toggleIdInput = document.getElementById('toggleDesignationId');
        const toggleForm    = document.getElementById('toggleForm');

        if (toggleIdInput && toggleForm) {
          toggleIdInput.value = String(pendingToggleId);
          toggleForm.submit();
        } else {
          closeToggleModal();
        }
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && toggleConfirmModal && !toggleConfirmModal.classList.contains('hidden')) {
        closeToggleModal();
      }
    });

    // ---- Live Search ----
    (function () {
      const searchInput = document.getElementById('searchInput');
      const tableBody   = document.getElementById('designationsTableBody');

      if (!searchInput || !tableBody) return;

      searchInput.addEventListener('input', function () {
        const term = this.value.toLowerCase().trim();
        const rows = tableBody.querySelectorAll('tr');

        rows.forEach(function (row) {
          const text = row.textContent.toLowerCase();
          row.style.display = (term === '' || text.indexOf(term) !== -1) ? '' : 'none';
        });
      });
    })();
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>