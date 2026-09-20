<?php
/**
 * staff/change_password.php
 * ---------------------------------------------------------------------------
 * Staff — Change Password
 * Rajagiri College Grievance Redressal Portal
 *
 * Handles:
 *   • Auth guard (TEACHER / NON_TEACHING only)
 *   • POST processing: verify current password → validate new → update BCRYPT hash
 *   • Auto-redirect to staff/dashboard.php after successful password change
 *   • Real-time password strength meter
 *   • Confirm password live match indicator
 *   • Header profile dropdown (avatar, name, email, links, logout)
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
// 2. AUTH GUARD (Staff only — TEACHER or NON_TEACHING)
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || !in_array($sessionRole, ['TEACHER', 'NON_TEACHING'], true)) {
    header('Location: ../login.php?role=staff');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------------
// 3. DATABASE CONNECTION
// ---------------------------------------------------------------------------
$dbFile = __DIR__ . '/../db_connect.php';

if (!file_exists($dbFile)) {
    die('Database configuration file (db_connect.php) not found.');
}

require_once $dbFile;

$dbError = null;

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

// ---------------------------------------------------------------------------
// 4. HELPER — HTML ESCAPE
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// 5. FETCH STAFF DETAILS (for header chip + avatar + dropdown)
// ---------------------------------------------------------------------------
$staffData = [
    'username'      => $_SESSION['username'] ?? 'Staff',
    'name'          => '',
    'email'         => '',
    'profile_image' => '',
];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.username,
                        s.name,
                        s.email,
                        s.profile_image
                FROM users u
                LEFT JOIN staff s ON s.user_id = u.id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $staffData['username']      = $row['username']      ?? $staffData['username'];
                $staffData['name']          = $row['name']          ?? '';
                $staffData['email']         = $row['email']         ?? '';
                $staffData['profile_image'] = $row['profile_image'] ?? '';
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Staff Change Password Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($staffData['name']) ? $staffData['name'] : $staffData['username'];
$displayEmail = !empty($staffData['email']) ? $staffData['email'] : 'staff@rajagiri.edu';

// ---------------------------------------------------------------------------
// PROFILE PICTURE RESOLUTION
//   Files stored at  <project-root>/uploads/profiles/...
//   From staff/ the browser URL is  ../uploads/profiles/...
// ---------------------------------------------------------------------------
$hasProfilePicture = false;
$profilePictureUrl = '';

if (!empty($staffData['profile_image'])) {
    $relative     = ltrim((string) $staffData['profile_image'], '/');
    $absolutePath = __DIR__ . '/../' . $relative;
    $browserPath  = '../' . $relative;

    if (file_exists($absolutePath) && is_file($absolutePath)) {
        $hasProfilePicture = true;
        $profilePictureUrl = $browserPath;
    }
}

// ---------------------------------------------------------------------------
// 6. HANDLE POST SUBMISSION
// ---------------------------------------------------------------------------
$successMessage  = '';
$formErrors      = [];
$passwordChanged = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ----- Collect & Sanitize -----
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword     = (string) ($_POST['new_password']     ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    // ----- Validation -----
    if ($currentPassword === '') {
        $formErrors[] = 'Current password is required.';
    }

    if ($newPassword === '') {
        $formErrors[] = 'New password is required.';
    } elseif (strlen($newPassword) < 6) {
        $formErrors[] = 'New password must be at least 6 characters.';
    }

    if ($confirmPassword === '') {
        $formErrors[] = 'Please confirm your new password.';
    } elseif ($newPassword !== $confirmPassword) {
        $formErrors[] = 'New password and Confirm password do not match.';
    }

    // ----- Fetch current hash & verify -----
    if (empty($formErrors) && $conn !== null) {
        try {
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $user        = $res->fetch_assoc();
                $currentHash = $user['password'] ?? '';

                if (!password_verify($currentPassword, $currentHash)) {
                    $formErrors[] = 'Current password is incorrect.';
                } elseif ($currentPassword === $newPassword) {
                    $formErrors[] = 'New password must be different from the current password.';
                } else {
                    // ----- Update password -----
                    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

                    $stmtUpd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmtUpd->bind_param('si', $hashedPassword, $userId);

                    if ($stmtUpd->execute()) {
                        $successMessage  = 'Your password has been changed successfully.';
                        $passwordChanged = true;
                    } else {
                        $formErrors[] = 'Failed to update the password. Please try again.';
                    }
                    $stmtUpd->close();
                }
            } else {
                $formErrors[] = 'Unable to locate your account. Please try again.';
            }
            $stmt->close();

        } catch (Throwable $ex) {
            error_log('[Staff Change Password] ' . $ex->getMessage());
            $formErrors[] = 'A system error occurred. Please try again.';
        }
    }

    if ($dbError !== null && empty($formErrors)) {
        $formErrors[] = $dbError;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Change Password — Staff | Rajagiri College Grievance Portal</title>
  <link rel="icon" type="image/svg+xml" href="../public/favicon.svg" />

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Lucide Icons CDN -->
  <script src="https://unpkg.com/lucide@latest"></script>

  <!-- Tailwind Theme -->
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brandPurple: '#4A154B',
            brandPink: '#E5097F',
            brandGreen: '#006837',
            brandGold: '#C5A059'
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
            countdown: {
              '0%':   { width: '100%' },
              '100%': { width: '0%' }
            }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':   'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'countdown':  'countdown 3s linear forwards'
          }
        }
      }
    };
  </script>

  <!-- Local Styles -->
  <link rel="stylesheet" href="../assets/css/index.css" />
</head>

<body class="min-h-screen bg-slate-50 text-slate-800 antialiased flex flex-col">

  <div class="flex min-h-screen flex-1">

    <!-- ============================================================
         SIDEBAR
         ============================================================ -->
    <aside id="staffSidebar"
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
                       opacity-0 w-0 overflow-hidden transition-all duration-200">
            Dashboard
          </span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                       bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Dashboard
          </span>
        </a>

        <!-- Profile -->
        <a href="profile.php"
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20
                  flex items-center text-white transition-all px-3">
          <i data-lucide="user" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap
                       opacity-0 w-0 overflow-hidden transition-all duration-200">
            My Profile
          </span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                       bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            My Profile
          </span>
        </a>

        <!-- Change Password (active) -->
        <a href="change_password.php"
           class="group relative w-full h-12 rounded-xl bg-white/20 backdrop-blur-sm
                  flex items-center text-white shadow-lg ring-2 ring-white/30
                  transition-all px-3">
          <i data-lucide="key" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap
                       opacity-0 w-0 overflow-hidden transition-all duration-200">
            Change Password
          </span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                       bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Change Password
          </span>
        </a>

      </nav>

      <!-- Logout -->
      <a href="#"
         data-logout-trigger="1"
         id="sidebarLogoutBtn"
         class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-red-500/40
                flex items-center text-white transition-all
                mx-3 px-3"
         style="width: calc(100% - 1.5rem);"
         title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 flex-shrink-0"></i>
        <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap
                     opacity-0 w-0 overflow-hidden transition-all duration-200">
          Logout
        </span>
        <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                     bg-red-600 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
          Logout
        </span>
      </a>

    </aside>

    <!-- ============================================================
         MAIN CONTENT
         ============================================================ -->
    <div id="staffMain" class="flex-1 ml-20 flex flex-col min-h-screen transition-all duration-300">

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

          <!-- Right Side: Profile Dropdown -->
          <div class="relative" id="staff-dropdown-container">
            <button id="staff-dropdown-btn"
                    type="button"
                    aria-haspopup="true"
                    aria-expanded="false"
                    class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-slate-100 transition-colors">

              <?php if ($hasProfilePicture): ?>
                <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"
                     class="w-10 h-10 rounded-full object-cover border-2 border-[#C5A059] shadow-md ring-2 ring-purple-100" />
              <?php else: ?>
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E]
                            flex items-center justify-center text-white shadow-md ring-2 ring-purple-100">
                  <i data-lucide="user" class="w-5 h-5"></i>
                </div>
              <?php endif; ?>

              <span class="hidden sm:block text-sm font-semibold text-slate-700">
                <?= e($displayName) ?>
              </span>
              <i data-lucide="chevron-down" id="staff-chevron"
                 class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <div id="staff-dropdown-menu"
                 class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-2xl
                        border border-slate-200 py-2 z-50 overflow-hidden">

              <!-- Dropdown Header -->
              <div class="px-4 py-3 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                <div class="flex items-center space-x-3">
                  <?php if ($hasProfilePicture): ?>
                    <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"
                         class="w-12 h-12 rounded-full object-cover border-2 border-[#C5A059]" />
                  <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E]
                                flex items-center justify-center text-white">
                      <i data-lucide="user" class="w-6 h-6 text-white"></i>
                    </div>
                  <?php endif; ?>
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-800 truncate"><?= e($displayName) ?></p>
                    <p class="text-xs text-slate-500 truncate"><?= e($displayEmail) ?></p>
                  </div>
                </div>
              </div>

              <!-- Dashboard link -->
              <a href="dashboard.php"
                 class="flex items-center px-4 py-2.5 text-sm text-slate-700
                        hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50
                        hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="layout-dashboard"
                   class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover/item:scale-110 transition-transform"></i>
                <span class="font-medium">Dashboard</span>
                <i data-lucide="arrow-right"
                   class="w-4 h-4 ml-auto opacity-0 group-hover/item:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>

              <!-- My Profile link -->
              <a href="profile.php"
                 class="flex items-center px-4 py-2.5 text-sm text-slate-700
                        hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50
                        hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="user"
                   class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover/item:scale-110 transition-transform"></i>
                <span class="font-medium">My Profile</span>
                <i data-lucide="arrow-right"
                   class="w-4 h-4 ml-auto opacity-0 group-hover/item:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>

              <!-- Change Password (active) -->
              <a href="change_password.php"
                 class="flex items-center px-4 py-2.5 text-sm text-[#8B1E7E] bg-purple-50/50 font-medium">
                <i data-lucide="key" class="w-4 h-4 mr-3"></i>
                <span>Change Password</span>
              </a>

              <!-- Logout -->
              <div class="border-t border-slate-100 mt-2 pt-2">
                <a href="#" data-logout-trigger="1" id="dropdownLogoutBtn"
                   class="flex items-center px-4 py-2.5 text-sm text-red-600
                          hover:bg-red-50 transition-all duration-200 group/item">
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

        <!-- Breadcrumb -->
        <div class="max-w-5xl mx-auto mb-8 animate-fade-in-up">
          <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-3 flex items-center tracking-tight">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#4A154B] to-[#E5097F] flex items-center justify-center mr-3 shadow-lg shadow-purple-500/20">
              <i data-lucide="key" class="w-5 h-5 text-white"></i>
            </div>
            Change Password
          </h1>
          <nav class="flex items-center space-x-2 text-sm text-slate-500 ml-1">
            <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors">
              <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i>
              Dashboard
            </a>
            <span class="text-slate-300">/</span>
            <span class="text-[#E5097F] font-semibold">Change Password</span>
          </nav>
        </div>

        <!-- Success Message (with auto-redirect countdown) -->
        <?php if ($passwordChanged && $successMessage !== ''): ?>
          <div id="success-banner"
               class="max-w-2xl mx-auto mb-6 rounded-2xl border-2 border-emerald-200 bg-emerald-50 px-5 py-4 flex items-start space-x-3 shadow-lg shadow-emerald-500/10">
            <div class="w-10 h-10 rounded-full bg-emerald-500 flex items-center justify-center flex-shrink-0">
              <i data-lucide="check-circle" class="w-6 h-6 text-white"></i>
            </div>
            <div class="flex-1">
              <p class="text-sm font-bold text-emerald-800">
                <?= e($successMessage) ?>
              </p>
              <p class="text-xs text-emerald-700 mt-1">
                Redirecting you to the dashboard in <span id="countdown-text">3</span> seconds…
              </p>
              <div class="h-1 bg-emerald-200 rounded-full overflow-hidden mt-3">
                <div class="h-full bg-emerald-500 animate-countdown"></div>
              </div>
            </div>
          </div>

          <script>
            (function () {
              var seconds = 3;
              var textEl = document.getElementById('countdown-text');

              var interval = setInterval(function () {
                seconds--;
                if (textEl) textEl.textContent = seconds > 0 ? seconds : 0;

                if (seconds <= 0) {
                  clearInterval(interval);
                  window.location.href = 'dashboard.php';
                }
              }, 1000);
            })();
          </script>
        <?php endif; ?>

        <!-- Error Messages -->
        <?php if (!empty($formErrors)): ?>
          <div class="max-w-2xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3 flex items-start space-x-2">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <div class="text-sm text-red-700 space-y-1">
              <?php foreach ($formErrors as $err): ?>
                <p><?= e($err) ?></p>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- ============ CHANGE PASSWORD CARD ============ -->
        <div class="max-w-2xl mx-auto animate-fade-in-up" style="animation-delay: 100ms;">
          <div class="relative group/card">
            <div class="absolute -inset-0.5 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] rounded-2xl blur opacity-10 group-hover/card:opacity-20 transition duration-500"></div>

            <div class="relative bg-white rounded-2xl shadow-xl border border-slate-200/60 overflow-hidden">

              <!-- Card Header -->
              <div class="bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] px-6 py-5 relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full blur-2xl -translate-y-1/2 translate-x-1/2"></div>
                <div class="absolute bottom-0 left-0 w-24 h-24 bg-white/5 rounded-full blur-xl translate-y-1/2 -translate-x-1/2"></div>

                <div class="relative flex items-center space-x-3">
                  <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center transition-transform duration-300 group-hover/card:scale-110 group-hover/card:rotate-12">
                    <i data-lucide="shield" class="w-6 h-6 text-white"></i>
                  </div>
                  <div>
                    <h2 class="text-xl font-bold text-white">Update Your Password</h2>
                    <p class="text-sm text-white/80 mt-1">Ensure your account security with a strong password</p>
                  </div>
                </div>
              </div>

              <!-- Form -->
              <form action="change_password.php" method="POST" class="p-6 sm:p-8 space-y-6" novalidate>

                <!-- Current Password -->
                <div class="space-y-2">
                  <label for="current_password" class="flex items-center text-sm font-semibold text-slate-700">
                    Current Password <span class="text-[#E5097F] ml-1">*</span>
                  </label>
                  <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                      <i data-lucide="lock" class="w-5 h-5 text-slate-400"></i>
                    </div>
                    <input
                      type="password"
                      id="current_password"
                      name="current_password"
                      required
                      autocomplete="current-password"
                      placeholder="Enter your current password"
                      class="w-full pl-12 pr-12 py-3.5 border-2 border-slate-200 rounded-xl bg-white text-slate-700 font-medium
                             focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                             transition-all duration-200 hover:border-[#4A154B]/40"
                    />
                    <button
                      type="button"
                      onclick="togglePasswordVisibility('current_password', this)"
                      class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-[#4A154B] transition-colors"
                      aria-label="Toggle password visibility"
                    >
                      <i data-lucide="eye" class="w-5 h-5"></i>
                    </button>
                  </div>
                </div>

                <!-- New Password -->
                <div class="space-y-2">
                  <label for="new_password" class="flex items-center text-sm font-semibold text-slate-700">
                    New Password <span class="text-[#E5097F] ml-1">*</span>
                  </label>
                  <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                      <i data-lucide="key" class="w-5 h-5 text-slate-400"></i>
                    </div>
                    <input
                      type="password"
                      id="new_password"
                      name="new_password"
                      required
                      minlength="6"
                      autocomplete="new-password"
                      placeholder="Enter new password"
                      oninput="updatePasswordStrength(this.value)"
                      class="w-full pl-12 pr-12 py-3.5 border-2 border-slate-200 rounded-xl bg-white text-slate-700 font-medium
                             focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                             transition-all duration-200 hover:border-[#4A154B]/40"
                    />
                    <button
                      type="button"
                      onclick="togglePasswordVisibility('new_password', this)"
                      class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-[#4A154B] transition-colors"
                      aria-label="Toggle password visibility"
                    >
                      <i data-lucide="eye" class="w-5 h-5"></i>
                    </button>
                  </div>

                  <!-- Password Strength Indicator -->
                  <div id="strength-wrapper" class="mt-3 space-y-2 hidden">
                    <div class="flex items-center justify-between">
                      <span class="text-xs font-semibold text-slate-600">Password Strength</span>
                      <span id="strength-text" class="text-xs font-bold text-slate-500">Weak</span>
                    </div>
                    <div class="h-2 bg-slate-200 rounded-full overflow-hidden">
                      <div id="strength-bar" class="h-full transition-all duration-500 ease-out bg-red-500" style="width: 0%;"></div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-3">
                      <div class="flex items-center space-x-2 req-row" data-req="minLength">
                        <i data-lucide="alert-circle" class="w-4 h-4 text-slate-300 flex-shrink-0"></i>
                        <span class="text-xs text-slate-500">At least 8 characters</span>
                      </div>
                      <div class="flex items-center space-x-2 req-row" data-req="hasUppercase">
                        <i data-lucide="alert-circle" class="w-4 h-4 text-slate-300 flex-shrink-0"></i>
                        <span class="text-xs text-slate-500">One uppercase letter</span>
                      </div>
                      <div class="flex items-center space-x-2 req-row" data-req="hasLowercase">
                        <i data-lucide="alert-circle" class="w-4 h-4 text-slate-300 flex-shrink-0"></i>
                        <span class="text-xs text-slate-500">One lowercase letter</span>
                      </div>
                      <div class="flex items-center space-x-2 req-row" data-req="hasNumber">
                        <i data-lucide="alert-circle" class="w-4 h-4 text-slate-300 flex-shrink-0"></i>
                        <span class="text-xs text-slate-500">One number</span>
                      </div>
                      <div class="flex items-center space-x-2 req-row" data-req="hasSpecialChar">
                        <i data-lucide="alert-circle" class="w-4 h-4 text-slate-300 flex-shrink-0"></i>
                        <span class="text-xs text-slate-500">One special character</span>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Confirm Password -->
                <div class="space-y-2">
                  <label for="confirm_password" class="flex items-center text-sm font-semibold text-slate-700">
                    Confirm Password <span class="text-[#E5097F] ml-1">*</span>
                  </label>
                  <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                      <i data-lucide="lock" class="w-5 h-5 text-slate-400"></i>
                    </div>
                    <input
                      type="password"
                      id="confirm_password"
                      name="confirm_password"
                      required
                      autocomplete="new-password"
                      placeholder="Re-enter new password"
                      oninput="checkPasswordMatch()"
                      class="w-full pl-12 pr-12 py-3.5 border-2 border-slate-200 rounded-xl bg-white text-slate-700 font-medium
                             focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                             transition-all duration-200 hover:border-[#4A154B]/40"
                    />
                    <button
                      type="button"
                      onclick="togglePasswordVisibility('confirm_password', this)"
                      class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-[#4A154B] transition-colors"
                      aria-label="Toggle password visibility"
                    >
                      <i data-lucide="eye" class="w-5 h-5"></i>
                    </button>
                    <div id="confirm-check" class="absolute inset-y-0 right-12 flex items-center pointer-events-none hidden">
                      <i data-lucide="check-circle" class="w-5 h-5 text-emerald-500"></i>
                    </div>
                  </div>
                  <p id="match-error" class="text-sm text-red-500 flex items-center mt-1 hidden">
                    <i data-lucide="alert-circle" class="w-4 h-4 mr-1"></i>
                    Passwords do not match
                  </p>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row justify-end items-center gap-3 pt-4 border-t border-slate-200">

                  <a href="dashboard.php"
                     class="group/btn relative w-full sm:w-auto overflow-hidden rounded-xl border-2 border-slate-200 bg-white hover:border-[#4A154B]/40 hover:bg-slate-50 transition-all duration-300 hover:scale-[1.02] active:scale-95">
                    <div class="relative flex items-center justify-center space-x-2 py-3 px-6 text-slate-700 font-bold group-hover/btn:text-[#4A154B] transition-colors">
                      <i data-lucide="arrow-left" class="w-4 h-4 group-hover/btn:-translate-x-1 transition-transform duration-300"></i>
                      <span>Back to Dashboard</span>
                    </div>
                  </a>

                  <button
                    type="submit"
                    class="group/btn relative w-full sm:w-auto overflow-hidden rounded-xl shadow-lg shadow-purple-500/30 hover:shadow-2xl hover:shadow-purple-500/50 hover:scale-[1.02] active:scale-95 transition-all duration-300"
                  >
                    <div class="absolute inset-0 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F]"></div>
                    <div class="absolute inset-0 bg-gradient-to-r from-[#E5097F] via-[#8B1E7E] to-[#4A154B] opacity-0 group-hover/btn:opacity-100 transition-opacity duration-500"></div>
                    <div class="absolute inset-0 bg-[radial-gradient(circle_at_50%_50%,rgba(255,255,255,0.2),transparent_70%)] opacity-0 group-hover/btn:opacity-100 transition-opacity duration-500"></div>
                    <div class="relative flex items-center justify-center space-x-2 py-3 px-8 text-white font-bold">
                      <i data-lucide="shield" class="w-5 h-5 group-hover/btn:rotate-12 transition-transform duration-300"></i>
                      <span>Change Password</span>
                    </div>
                  </button>

                </div>

                <!-- Security Tips -->
                <div class="mt-6 p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl border border-blue-200">
                  <h3 class="text-sm font-bold text-blue-900 mb-2 flex items-center">
                    <i data-lucide="shield" class="w-4 h-4 mr-2"></i>
                    Security Tips
                  </h3>
                  <ul class="text-xs text-blue-800 space-y-1 list-disc list-inside">
                    <li>Never share your password with anyone</li>
                    <li>Use a unique password for each account</li>
                    <li>Change your password regularly (every 90 days)</li>
                    <li>Avoid using personal information in your password</li>
                  </ul>
                </div>

              </form>

            </div>
          </div>
        </div>

      </main>

      <!-- ============ FOOTER ============ -->
      <footer class="bg-gradient-to-r from-purple-200 via-pink-100 to-purple-200 border-t border-purple-200/60 mt-auto">
        <div class="px-6 py-6">
          <div class="max-w-7xl mx-auto text-center">
            <p class="text-xs text-slate-700">
              &copy; <?= date('Y') ?>
              <span class="font-bold text-[#006837]">Rajagiri College of Social Sciences</span>.
              All rights reserved.
            </p>
            <p class="text-xs text-slate-700 mt-1">
              Powered by
              <span class="font-bold bg-gradient-to-r from-[#4A154B] to-[#E5097F] bg-clip-text text-transparent ml-1">
                Oréll Grievance
              </span>
            </p>
          </div>
        </div>
      </footer>

    </div>
  </div>

  <!-- ============================================================= -->
  <!-- CUSTOM LOGOUT CONFIRMATION MODAL                              -->
  <!-- ============================================================= -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeLogoutModal()"></div>

    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden">
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

  <!-- ====================== SCRIPTS ====================== -->
  <script>
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }

    // ---- Toggle Password Visibility ----
    function togglePasswordVisibility(inputId, btn) {
      const input = document.getElementById(inputId);
      if (!input) return;

      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';

      const icon = btn.querySelector('i');
      if (icon && typeof lucide !== 'undefined') {
        icon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
        lucide.createIcons({ targets: [icon] });
      }
    }

    // ---- Password Strength Meter ----
    function updatePasswordStrength(password) {
      const wrapper = document.getElementById('strength-wrapper');
      const bar     = document.getElementById('strength-bar');
      const text    = document.getElementById('strength-text');

      if (!wrapper || !bar || !text) return;

      if (password === '') {
        wrapper.classList.add('hidden');
        return;
      }
      wrapper.classList.remove('hidden');

      const reqs = {
        minLength:      password.length >= 8,
        hasUppercase:   /[A-Z]/.test(password),
        hasLowercase:   /[a-z]/.test(password),
        hasNumber:      /[0-9]/.test(password),
        hasSpecialChar: /[!@#$%^&*(),.?":{}|<>]/.test(password),
      };

      const score = Object.values(reqs).filter(Boolean).length;

      const percent = (score / 5) * 100;
      bar.style.width = percent + '%';

      bar.classList.remove('bg-red-500', 'bg-yellow-500', 'bg-blue-500', 'bg-emerald-500');
      text.classList.remove('text-red-500', 'text-yellow-500', 'text-blue-500', 'text-emerald-500');

      if (score <= 2) {
        bar.classList.add('bg-red-500');
        text.classList.add('text-red-500');
        text.textContent = 'Weak';
      } else if (score <= 3) {
        bar.classList.add('bg-yellow-500');
        text.classList.add('text-yellow-500');
        text.textContent = 'Fair';
      } else if (score <= 4) {
        bar.classList.add('bg-blue-500');
        text.classList.add('text-blue-500');
        text.textContent = 'Good';
      } else {
        bar.classList.add('bg-emerald-500');
        text.classList.add('text-emerald-500');
        text.textContent = 'Strong';
      }

      document.querySelectorAll('.req-row').forEach(function (row) {
        const key = row.getAttribute('data-req');
        const icon = row.querySelector('i');
        const label = row.querySelector('span');
        if (!key || !icon) return;

        const met = !!reqs[key];

        icon.setAttribute('data-lucide', met ? 'check-circle' : 'alert-circle');
        icon.classList.toggle('text-emerald-500', met);
        icon.classList.toggle('text-slate-300', !met);

        if (label) {
          label.classList.toggle('text-emerald-700', met);
          label.classList.toggle('text-slate-500', !met);
        }
      });

      if (typeof lucide !== 'undefined') {
        lucide.createIcons({ targets: document.querySelectorAll('.req-row i') });
      }
    }

    // ---- Confirm Password Match Check ----
    function checkPasswordMatch() {
      const newPwd     = document.getElementById('new_password').value;
      const confirmPwd = document.getElementById('confirm_password').value;
      const checkIcon  = document.getElementById('confirm-check');
      const errorMsg   = document.getElementById('match-error');
      const confirmInp = document.getElementById('confirm_password');

      if (confirmPwd === '') {
        checkIcon.classList.add('hidden');
        errorMsg.classList.add('hidden');
        confirmInp.classList.remove('border-emerald-300', 'border-red-300');
        return;
      }

      if (newPwd === confirmPwd) {
        checkIcon.classList.remove('hidden');
        errorMsg.classList.add('hidden');
        confirmInp.classList.add('border-emerald-300');
        confirmInp.classList.remove('border-red-300');
      } else {
        checkIcon.classList.add('hidden');
        errorMsg.classList.remove('hidden');
        confirmInp.classList.add('border-red-300');
        confirmInp.classList.remove('border-emerald-300');
      }
    }

    // ============================================================
    // SIDEBAR EXPAND / COLLAPSE
    // ============================================================
    (function () {
      const toggleBtn = document.getElementById('sidebarToggle');
      const sidebar   = document.getElementById('staffSidebar');
      const main      = document.getElementById('staffMain');
      if (!toggleBtn || !sidebar || !main) return;

      const labels   = sidebar.querySelectorAll('.sidebar-label');
      const tooltips = sidebar.querySelectorAll('.sidebar-tooltip');

      let expanded = false;

      toggleBtn.addEventListener('click', function () {
        expanded = !expanded;

        if (expanded) {
          sidebar.classList.remove('w-20');
          sidebar.classList.add('w-64');
          main.classList.remove('ml-20');
          main.classList.add('ml-64');

          labels.forEach(function (el) {
            el.classList.remove('opacity-0', 'w-0');
            el.classList.add('opacity-100', 'w-auto');
          });
          tooltips.forEach(function (el) { el.classList.add('hidden'); });
        } else {
          sidebar.classList.add('w-20');
          sidebar.classList.remove('w-64');
          main.classList.add('ml-20');
          main.classList.remove('ml-64');

          labels.forEach(function (el) {
            el.classList.add('opacity-0', 'w-0');
            el.classList.remove('opacity-100', 'w-auto');
          });
          tooltips.forEach(function (el) { el.classList.remove('hidden'); });
        }

        setTimeout(function () {
          if (typeof lucide !== 'undefined') lucide.createIcons();
        }, 250);
      });
    })();

    // ============================================================
    // STAFF PROFILE DROPDOWN
    // ============================================================
    (function () {
      const btn       = document.getElementById('staff-dropdown-btn');
      const menu      = document.getElementById('staff-dropdown-menu');
      const chevron   = document.getElementById('staff-chevron');
      const container = document.getElementById('staff-dropdown-container');

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
    // LOGOUT CONFIRMATION MODAL
    // ============================================================
    const logoutConfirmModal = document.getElementById('logoutConfirmModal');
    const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');

    const LOGOUT_URL = '../logout.php?role=staff';

    function openLogoutModal() {
      logoutConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
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

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) {
        closeLogoutModal();
      }
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>