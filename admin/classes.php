<?php
/**
 * admin/classes.php
 * ---------------------------------------------------------------------------
 * Admin — Class/Semester Management
 * Rajagiri College Grievance Redressal Portal
 *
 * Features:
 *   • View classes grouped by course
 *   • Create / Edit / Delete classes
 *   • Live student count per class
 *   • Click a class card → navigate to student list for that class
 *   • Flash messages auto-dismiss after 3 seconds
 *   • 3-column grid layout for class cards
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
// 4. HELPER — HTML ESCAPE
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
        error_log('[Classes Admin Profile] ' . $ex->getMessage());
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

    // -------- CREATE CLASS --------
    if ($action === 'create_class') {
        $courseId  = (int) ($_POST['course_id'] ?? 0);
        $className = trim((string) ($_POST['class_name'] ?? ''));

        if ($courseId <= 0) {
            $flashError = 'Please select a valid course.';
        } elseif ($className === '') {
            $flashError = 'Class name is required.';
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO classes (course_id, class_name) VALUES (?, ?)");
                $stmt->bind_param('is', $courseId, $className);
                if ($stmt->execute()) {
                    $flashSuccess = 'Class created successfully.';
                } else {
                    $flashError = 'Failed to create class.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Create Class] ' . $ex->getMessage());
                $flashError = 'A system error occurred while creating the class.';
            }
        }
    }

    // -------- EDIT CLASS --------
    if ($action === 'edit_class') {
        $classId   = (int) ($_POST['class_id'] ?? 0);
        $courseId  = (int) ($_POST['course_id'] ?? 0);
        $className = trim((string) ($_POST['class_name'] ?? ''));

        if ($classId <= 0 || $courseId <= 0) {
            $flashError = 'Invalid class or course selection.';
        } elseif ($className === '') {
            $flashError = 'Class name is required.';
        } else {
            try {
                $stmt = $conn->prepare("UPDATE classes SET class_name = ?, course_id = ? WHERE id = ?");
                $stmt->bind_param('sii', $className, $courseId, $classId);
                if ($stmt->execute()) {
                    $flashSuccess = 'Class updated successfully.';
                } else {
                    $flashError = 'Failed to update class.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Edit Class] ' . $ex->getMessage());
                $flashError = 'A system error occurred while updating the class.';
            }
        }
    }

    // -------- DELETE CLASS --------
    if ($action === 'delete_class') {
        $classId = (int) ($_POST['class_id'] ?? 0);
        if ($classId > 0) {
            try {
                $chk = $conn->prepare("SELECT COUNT(*) AS c FROM students WHERE class_id = ?");
                $chk->bind_param('i', $classId);
                $chk->execute();
                $cnt = (int) ($chk->get_result()->fetch_assoc()['c'] ?? 0);
                $chk->close();

                if ($cnt > 0) {
                    $flashError = "Cannot delete this class — {$cnt} student(s) are still assigned to it.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
                    $stmt->bind_param('i', $classId);
                    if ($stmt->execute()) {
                        $flashSuccess = 'Class deleted successfully.';
                    } else {
                        $flashError = 'Failed to delete class.';
                    }
                    $stmt->close();
                }
            } catch (Throwable $ex) {
                error_log('[Delete Class] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the class.';
            }
        }
    }

    if ($flashSuccess !== '' || $flashError !== '') {
        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: classes.php');
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
// 7. FETCH COURSES & GROUPED CLASSES
// ---------------------------------------------------------------------------
$courses = [];
$classesByCourse = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, course_name FROM courses WHERE status = 'active' OR status IS NULL OR status = '' ORDER BY course_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $courses[] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Courses] ' . $ex->getMessage());
    }

    try {
        $sql = "SELECT  cl.id,
                        cl.class_name,
                        cl.course_id,
                        co.course_name,
                        COUNT(st.id) AS student_count
                FROM classes cl
                JOIN courses co ON cl.course_id = co.id
                LEFT JOIN students st ON cl.id = st.class_id
                GROUP BY cl.id
                ORDER BY co.course_name ASC, cl.id ASC";

        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $courseName = $row['course_name'] ?? 'Unassigned';
                if (!isset($classesByCourse[$courseName])) {
                    $classesByCourse[$courseName] = [];
                }
                $classesByCourse[$courseName][] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Classes] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Class/Semester — Admin | Rajagiri College Grievance Portal</title>
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
                <a href="../logout.php?role=admin" id="dropdownLogoutBtn" class="flex items-center px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-all duration-200 group/item">
                  <i data-lucide="log-out" class="w-4 h-4 mr-3 group-hover/item:scale-110 transition-transform"></i>
                  <span class="font-medium">Logout</span>
                </a>
              </div>
            </div>
          </div>

        </div>
      </header>

      <main class="flex-1 px-6 py-8">

        <div class="max-w-6xl mx-auto mb-8 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

            <div>
              <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2 tracking-tight">
                Class/Semester
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
                <span class="text-[#E5097F] font-semibold">Semester</span>
              </nav>
            </div>

            <button type="button"
                    onclick="openClassModal('create')"
                    class="group relative inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl
                           bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]
                           hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A]
                           text-white font-semibold shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                           transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
              <i data-lucide="plus" class="w-5 h-5 group-hover:rotate-90 transition-transform duration-300"></i>
              <span>Add Class</span>
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

        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up" style="animation-delay: 60ms;">
          <div class="bg-slate-100 border border-slate-200 rounded-xl px-5 py-3.5">
            <p class="text-sm text-slate-600 flex items-center">
              <i data-lucide="mouse-pointer-click" class="w-4 h-4 mr-2 text-[#8B1E7E]"></i>
              Click on a class card to manage its students — Drag &amp; drop to prioritize!
            </p>
          </div>
        </div>

        <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 100ms;">

          <?php if (empty($classesByCourse)): ?>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/70 p-12 text-center">
              <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-purple-50 mb-4">
                <i data-lucide="graduation-cap" class="w-8 h-8 text-[#8B1E7E]"></i>
              </div>
              <h3 class="text-lg font-bold text-slate-800 mb-2">No classes yet</h3>
              <p class="text-sm text-slate-500 mb-6">
                Get started by creating your first class or semester.
              </p>
              <button type="button"
                      onclick="openClassModal('create')"
                      class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A] hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A] text-white font-semibold shadow-lg transition-all duration-300 hover:-translate-y-0.5">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Add First Class</span>
              </button>
            </div>

          <?php else: ?>

            <?php foreach ($classesByCourse as $courseName => $classes): ?>

              <div class="mb-5 mt-8 first:mt-0">
                <h2 class="text-lg font-bold text-slate-800 flex items-center">
                  <span class="w-2 h-2 bg-[#E5097F] rounded-full mr-3"></span>
                  <?= e($courseName) ?>
                </h2>
                <div class="mt-3 h-px bg-gradient-to-r from-slate-300 via-slate-200 to-transparent"></div>
              </div>

              <!-- 3-column grid: 1 col on mobile, 2 on tablet, 3 on desktop -->
              <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-4">

                <?php foreach ($classes as $i => $class): ?>
                  <?php
                    $isPurpleTint = ($i % 2 === 0);
                    $cardBgClass  = $isPurpleTint ? 'bg-purple-100' : 'bg-pink-100';
                    $studentCount = (int) ($class['student_count'] ?? 0);
                    $classId      = (int) $class['id'];
                    $classUrl     = 'students.php?class_id=' . $classId;
                  ?>
                  <a href="<?= e($classUrl) ?>"
                     title="Manage students of <?= e($class['class_name']) ?>"
                     class="group relative <?= $cardBgClass ?> rounded-2xl px-4 py-4 shadow-sm
                            border-2 border-transparent
                            hover:shadow-xl hover:-translate-y-1
                            hover:border-[#8B1E7E]
                            cursor-pointer
                            transition-all duration-300 no-underline text-inherit
                            overflow-hidden
                            flex flex-col justify-center min-h-[88px]">

                    <!-- Content -->
                    <div class="relative z-10 flex items-center justify-between gap-3">

                      <!-- Left: Student Count Badge -->
                      <div class="flex items-center space-x-1.5 bg-white rounded-full px-2.5 py-1.5 shadow-sm flex-shrink-0
                                  group-hover:bg-gradient-to-r group-hover:from-[#6A2C8A] group-hover:to-[#C43A7A]
                                  transition-all duration-300">
                        <i data-lucide="users" class="w-3.5 h-3.5 text-[#4A154B] group-hover:text-white transition-colors"></i>
                        <span class="text-xs font-bold text-[#4A154B] group-hover:text-white transition-colors"><?= $studentCount ?></span>
                      </div>

                      <!-- Center: Course + Class Name -->
                      <div class="flex-1 min-w-0 text-center">
                        <p class="text-[9px] font-bold uppercase tracking-wider text-slate-500 mb-0.5 truncate">
                          <?= e($class['course_name']) ?>
                        </p>
                        <p class="text-sm font-bold text-slate-800 truncate group-hover:text-[#4A154B] transition-colors">
                          <?= e($class['class_name']) ?>
                        </p>
                      </div>

                      <!-- Right: Edit + Delete Icons -->
                      <div class="flex flex-col gap-1 flex-shrink-0">
                        <button type="button"
                                title="Edit class"
                                onclick='event.preventDefault(); event.stopPropagation(); openClassModal("edit", <?= $classId ?>, <?= (int) $class["course_id"] ?>, <?= json_encode((string) $class["class_name"]) ?>)'
                                class="w-7 h-7 rounded-full bg-white flex items-center justify-center
                                       shadow-sm hover:bg-gradient-to-r hover:from-[#6A2C8A] hover:to-[#C43A7A] text-[#4A154B] hover:text-white
                                       transition-all duration-200 hover:scale-110">
                          <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                        </button>

                        <button type="button"
                                title="Delete class"
                                onclick='event.preventDefault(); event.stopPropagation(); confirmDeleteClass(<?= $classId ?>, <?= json_encode((string) $class["class_name"]) ?>)'
                                class="w-7 h-7 rounded-full bg-white flex items-center justify-center
                                       shadow-sm hover:bg-red-500 text-red-500 hover:text-white
                                       transition-all duration-200 hover:scale-110">
                          <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                        </button>
                      </div>

                    </div>

                    <!-- Bottom accent bar — matching the new darker button gradient -->
                    <div class="absolute bottom-0 left-0 right-0 h-1 z-0
                                bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]
                                transform scale-x-0 group-hover:scale-x-100
                                transition-transform duration-500 origin-left"></div>

                  </a>
                <?php endforeach; ?>

              </div>

            <?php endforeach; ?>

          <?php endif; ?>

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
              <span class="font-bold bg-gradient-to-r from-[#4A154B] to-[#E5097F] bg-clip-text text-transparent ml-1">
                Orell
              </span>
            </p>
          </div>
        </div>
      </footer>

    </div>
  </div>

  <!-- CREATE / EDIT CLASS MODAL -->
  <div id="classModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeClassModal()"></div>

    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 id="classModalTitle" class="text-lg font-bold text-slate-800">Create Class</h3>
        <button type="button" onclick="closeClassModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="classForm" method="POST" action="classes.php" class="p-6 space-y-5">
        <input type="hidden" name="action" id="formAction" value="create_class" />
        <input type="hidden" name="class_id" id="formClassId" value="" />

        <div class="space-y-2">
          <label for="course_id" class="block text-sm font-semibold text-slate-700">
            Course
          </label>
          <select name="course_id" id="course_id" required
                  class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl appearance-none bg-white text-slate-800 font-medium
                         focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                         hover:border-[#4A154B]/40 transition-all">
            <option value="">-- Select Course --</option>
            <?php foreach ($courses as $course): ?>
              <option value="<?= (int) $course['id'] ?>">
                <?= e($course['course_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="space-y-2">
          <label for="class_name" class="block text-sm font-semibold text-slate-700">
            Class Name <span class="text-[#E5097F]">*</span>
          </label>
          <input type="text" name="class_name" id="class_name" required
                 placeholder="e.g. SEMESTER I"
                 class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                        placeholder-slate-400
                        focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                        hover:border-[#4A154B]/40 transition-all" />
        </div>

        <div class="flex justify-center pt-2">
          <button type="submit"
                  class="px-8 py-3 rounded-xl
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

  <!-- ============================================================= -->
  <!-- CUSTOM DELETE CONFIRMATION MODAL                              -->
  <!-- ============================================================= -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>

    <!-- Modal Panel -->
    <div id="deleteConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <!-- Top gradient accent bar -->
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <!-- Icon + Heading -->
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4
                    bg-gradient-to-br from-red-100 to-pink-100 ring-4 ring-red-50">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 class="text-xl font-bold text-slate-800 mb-2">Delete Class?</h3>

        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to permanently delete
          <span id="deleteClassNameDisplay"
                class="font-bold text-[#8B1E7E] break-words">this class</span>.
        </p>

        <p class="text-xs text-red-500 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>
          This action cannot be undone.
        </p>
      </div>

      <!-- Action Buttons -->
      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button"
                onclick="closeDeleteModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700
                       bg-slate-100 hover:bg-slate-200
                       border border-slate-200
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

  <!-- HIDDEN DELETE FORM -->
  <form id="deleteForm" method="POST" action="classes.php" class="hidden">
    <input type="hidden" name="action" value="delete_class" />
    <input type="hidden" name="class_id" id="deleteClassId" value="" />
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

        // After 3s, play the fade-out animation
        setTimeout(function () {
          box.classList.remove('animate-flash-in');
          box.classList.add('animate-flash-out');

          // After the fade-out animation finishes, remove from DOM
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

    // ---- Class Modal ----
    const classModal      = document.getElementById('classModal');
    const classModalTitle = document.getElementById('classModalTitle');
    const classForm       = document.getElementById('classForm');
    const formAction      = document.getElementById('formAction');
    const formClassId     = document.getElementById('formClassId');
    const courseSelect    = document.getElementById('course_id');
    const classNameInput  = document.getElementById('class_name');

    function openClassModal(mode, classId, courseId, className) {
      classModal.classList.remove('hidden');

      if (mode === 'edit') {
        classModalTitle.textContent = 'Edit Class';
        formAction.value = 'edit_class';
        formClassId.value = classId || '';
        if (courseSelect && courseId) courseSelect.value = String(courseId);
        if (classNameInput) classNameInput.value = className || '';
      } else {
        classModalTitle.textContent = 'Create Class';
        formAction.value = 'create_class';
        formClassId.value = '';
        if (classForm) classForm.reset();
      }

      setTimeout(() => {
        const focusTarget = courseSelect && courseSelect.value
          ? classNameInput
          : courseSelect;
        if (focusTarget) focusTarget.focus();
      }, 50);

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeClassModal() {
      classModal.classList.add('hidden');
      if (classForm) classForm.reset();
      formAction.value = 'create_class';
      formClassId.value = '';
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && classModal && !classModal.classList.contains('hidden')) {
        closeClassModal();
      }
    });

    // ---- Custom Delete Confirmation Modal ----
    const deleteConfirmModal   = document.getElementById('deleteConfirmModal');
    const deleteConfirmPanel   = document.getElementById('deleteConfirmPanel');
    const deleteClassNameEl    = document.getElementById('deleteClassNameDisplay');
    const confirmDeleteBtn     = document.getElementById('confirmDeleteBtn');

    let pendingDeleteId    = null;
    let pendingDeleteName  = '';

    function confirmDeleteClass(classId, className) {
      pendingDeleteId   = classId;
      pendingDeleteName = className;

      if (deleteClassNameEl) {
        deleteClassNameEl.textContent = '"' + className + '"';
      }

      deleteConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      // Slight shake for emphasis
      if (deleteConfirmPanel) {
        deleteConfirmPanel.classList.remove('animate-confirm-shake');
        void deleteConfirmPanel.offsetWidth; // reflow to restart animation
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
      pendingDeleteId   = null;
      pendingDeleteName = '';
    }

    if (confirmDeleteBtn) {
      confirmDeleteBtn.addEventListener('click', function () {
        if (pendingDeleteId === null || pendingDeleteId === undefined) {
          closeDeleteModal();
          return;
        }

        const delIdInput = document.getElementById('deleteClassId');
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
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>