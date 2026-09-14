<?php
/**
 * admin/grievance_types.php
 * ---------------------------------------------------------------------------
 * Admin — Grievance Type Management
 * Rajagiri College Grievance Redressal Portal
 *
 * Features:
 *   • View all grievance types in a searchable table
 *   • Create / Edit / Delete grievance types
 *   • View details modal for each grievance type
 *   • FK-safe delete (checks grievances + cell_members references)
 *   • Live search + entries-per-page dropdown
 *   • Themed delete confirmation modal
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

if (empty($_SESSION['user_id']) || ($sessionRole !== 'ADMIN' && $sessionRole !== 'MANAGEMENT')) {
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
// 5. CSRF TOKEN
// ---------------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $ex) {
        $_SESSION['csrf_token'] = md5(uniqid((string) mt_rand(), true));
    }
}
$csrfToken = (string) $_SESSION['csrf_token'];

// ---------------------------------------------------------------------------
// 6. FETCH ADMIN PROFILE
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
        error_log('[Grievance Types Admin Profile] ' . $ex->getMessage());
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
// 7. HANDLE FORM SUBMISSIONS (POST)
// ---------------------------------------------------------------------------
$flashSuccess = '';
$flashError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    // ---- CSRF check ----
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        $flashError = 'Invalid session token. Please try again.';
    } else {

        $action = $_POST['action'] ?? '';

        // -------- CREATE GRIEVANCE TYPE --------
        if ($action === 'create_type') {
            $typeName    = trim((string) ($_POST['type_name']   ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));

            if ($typeName === '') {
                $flashError = 'Grievance type name is required.';
            } else {
                try {
                    // Duplicate check
                    $chk = $conn->prepare("SELECT id FROM grievance_types WHERE type_name = ? LIMIT 1");
                    $chk->bind_param('s', $typeName);
                    $chk->execute();
                    if ($chk->get_result()->num_rows > 0) {
                        $chk->close();
                        throw new Exception('A grievance type with this name already exists.');
                    }
                    $chk->close();

                    $stmt = $conn->prepare("INSERT INTO grievance_types (type_name, description, status) VALUES (?, ?, 'Active')");
                    $stmt->bind_param('ss', $typeName, $description);
                    if ($stmt->execute()) {
                        $flashSuccess = 'Grievance type created successfully.';
                    } else {
                        $flashError = 'Failed to create grievance type.';
                    }
                    $stmt->close();
                } catch (Throwable $ex) {
                    error_log('[Create Grievance Type] ' . $ex->getMessage());
                    $flashError = $ex->getMessage() ?: 'A system error occurred while creating the grievance type.';
                }
            }
        }

        // -------- EDIT GRIEVANCE TYPE --------
        if ($action === 'edit_type') {
            $typeId      = (int) ($_POST['type_id']      ?? 0);
            $typeName    = trim((string) ($_POST['type_name']   ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $status      = trim((string) ($_POST['status']      ?? 'Active'));

            if (!in_array($status, ['Active', 'Inactive'], true)) {
                $status = 'Active';
            }

            if ($typeId <= 0) {
                $flashError = 'Invalid grievance type.';
            } elseif ($typeName === '') {
                $flashError = 'Grievance type name is required.';
            } else {
                try {
                    // Duplicate check (excluding self)
                    $chk = $conn->prepare("SELECT id FROM grievance_types WHERE type_name = ? AND id != ? LIMIT 1");
                    $chk->bind_param('si', $typeName, $typeId);
                    $chk->execute();
                    if ($chk->get_result()->num_rows > 0) {
                        $chk->close();
                        throw new Exception('Another grievance type with this name already exists.');
                    }
                    $chk->close();

                    $stmt = $conn->prepare("UPDATE grievance_types SET type_name = ?, description = ?, status = ? WHERE id = ?");
                    $stmt->bind_param('sssi', $typeName, $description, $status, $typeId);
                    if ($stmt->execute()) {
                        $flashSuccess = 'Grievance type updated successfully.';
                    } else {
                        $flashError = 'Failed to update grievance type.';
                    }
                    $stmt->close();
                } catch (Throwable $ex) {
                    error_log('[Edit Grievance Type] ' . $ex->getMessage());
                    $flashError = $ex->getMessage() ?: 'A system error occurred while updating the grievance type.';
                }
            }
        }

        // -------- DELETE (with FK safety) --------
        if ($action === 'delete_type') {
            $typeId = (int) ($_POST['type_id'] ?? 0);
            if ($typeId > 0) {
                try {
                    // FK safety: check for references in grievances
                    $chkG = $conn->prepare("SELECT COUNT(*) AS c FROM grievances WHERE grievance_type_id = ?");
                    $chkG->bind_param('i', $typeId);
                    $chkG->execute();
                    $grievanceRefs = (int) ($chkG->get_result()->fetch_assoc()['c'] ?? 0);
                    $chkG->close();

                    // FK safety: check for references in cell_members
                    $chkC = $conn->prepare("SELECT COUNT(*) AS c FROM cell_members WHERE grievance_type_id = ?");
                    $chkC->bind_param('i', $typeId);
                    $chkC->execute();
                    $cellRefs = (int) ($chkC->get_result()->fetch_assoc()['c'] ?? 0);
                    $chkC->close();

                    if ($grievanceRefs > 0 || $cellRefs > 0) {
                        // Safe alternative: mark as Inactive instead of deleting
                        $stmtU = $conn->prepare("UPDATE grievance_types SET status = 'Inactive' WHERE id = ?");
                        $stmtU->bind_param('i', $typeId);
                        $stmtU->execute();
                        $stmtU->close();

                        $parts = [];
                        if ($grievanceRefs > 0) $parts[] = $grievanceRefs . ' grievance(s)';
                        if ($cellRefs > 0)      $parts[] = $cellRefs . ' member(s)';
                        $refsSummary = implode(' and ', $parts);

                        $flashSuccess = 'This grievance type is referenced by ' . $refsSummary .
                            ' and cannot be deleted. It has been marked as Inactive instead.';
                    } else {
                        $stmt = $conn->prepare("DELETE FROM grievance_types WHERE id = ?");
                        $stmt->bind_param('i', $typeId);
                        if ($stmt->execute()) {
                            $flashSuccess = 'Grievance type deleted successfully.';
                        } else {
                            $flashError = 'Failed to delete grievance type.';
                        }
                        $stmt->close();
                    }
                } catch (Throwable $ex) {
                    error_log('[Delete Grievance Type] ' . $ex->getMessage());
                    $flashError = 'A system error occurred while deleting the grievance type.';
                }
            }
        }
    }

    if ($flashSuccess !== '' || $flashError !== '') {
        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: grievance_types.php');
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
// 8. SEARCH + FETCH GRIEVANCE TYPES
// ---------------------------------------------------------------------------
$search       = trim((string) ($_GET['q'] ?? ''));
$types = [];

if ($conn instanceof mysqli) {
    try {
        if ($search !== '') {
            $sql = "SELECT id, type_name, description, status, created_at
                    FROM grievance_types
                    WHERE type_name LIKE ? OR description LIKE ?
                    ORDER BY id ASC";
            $like = '%' . $search . '%';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $like, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $types[] = $row;
            $stmt->close();
        } else {
            $res = $conn->query("SELECT id, type_name, description, status, created_at FROM grievance_types ORDER BY id ASC");
            if ($res) {
                while ($row = $res->fetch_assoc()) $types[] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Grievance Types] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 9. HELPER — TRUNCATE DESCRIPTION
// ---------------------------------------------------------------------------
function truncateText(?string $text, int $limit = 60): string
{
    $text = trim((string) $text);
    if ($text === '') return '';
    if (mb_strlen($text, 'UTF-8') <= $limit) return $text;
    return mb_substr($text, 0, $limit, 'UTF-8') . '…';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Grievance Type List — Admin | Rajagiri College Grievance Portal</title>
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

        <!-- Breadcrumb + Action Button -->
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

            <div>
              <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2 tracking-tight">
                Grievance Type List
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
                <span class="text-[#E5097F] font-semibold">Grievance Type</span>
              </nav>
            </div>

            <button type="button"
                    onclick="openTypeModal('add')"
                    title="Add Grievance Type"
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

        <!-- TABLE CONTROLS -->
        <div class="max-w-6xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 60ms;">
          <form method="GET" action="grievance_types.php" id="filterForm" class="bg-white rounded-xl shadow-sm border border-slate-200/70 px-5 py-4">
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
              <table class="w-full" id="typesTable">
                <thead>
                  <tr class="bg-[#4A154B] text-white">
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Description</th>
                    <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="typesTableBody">

                  <?php if (empty($types)): ?>

                    <tr>
                      <td colspan="4" class="px-6 py-16 text-center text-slate-500">
                        <div class="flex flex-col items-center justify-center">
                          <div class="w-16 h-16 bg-purple-50 rounded-full flex items-center justify-center mb-4">
                            <i data-lucide="list-checks" class="w-8 h-8 text-[#8B1E7E]"></i>
                          </div>
                          <p class="text-lg font-semibold text-slate-700">No grievance types yet</p>
                          <p class="text-sm text-slate-500 mt-1 mb-4">
                            Click "Add Grievance Type" to create your first category.
                          </p>
                        </div>
                      </td>
                    </tr>

                  <?php else: ?>

                    <?php foreach ($types as $index => $type): ?>
                      <?php
                        $typeId       = (int) $type['id'];
                        $typeName     = (string) ($type['type_name']   ?? '');
                        $typeDesc     = (string) ($type['description'] ?? '');
                        $typeStatus   = (string) ($type['status']      ?? 'Active');

                        $shortDesc    = truncateText($typeDesc, 60);
                        $hasMoreDesc  = (mb_strlen($typeDesc, 'UTF-8') > 60);
                      ?>
                      <tr class="hover:bg-slate-50/80 transition-colors group align-top">
                        <td class="px-6 py-5 whitespace-nowrap text-sm font-medium text-slate-900">
                          <?= $index + 1 ?>
                        </td>
                        <td class="px-6 py-5 text-sm font-semibold text-slate-800 max-w-[320px]">
                          <?= e($typeName) ?>
                        </td>
                        <td class="px-6 py-5 text-sm text-slate-600 max-w-[420px]">
                          <?php if ($shortDesc !== ''): ?>
                            <span><?= e($shortDesc) ?></span>
                            <?php if ($hasMoreDesc): ?>
                              <div class="mt-1">
                                <button type="button"
                                        onclick='openViewModal(<?= json_encode($typeName) ?>, <?= json_encode($typeDesc) ?>, <?= json_encode($typeStatus) ?>)'
                                        class="text-xs font-semibold text-[#E5097F] hover:text-[#C41574] transition-colors inline-flex items-center gap-1">
                                  View More
                                  <i data-lucide="arrow-right" class="w-3 h-3"></i>
                                </button>
                              </div>
                            <?php endif; ?>
                          <?php else: ?>
                            <span class="text-slate-400 italic">No description</span>
                          <?php endif; ?>
                        </td>
                        <td class="px-6 py-5 whitespace-nowrap">
                          <div class="flex items-center justify-center gap-2">

                            <!-- Edit -->
                            <button type="button"
                                    title="Edit grievance type"
                                    onclick='openTypeModal("edit", <?= $typeId ?>, <?= json_encode($typeName) ?>, <?= json_encode($typeDesc) ?>, <?= json_encode($typeStatus) ?>)'
                                    class="w-9 h-9 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                           flex items-center justify-center text-[#4A154B] hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="pencil" class="w-4 h-4"></i>
                            </button>

                            <!-- View -->
                            <button type="button"
                                    title="View grievance type"
                                    onclick='openViewModal(<?= json_encode($typeName) ?>, <?= json_encode($typeDesc) ?>, <?= json_encode($typeStatus) ?>)'
                                    class="w-9 h-9 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                           flex items-center justify-center text-[#4A154B] hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>

                            <!-- Delete -->
                            <button type="button"
                                    title="Delete grievance type"
                                    onclick='confirmDelete(<?= $typeId ?>, <?= json_encode($typeName) ?>)'
                                    class="w-9 h-9 rounded-full bg-purple-50 hover:bg-red-500
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

            <!-- Footer Info & Pagination -->
            <?php if (!empty($types)): ?>
              <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">

                <p class="text-sm text-slate-600" id="tableInfo">
                  Showing <span class="font-semibold text-slate-900">1</span> to
                  <span class="font-semibold text-slate-900"><?= count($types) ?></span> of
                  <span class="font-semibold text-slate-900"><?= count($types) ?></span> entries
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

  <!-- ============================================================
       ADD / EDIT GRIEVANCE TYPE MODAL
       ============================================================ -->
  <div id="typeModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeTypeModal()"></div>

    <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 id="typeModalTitle" class="text-lg font-bold text-slate-800">Add Grievance Type</h3>
        <button type="button" onclick="closeTypeModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="typeForm" method="POST" action="grievance_types.php" class="p-6 space-y-5">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="action" id="formAction" value="create_type" />
        <input type="hidden" name="type_id" id="formTypeId" value="" />

        <div class="space-y-2">
          <label for="type_name" class="block text-sm font-semibold text-slate-700">
            Grievance Type Name <span class="text-[#E5097F]">*</span>
          </label>
          <input type="text" name="type_name" id="type_name" required
                 placeholder="e.g. Grievance related to Admission"
                 class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                        placeholder-slate-400
                        focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                        hover:border-[#4A154B]/40 transition-all" />
        </div>

        <div class="space-y-2">
          <label for="description" class="block text-sm font-semibold text-slate-700">
            Description
          </label>
          <textarea name="description" id="description" rows="4"
                    placeholder="Brief summary of what this grievance category covers…"
                    class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                           placeholder-slate-400 resize-none
                           focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                           hover:border-[#4A154B]/40 transition-all"></textarea>
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
                  onclick="closeTypeModal()"
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
       VIEW DETAILS MODAL
       ============================================================ -->
  <div id="viewModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeViewModal()"></div>

    <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="text-lg font-bold text-slate-800">Grievance Type Details</h3>
        <button type="button" onclick="closeViewModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="p-6 space-y-4">

        <div class="flex items-center space-x-4 pb-4 border-b border-slate-100">
          <div class="w-14 h-14 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E] flex items-center justify-center text-white shadow-md">
            <i data-lucide="list-checks" class="w-7 h-7"></i>
          </div>
          <div class="min-w-0">
            <p id="viewTypeName" class="text-base font-bold text-slate-800 break-words">—</p>
            <p id="viewTypeStatus" class="text-xs text-slate-500">—</p>
          </div>
        </div>

        <div class="space-y-3">
          <div class="flex items-start gap-3">
            <i data-lucide="file-text" class="w-4 h-4 text-[#8B1E7E] mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Description</p>
              <p id="viewTypeDesc" class="text-sm text-slate-700 break-words leading-relaxed">—</p>
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

        <h3 class="text-xl font-bold text-slate-800 mb-2">Delete Grievance Type?</h3>

        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to permanently delete
          <span id="deleteTypeNameDisplay" class="font-bold text-[#8B1E7E] break-words">this grievance type</span>.
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

  <!-- HIDDEN FORM -->
  <form id="deleteForm" method="POST" action="grievance_types.php" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
    <input type="hidden" name="action" value="delete_type" />
    <input type="hidden" name="type_id" id="deleteTypeId" value="" />
  </form>

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
    // ADD / EDIT TYPE MODAL
    // ============================================================
    const typeModal      = document.getElementById('typeModal');
    const typeModalTitle = document.getElementById('typeModalTitle');
    const typeForm       = document.getElementById('typeForm');
    const formAction     = document.getElementById('formAction');
    const formTypeId     = document.getElementById('formTypeId');
    const typeNameInput  = document.getElementById('type_name');
    const descriptionInput = document.getElementById('description');
    const statusInput    = document.getElementById('status');
    const statusFieldWrapper = document.getElementById('statusFieldWrapper');

    function openTypeModal(mode, id, name, description, status) {
      typeModal.classList.remove('hidden');

      if (mode === 'edit') {
        typeModalTitle.textContent = 'Edit Grievance Type';
        formAction.value  = 'edit_type';
        formTypeId.value  = id || '';
        typeNameInput.value = name || '';
        descriptionInput.value = description || '';
        statusInput.value = status || 'Active';

        if (statusFieldWrapper) statusFieldWrapper.style.display = '';
        if (statusInput) statusInput.setAttribute('required', 'required');
      } else {
        typeModalTitle.textContent = 'Add Grievance Type';
        formAction.value  = 'create_type';
        formTypeId.value  = '';
        typeForm.reset();

        if (statusFieldWrapper) statusFieldWrapper.style.display = 'none';
        if (statusInput) statusInput.removeAttribute('required');
      }

      setTimeout(() => typeNameInput && typeNameInput.focus(), 50);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeTypeModal() {
      typeModal.classList.add('hidden');
      typeForm.reset();
      formAction.value = 'create_type';
      formTypeId.value = '';
    }

    // ============================================================
    // VIEW DETAILS MODAL
    // ============================================================
    const viewModal       = document.getElementById('viewModal');
    const viewTypeName    = document.getElementById('viewTypeName');
    const viewTypeStatus  = document.getElementById('viewTypeStatus');
    const viewTypeDesc    = document.getElementById('viewTypeDesc');

    function openViewModal(name, description, status) {
      viewTypeName.textContent   = name || '—';
      viewTypeStatus.textContent = status || '—';
      viewTypeDesc.textContent   = (description && description.trim() !== '') ? description : 'No description provided.';

      viewModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeViewModal() {
      viewModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    // ============================================================
    // DELETE MODAL
    // ============================================================
    const deleteConfirmModal = document.getElementById('deleteConfirmModal');
    const deleteConfirmPanel = document.getElementById('deleteConfirmPanel');
    const deleteTypeNameEl   = document.getElementById('deleteTypeNameDisplay');
    const confirmDeleteBtn   = document.getElementById('confirmDeleteBtn');
    let pendingDeleteId = null;

    function confirmDelete(typeId, typeName) {
      pendingDeleteId = typeId;
      if (deleteTypeNameEl) deleteTypeNameEl.textContent = '"' + typeName + '"';

      deleteConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      if (deleteConfirmPanel) {
        deleteConfirmPanel.classList.remove('animate-confirm-shake');
        void deleteConfirmPanel.offsetWidth;
        deleteConfirmPanel.classList.add('animate-confirm-shake');
      }

      setTimeout(function () { if (confirmDeleteBtn) confirmDeleteBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeDeleteModal() {
      deleteConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      pendingDeleteId = null;
    }

    if (confirmDeleteBtn) {
      confirmDeleteBtn.addEventListener('click', function () {
        if (pendingDeleteId === null) return closeDeleteModal();
        const input = document.getElementById('deleteTypeId');
        const form = document.getElementById('deleteForm');
        if (input && form) {
          input.value = String(pendingDeleteId);
          form.submit();
        }
      });
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

    // ============================================================
    // ENTRIES DROPDOWN + SEARCH
    // ============================================================
    (function () {
      const entriesSelect = document.getElementById('entriesPerPage');
      if (entriesSelect) {
        entriesSelect.addEventListener('change', function () {
          const limit = parseInt(entriesSelect.value, 10) || 10;
          const rows = document.querySelectorAll('#typesTableBody tr');
          rows.forEach(function (row, idx) {
            row.style.display = (idx < limit) ? '' : 'none';
          });
        });
      }
    })();

    (function () {
      const searchInput = document.getElementById('searchInput');
      const tableBody   = document.getElementById('typesTableBody');
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
        }, 700);
      });
    })();

    // ============================================================
    // ESCAPE KEY CLOSES ANY OPEN MODAL
    // ============================================================
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (typeModal && !typeModal.classList.contains('hidden')) closeTypeModal();
      if (viewModal && !viewModal.classList.contains('hidden')) closeViewModal();
      if (deleteConfirmModal && !deleteConfirmModal.classList.contains('hidden')) closeDeleteModal();
      if (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) closeLogoutModal();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>