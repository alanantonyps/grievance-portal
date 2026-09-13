<?php
/**
 * admin/cell_members.php
 * ---------------------------------------------------------------------------
 * Admin — Grievance Cell Member List
 * Rajagiri College Grievance Redressal Portal
 *
 * Database schema (from grievance_db.sql):
 *   cell_members: id, user_id(NOT NULL FK), designation_id(NOT NULL FK),
 *                 department_id(NULLABLE FK), member_type(ENUM NOT NULL),
 *                 grievance_type_id(NULLABLE FK), name, email, mobile_number
 *
 * Assigned Category (grievance_type_id) is intentionally not editable here —
 * it will be set to NULL for new members.
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

function isValidMobile(string $mobile): bool
{
    return (bool) preg_match('/^[0-9]{10}$/', $mobile);
}

function memberTypeLabel(string $type): string
{
    $map = [
        'MANAGEMENT'       => 'MANAGEMENT',
        'GRIEVANCE_MEMBER' => 'GRIEVANCE MEMBER',
        'TEACHING'         => 'TEACHING',
        'NON_TEACHING'     => 'NON TEACHING',
        'PARENT'           => 'PARENT',
        'STUDENT'          => 'STUDENT',
    ];
    return $map[$type] ?? $type;
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
        error_log('[Cell Members Admin Profile] ' . $ex->getMessage());
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
// 6. FETCH ACTIVE DROPDOWN OPTIONS (Designations + Departments only)
// ---------------------------------------------------------------------------
$designationOptions = [];
$departmentOptions  = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, designation_name FROM designations WHERE status = 'Active' ORDER BY designation_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) $designationOptions[] = $row;
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Designations] ' . $ex->getMessage());
    }

    try {
        $res = $conn->query("SELECT id, department_name FROM departments WHERE status = 'Active' ORDER BY department_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) $departmentOptions[] = $row;
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Departments] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 7. HANDLE FORM SUBMISSIONS
// ---------------------------------------------------------------------------
$flashSuccess = '';
$flashError   = '';

// Map cell_members.member_type → users.role enum
// users.role ENUM('ADMIN','STUDENT','PARENT','TEACHER','NON_TEACHING','MANAGEMENT')
$roleMap = [
    'MANAGEMENT'       => 'MANAGEMENT',
    'GRIEVANCE_MEMBER' => 'MANAGEMENT',
    'TEACHING'         => 'TEACHER',
    'NON_TEACHING'     => 'NON_TEACHING',
    'PARENT'           => 'PARENT',
    'STUDENT'          => 'STUDENT',
];

$validMemberTypes = ['MANAGEMENT', 'GRIEVANCE_MEMBER', 'TEACHING', 'NON_TEACHING', 'PARENT', 'STUDENT'];
$validStatuses    = ['Approved', 'Pending', 'Rejected', 'Terminated'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    $action = $_POST['action'] ?? '';

    // -------- ADD MEMBER --------
    if ($action === 'add_member') {
        $name            = trim((string) ($_POST['name']             ?? ''));
        $email           = trim((string) ($_POST['email']            ?? ''));
        $mobileNumber    = trim((string) ($_POST['mobile_number']    ?? ''));
        $username        = trim((string) ($_POST['username']         ?? ''));
        $password        = (string)       ($_POST['password']        ?? '');
        $memberType      = trim((string) ($_POST['member_type']      ?? 'STUDENT'));
        $designationId   = (int) ($_POST['designation_id']           ?? 0);
        $departmentId    = (int) ($_POST['department_id']            ?? 0);

        // Normalise nullable FK: 0 becomes NULL
        $departmentIdDb = ($departmentId > 0) ? $departmentId : null;

        if (!in_array($memberType, $validMemberTypes, true)) {
            $memberType = 'STUDENT';
        }

        if ($name === '' || $email === '' || $mobileNumber === '' || $username === '' || $password === '') {
            $flashError = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashError = 'Please enter a valid email address.';
        } elseif (!isValidMobile($mobileNumber)) {
            $flashError = 'Mobile number must be exactly 10 digits.';
        } elseif ($designationId <= 0) {
            $flashError = 'Please select a designation.';
        }

        // Verify designation exists (required FK)
        if ($flashError === '') {
            try {
                $chk = $conn->prepare("SELECT id FROM designations WHERE id = ? LIMIT 1");
                $chk->bind_param('i', $designationId);
                $chk->execute();
                if ($chk->get_result()->num_rows === 0) {
                    $chk->close();
                    throw new Exception('Selected designation is not valid.');
                }
                $chk->close();

                if ($departmentIdDb !== null) {
                    $chk = $conn->prepare("SELECT id FROM departments WHERE id = ? LIMIT 1");
                    $chk->bind_param('i', $departmentIdDb);
                    $chk->execute();
                    if ($chk->get_result()->num_rows === 0) {
                        $chk->close();
                        throw new Exception('Selected department is not valid.');
                    }
                    $chk->close();
                }
            } catch (Throwable $ex) {
                $flashError = $ex->getMessage();
            }
        }

        if ($flashError === '') {
            try {
                $conn->begin_transaction();

                // Duplicate checks
                $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $chk->bind_param('s', $username);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $chk->close();
                    throw new Exception('Username already exists.');
                }
                $chk->close();

                $chk2 = $conn->prepare("SELECT id FROM cell_members WHERE email = ? LIMIT 1");
                $chk2->bind_param('s', $email);
                $chk2->execute();
                if ($chk2->get_result()->num_rows > 0) {
                    $chk2->close();
                    throw new Exception('Email is already registered for another member.');
                }
                $chk2->close();

                // Create user (mapped role)
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $userRole = $roleMap[$memberType] ?? 'STUDENT';

                $stmtU = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, 'Approved')");
                $stmtU->bind_param('sss', $username, $hash, $userRole);
                $stmtU->execute();
                $newUserId = (int) $conn->insert_id;
                $stmtU->close();

                // Insert cell member (grievance_type_id always NULL)
                $stmtS = $conn->prepare("INSERT INTO cell_members (user_id, designation_id, department_id, member_type, grievance_type_id, name, email, mobile_number) VALUES (?, ?, ?, ?, NULL, ?, ?, ?)");
                $stmtS->bind_param(
                    'iiissss',
                    $newUserId,
                    $designationId,
                    $departmentIdDb,
                    $memberType,
                    $name,
                    $email,
                    $mobileNumber
                );

                if (!$stmtS->execute()) {
                    throw new Exception('Failed to add member details: ' . $stmtS->error);
                }
                $stmtS->close();

                $conn->commit();
                $flashSuccess = 'Member added successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Add Member] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while adding the member.';
            }
        }
    }

    // -------- EDIT MEMBER --------
    if ($action === 'edit_member') {
        $memberId        = (int) ($_POST['member_id']      ?? 0);
        $name            = trim((string) ($_POST['name']             ?? ''));
        $email           = trim((string) ($_POST['email']            ?? ''));
        $mobileNumber    = trim((string) ($_POST['mobile_number']    ?? ''));
        $memberType      = trim((string) ($_POST['member_type']      ?? 'STUDENT'));
        $designationId   = (int) ($_POST['designation_id']           ?? 0);
        $departmentId    = (int) ($_POST['department_id']            ?? 0);

        $departmentIdDb = ($departmentId > 0) ? $departmentId : null;

        if (!in_array($memberType, $validMemberTypes, true)) {
            $memberType = 'STUDENT';
        }

        if ($memberId <= 0) {
            $flashError = 'Invalid member.';
        } elseif ($name === '' || $email === '' || $mobileNumber === '') {
            $flashError = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashError = 'Please enter a valid email address.';
        } elseif (!isValidMobile($mobileNumber)) {
            $flashError = 'Mobile number must be exactly 10 digits.';
        } elseif ($designationId <= 0) {
            $flashError = 'Please select a designation.';
        }

        if ($flashError === '') {
            try {
                $conn->begin_transaction();

                $chk = $conn->prepare("SELECT id FROM cell_members WHERE email = ? AND id != ? LIMIT 1");
                $chk->bind_param('si', $email, $memberId);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $chk->close();
                    throw new Exception('Email is already registered for another member.');
                }
                $chk->close();

                // Update member (leave grievance_type_id untouched)
                $stmt = $conn->prepare("UPDATE cell_members SET designation_id = ?, department_id = ?, member_type = ?, name = ?, email = ?, mobile_number = ? WHERE id = ?");
                $stmt->bind_param(
                    'iissssi',
                    $designationId,
                    $departmentIdDb,
                    $memberType,
                    $name,
                    $email,
                    $mobileNumber,
                    $memberId
                );
                $stmt->execute();
                $stmt->close();

                // Sync role in users table
                $stmtG = $conn->prepare("SELECT user_id FROM cell_members WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $memberId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $linkedUserId = $resG && $resG->num_rows > 0 ? (int) ($resG->fetch_assoc()['user_id'] ?? 0) : 0;
                $stmtG->close();

                if ($linkedUserId > 0) {
                    $userRole = $roleMap[$memberType] ?? 'STUDENT';
                    $stmtU = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
                    $stmtU->bind_param('si', $userRole, $linkedUserId);
                    $stmtU->execute();
                    $stmtU->close();
                }

                $conn->commit();
                $flashSuccess = 'Member updated successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Edit Member] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while updating the member.';
            }
        }
    }

    // -------- DELETE MEMBER --------
    if ($action === 'delete_member') {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        if ($memberId > 0) {
            try {
                $conn->begin_transaction();

                $stmtG = $conn->prepare("SELECT user_id FROM cell_members WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $memberId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $linkedUserId = $resG && $resG->num_rows > 0 ? (int) ($resG->fetch_assoc()['user_id'] ?? 0) : 0;
                $stmtG->close();

                $stmtD = $conn->prepare("DELETE FROM cell_members WHERE id = ?");
                $stmtD->bind_param('i', $memberId);
                $stmtD->execute();
                $stmtD->close();

                if ($linkedUserId > 0) {
                    $stmtU = $conn->prepare("DELETE FROM users WHERE id = ?");
                    $stmtU->bind_param('i', $linkedUserId);
                    $stmtU->execute();
                    $stmtU->close();
                }

                $conn->commit();
                $flashSuccess = 'Member deleted successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Delete Member] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the member.';
            }
        }
    }

    // -------- DEACTIVATE --------
    if ($action === 'deactivate_member') {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        if ($memberId > 0) {
            try {
                $stmtG = $conn->prepare("SELECT user_id FROM cell_members WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $memberId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $linkedUserId = $resG && $resG->num_rows > 0 ? (int) ($resG->fetch_assoc()['user_id'] ?? 0) : 0;
                $stmtG->close();

                if ($linkedUserId > 0) {
                    $stmtU = $conn->prepare("UPDATE users SET status = 'Terminated' WHERE id = ?");
                    $stmtU->bind_param('i', $linkedUserId);
                    $stmtU->execute();
                    $stmtU->close();

                    $flashSuccess = 'Member deactivated successfully.';
                } else {
                    $flashError = 'Linked user account not found.';
                }
            } catch (Throwable $ex) {
                error_log('[Deactivate Member] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deactivating the member.';
            }
        }
    }

    // -------- RESET PASSWORD --------
    if ($action === 'reset_password') {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        if ($memberId > 0) {
            try {
                $stmtG = $conn->prepare("SELECT user_id FROM cell_members WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $memberId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $linkedUserId = $resG && $resG->num_rows > 0 ? (int) ($resG->fetch_assoc()['user_id'] ?? 0) : 0;
                $stmtG->close();

                if ($linkedUserId > 0) {
                    $newPassword = 'Member@' . random_int(1000, 9999);
                    $hash        = password_hash($newPassword, PASSWORD_BCRYPT);

                    $stmtU = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmtU->bind_param('si', $hash, $linkedUserId);
                    $stmtU->execute();
                    $stmtU->close();

                    $flashSuccess = 'Password reset successfully. New password: ' . $newPassword;
                } else {
                    $flashError = 'Linked user account not found.';
                }
            } catch (Throwable $ex) {
                error_log('[Reset Password] ' . $ex->getMessage());
                $flashError = 'A system error occurred while resetting the password.';
            }
        }
    }

    if ($flashSuccess !== '' || $flashError !== '') {
        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;

        $qFilterMemberType = urlencode((string) ($_POST['filter_member_type'] ?? 'ALL'));
        $qFilterStatus     = urlencode((string) ($_POST['filter_status']      ?? 'All'));

        header('Location: cell_members.php?member_type=' . $qFilterMemberType . '&status=' . $qFilterStatus);
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
// 8. FILTERS
// ---------------------------------------------------------------------------
$filterMemberType = isset($_GET['member_type']) ? (string) $_GET['member_type'] : 'ALL';
$filterStatus     = isset($_GET['status'])      ? (string) $_GET['status']      : 'All';

if (!in_array($filterMemberType, array_merge(['ALL'], $validMemberTypes), true)) {
    $filterMemberType = 'ALL';
}
if (!in_array($filterStatus, array_merge(['All'], $validStatuses), true)) {
    $filterStatus = 'All';
}

// ---------------------------------------------------------------------------
// 9. FETCH CELL MEMBERS (with joins)
// ---------------------------------------------------------------------------
$members = [];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  cm.id,
                        cm.user_id,
                        cm.designation_id,
                        cm.department_id,
                        cm.grievance_type_id,
                        cm.member_type,
                        cm.name,
                        cm.email,
                        cm.mobile_number,
                        u.username,
                        u.status,
                        d.designation_name,
                        dep.department_name,
                        gt.type_name
                FROM cell_members cm
                LEFT JOIN users u            ON cm.user_id            = u.id
                LEFT JOIN designations d     ON cm.designation_id     = d.id
                LEFT JOIN departments dep    ON cm.department_id      = dep.id
                LEFT JOIN grievance_types gt ON cm.grievance_type_id  = gt.id
                WHERE 1=1";

        $params = [];
        $types  = '';

        if ($filterMemberType !== 'ALL') {
            $sql .= " AND cm.member_type = ?";
            $params[] = $filterMemberType;
            $types   .= 's';
        }

        if ($filterStatus !== 'All') {
            $sql .= " AND u.status = ?";
            $params[] = $filterStatus;
            $types   .= 's';
        }

        $sql .= " ORDER BY cm.id ASC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $members[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Cell Members] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Grievance Cell Member List — Admin | Rajagiri College Grievance Portal</title>
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

        <!-- Breadcrumb -->
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2 tracking-tight">Grievance Cell Member List</h1>
              <nav class="flex items-center space-x-2 text-sm text-slate-500">
                <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i>Dashboard
                </a>
                <span class="text-slate-300">/</span>
                <a href="members.php" class="hover:text-[#8B1E7E] transition-colors">Members</a>
                <span class="text-slate-300">/</span>
                <span class="text-[#E5097F] font-semibold">Cell Members</span>
              </nav>
            </div>

            <button type="button" onclick="openMemberModal('add')" title="Add Member"
                    class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-[#4A154B] hover:bg-[#5A1B5C] text-white shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
              <i data-lucide="plus" class="w-5 h-5"></i>
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

        <!-- FILTER BAR -->
        <div class="max-w-6xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 40ms;">
          <div class="bg-slate-100 border border-slate-200 rounded-xl px-5 py-4 shadow-sm">
            <form method="GET" action="cell_members.php" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
              <div class="md:col-span-4">
                <label for="member_type" class="block text-sm font-semibold text-slate-700 mb-1.5">Member Type</label>
                <select name="member_type" id="member_type" class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10 hover:border-[#4A154B]/40 transition-all">
                  <option value="ALL"              <?= $filterMemberType === 'ALL'              ? 'selected' : '' ?>>ALL</option>
                  <option value="MANAGEMENT"       <?= $filterMemberType === 'MANAGEMENT'       ? 'selected' : '' ?>>MANAGEMENT</option>
                  <option value="GRIEVANCE_MEMBER" <?= $filterMemberType === 'GRIEVANCE_MEMBER' ? 'selected' : '' ?>>GRIEVANCE MEMBER</option>
                  <option value="TEACHING"         <?= $filterMemberType === 'TEACHING'         ? 'selected' : '' ?>>TEACHING</option>
                  <option value="NON_TEACHING"     <?= $filterMemberType === 'NON_TEACHING'     ? 'selected' : '' ?>>NON TEACHING</option>
                  <option value="PARENT"           <?= $filterMemberType === 'PARENT'           ? 'selected' : '' ?>>PARENT</option>
                  <option value="STUDENT"          <?= $filterMemberType === 'STUDENT'          ? 'selected' : '' ?>>STUDENT</option>
                </select>
              </div>

              <div class="md:col-span-4">
                <label for="status" class="block text-sm font-semibold text-slate-700 mb-1.5">Status</label>
                <select name="status" id="status" class="w-full px-4 py-2.5 border-2 border-slate-200 rounded-lg text-sm bg-white text-slate-800 font-medium focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10 hover:border-[#4A154B]/40 transition-all">
                  <option value="All"        <?= $filterStatus === 'All'        ? 'selected' : '' ?>>All</option>
                  <option value="Approved"   <?= $filterStatus === 'Approved'   ? 'selected' : '' ?>>Approved</option>
                  <option value="Pending"    <?= $filterStatus === 'Pending'    ? 'selected' : '' ?>>Pending</option>
                  <option value="Rejected"   <?= $filterStatus === 'Rejected'   ? 'selected' : '' ?>>Rejected</option>
                  <option value="Terminated" <?= $filterStatus === 'Terminated' ? 'selected' : '' ?>>Terminated</option>
                </select>
              </div>

              <div class="md:col-span-4 flex md:justify-end">
                <button type="submit" class="w-full md:w-auto px-8 py-2.5 rounded-lg bg-[#4A154B] hover:bg-[#5A1B5C] text-white font-semibold shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50 transition-all duration-300 active:scale-95">
                  Submit
                </button>
              </div>
            </form>
          </div>
        </div>

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
            <div class="overflow-x-auto">
              <table class="w-full" id="membersTable">
                <thead>
                  <tr class="bg-[#4A154B] text-white">
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Member Type</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Email Id</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Mobile No</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                    <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="membersTableBody">

                  <?php if (empty($members)): ?>
                    <tr>
                      <td colspan="7" class="px-6 py-16 text-center text-slate-500">
                        <div class="flex flex-col items-center justify-center">
                          <div class="w-16 h-16 bg-purple-50 rounded-full flex items-center justify-center mb-4">
                            <i data-lucide="users" class="w-8 h-8 text-[#8B1E7E]"></i>
                          </div>
                          <p class="text-lg font-semibold text-slate-700">No members found</p>
                          <p class="text-sm text-slate-500 mt-1 mb-4">Try adjusting the filters, or add a new member with the "+" button.</p>
                        </div>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($members as $index => $member): ?>
                      <?php
                        $memberId        = (int) $member['id'];
                        $memberName      = (string) ($member['name']          ?? '');
                        $memberType      = (string) ($member['member_type']   ?? '');
                        $memberEmail     = (string) ($member['email']         ?? '');
                        $memberMobile    = (string) ($member['mobile_number'] ?? '');
                        $memberStatus    = (string) ($member['status']        ?? 'Approved');
                        $memberUser      = (string) ($member['username']      ?? '');
                        $memberDesigId   = (int)    ($member['designation_id']    ?? 0);
                        $memberDeptId    = (int)    ($member['department_id']     ?? 0);

                        $statusCls = match (strtolower($memberStatus)) {
                            'approved'   => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                            'pending'    => 'bg-amber-100 text-amber-800 border-amber-200',
                            'rejected'   => 'bg-red-100 text-red-800 border-red-200',
                            'terminated' => 'bg-slate-200 text-slate-700 border-slate-300',
                            default      => 'bg-slate-100 text-slate-700 border-slate-200',
                        };
                      ?>
                      <tr class="hover:bg-slate-50/80 transition-colors group">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900"><?= $index + 1 ?></td>
                        <td class="px-6 py-4 text-sm font-semibold text-slate-800"><?= e($memberName) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-700"><?= e(memberTypeLabel($memberType)) ?></td>
                        <td class="px-6 py-4 text-sm text-slate-600 break-all"><?= e($memberEmail) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600"><?= e($memberMobile !== '' ? $memberMobile : '—') ?></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <span class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full border <?= $statusCls ?>"><?= e($memberStatus) ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="grid grid-cols-2 gap-1.5 w-fit mx-auto">
                            <button type="button" title="Reset password" onclick='confirmResetPassword(<?= $memberId ?>, <?= json_encode($memberName) ?>)'
                                    class="w-8 h-8 rounded-full bg-purple-50 hover:bg-[#4A154B] flex items-center justify-center text-[#4A154B] hover:text-white transition-all duration-200 hover:scale-110">
                              <i data-lucide="lock" class="w-3.5 h-3.5"></i>
                            </button>

                            <button type="button" title="Edit member" onclick='openMemberModal("edit", <?= $memberId ?>, <?= json_encode($memberName) ?>, <?= json_encode($memberType) ?>, <?= json_encode($memberDesigId) ?>, <?= json_encode($memberDeptId) ?>, <?= json_encode($memberEmail) ?>, <?= json_encode($memberMobile) ?>)'
                                    class="w-8 h-8 rounded-full bg-purple-50 hover:bg-[#4A154B] flex items-center justify-center text-[#4A154B] hover:text-white transition-all duration-200 hover:scale-110">
                              <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                            </button>

                            <button type="button" title="Deactivate member" onclick='confirmDeactivate(<?= $memberId ?>, <?= json_encode($memberName) ?>)'
                                    class="w-8 h-8 rounded-full bg-purple-50 hover:bg-[#4A154B] flex items-center justify-center text-[#4A154B] hover:text-white transition-all duration-200 hover:scale-110">
                              <i data-lucide="x" class="w-3.5 h-3.5"></i>
                            </button>

                            <button type="button" title="Delete member" onclick='confirmDeleteMember(<?= $memberId ?>, <?= json_encode($memberName) ?>)'
                                    class="w-8 h-8 rounded-full bg-purple-50 hover:bg-red-500 flex items-center justify-center text-[#4A154B] hover:text-white transition-all duration-200 hover:scale-110">
                              <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                            </button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>

                </tbody>
              </table>
            </div>

            <?php if (!empty($members)): ?>
              <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-sm text-slate-600" id="tableInfo">
                  Showing <span class="font-semibold text-slate-900">1</span> to
                  <span class="font-semibold text-slate-900"><?= count($members) ?></span> of
                  <span class="font-semibold text-slate-900"><?= count($members) ?></span> entries
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

  <!-- ADD/EDIT MEMBER MODAL -->
  <div id="memberModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeMemberModal()"></div>

    <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 id="memberModalTitle" class="text-lg font-bold text-slate-800">Add Member</h3>
        <button type="button" onclick="closeMemberModal()" class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="memberForm" method="POST" action="cell_members.php" class="p-6 space-y-4">
        <input type="hidden" name="action" id="formAction" value="add_member" />
        <input type="hidden" name="member_id" id="formMemberId" value="" />
        <input type="hidden" name="filter_member_type" value="<?= e($filterMemberType) ?>" />
        <input type="hidden" name="filter_status" value="<?= e($filterStatus) ?>" />

        <?php if (empty($designationOptions)): ?>
          <div class="rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3 flex items-start space-x-2">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5"></i>
            <div>
              <p class="text-sm font-semibold text-amber-800">No designations available</p>
              <p class="text-xs text-amber-700 mt-1">
                You must add at least one <strong>Designation</strong> first before adding cell members.
                <a href="designations.php" class="underline font-semibold">Go to Designations →</a>
              </p>
            </div>
          </div>
        <?php endif; ?>

        <!-- Row 1: Name + Member Type -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label for="name" class="block text-sm font-semibold text-slate-700">Full Name <span class="text-[#E5097F]">*</span></label>
            <input type="text" name="name" id="name" required placeholder="e.g. John Doe"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10 hover:border-[#4A154B]/40 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="member_type" class="block text-sm font-semibold text-slate-700">Member Type <span class="text-[#E5097F]">*</span></label>
            <select name="member_type" id="member_type" required
                    class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl appearance-none bg-white text-slate-800 font-medium focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10 hover:border-[#4A154B]/40 transition-all">
              <option value="MANAGEMENT">MANAGEMENT</option>
              <option value="GRIEVANCE_MEMBER">GRIEVANCE MEMBER</option>
              <option value="TEACHING">TEACHING</option>
              <option value="NON_TEACHING">NON TEACHING</option>
              <option value="PARENT">PARENT</option>
              <option value="STUDENT" selected>STUDENT</option>
            </select>
          </div>
        </div>

        <!-- Row 2: Designation + Department -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label for="designation_id" class="block text-sm font-semibold text-slate-700">Designation <span class="text-[#E5097F]">*</span></label>
            <select name="designation_id" id="designation_id" required
                    class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl appearance-none bg-white text-slate-800 font-medium focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10 hover:border-[#4A154B]/40 transition-all">
              <option value="">-- Select Designation --</option>
              <?php foreach ($designationOptions as $opt): ?>
                <option value="<?= (int) $opt['id'] ?>"><?= e($opt['designation_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="space-y-2">
            <label for="department_id" class="block text-sm font-semibold text-slate-700">Department <span class="text-slate-400 text-xs">(optional)</span></label>
            <select name="department_id" id="department_id"
                    class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl appearance-none bg-white text-slate-800 font-medium focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10 hover:border-[#4A154B]/40 transition-all">
              <option value="">-- None --</option>
              <?php foreach ($departmentOptions as $opt): ?>
                <option value="<?= (int) $opt['id'] ?>"><?= e($opt['department_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Row 3: Email + Mobile -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label for="email" class="block text-sm font-semibold text-slate-700">Email <span class="text-[#E5097F]">*</span></label>
            <input type="email" name="email" id="email" required placeholder="e.g. member@rajagiri.edu"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10 hover:border-[#4A154B]/40 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="mobile_number" class="block text-sm font-semibold text-slate-700">Mobile Number <span class="text-[#E5097F]">*</span></label>
            <input type="tel" name="mobile_number" id="mobile_number" required inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10" title="Please enter exactly 10 digits" placeholder="e.g. 9876543210"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10 hover:border-[#4A154B]/40 transition-all" />
            <p class="text-[11px] text-slate-500">Enter exactly 10 digits.</p>
          </div>
        </div>

        <!-- Row 4: Username + Password (Add only) -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="credentialsBlock">
          <div class="space-y-2">
            <label for="username" class="block text-sm font-semibold text-slate-700">Username <span class="text-[#E5097F]">*</span></label>
            <input type="text" name="username" id="username" required placeholder="e.g. johndoe"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10 hover:border-[#4A154B]/40 transition-all" />
          </div>

          <div class="space-y-2" id="passwordFieldWrapper">
            <label for="password" class="block text-sm font-semibold text-slate-700">Password <span class="text-[#E5097F]">*</span></label>
            <div class="relative">
              <input type="password" name="password" id="password" placeholder="e.g. Member@123"
                     class="w-full pl-4 pr-12 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10 hover:border-[#4A154B]/40 transition-all" />
              <button type="button" id="togglePasswordBtn" title="Show password" aria-label="Show password" tabindex="-1"
                      class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-lg flex items-center justify-center text-slate-400 hover:text-[#8B1E7E] hover:bg-purple-50 transition-all duration-200 active:scale-95">
                <i data-lucide="eye" id="togglePasswordIcon" class="w-5 h-5"></i>
              </button>
            </div>
          </div>
        </div>

        <div class="flex justify-center pt-3 gap-3">
          <button type="button" onclick="closeMemberModal()" class="px-6 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">Cancel</button>
          <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A] hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A] text-white font-bold shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95">Save</button>
        </div>
      </form>
    </div>
  </div>

  <!-- DELETE MODAL -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
    <div id="deleteConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-gradient-to-br from-red-100 to-pink-100 ring-4 ring-red-50">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </div>
        <h3 class="text-xl font-bold text-slate-800 mb-2">Delete Member?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">You are about to permanently delete <span id="deleteMemberNameDisplay" class="font-bold text-[#8B1E7E] break-words">this member</span>.</p>
        <p class="text-xs text-red-500 font-medium mt-3 flex items-center gap-1.5"><i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>This action cannot be undone.</p>
      </div>
      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeDeleteModal()" class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">Cancel</button>
        <button type="button" id="confirmDeleteBtn" class="flex-1 px-5 py-3 rounded-xl font-bold text-white bg-gradient-to-r from-red-500 via-red-600 to-rose-600 hover:from-red-600 hover:via-red-700 hover:to-rose-700 shadow-lg shadow-red-500/30 hover:shadow-red-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95 flex items-center justify-center gap-2">
          <i data-lucide="trash-2" class="w-4 h-4"></i><span>Delete</span>
        </button>
      </div>
    </div>
  </div>

  <!-- DEACTIVATE MODAL -->
  <div id="deactivateConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeactivateModal()"></div>
    <div id="deactivateConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-gradient-to-br from-purple-100 to-pink-100 ring-4 ring-purple-50">
          <i data-lucide="user-x" class="w-8 h-8 text-[#8B1E7E]"></i>
        </div>
        <h3 class="text-xl font-bold text-slate-800 mb-2">Deactivate Member?</h3>
        <p class="text-sm text-slate-500 leading-relaxed"><span id="deactivateMemberNameDisplay" class="font-bold text-[#8B1E7E] break-words">This member</span> will be marked as <span class="font-bold text-[#8B1E7E]">Terminated</span> and will no longer be able to log in.</p>
      </div>
      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeDeactivateModal()" class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">Cancel</button>
        <button type="button" id="confirmDeactivateBtn" class="flex-1 px-5 py-3 rounded-xl font-bold text-white bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A] hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A] shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95 flex items-center justify-center gap-2">
          <i data-lucide="user-x" class="w-4 h-4"></i><span>Deactivate</span>
        </button>
      </div>
    </div>
  </div>

  <!-- RESET PASSWORD MODAL -->
  <div id="resetConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeResetModal()"></div>
    <div id="resetConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-gradient-to-br from-purple-100 to-pink-100 ring-4 ring-purple-50">
          <i data-lucide="lock" class="w-8 h-8 text-[#8B1E7E]"></i>
        </div>
        <h3 class="text-xl font-bold text-slate-800 mb-2">Reset Password?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">A new random password will be generated for <span id="resetMemberNameDisplay" class="font-bold text-[#8B1E7E] break-words">this member</span>.</p>
        <p class="text-xs text-[#8B1E7E] font-medium mt-3 flex items-center gap-1.5"><i data-lucide="info" class="w-3.5 h-3.5"></i>The new password will be shown after reset.</p>
      </div>
      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeResetModal()" class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">Cancel</button>
        <button type="button" id="confirmResetBtn" class="flex-1 px-5 py-3 rounded-xl font-bold text-white bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A] hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A] shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95 flex items-center justify-center gap-2">
          <i data-lucide="refresh-cw" class="w-4 h-4"></i><span>Reset Password</span>
        </button>
      </div>
    </div>
  </div>

  <!-- HIDDEN FORMS -->
  <form id="deleteForm" method="POST" action="cell_members.php" class="hidden">
    <input type="hidden" name="action" value="delete_member" />
    <input type="hidden" name="member_id" id="deleteMemberId" value="" />
    <input type="hidden" name="filter_member_type" value="<?= e($filterMemberType) ?>" />
    <input type="hidden" name="filter_status" value="<?= e($filterStatus) ?>" />
  </form>

  <form id="deactivateForm" method="POST" action="cell_members.php" class="hidden">
    <input type="hidden" name="action" value="deactivate_member" />
    <input type="hidden" name="member_id" id="deactivateMemberId" value="" />
    <input type="hidden" name="filter_member_type" value="<?= e($filterMemberType) ?>" />
    <input type="hidden" name="filter_status" value="<?= e($filterStatus) ?>" />
  </form>

  <form id="resetForm" method="POST" action="cell_members.php" class="hidden">
    <input type="hidden" name="action" value="reset_password" />
    <input type="hidden" name="member_id" id="resetMemberId" value="" />
    <input type="hidden" name="filter_member_type" value="<?= e($filterMemberType) ?>" />
    <input type="hidden" name="filter_status" value="<?= e($filterStatus) ?>" />
  </form>

  <script>
    if (typeof lucide !== 'undefined') { lucide.createIcons(); }

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

    (function () {
      [document.getElementById('sidebarLogoutBtn'), document.getElementById('dropdownLogoutBtn')].forEach(function (btn) {
        if (!btn) return;
        btn.addEventListener('click', function (e) {
          if (!window.confirm('Are you sure you want to log out?')) { e.preventDefault(); e.stopPropagation(); return false; }
        });
      });
    })();

    (function () {
      const mobileInput = document.getElementById('mobile_number');
      if (!mobileInput) return;
      mobileInput.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 10); });
    })();

    (function () {
      const pw = document.getElementById('password');
      const btn = document.getElementById('togglePasswordBtn');
      const icon = document.getElementById('togglePasswordIcon');
      if (!pw || !btn || !icon) return;
      btn.addEventListener('click', function () {
        const hidden = pw.type === 'password';
        pw.type = hidden ? 'text' : 'password';
        icon.setAttribute('data-lucide', hidden ? 'eye-off' : 'eye');
        if (typeof lucide !== 'undefined') lucide.createIcons();
      });
    })();

    const memberModal      = document.getElementById('memberModal');
    const memberModalTitle = document.getElementById('memberModalTitle');
    const memberForm       = document.getElementById('memberForm');
    const formAction       = document.getElementById('formAction');
    const formMemberId     = document.getElementById('formMemberId');
    const nameInput        = document.getElementById('name');
    const memberTypeInput  = document.getElementById('member_type');
    const designationInput = document.getElementById('designation_id');
    const departmentInput  = document.getElementById('department_id');
    const emailInput       = document.getElementById('email');
    const mobileInput      = document.getElementById('mobile_number');
    const usernameInput    = document.getElementById('username');
    const passwordInput    = document.getElementById('password');
    const credentialsBlock = document.getElementById('credentialsBlock');

    function openMemberModal(mode, memberId, name, memberType, designationId, departmentId, email, mobile) {
      memberModal.classList.remove('hidden');
      if (mode === 'edit') {
        memberModalTitle.textContent = 'Edit Member';
        formAction.value         = 'edit_member';
        formMemberId.value       = memberId || '';
        nameInput.value          = name || '';
        memberTypeInput.value    = memberType || 'STUDENT';
        designationInput.value   = designationId ? String(designationId) : '';
        departmentInput.value    = departmentId  ? String(departmentId)  : '';
        emailInput.value         = email || '';
        mobileInput.value        = mobile || '';
        if (credentialsBlock) credentialsBlock.style.display = 'none';
        if (usernameInput) usernameInput.removeAttribute('required');
        if (passwordInput) { passwordInput.removeAttribute('required'); passwordInput.value = ''; }
      } else {
        memberModalTitle.textContent = 'Add Member';
        formAction.value = 'add_member';
        formMemberId.value = '';
        memberForm.reset();
        if (credentialsBlock) credentialsBlock.style.display = '';
        if (usernameInput) usernameInput.setAttribute('required', 'required');
        if (passwordInput) passwordInput.setAttribute('required', 'required');
      }
      setTimeout(() => nameInput && nameInput.focus(), 50);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeMemberModal() {
      memberModal.classList.add('hidden');
      memberForm.reset();
      formAction.value = 'add_member';
      formMemberId.value = '';
    }

    const deleteConfirmModal = document.getElementById('deleteConfirmModal');
    const deleteConfirmPanel = document.getElementById('deleteConfirmPanel');
    const deleteMemberNameEl = document.getElementById('deleteMemberNameDisplay');
    const confirmDeleteBtn   = document.getElementById('confirmDeleteBtn');
    let pendingDeleteId = null;

    function confirmDeleteMember(memberId, memberName) {
      pendingDeleteId = memberId;
      if (deleteMemberNameEl) deleteMemberNameEl.textContent = '"' + memberName + '"';
      deleteConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (deleteConfirmPanel) { deleteConfirmPanel.classList.remove('animate-confirm-shake'); void deleteConfirmPanel.offsetWidth; deleteConfirmPanel.classList.add('animate-confirm-shake'); }
      setTimeout(function () { if (confirmDeleteBtn) confirmDeleteBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeDeleteModal() { deleteConfirmModal.classList.add('hidden'); document.body.classList.remove('overflow-hidden'); pendingDeleteId = null; }
    if (confirmDeleteBtn) confirmDeleteBtn.addEventListener('click', function () {
      if (pendingDeleteId === null) return closeDeleteModal();
      const input = document.getElementById('deleteMemberId');
      const form = document.getElementById('deleteForm');
      if (input && form) { input.value = String(pendingDeleteId); form.submit(); }
    });

    const deactivateConfirmModal = document.getElementById('deactivateConfirmModal');
    const deactivateConfirmPanel = document.getElementById('deactivateConfirmPanel');
    const deactivateMemberNameEl = document.getElementById('deactivateMemberNameDisplay');
    const confirmDeactivateBtn   = document.getElementById('confirmDeactivateBtn');
    let pendingDeactivateId = null;

    function confirmDeactivate(memberId, memberName) {
      pendingDeactivateId = memberId;
      if (deactivateMemberNameEl) deactivateMemberNameEl.textContent = '"' + memberName + '"';
      deactivateConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (deactivateConfirmPanel) { deactivateConfirmPanel.classList.remove('animate-confirm-shake'); void deactivateConfirmPanel.offsetWidth; deactivateConfirmPanel.classList.add('animate-confirm-shake'); }
      setTimeout(function () { if (confirmDeactivateBtn) confirmDeactivateBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeDeactivateModal() { deactivateConfirmModal.classList.add('hidden'); document.body.classList.remove('overflow-hidden'); pendingDeactivateId = null; }
    if (confirmDeactivateBtn) confirmDeactivateBtn.addEventListener('click', function () {
      if (pendingDeactivateId === null) return closeDeactivateModal();
      const input = document.getElementById('deactivateMemberId');
      const form = document.getElementById('deactivateForm');
      if (input && form) { input.value = String(pendingDeactivateId); form.submit(); }
    });

    const resetConfirmModal = document.getElementById('resetConfirmModal');
    const resetConfirmPanel = document.getElementById('resetConfirmPanel');
    const resetMemberNameEl = document.getElementById('resetMemberNameDisplay');
    const confirmResetBtn   = document.getElementById('confirmResetBtn');
    let pendingResetId = null;

    function confirmResetPassword(memberId, memberName) {
      pendingResetId = memberId;
      if (resetMemberNameEl) resetMemberNameEl.textContent = '"' + memberName + '"';
      resetConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (resetConfirmPanel) { resetConfirmPanel.classList.remove('animate-confirm-shake'); void resetConfirmPanel.offsetWidth; resetConfirmPanel.classList.add('animate-confirm-shake'); }
      setTimeout(function () { if (confirmResetBtn) confirmResetBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeResetModal() { resetConfirmModal.classList.add('hidden'); document.body.classList.remove('overflow-hidden'); pendingResetId = null; }
    if (confirmResetBtn) confirmResetBtn.addEventListener('click', function () {
      if (pendingResetId === null) return closeResetModal();
      const input = document.getElementById('resetMemberId');
      const form = document.getElementById('resetForm');
      if (input && form) { input.value = String(pendingResetId); form.submit(); }
    });

    (function () {
      const searchInput = document.getElementById('searchInput');
      const tableBody = document.getElementById('membersTableBody');
      if (!searchInput || !tableBody) return;
      searchInput.addEventListener('input', function () {
        const term = this.value.toLowerCase().trim();
        tableBody.querySelectorAll('tr').forEach(function (row) {
          row.style.display = (term === '' || row.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
        });
      });
    })();

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (memberModal && !memberModal.classList.contains('hidden')) closeMemberModal();
      if (deleteConfirmModal && !deleteConfirmModal.classList.contains('hidden')) closeDeleteModal();
      if (deactivateConfirmModal && !deactivateConfirmModal.classList.contains('hidden')) closeDeactivateModal();
      if (resetConfirmModal && !resetConfirmModal.classList.contains('hidden')) closeResetModal();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>