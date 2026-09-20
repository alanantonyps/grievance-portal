<?php
/**
 * login.php
 * ---------------------------------------------------------------------------
 * Unified Grievance Redressal Portal Login
 * Rajagiri College of Social Sciences
 *
 * Roles supported (URL param): admin | student | parent | staff | management
 * Usage:  login.php?role=admin
 *
 * DB role ENUM values (UPPERCASE):
 *   ADMIN | STUDENT | PARENT | TEACHER | NON_TEACHING | MANAGEMENT
 *
 * DB status ENUM values:
 *   Pending | Approved | Rejected | Terminated
 *
 * Registration is allowed only for:
 *   STUDENT | PARENT | TEACHER | NON_TEACHING
 *
 * NOTE: The "staff" URL key maps to BOTH the TEACHER and NON_TEACHING DB roles.
 *       Both staff types share a single login portal and dashboard.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. SESSION
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// ROLE DASHBOARD MAP (single source of truth for redirection)
//   key   = DB role (UPPERCASE)
//   value = target dashboard path
// ---------------------------------------------------------------------------
$roleDashboardMap = [
    'ADMIN'        => 'admin/dashboard.php',
    'STUDENT'      => 'student/dashboard.php',
    'PARENT'       => 'parent/dashboard.php',
    'TEACHER'      => 'staff/dashboard.php',      // Unified staff dashboard
    'NON_TEACHING' => 'staff/dashboard.php',      // Unified staff dashboard
    'MANAGEMENT'   => 'management/dashboard.php',
];

// If already logged in, redirect away from login page to the correct dashboard
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    $currentRole = strtoupper((string) $_SESSION['role']);
    $targetPath  = $roleDashboardMap[$currentRole] ?? 'student/dashboard.php';

    header('Location: ' . $targetPath);
    exit;
}

// ---------------------------------------------------------------------------
// 2. DATABASE CONNECTION (mysqli)
// ---------------------------------------------------------------------------
$dbError = null;
$conn    = null;

$dbFile = __DIR__ . '/db_connect.php';

if (!file_exists($dbFile)) {
    $dbError = 'Database configuration file (db_connect.php) is missing.';
} else {
    require_once $dbFile;

    // Ensure we have a valid mysqli connection
    if (!isset($conn) || !($conn instanceof mysqli)) {
        $conn = @new mysqli('localhost', 'root', '', 'grievance_db');

        if ($conn->connect_error) {
            $dbError = 'Unable to connect to the database.';
            $conn    = null;
        } else {
            $conn->set_charset('utf8mb4');
        }
    }

    if ($conn && $conn->connect_errno) {
        $dbError = 'Database connection failed: ' . $conn->connect_error;
        $conn    = null;
    }
}

// ---------------------------------------------------------------------------
// 3. ROLE MAP
//    key    = URL param (lowercase, used in login.php?role=xxx)
//    value  = full configuration
//      - 'db_role'       : single string OR array of allowed DB roles
//      - 'register_page' : dedicated registration page for that role
// ---------------------------------------------------------------------------
$roleConfig = [
    'admin' => [
        'db_role'       => 'ADMIN',
        'title'         => 'Administrator Portal',
        'subtitle'      => 'Core Control & System Governance',
        'icon'          => 'shield-check',
        'placeholder'   => 'Enter your Admin Username',
        'label'         => 'Admin Username',
        'notice'        => 'System Administrator Access Only',
        'image'         => 'https://images.unsplash.com/photo-1551836022-d5d88e9218df?auto=format&fit=crop&w=800&q=80',
        'imageTitle'    => 'System Control Center',
        'imageDesc'     => 'System Configuration, User Management & Analytics',
        'supportMail'   => 'admin.support@rajagiri.edu',
        'supportTag'    => 'Tech Support',
        'register_page' => null, // Admin cannot self-register
    ],
    'student' => [
        'db_role'       => 'STUDENT',
        'title'         => 'Student Portal',
        'subtitle'      => 'Secure access to your grievance dashboard',
        'icon'          => 'graduation-cap',
        'placeholder'   => 'Enter your Student Username',
        'label'         => 'Student Username',
        'notice'        => 'Enrolled Students Only',
        'image'         => 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?auto=format&fit=crop&w=800&q=80',
        'imageTitle'    => 'Student Portal',
        'imageDesc'     => 'Your gateway to fair and transparent grievance resolution',
        'supportMail'   => 'student.grievance@rajigarircss.edu',
        'supportTag'    => 'Helpdesk Email',
        'register_page' => 'student_register.php',
    ],
    'parent' => [
        'db_role'       => 'PARENT',
        'title'         => 'Parent Portal',
        'subtitle'      => "Monitor Your Ward's Grievances",
        'icon'          => 'users',
        'placeholder'   => 'Enter your Email',
        'label'         => 'Parent Email',
        'notice'        => 'For Registered Parents & Guardians',
        'image'         => 'https://images.unsplash.com/photo-1523240795612-9a054b0db644?auto=format&fit=crop&w=800&q=80',
        'imageTitle'    => 'Parent Portal',
        'imageDesc'     => "Stay connected with your ward's academic journey",
        'supportMail'   => 'parent.help@rajigarircss.edu',
        'supportTag'    => 'Support Email',
        'register_page' => 'parent_register.php',
    ],
    'staff' => [
        'db_role'       => ['TEACHER', 'NON_TEACHING'], // Either role logs in here
        'title'         => 'Staff Portal',
        'subtitle'      => 'Teaching & Non-Teaching Staff Access',
        'icon'          => 'briefcase',
        'placeholder'   => 'Enter your Staff Username',
        'label'         => 'Staff Username',
        'notice'        => 'Verified Staff Members Only',
        'image'         => 'https://images.unsplash.com/photo-1524178232363-1fb2b075b655?auto=format&fit=crop&w=800&q=80',
        'imageTitle'    => 'Staff Portal',
        'imageDesc'     => 'Dedicated portal for teaching and support staff',
        'supportMail'   => 'staff.help@rajagiri.edu',
        'supportTag'    => 'Support Email',
        'register_page' => 'staff_register.php',
    ],
    'management' => [
        'db_role'       => 'MANAGEMENT',
        'title'         => 'Management & Grievance Portal',
        'subtitle'      => 'Committee Oversight & Resolution Management',
        'icon'          => 'layers',
        'placeholder'   => 'Enter your Management Username',
        'label'         => 'Management Username',
        'notice'        => 'Authorized Committee Members Only',
        'image'         => 'https://images.unsplash.com/photo-1517245386807-bb43f82c33c4?auto=format&fit=crop&w=800&q=80',
        'imageTitle'    => 'Management & Grievance Cell',
        'imageDesc'     => 'Redressal Committee Oversight & Escalation Management',
        'supportMail'   => 'grievance.committee@rajagiri.edu',
        'supportTag'    => 'Committee Helpdesk',
        'register_page' => null, // Management cannot self-register
    ],
];

// Roles allowed to register (by URL key)
$registrationAllowedRoles = ['student', 'parent', 'staff'];

// ---------------------------------------------------------------------------
// 4. RESOLVE ROLE FROM QUERY STRING
// ---------------------------------------------------------------------------
$roleKey = isset($_GET['role']) ? strtolower(trim((string) $_GET['role'])) : 'student';
if (!array_key_exists($roleKey, $roleConfig)) {
    $roleKey = 'student';
}
$role = $roleConfig[$roleKey];

// Whether this role can register
$canRegister = in_array($roleKey, $registrationAllowedRoles, true);

// ---------------------------------------------------------------------------
// 5. HANDLE POST SUBMISSION
// ---------------------------------------------------------------------------
$errors     = [];
$loginInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---- CSRF check ----
    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken   = $_SESSION['csrf_token'] ?? '';

    if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }

    // ---- Input collection ----
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // Role from POST, else from GET param
    if (isset($_POST['role']) && array_key_exists($_POST['role'], $roleConfig)) {
        $roleKey = $_POST['role'];
    }
    $role = $roleConfig[$roleKey];
    $canRegister = in_array($roleKey, $registrationAllowedRoles, true);

    // ---- Normalize the allowed DB roles to an array of UPPERCASE strings ----
    $allowedDbRoles = is_array($role['db_role'])
        ? array_map('strtoupper', $role['db_role'])
        : [strtoupper((string) $role['db_role'])];

    $loginInput = $username;

    // ---- Basic validation ----
    if ($username === '') {
        $errors[] = 'Please enter your username.';
    }
    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    // ---- Authenticate ----
    if (empty($errors)) {

        if ($conn === null) {
            $errors[] = $dbError ?: 'Database is unavailable. Please try again later.';
        } else {
            try {
                $sql = "SELECT id, username, password, role, status
                        FROM users
                        WHERE username = ?
                        LIMIT 1";

                $stmt = $conn->prepare($sql);

                if (!$stmt) {
                    throw new Exception('Query preparation failed: ' . $conn->error);
                }

                $stmt->bind_param('s', $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result === false || $result->num_rows === 0) {
                    $errors[] = 'Invalid username or password.';
                } else {
                    $user = $result->fetch_assoc();

                    // Normalize DB values
                    $dbUserRole   = strtoupper((string) ($user['role']   ?? ''));
                    $dbUserStatus = ucfirst(strtolower((string) ($user['status'] ?? '')));

                    // ---- Role match check (supports arrays for unified portals) ----
                    if (!in_array($dbUserRole, $allowedDbRoles, true)) {
                        $errors[] = 'Invalid username or password for this portal.';
                    }
                    // ---- Status check (must be 'Approved') ----
                    elseif ($dbUserStatus !== 'Approved') {
                        $errors[] = 'Your account status is ' . $dbUserStatus . '. Access is restricted until approved.';
                    }
                    // ---- Password verification ----
                    elseif (!password_verify($password, $user['password'])) {
                        $errors[] = 'Invalid username or password.';
                    }
                    // ---- SUCCESS ----
                    else {
                        session_regenerate_id(true);

                        $_SESSION['user_id']    = (int) $user['id'];
                        $_SESSION['username']   = $user['username'];
                        $_SESSION['role']       = $dbUserRole;
                        $_SESSION['logged_in']  = true;
                        $_SESSION['login_time'] = time();

                        $stmt->close();
                        $conn->close();

                        // ---- Redirect to role-specific dashboard ----
                        $targetPath = $roleDashboardMap[$dbUserRole] ?? 'student/dashboard.php';

                        header('Location: ' . $targetPath);
                        exit;
                    }
                }

                $stmt->close();

            } catch (Exception $ex) {
                error_log('[Login Error] ' . $ex->getMessage());
                $errors[] = 'A system error occurred while signing you in. Please try again.';
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 6. CSRF TOKEN
// ---------------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ---------------------------------------------------------------------------
// 7. HELPER
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= e($role['title']) ?> — Rajagiri College of Social Sciences</title>
  <link rel="icon" type="image/svg+xml" href="public/favicon.svg" />

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Lucide Icons CDN -->
  <script src="https://unpkg.com/lucide@latest"></script>

  <!-- Tailwind Theme Config -->
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brandPurple: '#4A154B',
            brandPink: '#E5097F',
            brandGreen: '#006837',
            brandGold: '#C5A059'
          }
        }
      }
    };
  </script>

  <!-- Local Page CSS -->
  <link rel="stylesheet" href="assets/css/index.css" />
</head>

<body class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-100 relative overflow-x-hidden font-sans text-slate-800 antialiased">

  <!-- ====================== BACKGROUND DECOR ====================== -->
  <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
    <div class="absolute top-0 left-0 w-[600px] h-[600px] bg-gradient-to-br from-[#8B1E7E]/10 to-transparent rounded-full blur-3xl -translate-x-1/2 -translate-y-1/2"></div>
    <div class="absolute bottom-0 right-0 w-[700px] h-[700px] bg-gradient-to-tl from-[#006837]/10 to-transparent rounded-full blur-3xl translate-x-1/3 translate-y-1/3"></div>
    <div class="absolute top-1/2 left-1/2 w-[500px] h-[500px] bg-gradient-to-r from-[#E5097F]/5 to-transparent rounded-full blur-3xl -translate-x-1/2 -translate-y-1/2"></div>
  </div>

  <!-- ====================== HEADER ====================== -->
  <header class="fixed top-0 left-0 right-0 z-50 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#006837] shadow-lg shadow-[#4A154B]/20">
    <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_50%,rgba(255,255,255,0.08),transparent_50%)] pointer-events-none"></div>
    <div class="absolute inset-0 bg-[radial-gradient(circle_at_80%_50%,rgba(255,255,255,0.05),transparent_50%)] pointer-events-none"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex justify-between items-center h-16 md:h-20">

        <div class="flex items-center space-x-3 sm:space-x-4">
          <a href="index.php" class="relative group">
            <img src="public/rcss-logo.png" alt="RCSS Logo"
                 class="h-10 md:h-12 w-auto drop-shadow-lg transition-transform group-hover:scale-105" />
          </a>
          <div class="hidden sm:flex items-center h-10">
            <div class="w-px h-full bg-gradient-to-b from-transparent via-white/50 to-transparent"></div>
          </div>
          <div class="hidden sm:block relative group">
            <div class="absolute -inset-1 bg-gradient-to-r from-white/40 via-white/60 to-white/40 rounded-xl blur-sm opacity-60 group-hover:opacity-80 transition-opacity duration-300"></div>
            <div class="relative bg-white/95 backdrop-blur-sm rounded-xl px-4 py-2 shadow-lg shadow-black/10 border border-white/80 transition-all duration-300 group-hover:bg-white group-hover:shadow-xl">
              <img src="public/orel-grievance.png" alt="Oréll Grievance"
                   class="h-7 md:h-9 w-auto object-contain" />
            </div>
          </div>
        </div>

        <a href="index.php"
           class="group relative inline-flex items-center space-x-2 px-4 sm:px-5 py-2.5 rounded-xl bg-white/10 hover:bg-white/20 backdrop-blur-md border border-white/30 hover:border-white/60 text-white transition-all duration-300 shadow-lg shadow-black/10 hover:shadow-xl hover:-translate-y-0.5">
          <i data-lucide="arrow-left" class="w-4 h-4 transition-transform duration-300 group-hover:-translate-x-1"></i>
          <span class="text-sm font-semibold tracking-wide">Back to Home</span>
          <i data-lucide="home" class="w-4 h-4 opacity-0 hidden sm:block transition-opacity duration-300 group-hover:opacity-100"></i>
        </a>

      </div>
    </div>
  </header>

  <!-- ====================== MAIN ====================== -->
  <main class="relative z-10 w-full min-h-screen flex items-start lg:items-center justify-center px-4 sm:px-6 lg:px-8 pt-24 md:pt-28 pb-12 md:pb-16">
    <div class="w-full max-w-6xl grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-12 items-center">

      <!-- ============ LEFT: LOGIN FORM ============ -->
      <div class="w-full max-w-md mx-auto lg:max-w-none lg:pr-8">
        <div class="relative group">
          <div class="absolute -inset-0.5 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] rounded-2xl blur opacity-20 group-hover:opacity-30 transition duration-500"></div>

          <div class="relative bg-white rounded-2xl shadow-2xl shadow-slate-200/60 border border-slate-100 p-6 sm:p-8 transition-all duration-300">

            <!-- Header -->
            <div class="text-center mb-6">
              <div class="relative inline-flex mb-4">
                <div class="absolute inset-0 bg-gradient-to-br from-[#4A154B] via-[#8B1E7E] to-[#E5097F] rounded-2xl blur-md opacity-50"></div>
                <div class="relative inline-flex items-center justify-center w-14 h-14 md:w-16 md:h-16 rounded-2xl bg-gradient-to-br from-[#4A154B] via-[#8B1E7E] to-[#E5097F] text-white shadow-lg shadow-pink-500/30">
                  <i data-lucide="<?= e($role['icon']) ?>" class="w-7 h-7 md:w-8 md:h-8"></i>
                </div>
              </div>
              <h1 class="text-xl md:text-2xl font-bold bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] bg-clip-text text-transparent mb-2">
                Log in with your credentials
              </h1>
              <p class="text-slate-500 text-xs md:text-sm"><?= e($role['title']) ?> — <?= e($role['subtitle']) ?></p>
            </div>

            <!-- Inline Error Alerts -->
            <?php if (!empty($errors)): ?>
              <div class="mb-5 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3">
                <div class="flex items-start space-x-2">
                  <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
                  <ul class="text-sm text-red-700 space-y-1">
                    <?php foreach ($errors as $err): ?>
                      <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
            <?php endif; ?>

            <!-- Database Error Banner -->
            <?php if ($dbError && empty($errors)): ?>
              <div class="mb-5 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3">
                <div class="flex items-start space-x-2">
                  <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
                  <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
                </div>
              </div>
            <?php endif; ?>

            <!-- Form -->
            <form action="login.php?role=<?= e($roleKey) ?>" method="POST" class="space-y-4" novalidate>
              <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
              <input type="hidden" name="role" value="<?= e($roleKey) ?>" />

              <!-- Username -->
              <div class="space-y-2">
                <label for="username" class="block text-sm font-semibold text-slate-700">
                  <?= e($role['label']) ?>
                </label>
                <div class="relative">
                  <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <i data-lucide="user" class="w-5 h-5"></i>
                  </div>
                  <input
                    id="username"
                    name="username"
                    type="text"
                    value="<?= e($loginInput) ?>"
                    placeholder="<?= e($role['placeholder']) ?>"
                    required
                    autocomplete="username"
                    class="w-full pl-11 md:pl-12 pr-4 py-3 border-2 border-slate-200 rounded-xl
                           focus:outline-none focus:border-[#8B1E7E] focus:ring-4 focus:ring-[#8B1E7E]/10
                           transition-all duration-300 bg-slate-50/50 focus:bg-white text-slate-900
                           placeholder-slate-400 font-medium text-sm md:text-base"
                  />
                </div>
              </div>

              <!-- Password -->
              <div class="space-y-2">
                <label for="password" class="block text-sm font-semibold text-slate-700">Password</label>
                <div class="relative">
                  <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <i data-lucide="lock" class="w-5 h-5"></i>
                  </div>
                  <input
                    id="password"
                    name="password"
                    type="password"
                    placeholder="Enter your password"
                    required
                    autocomplete="current-password"
                    class="w-full pl-11 md:pl-12 pr-12 py-3 border-2 border-slate-200 rounded-xl
                           focus:outline-none focus:border-[#8B1E7E] focus:ring-4 focus:ring-[#8B1E7E]/10
                           transition-all duration-300 bg-slate-50/50 focus:bg-white text-slate-900
                           placeholder-slate-400 font-medium text-sm md:text-base"
                  />
                  <button
                    type="button"
                    id="togglePassword"
                    aria-label="Toggle password visibility"
                    class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-[#8B1E7E] transition-colors">
                    <i data-lucide="eye" class="w-5 h-5" id="eyeIcon"></i>
                  </button>
                </div>
              </div>

              <!-- Forgot Password -->
              <div class="flex justify-end">
                <a href="forgot-password.php?role=<?= e($roleKey) ?>"
                   class="group/link inline-flex items-center space-x-1 text-xs md:text-sm font-medium text-[#8B1E7E] hover:text-[#4A154B] transition-colors">
                  <span>Forgotten password?</span>
                  <i data-lucide="arrow-right" class="w-3 h-3 transition-transform group-hover/link:translate-x-0.5"></i>
                </a>
              </div>

              <!-- Submit -->
              <button type="submit" class="relative w-full overflow-hidden rounded-xl mt-2 group/btn">
                <div class="absolute inset-0 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] transition-all duration-300"></div>
                <div class="absolute inset-0 bg-gradient-to-r from-[#E5097F] via-[#8B1E7E] to-[#4A154B] opacity-0 group-hover/btn:opacity-100 transition-opacity duration-500"></div>
                <div class="relative flex items-center justify-center space-x-2 py-3.5 px-6 text-white font-semibold shadow-lg shadow-pink-500/30 group-hover/btn:shadow-pink-500/50 transition-all duration-300">
                  <span>Log in</span>
                  <i data-lucide="arrow-right" class="w-4 h-4 transition-transform group-hover/btn:translate-x-1"></i>
                </div>
              </button>
            </form>

            <!-- ============================================================
                 CREATE ACCOUNT SECTION (Dynamic — only for eligible roles)
                 ============================================================ -->
            <?php if ($canRegister && !empty($role['register_page'])): ?>
              <div class="mt-6 pt-6 border-t border-slate-100">
                <div class="text-center">
                  <p class="text-sm text-slate-500 mb-3">
                    Don't have an account yet?
                  </p>

                  <a href="<?= e($role['register_page']) ?>?role=<?= e($roleKey) ?>"
                     class="group/register relative inline-flex w-full items-center justify-center gap-2 px-5 py-3 rounded-xl
                            bg-white hover:bg-gradient-to-r hover:from-[#8B5FBF] hover:via-[#B14FB8] hover:to-[#F45D9E]
                            border-2 border-[#4A154B]/20 hover:border-transparent
                            text-[#4A154B] hover:text-white
                            font-semibold shadow-sm hover:shadow-lg hover:shadow-pink-500/30
                            transition-all duration-300 hover:-translate-y-0.5 active:scale-[0.98]">

                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full
                                 bg-[#4A154B]/10 group-hover/register:bg-white/20
                                 transition-colors duration-300">
                      <i data-lucide="user-plus" class="w-4 h-4"></i>
                    </span>

                    <span>Create an account</span>

                    <i data-lucide="arrow-right"
                       class="w-4 h-4 transition-transform duration-300 group-hover/register:translate-x-1"></i>
                  </a>

                  <p class="text-[11px] text-slate-400 mt-3 flex items-center justify-center gap-1">
                    <i data-lucide="shield-check" class="w-3 h-3"></i>
                    <span>Quick registration — approval within 24 hours</span>
                  </p>
                </div>
              </div>
            <?php endif; ?>

            <!-- Notice -->
            <div class="mt-5 text-center">
              <p class="inline-flex items-center justify-center space-x-2 text-xs md:text-sm text-slate-500">
                <i data-lucide="key-round" class="w-4 h-4 text-[#006837]"></i>
                <span><?= e($role['notice']) ?></span>
              </p>
            </div>

          </div>
        </div>
      </div>

      <!-- ============ RIGHT: BRANDING PANEL ============ -->
      <div class="hidden lg:flex flex-col justify-center items-center relative">
        <div class="absolute inset-0 bg-gradient-to-br from-[#4A154B]/5 via-[#8B1E7E]/5 to-[#E5097F]/5 rounded-3xl"></div>
        <div class="absolute top-10 left-10 w-32 h-32 bg-gradient-to-br from-[#8B1E7E]/20 to-transparent rounded-full blur-2xl"></div>
        <div class="absolute bottom-10 right-10 w-40 h-40 bg-gradient-to-tl from-[#006837]/20 to-transparent rounded-full blur-2xl"></div>

        <div class="relative z-10 w-full max-w-lg p-6 xl:p-8">

          <div class="text-center mb-6 xl:mb-8">
            <div class="inline-flex items-center justify-center mb-4 bg-white/80 backdrop-blur-sm rounded-2xl p-3 xl:p-4 shadow-lg">
              <img src="public/rcss-logo.png" alt="RCSS Logo" class="h-14 xl:h-16 w-auto" />
            </div>
            <h2 class="text-xl xl:text-2xl 2xl:text-3xl font-bold bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#006837] bg-clip-text text-transparent">
              Rajagiri College of Social Sciences
            </h2>
            <p class="text-slate-600 mt-2 text-xs xl:text-sm italic">"Relentlessly Towards Excellence"</p>
          </div>

          <div class="relative group/image">
            <div class="absolute -inset-1 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] rounded-3xl blur opacity-30 group-hover/image:opacity-50 transition duration-500"></div>
            <div class="relative bg-white rounded-3xl overflow-hidden shadow-2xl">
              <div class="relative h-64 xl:h-72 2xl:h-80 w-full overflow-hidden bg-slate-200">
                <img
                  src="<?= e($role['image']) ?>"
                  alt="<?= e($role['imageTitle']) ?>"
                  class="w-full h-full object-cover transition-transform duration-500 group-hover/image:scale-105"
                  onerror="this.onerror=null;this.src='https://images.unsplash.com/photo-1523240795612-9a054b0db644?auto=format&fit=crop&w=800&q=80';"
                />
                <div class="absolute inset-0 bg-gradient-to-t from-[#4A154B]/60 via-[#4A154B]/20 to-transparent"></div>
                <div class="absolute bottom-0 left-0 right-0 p-4 xl:p-6 text-white">
                  <div class="flex items-center space-x-2 mb-1 xl:mb-2">
                    <i data-lucide="<?= e($role['icon']) ?>" class="w-4 h-4 xl:w-5 xl:h-5"></i>
                    <span class="font-semibold text-sm xl:text-base"><?= e($role['imageTitle']) ?></span>
                  </div>
                  <p class="text-xs xl:text-sm text-white/90"><?= e($role['imageDesc']) ?></p>
                </div>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 xl:grid-cols-2 gap-3 xl:gap-4 mt-6 xl:mt-8">
            <div class="bg-white/80 backdrop-blur-sm rounded-xl p-3 xl:p-4 border border-slate-200 shadow-lg">
              <div class="flex items-center space-x-3">
                <div class="w-9 h-9 xl:w-10 xl:h-10 rounded-lg bg-gradient-to-br from-[#006837] to-[#008a4a] flex items-center justify-center flex-shrink-0">
                  <i data-lucide="mail" class="w-4 h-4 xl:w-5 xl:h-5 text-white"></i>
                </div>
                <div class="min-w-0 flex-1">
                  <p class="text-[10px] xl:text-xs text-slate-500 font-medium"><?= e($role['supportTag']) ?></p>
                  <p class="text-[11px] xl:text-xs font-semibold text-slate-800 break-all leading-tight">
                    <?= e($role['supportMail']) ?>
                  </p>
                </div>
              </div>
            </div>

            <div class="bg-white/80 backdrop-blur-sm rounded-xl p-3 xl:p-4 border border-slate-200 shadow-lg">
              <div class="flex items-center space-x-3">
                <div class="w-9 h-9 xl:w-10 xl:h-10 rounded-lg bg-gradient-to-br from-[#4A154B] to-[#8B1E7E] flex items-center justify-center flex-shrink-0">
                  <i data-lucide="phone" class="w-4 h-4 xl:w-5 xl:h-5 text-white"></i>
                </div>
                <div class="min-w-0 flex-1">
                  <p class="text-[10px] xl:text-xs text-slate-500 font-medium">Helpline</p>
                  <p class="text-xs xl:text-sm font-semibold text-slate-800">+91 484 XXX XXXX</p>
                  <p class="text-[10px] xl:text-xs text-slate-500 mt-0.5">Mon-Fri, 9 AM - 5 PM</p>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>

    </div>
  </main>

  <!-- ====================== SCRIPTS ====================== -->
  <script>
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }

    // Toggle password visibility
    (function () {
      const toggleBtn = document.getElementById('togglePassword');
      const pwdInput  = document.getElementById('password');
      const eyeIcon   = document.getElementById('eyeIcon');

      if (!toggleBtn || !pwdInput || !eyeIcon) return;

      toggleBtn.addEventListener('click', function () {
        const isHidden = pwdInput.type === 'password';
        pwdInput.type = isHidden ? 'text' : 'password';

        eyeIcon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
        if (typeof lucide !== 'undefined') {
          lucide.createIcons({ targets: [eyeIcon] });
        }
      });
    })();

    window.scrollTo(0, 0);
  </script>

  <script src="assets/js/index.js"></script>
</body>
</html>