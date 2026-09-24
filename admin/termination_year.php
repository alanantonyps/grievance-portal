<?php
/**
 * admin/termination_year.php
 * ---------------------------------------------------------------------------
 * Admin — Termination Year (List of Terminated Users)
 * Rajagiri College Grievance Redressal Portal
 *
 * Filter Logic (Submit = refresh the list):
 *   • Member Type    → users.role   (STUDENT, PARENT, TEACHER, NON_TEACHING, MANAGEMENT)
 *   • Class/Semester → classes.id   (joined via students.class_id)
 *
 * Only users with status = 'Terminated' are shown.
 *
 * Row Actions:
 *   • Reactivate → sets users.status = 'Approved'
 *   • Delete     → permanently removes the user (and role-specific row)
 *
 * Includes themed logout confirmation modal.
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
        error_log('[Termination Year Admin Profile] ' . $ex->getMessage());
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
// 6. FETCH CLASSES FOR DROPDOWN
// ---------------------------------------------------------------------------
$classOptions = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, class_name FROM classes ORDER BY class_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) $classOptions[] = $row;
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Classes] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 7. HANDLE ROW ACTIONS (POST)
// ---------------------------------------------------------------------------
$flashSuccess = '';
$flashError   = '';

$validMemberTypes = ['STUDENT', 'PARENT', 'TEACHER', 'NON_TEACHING', 'MANAGEMENT'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    $action = $_POST['action'] ?? '';

    // -------- REACTIVATE SINGLE USER --------
    if ($action === 'reactivate_user') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        if ($targetUserId > 0) {
            try {
                $stmt = $conn->prepare("UPDATE users SET status = 'Approved' WHERE id = ? AND status = 'Terminated'");
                $stmt->bind_param('i', $targetUserId);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $flashSuccess = 'User has been reactivated successfully.';
                } else {
                    $flashError = 'Unable to reactivate this user. The account may not be terminated.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Reactivate User] ' . $ex->getMessage());
                $flashError = 'A system error occurred while reactivating the user.';
            }
        }

        $_SESSION['filter_member_type'] = (string) ($_POST['filter_member_type'] ?? 'ALL');
        $_SESSION['filter_class_id']    = (string) ($_POST['filter_class_id']    ?? 'ALL');

        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: termination_year.php');
        exit;
    }

    // -------- DELETE SINGLE USER --------
    if ($action === 'delete_user') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        if ($targetUserId > 0) {
            try {
                $conn->begin_transaction();

                $conn->query("DELETE FROM students     WHERE user_id = " . $targetUserId);
                $conn->query("DELETE FROM parents      WHERE user_id = " . $targetUserId);
                $conn->query("DELETE FROM cell_members WHERE user_id = " . $targetUserId);

                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param('i', $targetUserId);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $flashSuccess = 'User has been deleted successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Delete User] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the user.';
            }
        }

        $_SESSION['filter_member_type'] = (string) ($_POST['filter_member_type'] ?? 'ALL');
        $_SESSION['filter_class_id']    = (string) ($_POST['filter_class_id']    ?? 'ALL');

        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: termination_year.php');
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
// 8. FILTER VALUES (GET) + restore from session (after POST)
// ---------------------------------------------------------------------------
$filterMemberType = 'ALL';
$filterClassId    = 'ALL';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (!empty($_GET['member_type']) || !empty($_GET['class_id']))) {
    $filterMemberType = trim((string) ($_GET['member_type'] ?? 'ALL'));
    $filterClassId    = trim((string) ($_GET['class_id']    ?? 'ALL'));
} else {
    if (!empty($_SESSION['filter_member_type'])) {
        $filterMemberType = (string) $_SESSION['filter_member_type'];
        unset($_SESSION['filter_member_type']);
    }
    if (!empty($_SESSION['filter_class_id'])) {
        $filterClassId = (string) $_SESSION['filter_class_id'];
        unset($_SESSION['filter_class_id']);
    }
}

if ($filterMemberType !== 'ALL' && !in_array($filterMemberType, $validMemberTypes, true)) {
    $filterMemberType = 'ALL';
}
$filterClassIdInt = ($filterClassId !== 'ALL' && ctype_digit($filterClassId)) ? (int) $filterClassId : 0;

// ---------------------------------------------------------------------------
// 9. FETCH TERMINATED USERS
// ---------------------------------------------------------------------------
$users = [];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.id AS user_id,
                        u.username,
                        u.role,
                        u.status,
                        COALESCE(s.name,   p.name,   cm.name)   AS name,
                        COALESCE(s.email,  p.email,  cm.email)  AS email,
                        COALESCE(s.address, 'N/A')              AS address,
                        s.class_id,
                        c.class_name
                FROM users u
                LEFT JOIN students     s  ON u.id = s.user_id
                LEFT JOIN parents      p  ON u.id = p.user_id
                LEFT JOIN cell_members cm ON u.id = cm.user_id
                LEFT JOIN classes      c  ON s.class_id = c.id
                WHERE u.role != 'ADMIN'
                  AND u.status = 'Terminated'";

        $params = [];
        $types  = '';

        if ($filterMemberType !== 'ALL') {
            $sql .= " AND u.role = ?";
            $params[] = $filterMemberType;
            $types   .= 's';
        }

        if ($filterClassIdInt > 0) {
            $sql .= " AND s.class_id = ?";
            $params[] = $filterClassIdInt;
            $types   .= 'i';
        }

        $sql .= " ORDER BY u.id DESC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $users[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Terminated Users] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Termination Year — Admin | Rajagiri College Grievance Portal</title>
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
        <a href="dashboard.php" class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110" title="Dashboard">
          <i data-lucide="home" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Dashboard</span>
        </a>
        <a href="profile.php" class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110" title="Profile">
          <i data-lucide="user" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Profile</span>
        </a>
        <a href="members.php" class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110" title="Back to Members">
          <i data-lucide="users" class="w-6 h-6 group-hover:scale-110 transition-transform duration-300"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Back to Members</span>
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
              <img src="../public/rcss-logo.png" alt="RCSS Logo" class="h-10 md:h-11 w-auto transition-transform group-hover:scale-105" />
            </a>
            <div class="hidden sm:flex items-center h-10"><div class="w-px h-full bg-gradient-to-b from-transparent via-slate-300 to-transparent"></div></div>
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
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E] flex items-center justify-center text-white"><i data-lucide="user" class="w-6 h-6 text-white"></i></div>
                  <?php endif; ?>
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-800 truncate"><?= e($displayName) ?></p>
                    <p class="text-xs text-slate-500 truncate"><?= e($displayEmail) ?></p>
                  </div>
                </div>
              </div>

              <a href="dashboard.php" class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="layout-dashboard" class="w-4 h-4 mr-3 text-[#8B1E7E]"></i><span class="font-medium">Dashboard</span>
              </a>
              <a href="profile.php" class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="user" class="w-4 h-4 mr-3 text-[#8B1E7E]"></i><span class="font-medium">My Profile</span>
              </a>
              <a href="change_password.php" class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="key" class="w-4 h-4 mr-3 text-[#8B1E7E]"></i><span class="font-medium">Change Password</span>
              </a>
              <div class="border-t border-slate-100 mt-2 pt-2">
                <a href="#" data-logout-trigger="1"
                   id="dropdownLogoutBtn"
                   class="flex items-center px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-all duration-200 group/item">
                  <i data-lucide="log-out" class="w-4 h-4 mr-3"></i><span class="font-medium">Logout</span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </header>

      <main class="flex-1 px-6 py-8">

        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up">
          <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2 tracking-tight">Termination Year</h1>
          <nav class="flex items-center space-x-2 text-sm text-slate-500">
            <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors">
              <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i>Dashboard
            </a>
            <span class="text-slate-300">/</span>
            <a href="members.php" class="hover:text-[#8B1E7E] transition-colors">Members</a>
            <span class="text-slate-300">/</span>
            <span class="text-[#E5097F] font-semibold">Termination Year</span>
          </nav>
        </div>

        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox" class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3 flex items-start space-x-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-[#006837] flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-emerald-800 font-medium"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox" class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3 flex items-start space-x-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-medium"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <div class="max-w-6xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 40ms;">
          <div class="bg-slate-100 border border-slate-200 rounded-xl px-5 py-5 shadow-sm">
            <form method="GET" action="termination_year.php" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">

              <div class="md:col-span-4">
                <label for="member_type" class="block text-sm font-semibold text-slate-700 mb-1.5">Member Type</label>
                <select name="member_type" id="member_type"
                        class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                               focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                               hover:border-[#4A154B]/40 transition-all">
                  <option value="ALL"          <?= $filterMemberType === 'ALL'          ? 'selected' : '' ?>>ALL</option>
                  <option value="STUDENT"      <?= $filterMemberType === 'STUDENT'      ? 'selected' : '' ?>>STUDENT</option>
                  <option value="PARENT"       <?= $filterMemberType === 'PARENT'       ? 'selected' : '' ?>>PARENT</option>
                  <option value="TEACHER"      <?= $filterMemberType === 'TEACHER'      ? 'selected' : '' ?>>TEACHER</option>
                  <option value="NON_TEACHING" <?= $filterMemberType === 'NON_TEACHING' ? 'selected' : '' ?>>NON TEACHING</option>
                  <option value="MANAGEMENT"   <?= $filterMemberType === 'MANAGEMENT'   ? 'selected' : '' ?>>MANAGEMENT</option>
                </select>
              </div>

              <div class="md:col-span-5">
                <label for="class_id" class="block text-sm font-semibold text-slate-700 mb-1.5">Class/Semester</label>
                <select name="class_id" id="class_id"
                        class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium
                               focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                               hover:border-[#4A154B]/40 transition-all">
                  <option value="ALL" <?= $filterClassId === 'ALL' ? 'selected' : '' ?>>ALL</option>
                  <?php foreach ($classOptions as $cls): ?>
                    <option value="<?= (int) $cls['id'] ?>" <?= (string) $filterClassId === (string) $cls['id'] ? 'selected' : '' ?>>
                      <?= e($cls['class_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="md:col-span-3 flex md:justify-end">
                <button type="submit"
                        class="w-full md:w-auto px-8 py-2.5 rounded-lg
                               bg-[#4A154B] hover:bg-[#5A1B5C]
                               text-white font-semibold shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                               transition-all duration-300 active:scale-95">
                  Submit
                </button>
              </div>

            </form>
          </div>
        </div>

        <div class="max-w-6xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 80ms;">
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

        <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 120ms;">
          <div class="bg-white rounded-2xl shadow-lg border border-slate-200/70 overflow-hidden">
            <div class="overflow-x-auto">
              <table class="w-full" id="usersTable">
                <thead>
                  <tr class="bg-[#4A154B] text-white">
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Address</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Email Id</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Role</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Class</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                    <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="usersTableBody">

                  <?php if (empty($users)): ?>
                    <tr>
                      <td colspan="8" class="px-6 py-16 text-center text-slate-500">
                        <div class="flex flex-col items-center justify-center">
                          <div class="w-16 h-16 bg-purple-50 rounded-full flex items-center justify-center mb-4">
                            <i data-lucide="shield-check" class="w-8 h-8 text-[#8B1E7E]"></i>
                          </div>
                          <p class="text-lg font-semibold text-slate-700">No terminated users found</p>
                          <p class="text-sm text-slate-500 mt-1">Try adjusting the filters above.</p>
                        </div>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($users as $index => $user): ?>
                      <?php
                        $uId     = (int) $user['user_id'];
                        $uName   = (string) ($user['name']        ?? 'N/A');
                        $uEmail  = (string) ($user['email']       ?? 'N/A');
                        $uAddr   = (string) ($user['address']     ?? 'N/A');
                        $uRole   = (string) ($user['role']        ?? '');
                        $uClass  = (string) ($user['class_name']  ?? '—');
                        $uStatus = (string) ($user['status']      ?? '');
                        $statusCls = 'bg-slate-200 text-slate-700 border-slate-300';
                      ?>
                      <tr class="hover:bg-slate-50/80 transition-colors group">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900"><?= $index + 1 ?></td>
                        <td class="px-6 py-4 text-sm font-semibold text-slate-800"><?= e($uName) ?></td>
                        <td class="px-6 py-4 text-sm text-slate-600 max-w-[180px]"><?= e($uAddr) ?></td>
                        <td class="px-6 py-4 text-sm text-slate-600 break-all"><?= e($uEmail) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-700"><?= e($uRole) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600"><?= e($uClass) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <span class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full border <?= $statusCls ?>">
                            <?= e($uStatus) ?>
                          </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="flex items-center justify-center gap-2">

                            <button type="button"
                                    title="Reactivate user"
                                    onclick='confirmReactivate(<?= $uId ?>, <?= json_encode($uName) ?>)'
                                    class="w-9 h-9 rounded-full bg-emerald-50 hover:bg-[#006837] flex items-center justify-center text-[#006837] hover:text-white transition-all duration-200 hover:scale-110">
                              <i data-lucide="user-check" class="w-4 h-4"></i>
                            </button>

                            <button type="button"
                                    title="Delete user"
                                    onclick='confirmDelete(<?= $uId ?>, <?= json_encode($uName) ?>)'
                                    class="w-9 h-9 rounded-full bg-purple-50 hover:bg-red-500 flex items-center justify-center text-[#4A154B] hover:text-white transition-all duration-200 hover:scale-110">
                              <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>

                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>

                </tbody>
              </table>
            </div>

            <?php if (!empty($users)): ?>
              <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-sm text-slate-600" id="tableInfo">
                  Showing <span class="font-semibold text-slate-900">1</span> to
                  <span class="font-semibold text-slate-900"><?= count($users) ?></span> of
                  <span class="font-semibold text-slate-900"><?= count($users) ?></span> entries
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

  <!-- REACTIVATE CONFIRMATION MODAL -->
  <div id="reactivateConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeReactivateModal()"></div>

    <div id="reactivateConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-gradient-to-br from-emerald-100 to-green-100 ring-4 ring-emerald-50">
          <i data-lucide="user-check" class="w-8 h-8 text-[#006837]"></i>
        </div>
        <h3 class="text-xl font-bold text-slate-800 mb-2">Reactivate User?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">
          <span id="reactivateNameDisplay" class="font-bold text-[#8B1E7E] break-words">This user</span>
          will be marked as <span class="font-bold text-[#006837]">Approved</span> and will regain login access.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeReactivateModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">
          Cancel
        </button>
        <button type="button" id="confirmReactivateBtn"
                class="flex-1 px-5 py-3 rounded-xl font-bold text-white bg-gradient-to-r from-[#006837] via-[#007a41] to-[#00a35a] hover:from-[#005a2e] hover:via-[#006a37] hover:to-[#009350] shadow-lg shadow-emerald-500/30 hover:shadow-emerald-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95 flex items-center justify-center gap-2">
          <i data-lucide="user-check" class="w-4 h-4"></i><span>Reactivate</span>
        </button>
      </div>
    </div>
  </div>

  <!-- DELETE CONFIRMATION MODAL -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>

    <div id="deleteConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-gradient-to-br from-red-100 to-pink-100 ring-4 ring-red-50">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </div>
        <h3 class="text-xl font-bold text-slate-800 mb-2">Delete User?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to permanently delete
          <span id="deleteNameDisplay" class="font-bold text-[#8B1E7E] break-words">this user</span>.
        </p>
        <p class="text-xs text-red-500 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>This action cannot be undone.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeDeleteModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">
          Cancel
        </button>
        <button type="button" id="confirmDeleteBtn"
                class="flex-1 px-5 py-3 rounded-xl font-bold text-white bg-gradient-to-r from-red-500 via-red-600 to-rose-600 hover:from-red-600 hover:via-red-700 hover:to-rose-700 shadow-lg shadow-red-500/30 hover:shadow-red-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95 flex items-center justify-center gap-2">
          <i data-lucide="trash-2" class="w-4 h-4"></i><span>Delete</span>
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
  <form id="reactivateForm" method="POST" action="termination_year.php" class="hidden">
    <input type="hidden" name="action" value="reactivate_user" />
    <input type="hidden" name="user_id" id="reactivateUserId" value="" />
    <input type="hidden" name="filter_member_type" value="<?= e($filterMemberType) ?>" />
    <input type="hidden" name="filter_class_id" value="<?= e($filterClassId) ?>" />
  </form>

  <form id="deleteForm" method="POST" action="termination_year.php" class="hidden">
    <input type="hidden" name="action" value="delete_user" />
    <input type="hidden" name="user_id" id="deleteUserId" value="" />
    <input type="hidden" name="filter_member_type" value="<?= e($filterMemberType) ?>" />
    <input type="hidden" name="filter_class_id" value="<?= e($filterClassId) ?>" />
  </form>

  <script>
    document.addEventListener('DOMContentLoaded', function () {

      if (typeof lucide !== 'undefined') { lucide.createIcons(); }

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
        const btn = document.getElementById('admin-dropdown-btn');
        const menu = document.getElementById('admin-dropdown-menu');
        const chevron = document.getElementById('admin-chevron');
        const container = document.getElementById('admin-dropdown-container');
        if (!btn || !menu || !container) return;
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          const open = !menu.classList.contains('hidden');
          if (open) { menu.classList.add('hidden'); menu.classList.remove('animate-dropdown'); if (chevron) chevron.classList.remove('rotate-180'); }
          else { menu.classList.remove('hidden'); menu.classList.add('animate-dropdown'); if (chevron) chevron.classList.add('rotate-180'); }
        });
        document.addEventListener('click', function (e) { if (!container.contains(e.target)) { menu.classList.add('hidden'); menu.classList.remove('animate-dropdown'); if (chevron) chevron.classList.remove('rotate-180'); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { menu.classList.add('hidden'); menu.classList.remove('animate-dropdown'); if (chevron) chevron.classList.remove('rotate-180'); } });
      })();

      // ---- Reactivate Modal ----
      const reactivateModal = document.getElementById('reactivateConfirmModal');
      const reactivatePanel = document.getElementById('reactivateConfirmPanel');
      const reactivateNameDisplay = document.getElementById('reactivateNameDisplay');
      const confirmReactivateBtn = document.getElementById('confirmReactivateBtn');
      let pendingReactivateId = null;

      window.confirmReactivate = function (userId, name) {
        pendingReactivateId = userId;
        if (reactivateNameDisplay) reactivateNameDisplay.textContent = '"' + name + '"';
        reactivateModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (reactivatePanel) { reactivatePanel.classList.remove('animate-confirm-shake'); void reactivatePanel.offsetWidth; reactivatePanel.classList.add('animate-confirm-shake'); }
        setTimeout(() => { if (confirmReactivateBtn) confirmReactivateBtn.focus(); }, 80);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };
      window.closeReactivateModal = function () {
        reactivateModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        pendingReactivateId = null;
      };
      if (confirmReactivateBtn) confirmReactivateBtn.addEventListener('click', function () {
        if (pendingReactivateId === null) return window.closeReactivateModal();
        const input = document.getElementById('reactivateUserId');
        const form = document.getElementById('reactivateForm');
        if (input && form) { input.value = String(pendingReactivateId); form.submit(); }
      });

      // ---- Delete Modal ----
      const deleteModal = document.getElementById('deleteConfirmModal');
      const deletePanel = document.getElementById('deleteConfirmPanel');
      const deleteNameDisplay = document.getElementById('deleteNameDisplay');
      const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
      let pendingDeleteId = null;

      window.confirmDelete = function (userId, name) {
        pendingDeleteId = userId;
        if (deleteNameDisplay) deleteNameDisplay.textContent = '"' + name + '"';
        deleteModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (deletePanel) { deletePanel.classList.remove('animate-confirm-shake'); void deletePanel.offsetWidth; deletePanel.classList.add('animate-confirm-shake'); }
        setTimeout(() => { if (confirmDeleteBtn) confirmDeleteBtn.focus(); }, 80);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };
      window.closeDeleteModal = function () {
        deleteModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        pendingDeleteId = null;
      };
      if (confirmDeleteBtn) confirmDeleteBtn.addEventListener('click', function () {
        if (pendingDeleteId === null) return window.closeDeleteModal();
        const input = document.getElementById('deleteUserId');
        const form = document.getElementById('deleteForm');
        if (input && form) { input.value = String(pendingDeleteId); form.submit(); }
      });

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

      // ---- Live Search ----
      (function () {
        const searchInput = document.getElementById('searchInput');
        const tableBody = document.getElementById('usersTableBody');
        if (!searchInput || !tableBody) return;
        searchInput.addEventListener('input', function () {
          const term = this.value.toLowerCase().trim();
          tableBody.querySelectorAll('tr').forEach(function (row) {
            row.style.display = (term === '' || row.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
          });
        });
      })();

      // ---- Escape closes any open modal ----
      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (reactivateModal && !reactivateModal.classList.contains('hidden')) window.closeReactivateModal();
        if (deleteModal && !deleteModal.classList.contains('hidden')) window.closeDeleteModal();
        if (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) window.closeLogoutModal();
      });

    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>