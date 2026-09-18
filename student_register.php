<?php
/**
 * student_register.php
 * ---------------------------------------------------------------------------
 * Student Self-Registration
 * Rajagiri College Grievance Redressal Portal
 *
 * Flow:
 *   1. Fetch active classes for the Class/Semester dropdown
 *   2. Validate required fields + duplicate email/admission check
 *   3. Insert into users (role = STUDENT, status = Pending)
 *   4. Insert into students (linked via user_id)
 *   5. Redirect to login.php?role=student&registered=success
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
// 2. DATABASE CONNECTION
// ---------------------------------------------------------------------------
$dbError = null;
$conn    = null;

$dbFile = __DIR__ . '/db_connect.php';

if (!file_exists($dbFile)) {
    $dbError = 'Database configuration file (db_connect.php) is missing.';
} else {
    require_once $dbFile;

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
// 3. HELPERS
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// 4. CSRF TOKEN
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
// 5. FETCH ACTIVE CLASSES (for dropdown)
// ---------------------------------------------------------------------------
$classOptions = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, class_name FROM classes WHERE status = 'Active' ORDER BY class_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $classOptions[] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Classes] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 6. HANDLE FORM SUBMISSION
// ---------------------------------------------------------------------------
$errors  = [];
$success = false;

$formData = [
    'name'             => '',
    'gender'           => '',
    'admission_number' => '',
    'class_id'         => '',
    'email'            => '',
    'contact_number'   => '',
    'guardian_name'    => '',
    'password'         => '',
    'address'          => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---- CSRF check ----
    $submittedToken = $_POST['csrf_token'] ?? '';
    if ($submittedToken === '' || !hash_equals($csrfToken, (string) $submittedToken)) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }

    // ---- Collect input ----
    $formData['name']             = trim((string) ($_POST['name']             ?? ''));
    $formData['gender']           = trim((string) ($_POST['gender']           ?? ''));
    $formData['admission_number'] = trim((string) ($_POST['admission_number'] ?? ''));
    $formData['class_id']         = trim((string) ($_POST['class_id']         ?? ''));
    $formData['email']            = trim((string) ($_POST['email']            ?? ''));
    $formData['contact_number']   = trim((string) ($_POST['contact_number']   ?? ''));
    $formData['guardian_name']    = trim((string) ($_POST['guardian_name']    ?? ''));
    $formData['password']         = (string)       ($_POST['password']         ?? '');
    $formData['address']          = trim((string) ($_POST['address']          ?? ''));

    // ---- Validation ----
    if ($formData['name'] === '') {
        $errors[] = 'Student Name is required.';
    }
    if (!in_array($formData['gender'], ['Male', 'Female', 'Other'], true)) {
        $errors[] = 'Please select a valid Gender.';
    }
    if ($formData['admission_number'] === '') {
        $errors[] = 'Admission Number is required.';
    }
    if ($formData['class_id'] === '' || !ctype_digit($formData['class_id'])) {
        $errors[] = 'Please select a valid Class/Semester.';
    }
    if ($formData['email'] === '') {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($formData['contact_number'] === '') {
        $errors[] = 'Contact Number is required.';
    } elseif (!preg_match('/^[0-9]{10}$/', $formData['contact_number'])) {
        $errors[] = 'Contact Number must be exactly 10 digits.';
    }
    if ($formData['guardian_name'] === '') {
        $errors[] = 'Guardian Name is required.';
    }
    if ($formData['password'] === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($formData['password']) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    }

    // ---- Duplicate checks ----
    if (empty($errors) && $conn instanceof mysqli) {
        try {
            // Email as username — check users table
            $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            if ($chk) {
                $chk->bind_param('s', $formData['email']);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $errors[] = 'This email is already registered. Please log in or use a different email.';
                }
                $chk->close();
            }

            // Email uniqueness in students table
            $chk2 = $conn->prepare("SELECT id FROM students WHERE email = ? LIMIT 1");
            if ($chk2) {
                $chk2->bind_param('s', $formData['email']);
                $chk2->execute();
                if ($chk2->get_result()->num_rows > 0) {
                    $errors[] = 'This email is already in use by another student record.';
                }
                $chk2->close();
            }

            // Admission number uniqueness
            $chk3 = $conn->prepare("SELECT id FROM students WHERE admission_number = ? LIMIT 1");
            if ($chk3) {
                $chk3->bind_param('s', $formData['admission_number']);
                $chk3->execute();
                if ($chk3->get_result()->num_rows > 0) {
                    $errors[] = 'This Admission Number is already registered.';
                }
                $chk3->close();
            }
        } catch (Throwable $ex) {
            error_log('[Duplicate Check] ' . $ex->getMessage());
            $errors[] = 'A system error occurred while validating your details.';
        }
    }

    // ---- Insert ----
    if (empty($errors) && $conn instanceof mysqli) {
        try {
            $conn->begin_transaction();

            $classIdInt = (int) $formData['class_id'];

            // Verify the selected class is active
            $chkClass = $conn->prepare("SELECT id FROM classes WHERE id = ? AND status = 'Active' LIMIT 1");
            if ($chkClass) {
                $chkClass->bind_param('i', $classIdInt);
                $chkClass->execute();
                if ($chkClass->get_result()->num_rows === 0) {
                    $chkClass->close();
                    throw new Exception('The selected Class/Semester is not available.');
                }
                $chkClass->close();
            }

            // 1. Insert into users
            $hash = password_hash($formData['password'], PASSWORD_BCRYPT);
            $role = 'STUDENT';

            $stmtU = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, 'Pending')");
            if (!$stmtU) {
                throw new Exception('Failed to prepare user insert.');
            }
            $stmtU->bind_param('sss', $formData['email'], $hash, $role);
            $stmtU->execute();
            $newUserId = (int) $conn->insert_id;
            $stmtU->close();

            // 2. Insert into students
            $stmtS = $conn->prepare("INSERT INTO students
                                        (user_id, class_id, name, admission_number, email, contact_number, guardian_name, address)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmtS) {
                throw new Exception('Failed to prepare student insert.');
            }
            $stmtS->bind_param(
                'iissssss',
                $newUserId,
                $classIdInt,
                $formData['name'],
                $formData['admission_number'],
                $formData['email'],
                $formData['contact_number'],
                $formData['guardian_name'],
                $formData['address']
            );
            $stmtS->execute();
            $stmtS->close();

            $conn->commit();

            $_SESSION['flash_success'] = 'Your registration request has been submitted! Please wait for admin approval before logging in.';

            header('Location: login.php?role=student&registered=success');
            exit;

        } catch (Throwable $ex) {
            if ($conn instanceof mysqli) $conn->rollback();
            error_log('[Student Register] ' . $ex->getMessage());
            $errors[] = $ex->getMessage() ?: 'A system error occurred while creating your account. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Student Registration — Rajagiri College of Social Sciences</title>
  <link rel="icon" type="image/svg+xml" href="public/favicon.svg" />

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
          }
        }
      }
    };
  </script>

  <link rel="stylesheet" href="assets/css/index.css" />
</head>

<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">

  <!-- ====================== HEADER BANNER ====================== -->
  <div class="bg-gradient-to-r from-[#4A154B] via-[#6A2C8A] to-[#8B1E7E] py-10 md:py-12 text-center shadow-lg">
    <div class="max-w-3xl mx-auto px-4">
      <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-white/15 backdrop-blur-sm mb-4 ring-1 ring-white/30">
        <i data-lucide="graduation-cap" class="w-7 h-7 text-white"></i>
      </div>
      <h1 class="text-2xl md:text-3xl lg:text-4xl font-bold text-white tracking-tight">
        Student Registration
      </h1>
      <p class="text-white/80 text-sm mt-2">
        Rajagiri College of Social Sciences — Grievance Redressal Portal
      </p>
    </div>
  </div>

  <!-- ====================== FORM CARD ====================== -->
  <main class="flex-1 -mt-6 pb-16">
    <div class="max-w-4xl mx-auto px-4 sm:px-6">

      <div class="bg-white rounded-2xl shadow-xl border border-slate-200/70 p-6 sm:p-8 md:p-10">

        <!-- Error Alerts -->
        <?php if (!empty($errors)): ?>
          <div class="mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3">
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

        <!-- Database Error -->
        <?php if ($dbError && empty($errors)): ?>
          <div class="mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3">
            <div class="flex items-start space-x-2">
              <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
              <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
            </div>
          </div>
        <?php endif; ?>

        <form method="POST" action="student_register.php" class="space-y-6" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />

          <!-- Row 1: Student Name + Gender -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5 md:gap-8">

            <div class="space-y-2">
              <label for="name" class="block text-sm font-semibold text-slate-700">
                Student Name <span class="text-red-500">*</span>
              </label>
              <input type="text" name="name" id="name" required
                     value="<?= e($formData['name']) ?>"
                     placeholder="Student Name"
                     class="w-full px-4 py-3 border-2 border-slate-200 rounded-lg bg-white text-slate-800 font-medium
                            placeholder-slate-400 text-sm
                            focus:outline-none focus:border-[#8B1E7E] focus:ring-4 focus:ring-[#8B1E7E]/10
                            hover:border-[#8B1E7E]/40 transition-all" />
            </div>

            <div class="space-y-2">
              <label for="gender" class="block text-sm font-semibold text-slate-700">
                Gender <span class="text-red-500">*</span>
              </label>
              <select name="gender" id="gender" required
                      class="w-full px-4 py-3 border-2 border-slate-200 rounded-lg bg-white text-slate-800 font-medium
                             text-sm
                             focus:outline-none focus:border-[#8B1E7E] focus:ring-4 focus:ring-[#8B1E7E]/10
                             hover:border-[#8B1E7E]/40 transition-all">
                <option value="">Gender</option>
                <option value="Male"   <?= $formData['gender'] === 'Male'   ? 'selected' : '' ?>>Male</option>
                <option value="Female" <?= $formData['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                <option value="Other"  <?= $formData['gender'] === 'Other'  ? 'selected' : '' ?>>Other</option>
              </select>
            </div>

          </div>

          <!-- Row 2: Admission Number + Class/Semester -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5 md:gap-8">

            <div class="space-y-2">
              <label for="admission_number" class="block text-sm font-semibold text-slate-700">
                Admission Number <span class="text-red-500">*</span>
              </label>
              <input type="text" name="admission_number" id="admission_number" required
                     value="<?= e($formData['admission_number']) ?>"
                     placeholder="Registration Number"
                     class="w-full px-4 py-3 border-2 border-slate-200 rounded-lg bg-white text-slate-800 font-medium
                            placeholder-slate-400 text-sm
                            focus:outline-none focus:border-[#8B1E7E] focus:ring-4 focus:ring-[#8B1E7E]/10
                            hover:border-[#8B1E7E]/40 transition-all" />
            </div>

            <div class="space-y-2">
              <label for="class_id" class="block text-sm font-semibold text-slate-700">
                Class/Semester <span class="text-red-500">*</span>
              </label>
              <select name="class_id" id="class_id" required
                      class="w-full px-4 py-3 border-2 border-slate-200 rounded-lg bg-white text-slate-800 font-medium
                             text-sm
                             focus:outline-none focus:border-[#8B1E7E] focus:ring-4 focus:ring-[#8B1E7E]/10
                             hover:border-[#8B1E7E]/40 transition-all">
                <option value="">SELECT</option>
                <?php foreach ($classOptions as $opt): ?>
                  <option value="<?= (int) $opt['id'] ?>" <?= (string) $formData['class_id'] === (string) $opt['id'] ? 'selected' : '' ?>>
                    <?= e($opt['class_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

          </div>

          <!-- Row 3: Email + Contact Number -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5 md:gap-8">

            <div class="space-y-2">
              <label for="email" class="block text-sm font-semibold text-slate-700">
                Email <span class="text-red-500">*</span>
              </label>
              <input type="email" name="email" id="email" required
                     value="<?= e($formData['email']) ?>"
                     placeholder="Email"
                     class="w-full px-4 py-3 border-2 border-slate-200 rounded-lg bg-white text-slate-800 font-medium
                            placeholder-slate-400 text-sm
                            focus:outline-none focus:border-[#8B1E7E] focus:ring-4 focus:ring-[#8B1E7E]/10
                            hover:border-[#8B1E7E]/40 transition-all" />
              <p class="text-xs text-red-500 font-semibold">
                Email address will be used as username
              </p>
            </div>

            <div class="space-y-2">
              <label for="contact_number" class="block text-sm font-semibold text-slate-700">
                Contact Number <span class="text-red-500">*</span>
              </label>
              <input type="tel" name="contact_number" id="contact_number" required
                     inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10"
                     value="<?= e($formData['contact_number']) ?>"
                     placeholder="Contact Number"
                     class="w-full px-4 py-3 border-2 border-slate-200 rounded-lg bg-white text-slate-800 font-medium
                            placeholder-slate-400 text-sm
                            focus:outline-none focus:border-[#8B1E7E] focus:ring-4 focus:ring-[#8B1E7E]/10
                            hover:border-[#8B1E7E]/40 transition-all" />
            </div>

          </div>

          <!-- Row 4: Guardian Name + Password -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5 md:gap-8">

            <div class="space-y-2">
              <label for="guardian_name" class="block text-sm font-semibold text-slate-700">
                Guardian Name <span class="text-red-500">*</span>
              </label>
              <input type="text" name="guardian_name" id="guardian_name" required
                     value="<?= e($formData['guardian_name']) ?>"
                     placeholder="Guardian Name"
                     class="w-full px-4 py-3 border-2 border-slate-200 rounded-lg bg-white text-slate-800 font-medium
                            placeholder-slate-400 text-sm
                            focus:outline-none focus:border-[#8B1E7E] focus:ring-4 focus:ring-[#8B1E7E]/10
                            hover:border-[#8B1E7E]/40 transition-all" />
            </div>

            <div class="space-y-2">
              <label for="password" class="block text-sm font-semibold text-slate-700">
                Password <span class="text-red-500">*</span>
              </label>
              <div class="relative">
                <input type="password" name="password" id="password" required
                       minlength="6"
                       placeholder="Password"
                       class="w-full px-4 py-3 pr-12 border-2 border-slate-200 rounded-lg bg-white text-slate-800 font-medium
                              placeholder-slate-400 text-sm
                              focus:outline-none focus:border-[#8B1E7E] focus:ring-4 focus:ring-[#8B1E7E]/10
                              hover:border-[#8B1E7E]/40 transition-all" />
                <button type="button"
                        id="togglePassword"
                        aria-label="Toggle password visibility"
                        class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-[#8B1E7E] transition-colors">
                  <i data-lucide="eye" class="w-5 h-5" id="eyeIcon"></i>
                </button>
              </div>
            </div>

          </div>

          <!-- Row 5: Address -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5 md:gap-8">

            <div class="space-y-2">
              <label for="address" class="block text-sm font-semibold text-slate-700">
                Address
              </label>
              <textarea name="address" id="address" rows="6"
                        placeholder="Address"
                        class="w-full px-4 py-3 border-2 border-slate-200 rounded-lg bg-white text-slate-800 font-medium
                               placeholder-slate-400 text-sm resize-none
                               focus:outline-none focus:border-[#8B1E7E] focus:ring-4 focus:ring-[#8B1E7E]/10
                               hover:border-[#8B1E7E]/40 transition-all"><?= e($formData['address']) ?></textarea>
            </div>

            <!-- Right column intentionally left empty per screenshot layout -->
            <div class="hidden md:block"></div>

          </div>

          <!-- Actions -->
          <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">

            <a href="login.php?role=student"
               class="px-6 py-3 rounded-lg font-semibold text-white
                      bg-[#4A154B] hover:bg-[#5A1B5C]
                      shadow-md hover:shadow-lg
                      transition-all duration-300 active:scale-95">
              Cancel
            </a>

            <button type="submit"
                    class="px-8 py-3 rounded-lg font-bold text-white
                           bg-[#4A154B] hover:bg-[#5A1B5C]
                           shadow-md hover:shadow-lg
                           transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
              Submit
            </button>

          </div>

        </form>

      </div>

    </div>
  </main>

  <script>
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }

    // ---- Toggle password visibility ----
    (function () {
      const btn     = document.getElementById('togglePassword');
      const pwd     = document.getElementById('password');
      const eyeIcon = document.getElementById('eyeIcon');
      if (!btn || !pwd || !eyeIcon) return;

      btn.addEventListener('click', function () {
        const isHidden = pwd.type === 'password';
        pwd.type = isHidden ? 'text' : 'password';
        eyeIcon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
        if (typeof lucide !== 'undefined') {
          lucide.createIcons({ targets: [eyeIcon] });
        }
      });
    })();

    // ---- Mobile number: only 10 digits ----
    (function () {
      const mobile = document.getElementById('contact_number');
      if (!mobile) return;
      mobile.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
      });
    })();
  </script>

  <script src="assets/js/index.js"></script>
</body>
</html>