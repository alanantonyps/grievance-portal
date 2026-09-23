<?php
/**
 * admin/new_registration.php
 * ---------------------------------------------------------------------------
 * Admin — New Registration Approvals
 * Rajagiri College Grievance Redressal Portal
 *
 * Database strategy:
 *   Pending users are stored in the `users` table with status = 'Pending'.
 *   Profile details are resolved by joining role-specific tables:
 *     - STUDENT                        → students
 *     - PARENT                         → parents
 *     - TEACHER / NON_TEACHING         → staff
 *     - MANAGEMENT                     → cell_members
 *
 * Features:
 *   • Lists pending registrations with live search
 *   • Approve single / approve-bulk (checkbox)
 *   • View full details (eye icon → modal)
 *   • Delete registration permanently
 *   • Themed modals & flash messages (auto-dismiss after 3 seconds)
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
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || $sessionRole !== 'ADMIN') {
    header('Location: ../login.php?role=admin');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------------
// DATABASE CONNECTION
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

function dash(?string $v): string
{
    $v = trim((string) $v);
    return $v === '' ? '—' : $v;
}

// ---------------------------------------------------------------------------
// FETCH ADMIN PROFILE
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
        error_log('[New Registrations Admin Profile] ' . $ex->getMessage());
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
// HANDLE FORM SUBMISSIONS
// ---------------------------------------------------------------------------
$flashSuccess = '';
$flashError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    $action = $_POST['action'] ?? '';

    // -------- APPROVE SINGLE --------
    if ($action === 'approve_single') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        if ($targetUserId > 0) {
            try {
                $stmt = $conn->prepare("UPDATE users SET status = 'Approved' WHERE id = ? AND status = 'Pending'");
                $stmt->bind_param('i', $targetUserId);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $flashSuccess = 'Registration approved successfully.';
                } else {
                    $flashError = 'Unable to approve this registration. It may already be approved or removed.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Approve Single] ' . $ex->getMessage());
                $flashError = 'A system error occurred while approving the registration.';
            }
        }
    }

    // -------- APPROVE BULK --------
    if ($action === 'approve_bulk') {
        $ids = $_POST['user_ids'] ?? [];
        if (is_array($ids) && !empty($ids)) {
            $cleanIds = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));

            if (!empty($cleanIds)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
                    $types        = str_repeat('i', count($cleanIds));

                    $stmt = $conn->prepare("UPDATE users SET status = 'Approved' WHERE id IN ($placeholders) AND status = 'Pending'");
                    $stmt->bind_param($types, ...$cleanIds);
                    $stmt->execute();
                    $affected = $stmt->affected_rows;
                    $stmt->close();

                    if ($affected > 0) {
                        $flashSuccess = $affected . ' registration(s) approved successfully.';
                    } else {
                        $flashError = 'No pending registrations were approved.';
                    }
                } catch (Throwable $ex) {
                    error_log('[Approve Bulk] ' . $ex->getMessage());
                    $flashError = 'A system error occurred while approving the registrations.';
                }
            } else {
                $flashError = 'No valid registrations selected.';
            }
        } else {
            $flashError = 'Please select at least one registration to approve.';
        }
    }

    // -------- DELETE --------
    if ($action === 'delete_registration') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        if ($targetUserId > 0) {
            try {
                $conn->begin_transaction();

                $stmt = $conn->prepare("DELETE FROM students     WHERE user_id = ?");
                $stmt->bind_param('i', $targetUserId); $stmt->execute(); $stmt->close();

                $stmt = $conn->prepare("DELETE FROM parents      WHERE user_id = ?");
                $stmt->bind_param('i', $targetUserId); $stmt->execute(); $stmt->close();

                $stmt = $conn->prepare("DELETE FROM staff        WHERE user_id = ?");
                $stmt->bind_param('i', $targetUserId); $stmt->execute(); $stmt->close();

                $stmt = $conn->prepare("DELETE FROM cell_members WHERE user_id = ?");
                $stmt->bind_param('i', $targetUserId); $stmt->execute(); $stmt->close();

                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param('i', $targetUserId);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $flashSuccess = 'Registration deleted successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Delete Registration] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the registration.';
            }
        }
    }

    if ($flashSuccess !== '' || $flashError !== '') {
        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: new_registration.php');
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
// FETCH PENDING REGISTRATIONS
//    Pull every role-specific column so the View modal can show full details.
// ---------------------------------------------------------------------------
$registrations = [];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.id AS user_id,
                        u.username,
                        u.role,
                        u.status,
                        u.created_at AS user_created_at,

                        -- Unified main fields
                        COALESCE(s.name,   p.name,   st.name,   cm.name)   AS name,
                        COALESCE(s.email,  p.email,  st.email,  cm.email)  AS email,
                        COALESCE(s.address, p.address, st.address, cm.address) AS address,

                        -- STUDENT fields
                        s.admission_number          AS student_admission_number,
                        s.contact_number            AS student_contact_number,
                        s.whatsapp_number           AS student_whatsapp_number,
                        s.guardian_name             AS student_guardian_name,
                        s.profile_image             AS student_profile_image,
                        s.class_id                  AS student_class_id,
                        cls.class_name              AS student_class_name,
                        crs.course_name             AS student_course_name,

                        -- PARENT fields
                        p.contact_number            AS parent_contact_number,
                        p.whatsapp_number           AS parent_whatsapp_number,
                        p.relation                  AS parent_relation,
                        p.profile_image             AS parent_profile_image,

                        -- STAFF fields
                        st.gender                   AS staff_gender,
                        st.contact_number           AS staff_contact_number,
                        st.whatsapp_number          AS staff_whatsapp_number,
                        st.employee_id              AS staff_employee_id,
                        st.staff_type               AS staff_staff_type,
                        st.profile_image            AS staff_profile_image,
                        st_des.designation_name     AS staff_designation_name,
                        st_dept.department_name     AS staff_department_name,

                        -- CELL MEMBER / MANAGEMENT fields
                        cm.member_type              AS cm_member_type,
                        cm.mobile_number            AS cm_mobile_number,
                        cm.whatsapp_number          AS cm_whatsapp_number,
                        cm.profile_image            AS cm_profile_image,
                        cm.grievance_type_id        AS cm_grievance_type_id,
                        cm_des.designation_name     AS cm_designation_name,
                        cm_dept.department_name     AS cm_department_name,
                        cm_gt.type_name             AS cm_grievance_type_name

                FROM users u
                LEFT JOIN students       s      ON u.id = s.user_id
                LEFT JOIN classes        cls    ON cls.id = s.class_id
                LEFT JOIN courses        crs    ON crs.id = cls.course_id

                LEFT JOIN parents        p      ON u.id = p.user_id

                LEFT JOIN staff          st     ON u.id = st.user_id
                LEFT JOIN designations   st_des ON st_des.id = st.designation_id
                LEFT JOIN departments    st_dept ON st_dept.id = st.department_id

                LEFT JOIN cell_members   cm     ON u.id = cm.user_id
                LEFT JOIN designations   cm_des ON cm_des.id = cm.designation_id
                LEFT JOIN departments    cm_dept ON cm_dept.id = cm.department_id
                LEFT JOIN grievance_types cm_gt ON cm_gt.id = cm.grievance_type_id

                WHERE u.status = 'Pending'
                ORDER BY u.id DESC";

        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $registrations[] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Pending Registrations] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>New Registration — Admin | Rajagiri College Grievance Portal</title>
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
      <a href="../logout.php?role=admin" id="sidebarLogoutBtn" class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-red-500/40 flex items-center justify-center text-white transition-all hover:scale-110" title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 group-hover:translate-x-0.5 transition-transform"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-red-600 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Logout</span>
      </a>
    </aside>

    <!-- MAIN CONTENT -->
    <div class="flex-1 ml-20 flex flex-col min-h-screen">

      <!-- HEADER -->
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
                <a href="../logout.php?role=admin" id="dropdownLogoutBtn" class="flex items-center px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-all duration-200 group/item">
                  <i data-lucide="log-out" class="w-4 h-4 mr-3"></i><span class="font-medium">Logout</span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </header>

      <!-- PAGE CONTENT -->
      <main class="flex-1 px-6 py-8">

        <!-- Breadcrumb + Top Right Approve Button -->
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2 tracking-tight">New Registration</h1>
              <nav class="flex items-center space-x-2 text-sm text-slate-500">
                <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i>Dashboard
                </a>
                <span class="text-slate-300">/</span>
                <a href="members.php" class="hover:text-[#8B1E7E] transition-colors">Members</a>
                <span class="text-slate-300">/</span>
                <span class="text-[#E5097F] font-semibold">New Registration</span>
              </nav>
            </div>

            <button type="button"
                    onclick="approveChecked()"
                    title="Approve Selected Registrations"
                    class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-[#4A154B] hover:bg-[#5A1B5C] text-white shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
              <i data-lucide="check-check" class="w-5 h-5"></i>
            </button>
          </div>
        </div>

        <!-- Flash Messages -->
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

        <!-- TABLE CONTROLS -->
        <div class="max-w-6xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 80ms;">
          <div class="bg-white rounded-xl shadow-sm border border-slate-200/70 px-5 py-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div class="flex items-center space-x-3">
                <span class="text-sm text-slate-600">Show</span>
                <select id="entriesPerPage" class="px-3 py-1.5 border-2 border-slate-200 rounded-lg text-sm font-medium text-slate-700 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10 hover:border-[#4A154B]/40 transition-colors bg-white">
                  <option value="10" selected>10</option>
                  <option value="25">25</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
                <span class="text-sm text-slate-600">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text" id="searchInput" placeholder="Search.." class="w-full pl-10 pr-4 py-2 border-2 border-slate-200 rounded-lg text-sm focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10 hover:border-[#4A154B]/40 transition-all bg-white" />
              </div>
            </div>
          </div>
        </div>

        <!-- DATA TABLE -->
        <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 120ms;">
          <div class="bg-white rounded-2xl shadow-lg border border-slate-200/70 overflow-hidden">

            <form id="bulkApproveForm" method="POST" action="new_registration.php">
              <input type="hidden" name="action" value="approve_bulk" />

              <div class="overflow-x-auto">
                <table class="w-full" id="registrationsTable">
                  <thead>
                    <tr class="bg-[#4A154B] text-white">
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Address</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Email Id</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Role</th>
                      <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                          <input type="checkbox" id="selectAllCheckbox"
                                 class="w-4 h-4 rounded border-slate-300 text-[#4A154B] focus:ring-[#4A154B]/30 cursor-pointer" />
                          <span>Select All</span>
                        </label>
                      </th>
                      <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100" id="registrationsTableBody">

                    <?php if (empty($registrations)): ?>
                      <tr>
                        <td colspan="7" class="px-6 py-16 text-center text-slate-500">
                          <div class="flex flex-col items-center justify-center">
                            <div class="w-16 h-16 bg-purple-50 rounded-full flex items-center justify-center mb-4">
                              <i data-lucide="user-check" class="w-8 h-8 text-[#8B1E7E]"></i>
                            </div>
                            <p class="text-lg font-semibold text-slate-700">No pending registrations</p>
                            <p class="text-sm text-slate-500 mt-1 mb-4">New sign-ups will appear here for approval.</p>
                          </div>
                        </td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($registrations as $index => $reg): ?>
                        <?php
                          $regUserId = (int) $reg['user_id'];
                          $regName   = (string) ($reg['name']    ?? 'N/A');
                          $regEmail  = (string) ($reg['email']   ?? 'N/A');
                          $regAddr   = (string) ($reg['address'] ?? 'N/A');
                          $regRole   = (string) ($reg['role']    ?? '');

                          // Build a compact, role-aware payload for the View modal
                          $viewPayload = [
                              'user_id'    => $regUserId,
                              'username'   => (string) ($reg['username'] ?? ''),
                              'role'       => $regRole,
                              'status'     => (string) ($reg['status'] ?? ''),
                              'created_at' => (string) ($reg['user_created_at'] ?? ''),
                              'name'       => $regName,
                              'email'      => $regEmail,
                              'address'    => $regAddr,
                          ];

                          if ($regRole === 'STUDENT') {
                              $viewPayload['student'] = [
                                  'admission_number' => (string) ($reg['student_admission_number'] ?? ''),
                                  'class_name'       => (string) ($reg['student_class_name']       ?? ''),
                                  'course_name'      => (string) ($reg['student_course_name']      ?? ''),
                                  'contact_number'   => (string) ($reg['student_contact_number']   ?? ''),
                                  'whatsapp_number'  => (string) ($reg['student_whatsapp_number']  ?? ''),
                                  'guardian_name'    => (string) ($reg['student_guardian_name']    ?? ''),
                                  'profile_image'    => (string) ($reg['student_profile_image']    ?? ''),
                              ];
                          } elseif ($regRole === 'PARENT') {
                              $viewPayload['parent'] = [
                                  'contact_number'  => (string) ($reg['parent_contact_number']   ?? ''),
                                  'whatsapp_number' => (string) ($reg['parent_whatsapp_number']  ?? ''),
                                  'relation'        => (string) ($reg['parent_relation']         ?? ''),
                                  'profile_image'   => (string) ($reg['parent_profile_image']    ?? ''),
                              ];
                          } elseif ($regRole === 'TEACHER' || $regRole === 'NON_TEACHING') {
                              $viewPayload['staff'] = [
                                  'gender'            => (string) ($reg['staff_gender']           ?? ''),
                                  'contact_number'    => (string) ($reg['staff_contact_number']   ?? ''),
                                  'whatsapp_number'   => (string) ($reg['staff_whatsapp_number']  ?? ''),
                                  'employee_id'       => (string) ($reg['staff_employee_id']      ?? ''),
                                  'staff_type'        => (string) ($reg['staff_staff_type']       ?? ''),
                                  'designation_name'  => (string) ($reg['staff_designation_name'] ?? ''),
                                  'department_name'   => (string) ($reg['staff_department_name']  ?? ''),
                                  'profile_image'     => (string) ($reg['staff_profile_image']    ?? ''),
                              ];
                          } else {
                              // MANAGEMENT (or fallback) → cell_members
                              $viewPayload['member'] = [
                                  'member_type'         => (string) ($reg['cm_member_type']           ?? ''),
                                  'mobile_number'       => (string) ($reg['cm_mobile_number']         ?? ''),
                                  'whatsapp_number'     => (string) ($reg['cm_whatsapp_number']       ?? ''),
                                  'designation_name'    => (string) ($reg['cm_designation_name']      ?? ''),
                                  'department_name'     => (string) ($reg['cm_department_name']       ?? ''),
                                  'grievance_type_name' => (string) ($reg['cm_grievance_type_name']   ?? ''),
                                  'profile_image'       => (string) ($reg['cm_profile_image']         ?? ''),
                              ];
                          }
                        ?>
                        <tr class="hover:bg-slate-50/80 transition-colors group">
                          <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900"><?= $index + 1 ?></td>
                          <td class="px-6 py-4 text-sm font-semibold text-slate-800"><?= e($regName) ?></td>
                          <td class="px-6 py-4 text-sm text-slate-600 max-w-[200px]"><?= e($regAddr) ?></td>
                          <td class="px-6 py-4 text-sm text-slate-600 break-all"><?= e($regEmail) ?></td>
                          <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-700"><?= e($regRole) ?></td>
                          <td class="px-6 py-4 whitespace-nowrap text-center">
                            <input type="checkbox"
                                   name="user_ids[]"
                                   value="<?= $regUserId ?>"
                                   class="reg-checkbox w-4 h-4 rounded border-slate-300 text-[#4A154B] focus:ring-[#4A154B]/30 cursor-pointer" />
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center justify-center gap-2">

                              <!-- View -->
                              <button type="button"
                                      title="View details"
                                      data-view-trigger="1"
                                      data-user='<?= e(json_encode($viewPayload, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
                                      class="w-9 h-9 rounded-full bg-purple-50 hover:bg-[#4A154B] flex items-center justify-center text-[#4A154B] hover:text-white transition-all duration-200 hover:scale-110">
                                <i data-lucide="eye" class="w-4 h-4 pointer-events-none"></i>
                              </button>

                              <!-- Approve Single -->
                              <button type="button"
                                      title="Approve this registration"
                                      onclick='confirmApprove(<?= $regUserId ?>, <?= json_encode($regName) ?>)'
                                      class="w-9 h-9 rounded-full bg-purple-50 hover:bg-[#4A154B] flex items-center justify-center text-[#4A154B] hover:text-white transition-all duration-200 hover:scale-110">
                                <i data-lucide="check" class="w-4 h-4"></i>
                              </button>

                              <!-- Delete -->
                              <button type="button"
                                      title="Delete registration"
                                      onclick='confirmDelete(<?= $regUserId ?>, <?= json_encode($regName) ?>)'
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
            </form>

            <?php if (!empty($registrations)): ?>
              <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-sm text-slate-600" id="tableInfo">
                  Showing <span class="font-semibold text-slate-900">1</span> to
                  <span class="font-semibold text-slate-900"><?= count($registrations) ?></span> of
                  <span class="font-semibold text-slate-900"><?= count($registrations) ?></span> entries
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

      <!-- FOOTER -->
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

  <!-- ============================================================
       VIEW DETAILS MODAL
       ============================================================ -->
  <div id="viewDetailsModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeViewModal()"></div>

    <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden max-h-[90vh] flex flex-col">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="text-lg font-bold text-slate-800">Registration Details</h3>
        <button type="button" onclick="closeViewModal()" class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="p-6 overflow-y-auto flex-1 space-y-5">

        <!-- Header card -->
        <div class="flex items-start space-x-4 pb-4 border-b border-slate-100">
          <div id="viewAvatar" class="w-16 h-16 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E] flex items-center justify-center text-white shadow-md flex-shrink-0 overflow-hidden">
            <i data-lucide="user" class="w-8 h-8"></i>
          </div>
          <div class="min-w-0 flex-1">
            <p id="viewName" class="text-lg font-bold text-slate-800 break-words">—</p>
            <p id="viewEmail" class="text-sm text-slate-500 break-words">—</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <span id="viewRoleBadge" class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider border border-purple-200 bg-purple-50 text-[#4A154B]">—</span>
              <span id="viewStatusBadge" class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider border border-amber-200 bg-amber-50 text-amber-800">—</span>
            </div>
          </div>
        </div>

        <!-- Common details -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Username</p>
            <p id="viewUsername" class="text-sm font-semibold text-slate-800 break-words">—</p>
          </div>
          <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Registered On</p>
            <p id="viewCreatedAt" class="text-sm font-semibold text-slate-800">—</p>
          </div>
        </div>

        <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
          <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Address</p>
          <p id="viewAddress" class="text-sm text-slate-700 break-words whitespace-pre-line">—</p>
        </div>

        <!-- Role-specific section (rendered dynamically) -->
        <div id="viewRoleSection"></div>

      </div>

      <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end">
        <button type="button" onclick="closeViewModal()"
                class="px-5 py-2.5 rounded-xl font-semibold text-slate-700 bg-white hover:bg-slate-100 border border-slate-200 transition-all duration-200 active:scale-95">
          Close
        </button>
      </div>
    </div>
  </div>

  <!-- ============================================================
       APPROVE CONFIRMATION MODAL
       ============================================================ -->
  <div id="approveConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeApproveModal()"></div>

    <div id="approveConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-gradient-to-br from-emerald-100 to-green-100 ring-4 ring-emerald-50">
          <i data-lucide="user-check" class="w-8 h-8 text-[#006837]"></i>
        </div>
        <h3 class="text-xl font-bold text-slate-800 mb-2">Approve Registration?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">
          <span id="approveNameDisplay" class="font-bold text-[#8B1E7E] break-words">This registration</span>
          will be marked as <span class="font-bold text-[#006837]">Approved</span> and the user will be able to log in.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeApproveModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">
          Cancel
        </button>
        <button type="button" id="confirmApproveBtn"
                class="flex-1 px-5 py-3 rounded-xl font-bold text-white bg-gradient-to-r from-[#006837] via-[#007a41] to-[#00a35a] hover:from-[#005a2e] hover:via-[#006a37] hover:to-[#009350] shadow-lg shadow-emerald-500/30 hover:shadow-emerald-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95 flex items-center justify-center gap-2">
          <i data-lucide="check-circle" class="w-4 h-4"></i><span>Approve</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ============================================================
       DELETE CONFIRMATION MODAL
       ============================================================ -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>

    <div id="deleteConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-gradient-to-br from-red-100 to-pink-100 ring-4 ring-red-50">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </div>
        <h3 class="text-xl font-bold text-slate-800 mb-2">Delete Registration?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to permanently delete
          <span id="deleteNameDisplay" class="font-bold text-[#8B1E7E] break-words">this registration</span>.
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

  <!-- HIDDEN FORMS -->
  <form id="approveForm" method="POST" action="new_registration.php" class="hidden">
    <input type="hidden" name="action" value="approve_single" />
    <input type="hidden" name="user_id" id="approveUserId" value="" />
  </form>

  <form id="deleteForm" method="POST" action="new_registration.php" class="hidden">
    <input type="hidden" name="action" value="delete_registration" />
    <input type="hidden" name="user_id" id="deleteUserId" value="" />
  </form>

  <script>
    if (typeof lucide !== 'undefined') { lucide.createIcons(); }

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
    })();

    // ---- Logout confirmation ----
    (function () {
      [document.getElementById('sidebarLogoutBtn'), document.getElementById('dropdownLogoutBtn')].forEach(function (btn) {
        if (!btn) return;
        btn.addEventListener('click', function (e) {
          if (!window.confirm('Are you sure you want to log out?')) { e.preventDefault(); e.stopPropagation(); return false; }
        });
      });
    })();

    // ---- Select All Checkbox ----
    (function () {
      const selectAll = document.getElementById('selectAllCheckbox');
      const checkboxes = document.querySelectorAll('.reg-checkbox');
      if (!selectAll) return;

      selectAll.addEventListener('change', function () {
        checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
      });
      checkboxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
          const allChecked = Array.from(checkboxes).every(c => c.checked);
          const anyChecked = Array.from(checkboxes).some(c => c.checked);
          selectAll.checked = allChecked;
          selectAll.indeterminate = anyChecked && !allChecked;
        });
      });
    })();

    // ---- Approve Checked (bulk) ----
    function approveChecked() {
      const checked = document.querySelectorAll('.reg-checkbox:checked');
      if (checked.length === 0) {
        alert('Please select at least one registration to approve.');
        return;
      }
      const form = document.getElementById('bulkApproveForm');
      if (form) form.submit();
    }

    // ---- Helpers ----
    function escapeHtml(str) {
      return String(str == null ? '' : str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function dash(v) {
      const s = (v == null) ? '' : String(v).trim();
      return s === '' ? '—' : s;
    }
    function formatDate(yyyymmdd) {
      if (!yyyymmdd) return '—';
      const d = new Date(String(yyyymmdd).replace(' ', 'T'));
      if (isNaN(d.getTime())) return String(yyyymmdd);
      const pad = n => String(n).padStart(2, '0');
      return pad(d.getDate()) + '-' + pad(d.getMonth() + 1) + '-' + d.getFullYear()
           + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function kv(label, value) {
      return '<div class="bg-slate-50 rounded-xl p-3 border border-slate-100">'
           +   '<p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">' + escapeHtml(label) + '</p>'
           +   '<p class="text-sm font-semibold text-slate-800 break-words">' + escapeHtml(dash(value)) + '</p>'
           + '</div>';
    }

    // ---- View Details Modal ----
    const viewDetailsModal = document.getElementById('viewDetailsModal');
    const viewRoleSection  = document.getElementById('viewRoleSection');

    function renderRoleSection(data) {
      const role = (data.role || '').toUpperCase();

      let html = '';

      if (role === 'STUDENT' && data.student) {
        const s = data.student;
        html += '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">'
             +    kv('Admission Number', s.admission_number)
             +    kv('Course',          s.course_name)
             +    kv('Class / Semester', s.class_name)
             +    kv('Guardian Name',   s.guardian_name)
             +    kv('Contact Number',  s.contact_number)
             +    kv('WhatsApp Number', s.whatsapp_number)
             +  '</div>';
      } else if (role === 'PARENT' && data.parent) {
        const p = data.parent;
        html += '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">'
             +    kv('Relation',        p.relation)
             +    kv('Contact Number',  p.contact_number)
             +    kv('WhatsApp Number', p.whatsapp_number)
             +  '</div>';
      } else if ((role === 'TEACHER' || role === 'NON_TEACHING') && data.staff) {
        const st = data.staff;
        html += '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">'
             +    kv('Employee ID',    st.employee_id)
             +    kv('Staff Type',     st.staff_type)
             +    kv('Gender',         st.gender)
             +    kv('Designation',    st.designation_name)
             +    kv('Department',     st.department_name)
             +    kv('Contact Number', st.contact_number)
             +    kv('WhatsApp Number', st.whatsapp_number)
             +  '</div>';
      } else if (data.member) {
        const m = data.member;
        html += '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">'
             +    kv('Member Type',      m.member_type)
             +    kv('Designation',      m.designation_name)
             +    kv('Department',       m.department_name)
             +    kv('Grievance Type',   m.grievance_type_name)
             +    kv('Mobile Number',    m.mobile_number)
             +    kv('WhatsApp Number',  m.whatsapp_number)
             +  '</div>';
      }

      viewRoleSection.innerHTML = html;
    }

    function openViewModal(data) {
      if (!data) return;

      document.getElementById('viewName').textContent     = dash(data.name);
      document.getElementById('viewEmail').textContent    = dash(data.email);
      document.getElementById('viewUsername').textContent = dash(data.username);
      document.getElementById('viewAddress').textContent  = dash(data.address);
      document.getElementById('viewCreatedAt').textContent = formatDate(data.created_at);

      // Role badge
      const roleBadge = document.getElementById('viewRoleBadge');
      roleBadge.textContent = dash(data.role);

      // Status badge
      const statusBadge = document.getElementById('viewStatusBadge');
      const st = (data.status || '').toLowerCase();
      statusBadge.className = 'inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider border ' +
        (st === 'pending'   ? 'border-amber-200 bg-amber-50 text-amber-800'
        : st === 'approved' ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
        : st === 'rejected' ? 'border-rose-200 bg-rose-50 text-rose-800'
                            : 'border-slate-200 bg-slate-50 text-slate-700');
      statusBadge.textContent = dash(data.status);

      // Avatar (role-aware)
      const avatarDiv = document.getElementById('viewAvatar');
      let avatarUrl = '';
      if (data.role === 'STUDENT' && data.student)  avatarUrl = data.student.profile_image || '';
      if (data.role === 'PARENT'  && data.parent)   avatarUrl = data.parent.profile_image  || '';
      if ((data.role === 'TEACHER' || data.role === 'NON_TEACHING') && data.staff) avatarUrl = data.staff.profile_image || '';
      if (data.member) avatarUrl = data.member.profile_image || '';

      avatarDiv.innerHTML = '';
      if (avatarUrl) {
        const img = document.createElement('img');
        img.src = '../' + String(avatarUrl).replace(/^\/+/, '');
        img.alt = data.name || 'User';
        img.className = 'w-full h-full object-cover';
        img.onerror = function () {
          avatarDiv.innerHTML = '<i data-lucide="user" class="w-8 h-8"></i>';
          if (typeof lucide !== 'undefined') lucide.createIcons({ targets: [avatarDiv] });
        };
        avatarDiv.appendChild(img);
      } else {
        avatarDiv.innerHTML = '<i data-lucide="user" class="w-8 h-8"></i>';
      }

      renderRoleSection(data);

      viewDetailsModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeViewModal() {
      viewDetailsModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      viewRoleSection.innerHTML = '';
    }

    // Delegated handler for eye buttons
    document.addEventListener('click', function (e) {
      const btn = e.target.closest('[data-view-trigger="1"]');
      if (!btn) return;
      e.preventDefault();
      const raw = btn.getAttribute('data-user');
      if (!raw) return;
      try { openViewModal(JSON.parse(raw)); } catch (err) { console.error(err); }
    });

    // ---- Approve Modal ----
    const approveConfirmModal = document.getElementById('approveConfirmModal');
    const approveConfirmPanel = document.getElementById('approveConfirmPanel');
    const approveNameDisplay = document.getElementById('approveNameDisplay');
    const confirmApproveBtn = document.getElementById('confirmApproveBtn');
    let pendingApproveId = null;

    function confirmApprove(userId, name) {
      pendingApproveId = userId;
      if (approveNameDisplay) approveNameDisplay.textContent = '"' + name + '"';
      approveConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (approveConfirmPanel) { approveConfirmPanel.classList.remove('animate-confirm-shake'); void approveConfirmPanel.offsetWidth; approveConfirmPanel.classList.add('animate-confirm-shake'); }
      setTimeout(() => { if (confirmApproveBtn) confirmApproveBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeApproveModal() {
      approveConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      pendingApproveId = null;
    }
    if (confirmApproveBtn) confirmApproveBtn.addEventListener('click', function () {
      if (pendingApproveId === null) return closeApproveModal();
      const input = document.getElementById('approveUserId');
      const form = document.getElementById('approveForm');
      if (input && form) { input.value = String(pendingApproveId); form.submit(); }
    });

    // ---- Delete Modal ----
    const deleteConfirmModal = document.getElementById('deleteConfirmModal');
    const deleteConfirmPanel = document.getElementById('deleteConfirmPanel');
    const deleteNameDisplay = document.getElementById('deleteNameDisplay');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    let pendingDeleteId = null;

    function confirmDelete(userId, name) {
      pendingDeleteId = userId;
      if (deleteNameDisplay) deleteNameDisplay.textContent = '"' + name + '"';
      deleteConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (deleteConfirmPanel) { deleteConfirmPanel.classList.remove('animate-confirm-shake'); void deleteConfirmPanel.offsetWidth; deleteConfirmPanel.classList.add('animate-confirm-shake'); }
      setTimeout(() => { if (confirmDeleteBtn) confirmDeleteBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeDeleteModal() {
      deleteConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      pendingDeleteId = null;
    }
    if (confirmDeleteBtn) confirmDeleteBtn.addEventListener('click', function () {
      if (pendingDeleteId === null) return closeDeleteModal();
      const input = document.getElementById('deleteUserId');
      const form = document.getElementById('deleteForm');
      if (input && form) { input.value = String(pendingDeleteId); form.submit(); }
    });

    // ---- Live Search ----
    (function () {
      const searchInput = document.getElementById('searchInput');
      const tableBody = document.getElementById('registrationsTableBody');
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
      if (viewDetailsModal && !viewDetailsModal.classList.contains('hidden')) closeViewModal();
      if (approveConfirmModal && !approveConfirmModal.classList.contains('hidden')) closeApproveModal();
      if (deleteConfirmModal && !deleteConfirmModal.classList.contains('hidden')) closeDeleteModal();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>