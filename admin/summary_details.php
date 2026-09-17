<?php
/**
 * admin/summary_details.php
 * ---------------------------------------------------------------------------
 * Admin — Summary Details (Grievance Listing + Create + Bulk Upload + View + Edit + Delete)
 * Rajagiri College Grievance Redressal Portal
 *
 * Features:
 *   • Lists all grievances with role-aware complainant resolution
 *   • Live search + server-side pagination
 *   • Top-right Print + Upload + Add buttons
 *   • Eye icon  → View Details modal
 *   • Pencil    → Edit modal
 *   • Trash     → Delete confirmation modal
 *   • CREATE modal — manually insert a grievance record
 *   • BULK UPLOAD modal — import grievances from CSV with template download
 *   • Themed status badges & flash messages
 *   • Custom themed logout confirmation modal
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
$allowedRoles = ['ADMIN', 'MANAGEMENT', 'TEACHER'];

if (empty($_SESSION['user_id']) || !in_array($sessionRole, $allowedRoles, true)) {
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

function statusBadgeClass(string $status): string
{
    return match (strtolower($status)) {
        'pending'     => 'bg-amber-100 text-amber-800 border-amber-200',
        'in progress' => 'bg-sky-100 text-sky-800 border-sky-200',
        'disposed'    => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'closed'      => 'bg-slate-100 text-slate-700 border-slate-200',
        'reopened'    => 'bg-pink-100 text-pink-800 border-pink-200',
        default       => 'bg-slate-100 text-slate-700 border-slate-200',
    };
}

function roleLabel(string $role): string
{
    $map = [
        'STUDENT'      => 'STUDENT',
        'PARENT'       => 'PARENT',
        'TEACHER'      => 'TEACHER',
        'NON_TEACHING' => 'NON TEACHING',
        'MANAGEMENT'   => 'MANAGEMENT',
        'ADMIN'        => 'ADMIN',
    ];
    return $map[$role] ?? $role;
}

function generateGrievanceNumber(mysqli $conn): string
{
    $prefix = 'GRV-' . date('Y') . '-';
    do {
        $suffix = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $candidate = $prefix . $suffix;

        $chk = $conn->prepare("SELECT id FROM grievances WHERE grievance_number = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param('s', $candidate);
            $chk->execute();
            $exists = $chk->get_result()->num_rows > 0;
            $chk->close();
        } else {
            $exists = false;
        }
    } while ($exists);

    return $candidate;
}

function resolveComplainantUser(mysqli $conn, string $role, string $name, string $cellNo, string $classOrDept): int
{
    $role = strtoupper($role);

    $validRoles = ['STUDENT', 'PARENT', 'TEACHER', 'NON_TEACHING', 'MANAGEMENT'];
    if (!in_array($role, $validRoles, true)) {
        $role = 'STUDENT';
    }

    $userRoleEnum = $role;

    $profileTable = '';
    switch ($role) {
        case 'STUDENT':      $profileTable = 'students';     break;
        case 'PARENT':       $profileTable = 'parents';      break;
        case 'TEACHER':      $profileTable = 'cell_members'; break;
        case 'NON_TEACHING': $profileTable = 'cell_members'; break;
        case 'MANAGEMENT':   $profileTable = 'cell_members'; break;
    }

    if ($profileTable !== '') {
        $sql = "SELECT u.id
                FROM users u
                INNER JOIN {$profileTable} p ON p.user_id = u.id
                WHERE u.role = ? AND p.name = ?
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $userRoleEnum, $name);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $existingId = (int) $res->fetch_assoc()['id'];
                $stmt->close();
                return $existingId;
            }
            $stmt->close();
        }
    }

    $baseUsername = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $name));
    $baseUsername = trim($baseUsername, '.');
    if ($baseUsername === '') $baseUsername = 'user';

    $username = $baseUsername;
    $i = 1;
    while (true) {
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->bind_param('s', $username);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $chk->close();
            break;
        }
        $chk->close();
        $username = $baseUsername . $i;
        $i++;
        if ($i > 1000) {
            $username = $baseUsername . '_' . bin2hex(random_bytes(3));
            break;
        }
    }

    $defaultPassword = password_hash('User@' . random_int(1000, 9999), PASSWORD_BCRYPT);

    $stmtU = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, 'Approved')");
    if (!$stmtU) {
        throw new Exception('Failed to prepare user insert.');
    }
    $stmtU->bind_param('sss', $username, $defaultPassword, $userRoleEnum);
    $stmtU->execute();
    $newUserId = (int) $conn->insert_id;
    $stmtU->close();

    switch ($role) {
        case 'STUDENT':
            $classId = null;
            if ($classOrDept !== '') {
                $chkC = $conn->prepare("SELECT id FROM classes WHERE class_name = ? LIMIT 1");
                $chkC->bind_param('s', $classOrDept);
                $chkC->execute();
                $resC = $chkC->get_result();
                if ($resC && $resC->num_rows > 0) {
                    $classId = (int) $resC->fetch_assoc()['id'];
                }
                $chkC->close();
            }

            if ($classId === null) {
                $resFirst = $conn->query("SELECT id FROM classes ORDER BY id ASC LIMIT 1");
                if ($resFirst && $resFirst->num_rows > 0) {
                    $classId = (int) $resFirst->fetch_assoc()['id'];
                }
            }

            if ($classId === null) {
                throw new Exception('Cannot create student: no classes exist. Please add a class first.');
            }

            $stmtS = $conn->prepare("INSERT INTO students (user_id, class_id, name, email, contact_number) VALUES (?, ?, ?, ?, ?)");
            if (!$stmtS) {
                throw new Exception('Failed to prepare student insert.');
            }
            $placeholderEmail = 'student_' . $newUserId . '@rajagiri.edu';
            $stmtS->bind_param('iisss', $newUserId, $classId, $name, $placeholderEmail, $cellNo);
            $stmtS->execute();
            $stmtS->close();
            break;

        case 'PARENT':
            $stmtP = $conn->prepare("INSERT INTO parents (user_id, name, email, contact_number) VALUES (?, ?, ?, ?)");
            if (!$stmtP) {
                throw new Exception('Failed to prepare parent insert.');
            }
            $placeholderEmail = 'parent_' . $newUserId . '@rajagiri.edu';
            $stmtP->bind_param('isss', $newUserId, $name, $placeholderEmail, $cellNo);
            $stmtP->execute();
            $stmtP->close();
            break;

        case 'TEACHER':
        case 'NON_TEACHING':
        case 'MANAGEMENT':
            $resD = $conn->query("SELECT id FROM designations WHERE status = 'Active' ORDER BY id ASC LIMIT 1");
            $designationId = 0;
            if ($resD && $resD->num_rows > 0) {
                $designationId = (int) $resD->fetch_assoc()['id'];
            }
            if ($designationId <= 0) {
                $defaultName = 'General Staff';
                $postOcc = 'Non Teaching';
                $stmtD = $conn->prepare("INSERT INTO designations (designation_name, post_occupied, status) VALUES (?, ?, 'Active')");
                if ($stmtD) {
                    $stmtD->bind_param('ss', $defaultName, $postOcc);
                    $stmtD->execute();
                    $designationId = (int) $conn->insert_id;
                    $stmtD->close();
                }
            }
            if ($designationId <= 0) {
                throw new Exception('Cannot create staff member: no designation available.');
            }

            $memberType = match ($role) {
                'TEACHER'      => 'TEACHING',
                'NON_TEACHING' => 'NON_TEACHING',
                'MANAGEMENT'   => 'MANAGEMENT',
                default        => 'TEACHING',
            };

            $stmtC = $conn->prepare("INSERT INTO cell_members (user_id, designation_id, member_type, name, email, mobile_number) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$stmtC) {
                throw new Exception('Failed to prepare cell member insert.');
            }
            $placeholderEmail = 'staff_' . $newUserId . '@rajagiri.edu';
            $stmtC->bind_param('iissss', $newUserId, $designationId, $memberType, $name, $placeholderEmail, $cellNo);
            $stmtC->execute();
            $stmtC->close();
            break;
    }

    return $newUserId;
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
        error_log('[Summary Details Admin Profile] ' . $ex->getMessage());
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
// 6. FETCH GRIEVANCE TYPES
// ---------------------------------------------------------------------------
$grievanceTypeOptions = [];
if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, type_name FROM grievance_types WHERE status = 'Active' ORDER BY type_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $grievanceTypeOptions[] = $row;
    } catch (Throwable $ex) {
        error_log('[Fetch Grievance Types] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 7. TEMPLATE CSV DOWNLOAD
// ---------------------------------------------------------------------------
if (isset($_GET['download_template']) && (string) $_GET['download_template'] === '1') {
    $filename = 'summary_template.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'role', 'name', 'academic_year', 'class_name', 'complaint',
        'grievance_type', 'action_taken', 'cell_no',
        'posted_date', 'reply_date', 'replied_by',
    ]);

    fputcsv($out, [
        'STUDENT', 'John Doe', '2025-2026', 'SEMESTER I',
        'Grievance regarding library timing',
        'Grievance related to Admission',
        'Investigated and resolved', '9876543210',
        '2026-01-15', '2026-01-20', 'Dr. Bindya Varghese',
    ]);

    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// 8. FLASH MESSAGES
// ---------------------------------------------------------------------------
$flashSuccess = '';
$flashError   = '';

if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ---------------------------------------------------------------------------
// 9. HANDLE FORM SUBMISSIONS
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    $action = $_POST['action'] ?? '';

    // -------- CREATE --------
    if ($action === 'create_summary') {
        $role          = trim((string) ($_POST['role']            ?? 'STUDENT'));
        $name          = trim((string) ($_POST['name']            ?? ''));
        $academicYear  = trim((string) ($_POST['academic_year']   ?? ''));
        $className     = trim((string) ($_POST['class_name']      ?? ''));
        $complaint     = trim((string) ($_POST['complaint']       ?? ''));
        $grievanceType = (int) ($_POST['grievance_type_id']       ?? 0);
        $actionTaken   = trim((string) ($_POST['action_taken']    ?? ''));
        $cellNo        = trim((string) ($_POST['cell_no']         ?? ''));
        $postedDate    = trim((string) ($_POST['posted_date']     ?? date('Y-m-d')));
        $replyDate     = trim((string) ($_POST['reply_date']      ?? ''));
        $repliedBy     = trim((string) ($_POST['replied_by']      ?? ''));

        if ($name === '' || $complaint === '' || $grievanceType <= 0 || $actionTaken === '' || $cellNo === '' || $repliedBy === '') {
            $flashError = 'Please fill in all required fields.';
        } elseif (!in_array($role, ['STUDENT', 'PARENT', 'TEACHER', 'NON_TEACHING', 'MANAGEMENT'], true)) {
            $flashError = 'Invalid role selected.';
        } else {
            try {
                $conn->begin_transaction();

                $complainantUserId = resolveComplainantUser($conn, $role, $name, $cellNo, $className);
                $grievanceNumber = generateGrievanceNumber($conn);

                $status = 'Disposed';
                $description = $complaint . ($academicYear !== '' ? "\n\nAcademic Year: " . $academicYear : '');

                $sql = "INSERT INTO grievances
                            (grievance_number, grievance_type_id, complainant_user_id, subject,
                             description, status, reply_details, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new Exception('Failed to prepare grievance insert.');

                $subject = mb_substr($complaint, 0, 250, 'UTF-8');
                $createdAt = $postedDate !== '' ? $postedDate . ' 00:00:00' : date('Y-m-d H:i:s');
                $updatedAt = $replyDate !== '' ? $replyDate . ' 00:00:00' : $createdAt;

                $stmt->bind_param(
                    'siissssss',
                    $grievanceNumber, $grievanceType, $complainantUserId,
                    $subject, $description, $status, $actionTaken,
                    $createdAt, $updatedAt
                );
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $flashSuccess = 'Record created successfully! Grievance No: ' . $grievanceNumber;
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Create Summary] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while creating the record.';
            }
        }

        if ($flashSuccess !== '' || $flashError !== '') {
            $_SESSION['flash_success'] = $flashSuccess;
            $_SESSION['flash_error']   = $flashError;
            header('Location: summary_details.php');
            exit;
        }
    }

    // -------- EDIT --------
    if ($action === 'edit_summary') {
        $grievanceId   = (int) ($_POST['grievance_id']    ?? 0);
        $subject       = trim((string) ($_POST['subject']       ?? ''));
        $description   = trim((string) ($_POST['description']   ?? ''));
        $status        = trim((string) ($_POST['status']        ?? 'Pending'));
        $replyDetails  = trim((string) ($_POST['reply_details'] ?? ''));

        $validStatuses = ['Pending', 'In Progress', 'Disposed', 'Closed', 'Reopened'];
        if (!in_array($status, $validStatuses, true)) {
            $status = 'Pending';
        }

        if ($grievanceId <= 0) {
            $flashError = 'Invalid grievance.';
        } elseif ($subject === '' || $description === '') {
            $flashError = 'Subject and Description are required.';
        } else {
            try {
                $stmt = $conn->prepare("UPDATE grievances
                                        SET subject = ?, description = ?, status = ?, reply_details = ?
                                        WHERE id = ?");
                if (!$stmt) throw new Exception('Failed to prepare update.');

                $stmt->bind_param('ssssi', $subject, $description, $status, $replyDetails, $grievanceId);
                $stmt->execute();
                $stmt->close();

                $flashSuccess = 'Grievance updated successfully.';
            } catch (Throwable $ex) {
                error_log('[Edit Summary] ' . $ex->getMessage());
                $flashError = 'A system error occurred while updating the record.';
            }
        }

        if ($flashSuccess !== '' || $flashError !== '') {
            $_SESSION['flash_success'] = $flashSuccess;
            $_SESSION['flash_error']   = $flashError;
            header('Location: summary_details.php');
            exit;
        }
    }

    // -------- DELETE --------
    if ($action === 'delete_summary') {
        $grievanceId = (int) ($_POST['grievance_id'] ?? 0);
        if ($grievanceId > 0) {
            try {
                $stmt = $conn->prepare("DELETE FROM grievances WHERE id = ?");
                if (!$stmt) throw new Exception('Failed to prepare delete.');

                $stmt->bind_param('i', $grievanceId);
                $stmt->execute();
                $stmt->close();

                $flashSuccess = 'Grievance deleted successfully.';
            } catch (Throwable $ex) {
                error_log('[Delete Summary] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the record.';
            }
        }

        if ($flashSuccess !== '' || $flashError !== '') {
            $_SESSION['flash_success'] = $flashSuccess;
            $_SESSION['flash_error']   = $flashError;
            header('Location: summary_details.php');
            exit;
        }
    }

    // -------- BULK UPLOAD --------
    if ($action === 'bulk_upload') {
        if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
            $flashError = 'Please select a CSV file to upload.';
        } elseif ((int) ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flashError = 'File upload failed. Please try again.';
        } else {
            $tmpPath  = (string) ($_FILES['csv_file']['tmp_name'] ?? '');
            $origName = (string) ($_FILES['csv_file']['name']     ?? '');
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['csv', 'txt'], true)) {
                $flashError = 'Unsupported file type. Please upload a CSV file.';
            } elseif (!is_uploaded_file($tmpPath)) {
                $flashError = 'Invalid upload. Please try again.';
            } else {
                $handle = fopen($tmpPath, 'r');
                if ($handle === false) {
                    $flashError = 'Unable to read the uploaded file.';
                } else {
                    @set_time_limit(0);

                    $insertedCount = 0;
                    $skippedRows   = [];
                    $rowNumber     = 0;

                    try {
                        $conn->begin_transaction();

                        $typeLookup = [];
                        $resT = $conn->query("SELECT id, type_name FROM grievance_types");
                        if ($resT) {
                            while ($rowT = $resT->fetch_assoc()) {
                                $typeLookup[strtolower(trim((string) $rowT['type_name']))] = (int) $rowT['id'];
                            }
                        }

                        while (($row = fgetcsv($handle, 0, ',')) !== false) {
                            $rowNumber++;

                            if (count($row) === 1 && trim((string) $row[0]) === '') continue;

                            if ($rowNumber === 1) {
                                $firstCell = strtolower(trim((string) ($row[0] ?? '')));
                                if ($firstCell === 'role') continue;
                            }

                            if (count($row) < 11) {
                                $skippedRows[] = "Row {$rowNumber}: Not enough columns (expected 11).";
                                continue;
                            }

                            $rRole         = trim((string) ($row[0]  ?? ''));
                            $rName         = trim((string) ($row[1]  ?? ''));
                            $rAcademicYear = trim((string) ($row[2]  ?? ''));
                            $rClassName    = trim((string) ($row[3]  ?? ''));
                            $rComplaint    = trim((string) ($row[4]  ?? ''));
                            $rGrievanceType= trim((string) ($row[5]  ?? ''));
                            $rActionTaken  = trim((string) ($row[6]  ?? ''));
                            $rCellNo       = trim((string) ($row[7]  ?? ''));
                            $rPostedDate   = trim((string) ($row[8]  ?? ''));
                            $rReplyDate    = trim((string) ($row[9]  ?? ''));
                            $rRepliedBy    = trim((string) ($row[10] ?? ''));

                            $rRole = preg_replace('/^\xEF\xBB\xBF/', '', $rRole) ?? $rRole;
                            $rRole = strtoupper($rRole);

                            if (!in_array($rRole, ['STUDENT', 'PARENT', 'TEACHER', 'NON_TEACHING', 'MANAGEMENT'], true)) {
                                $skippedRows[] = "Row {$rowNumber}: Invalid role '{$rRole}'.";
                                continue;
                            }

                            if ($rName === '' || $rComplaint === '' || $rGrievanceType === '' || $rActionTaken === '' || $rCellNo === '' || $rRepliedBy === '') {
                                $skippedRows[] = "Row {$rowNumber}: Missing required fields.";
                                continue;
                            }

                            $typeKey = strtolower($rGrievanceType);
                            if (!isset($typeLookup[$typeKey])) {
                                $skippedRows[] = "Row {$rowNumber}: Unknown grievance type '{$rGrievanceType}'.";
                                continue;
                            }
                            $typeId = $typeLookup[$typeKey];

                            $complainantUserId = resolveComplainantUser($conn, $rRole, $rName, $rCellNo, $rClassName);
                            $grievanceNumber = generateGrievanceNumber($conn);

                            $createdAt = ($rPostedDate !== '' && strtotime($rPostedDate) !== false)
                                ? date('Y-m-d H:i:s', strtotime($rPostedDate))
                                : date('Y-m-d H:i:s');
                            $updatedAt = ($rReplyDate !== '' && strtotime($rReplyDate) !== false)
                                ? date('Y-m-d H:i:s', strtotime($rReplyDate))
                                : $createdAt;

                            $status = 'Disposed';
                            $subject = mb_substr($rComplaint, 0, 250, 'UTF-8');
                            $description = $rComplaint . ($rAcademicYear !== '' ? "\n\nAcademic Year: " . $rAcademicYear : '');

                            $stmtI = $conn->prepare("INSERT INTO grievances
                                                        (grievance_number, grievance_type_id, complainant_user_id, subject,
                                                         description, status, reply_details, created_at, updated_at)
                                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            if (!$stmtI) throw new Exception("Row {$rowNumber}: Failed to prepare insert.");

                            $stmtI->bind_param(
                                'siissssss',
                                $grievanceNumber, $typeId, $complainantUserId,
                                $subject, $description, $status, $rActionTaken,
                                $createdAt, $updatedAt
                            );
                            $stmtI->execute();
                            $stmtI->close();

                            $insertedCount++;
                        }

                        if ($insertedCount === 0) {
                            throw new Exception('No valid rows were found in the uploaded file.');
                        }

                        $conn->commit();

                        $msg = 'CSV Imported: ' . $insertedCount . ' row(s) added successfully!';
                        if (!empty($skippedRows)) {
                            $msg .= ' Skipped ' . count($skippedRows) . ' row(s).';
                            $_SESSION['import_skipped_rows'] = $skippedRows;
                        }
                        $flashSuccess = $msg;
                    } catch (Throwable $ex) {
                        if ($conn instanceof mysqli) $conn->rollback();
                        error_log('[Bulk Upload] ' . $ex->getMessage());
                        $flashError = $ex->getMessage() ?: 'A system error occurred during the import.';
                    }

                    fclose($handle);
                }
            }
        }

        if ($flashSuccess !== '' || $flashError !== '') {
            $_SESSION['flash_success'] = $flashSuccess;
            $_SESSION['flash_error']   = $flashError;
            header('Location: summary_details.php');
            exit;
        }
    }
}

$importSkippedRows = [];
if (!empty($_SESSION['import_skipped_rows']) && is_array($_SESSION['import_skipped_rows'])) {
    $importSkippedRows = $_SESSION['import_skipped_rows'];
    unset($_SESSION['import_skipped_rows']);
}

// ---------------------------------------------------------------------------
// 10. QUERY PARAMS
// ---------------------------------------------------------------------------
$search  = trim((string) ($_GET['q']       ?? ''));
$entries = (int) ($_GET['entries']          ?? 10);
$page    = (int) ($_GET['page']             ?? 1);

if (!in_array($entries, [10, 25, 50, 100], true)) {
    $entries = 10;
}
if ($page < 1) $page = 1;

$offset = ($page - 1) * $entries;

// ---------------------------------------------------------------------------
// 11. FETCH GRIEVANCES
// ---------------------------------------------------------------------------
$rows       = [];
$totalRows  = 0;
$totalPages = 1;

if ($conn instanceof mysqli) {
    try {
        $whereSql = " WHERE 1=1";
        $params   = [];
        $types    = '';

        if ($search !== '') {
            $whereSql .= " AND (
                g.subject LIKE ?
                OR g.status LIKE ?
                OR g.grievance_number LIKE ?
                OR COALESCE(s.name, p.name, cm.name, ap.name, u.username) LIKE ?
            )";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types   .= 'ssss';
        }

        $countSql = "SELECT COUNT(*) AS c
                     FROM grievances g
                     LEFT JOIN users u            ON g.complainant_user_id = u.id
                     LEFT JOIN students s         ON u.id = s.user_id
                     LEFT JOIN parents p          ON u.id = p.user_id
                     LEFT JOIN cell_members cm    ON u.id = cm.user_id
                     LEFT JOIN admin_profiles ap  ON u.id = ap.user_id
                     $whereSql";

        $stmt = $conn->prepare($countSql);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res       = $stmt->get_result();
            $totalRows = (int) ($res ? ($res->fetch_assoc()['c'] ?? 0) : 0);
            $stmt->close();
        }

        $totalPages = max(1, (int) ceil($totalRows / $entries));

        if ($page > $totalPages) {
            $page   = $totalPages;
            $offset = ($page - 1) * $entries;
        }

        $dataSql = "SELECT  g.id,
                            g.grievance_number,
                            g.subject,
                            g.description,
                            g.status,
                            g.reply_details,
                            g.feedback_details,
                            g.created_at,
                            g.updated_at,
                            gt.type_name,
                            u.role AS complainant_role,
                            COALESCE(s.name, p.name, cm.name, ap.name, u.username) AS complainant_name,
                            COALESCE(c.class_name, d.department_name, '—') AS class_or_department
                    FROM grievances g
                    LEFT JOIN grievance_types gt ON g.grievance_type_id = gt.id
                    LEFT JOIN users u            ON g.complainant_user_id = u.id
                    LEFT JOIN students s         ON u.id = s.user_id
                    LEFT JOIN classes c          ON s.class_id = c.id
                    LEFT JOIN parents p          ON u.id = p.user_id
                    LEFT JOIN cell_members cm    ON u.id = cm.user_id
                    LEFT JOIN departments d      ON cm.department_id = d.id
                    LEFT JOIN admin_profiles ap  ON u.id = ap.user_id
                    $whereSql
                    ORDER BY g.id DESC
                    LIMIT ? OFFSET ?";

        $dataParams = $params;
        $dataTypes  = $types . 'ii';
        $dataParams[] = $entries;
        $dataParams[] = $offset;

        $stmt = $conn->prepare($dataSql);
        if ($stmt) {
            $stmt->bind_param($dataTypes, ...$dataParams);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Summary Details] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Summary Details — Admin | Rajagiri College Grievance Portal</title>
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

  <style>
    @media print {
      body * { visibility: hidden; }
      #printArea, #printArea * { visibility: visible; }
      #printArea {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        padding: 0 !important;
        margin: 0 !important;
      }
      .no-print { display: none !important; }
      .print-table { width: 100%; border-collapse: collapse; font-size: 11px; }
      .print-table th, .print-table td { border: 1px solid #333; padding: 5px 7px; text-align: left; }
      .print-table th { background-color: #f3f3f3 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      @page { margin: 12mm; }
    }
  </style>
</head>

<body class="min-h-screen bg-slate-50 text-slate-800 antialiased flex flex-col">

  <div class="flex min-h-screen flex-1">

    <!-- SIDEBAR -->
    <aside class="w-20 bg-gradient-to-b from-[#4A154B] via-[#5A1B5C] to-[#006837] flex flex-col items-center py-4 shadow-2xl fixed inset-y-0 left-0 z-40 no-print">

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
      <header class="bg-white border-b border-slate-200 shadow-sm sticky top-0 z-30 no-print">
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

        <!-- Breadcrumb + Action Buttons -->
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up no-print">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

            <div>
              <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2 tracking-tight">
                Summary Details
              </h1>
              <nav class="flex items-center space-x-2 text-sm text-slate-500">
                <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i>
                  Dashboard
                </a>
                <span class="text-slate-300">/</span>
                <span class="text-[#E5097F] font-semibold">Summary Details</span>
              </nav>
            </div>

            <div class="flex items-center gap-2">
              <!-- Print -->
              <button type="button"
                      onclick="window.print();"
                      title="Print Summary"
                      class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-[#4A154B] hover:bg-[#5A1B5C]
                             text-white shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                             transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="printer" class="w-5 h-5"></i>
              </button>

              <!-- Bulk Upload -->
              <button type="button"
                      onclick="openBulkUploadModal()"
                      title="Bulk Upload Summary Details"
                      class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-[#4A154B] hover:bg-[#5A1B5C]
                             text-white shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                             transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="upload" class="w-5 h-5"></i>
              </button>

              <!-- Add -->
              <button type="button"
                      onclick="openCreateModal()"
                      title="Create Summary Details"
                      class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-[#4A154B] hover:bg-[#5A1B5C]
                             text-white shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                             transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="plus" class="w-5 h-5"></i>
              </button>
            </div>

          </div>
        </div>

        <!-- Flash Messages -->
        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox" class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3 flex items-start space-x-2 animate-flash-in overflow-hidden no-print">
            <i data-lucide="check-circle" class="w-5 h-5 text-[#006837] flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-emerald-800 font-medium"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox" class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3 flex items-start space-x-2 animate-flash-in overflow-hidden no-print">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-medium"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($importSkippedRows)): ?>
          <div class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3 animate-fade-in-up no-print">
            <div class="flex items-start space-x-2 mb-2">
              <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5"></i>
              <p class="text-sm font-semibold text-amber-800">
                Some rows were skipped during import (<?= count($importSkippedRows) ?>):
              </p>
            </div>
            <ul class="text-xs text-amber-700 space-y-1 ml-7 list-disc">
              <?php foreach (array_slice($importSkippedRows, 0, 15) as $sk): ?>
                <li><?= e((string) $sk) ?></li>
              <?php endforeach; ?>
              <?php if (count($importSkippedRows) > 15): ?>
                <li class="italic">... and <?= count($importSkippedRows) - 15 ?> more</li>
              <?php endif; ?>
            </ul>
          </div>
        <?php endif; ?>

        <!-- TABLE CONTROLS -->
        <div class="max-w-6xl mx-auto mb-5 animate-fade-in-up no-print" style="animation-delay: 60ms;">
          <form method="GET" action="summary_details.php" id="filterForm" class="bg-white rounded-xl shadow-sm border border-slate-200/70 px-5 py-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">

              <div class="flex items-center space-x-3">
                <span class="text-sm text-slate-600">Show</span>
                <select name="entries" id="entriesPerPage"
                        class="px-3 py-1.5 border-2 border-slate-200 rounded-lg text-sm font-medium text-slate-700
                               focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                               hover:border-[#4A154B]/40 transition-colors bg-white">
                  <option value="10"  <?= $entries === 10  ? 'selected' : '' ?>>10</option>
                  <option value="25"  <?= $entries === 25  ? 'selected' : '' ?>>25</option>
                  <option value="50"  <?= $entries === 50  ? 'selected' : '' ?>>50</option>
                  <option value="100" <?= $entries === 100 ? 'selected' : '' ?>>100</option>
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
        <div class="max-w-6xl mx-auto animate-fade-in-up" id="printArea" style="animation-delay: 100ms;">
          <div class="bg-white rounded-2xl shadow-lg border border-slate-200/70 overflow-hidden">

            <div class="overflow-x-auto">
              <table class="w-full print-table" id="summaryTable">
                <thead>
                  <tr class="bg-[#4A154B] text-white">
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Class/Department</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Complaint</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Posted Date</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Role</th>
                    <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="summaryTableBody">

                  <?php if (empty($rows)): ?>
                    <tr>
                      <td colspan="8" class="px-6 py-12 text-center text-slate-500 bg-slate-50">
                        <span class="text-sm font-medium">No data available in table</span>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($rows as $index => $r): ?>
                      <?php
                        $gId        = (int) $r['id'];
                        $gNumber    = (string) ($r['grievance_number']      ?? '');
                        $gName      = (string) ($r['complainant_name']      ?? 'N/A');
                        $gClass     = (string) ($r['class_or_department']   ?? '—');
                        $gSubject   = (string) ($r['subject']               ?? '—');
                        $gDesc      = (string) ($r['description']           ?? '');
                        $gReply     = (string) ($r['reply_details']         ?? '');
                        $gFeedback  = (string) ($r['feedback_details']      ?? '');
                        $gType      = (string) ($r['type_name']             ?? '—');
                        $gDate      = (string) ($r['created_at']            ?? '');
                        $gUpd       = (string) ($r['updated_at']            ?? '');
                        $gRole      = (string) ($r['complainant_role']      ?? '');
                        $gStatus    = (string) ($r['status']                ?? 'Pending');

                        $formattedDate    = '—';
                        $formattedUpdated = '—';
                        if ($gDate !== '' && strtotime($gDate) !== false) $formattedDate = date('Y-m-d', strtotime($gDate));
                        if ($gUpd !== ''  && strtotime($gUpd)  !== false) $formattedUpdated = date('Y-m-d', strtotime($gUpd));

                        $statusCls   = statusBadgeClass($gStatus);
                        $globalIndex = $offset + $index + 1;
                      ?>
                      <tr class="hover:bg-slate-50/80 transition-colors group">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-900"><?= $globalIndex ?></td>
                        <td class="px-6 py-4 text-sm font-semibold text-slate-800"><?= e($gName) ?></td>
                        <td class="px-6 py-4 text-sm text-slate-700 max-w-[200px]"><?= e($gClass) ?></td>
                        <td class="px-6 py-4 text-sm text-slate-700 max-w-[260px]"><?= e($gSubject) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600"><?= e($formattedDate) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-700"><?= e(roleLabel($gRole)) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-center no-print">
                          <div class="inline-flex items-center gap-1.5">

                            <!-- VIEW -->
                            <button type="button"
                                    title="View grievance"
                                    onclick='openViewModal(<?= json_encode([
                                        "grievance_number" => $gNumber,
                                        "name"             => $gName,
                                        "role"             => roleLabel($gRole),
                                        "class_department" => $gClass,
                                        "grievance_type"   => $gType,
                                        "subject"          => $gSubject,
                                        "description"      => $gDesc,
                                        "status"           => $gStatus,
                                        "posted_date"      => $formattedDate,
                                        "reply_date"       => $formattedUpdated,
                                        "reply_details"    => $gReply,
                                        "feedback_details" => $gFeedback,
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    class="inline-flex w-9 h-9 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                           items-center justify-center text-[#4A154B] hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>

                            <!-- EDIT -->
                            <button type="button"
                                    title="Edit grievance"
                                    onclick='openEditModal(<?= json_encode([
                                        "id"           => $gId,
                                        "number"       => $gNumber,
                                        "subject"      => $gSubject,
                                        "description"  => $gDesc,
                                        "status"       => $gStatus,
                                        "reply_details"=> $gReply,
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    class="inline-flex w-9 h-9 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                           items-center justify-center text-[#4A154B] hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="pencil" class="w-4 h-4"></i>
                            </button>

                            <!-- DELETE -->
                            <button type="button"
                                    title="Delete grievance"
                                    onclick='confirmDelete(<?= $gId ?>, <?= json_encode($gNumber !== '' ? $gNumber : $gSubject, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    class="inline-flex w-9 h-9 rounded-full bg-purple-50 hover:bg-red-500
                                           items-center justify-center text-[#4A154B] hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>

                          </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <span class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full border <?= $statusCls ?>">
                            <?= e($gStatus) ?>
                          </span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>

                </tbody>
              </table>
            </div>

            <!-- Footer Info & Pagination -->
            <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4 no-print">

              <p class="text-sm text-slate-600" id="tableInfo">
                Showing
                <span class="font-semibold text-slate-900"><?= $totalRows > 0 ? ($offset + 1) : 0 ?></span>
                to
                <span class="font-semibold text-slate-900"><?= $totalRows > 0 ? min($offset + count($rows), $totalRows) : 0 ?></span>
                of
                <span class="font-semibold text-slate-900"><?= $totalRows ?></span>
                entries
              </p>

              <div class="flex items-center space-x-2">
                <?php
                  $qsBase = 'summary_details.php?entries=' . $entries;
                  if ($search !== '') $qsBase .= '&q=' . urlencode($search);
                ?>

                <?php if ($page > 1): ?>
                  <a href="<?= e($qsBase . '&page=' . ($page - 1)) ?>"
                     class="px-4 py-2 rounded-lg text-sm font-medium text-slate-700 bg-white hover:bg-slate-100 border border-slate-200 transition-colors">
                    Previous
                  </a>
                <?php else: ?>
                  <button type="button"
                          class="px-4 py-2 rounded-lg text-sm font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed"
                          disabled>
                    Previous
                  </button>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                  <a href="<?= e($qsBase . '&page=' . ($page + 1)) ?>"
                     class="px-4 py-2 rounded-lg text-sm font-medium text-slate-700 bg-white hover:bg-slate-100 border border-slate-200 transition-colors">
                    Next
                  </a>
                <?php else: ?>
                  <button type="button"
                          class="px-4 py-2 rounded-lg text-sm font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed"
                          disabled>
                    Next
                  </button>
                <?php endif; ?>
              </div>

            </div>

          </div>
        </div>

      </main>

      <!-- FOOTER -->
      <footer class="bg-gradient-to-r from-purple-200 via-pink-100 to-purple-200 border-t border-purple-200/60 mt-auto no-print">
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
       VIEW DETAILS MODAL
       ============================================================ -->
  <div id="viewModal" class="hidden fixed inset-0 z-[65] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeViewModal()"></div>

    <div class="relative w-full max-w-3xl bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-purple-50 flex items-center justify-center">
            <i data-lucide="eye" class="w-5 h-5 text-[#8B1E7E]"></i>
          </div>
          <h3 class="text-lg font-bold text-slate-800">Grievance Details</h3>
        </div>
        <button type="button" onclick="closeViewModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="p-6 space-y-5 max-h-[75vh] overflow-y-auto">

        <div class="flex items-center justify-between flex-wrap gap-4 pb-4 border-b border-slate-100">
          <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E] flex items-center justify-center text-white shadow-md">
              <i data-lucide="user" class="w-7 h-7"></i>
            </div>
            <div class="min-w-0">
              <p id="viewName" class="text-base font-bold text-slate-800 break-words">—</p>
              <p class="text-xs text-slate-500">
                <span id="viewRole">—</span> ·
                <span id="viewClassDept">—</span>
              </p>
            </div>
          </div>
          <span id="viewStatus" class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full border">
            —
          </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Grievance Number</p>
            <p id="viewNumber" class="text-sm font-semibold text-[#4A154B] break-all">—</p>
          </div>
          <div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Grievance Type</p>
            <p id="viewType" class="text-sm text-slate-700">—</p>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Posted Date</p>
            <p id="viewPostedDate" class="text-sm text-slate-700">—</p>
          </div>
          <div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Reply Date</p>
            <p id="viewReplyDate" class="text-sm text-slate-700">—</p>
          </div>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Subject</p>
          <p id="viewSubject" class="text-sm text-slate-800 font-medium break-words">—</p>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Description</p>
          <p id="viewDescription" class="text-sm text-slate-700 leading-relaxed whitespace-pre-wrap break-words">—</p>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Reply / Action Taken</p>
          <p id="viewReplyDetails" class="text-sm text-slate-700 leading-relaxed whitespace-pre-wrap break-words">—</p>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Feedback Details</p>
          <p id="viewFeedbackDetails" class="text-sm text-slate-700 leading-relaxed whitespace-pre-wrap break-words">—</p>
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

  <!-- ============================================================
       EDIT MODAL
       ============================================================ -->
  <div id="editModal" class="hidden fixed inset-0 z-[66] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeEditModal()"></div>

    <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-purple-50 flex items-center justify-center">
            <i data-lucide="pencil" class="w-5 h-5 text-[#8B1E7E]"></i>
          </div>
          <h3 class="text-lg font-bold text-slate-800">Edit Grievance</h3>
        </div>
        <button type="button" onclick="closeEditModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="editForm" method="POST" action="summary_details.php" class="p-6 space-y-5 max-h-[75vh] overflow-y-auto">
        <input type="hidden" name="action" value="edit_summary" />
        <input type="hidden" name="grievance_id" id="editGrievanceId" value="" />

        <div class="space-y-2">
          <label class="block text-sm font-semibold text-slate-700">Grievance Number</label>
          <input type="text" id="editNumber" readonly
                 class="w-full px-4 py-3 border-2 border-slate-100 rounded-xl bg-slate-50 text-slate-500 font-medium cursor-not-allowed" />
        </div>

        <div class="space-y-2">
          <label for="edit_subject" class="block text-sm font-semibold text-slate-700">
            Subject <span class="text-[#E5097F]">*</span>
          </label>
          <input type="text" name="subject" id="edit_subject" required
                 placeholder="Enter subject"
                 class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                        placeholder-slate-400
                        focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                        hover:border-[#4A154B]/40 transition-all" />
        </div>

        <div class="space-y-2">
          <label for="edit_description" class="block text-sm font-semibold text-slate-700">
            Description <span class="text-[#E5097F]">*</span>
          </label>
          <textarea name="description" id="edit_description" required rows="4"
                    placeholder="Enter description"
                    class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                           placeholder-slate-400 resize-none
                           focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                           hover:border-[#4A154B]/40 transition-all"></textarea>
        </div>

        <div class="space-y-2">
          <label for="edit_status" class="block text-sm font-semibold text-slate-700">
            Status <span class="text-[#E5097F]">*</span>
          </label>
          <select name="status" id="edit_status" required
                  class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl appearance-none bg-white text-slate-800 font-medium
                         focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                         hover:border-[#4A154B]/40 transition-all">
            <option value="Pending">Pending</option>
            <option value="In Progress">In Progress</option>
            <option value="Disposed">Disposed</option>
            <option value="Closed">Closed</option>
            <option value="Reopened">Reopened</option>
          </select>
        </div>

        <div class="space-y-2">
          <label for="edit_reply_details" class="block text-sm font-semibold text-slate-700">
            Reply / Action Taken
          </label>
          <textarea name="reply_details" id="edit_reply_details" rows="3"
                    placeholder="Enter reply or action taken"
                    class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                           placeholder-slate-400 resize-none
                           focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                           hover:border-[#4A154B]/40 transition-all"></textarea>
        </div>

        <div class="flex justify-center pt-3 gap-3">
          <button type="button"
                  onclick="closeEditModal()"
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
            Save Changes
          </button>
        </div>
      </form>

    </div>
  </div>

  <!-- ============================================================
       DELETE CONFIRMATION MODAL
       ============================================================ -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[67] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>

    <div id="deleteConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4
                    bg-gradient-to-br from-red-100 to-pink-100 ring-4 ring-red-50">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 class="text-xl font-bold text-slate-800 mb-2">Delete Grievance?</h3>

        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to permanently delete
          <span id="deleteNameDisplay" class="font-bold text-[#8B1E7E] break-words">this grievance</span>.
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

  <!-- ============================================================
       CREATE SUMMARY DETAILS MODAL
       ============================================================ -->
  <div id="createSummaryModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeCreateModal()"></div>

    <div class="relative w-full max-w-4xl bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="text-lg font-bold text-slate-800">Create Summary Details</h3>
        <button type="button" onclick="closeCreateModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="createSummaryForm" method="POST" action="summary_details.php" class="p-6 space-y-5 max-h-[80vh] overflow-y-auto">
        <input type="hidden" name="action" value="create_summary" />

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div class="space-y-2">
            <label for="create_role" class="block text-sm font-semibold text-slate-700">Role <span class="text-[#E5097F]">*</span></label>
            <select name="role" id="create_role" required
                    class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl appearance-none bg-white text-slate-800 font-medium
                           focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                           hover:border-[#4A154B]/40 transition-all">
              <option value="">Select</option>
              <option value="STUDENT">STUDENT</option>
              <option value="PARENT">PARENT</option>
              <option value="TEACHER">TEACHER</option>
              <option value="NON_TEACHING">NON TEACHING</option>
              <option value="MANAGEMENT">MANAGEMENT</option>
            </select>
          </div>

          <div class="space-y-2">
            <label for="create_name" class="block text-sm font-semibold text-slate-700">Name <span class="text-[#E5097F]">*</span></label>
            <input type="text" name="name" id="create_name" required placeholder="StudentName"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                          placeholder-slate-400
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="create_academic_year" class="block text-sm font-semibold text-slate-700">Academic Year <span class="text-[#E5097F]">*</span></label>
            <input type="text" name="academic_year" id="create_academic_year" required placeholder="AcademicYear"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                          placeholder-slate-400
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div class="space-y-2">
            <label for="create_complaint" class="block text-sm font-semibold text-slate-700">Complaint <span class="text-[#E5097F]">*</span></label>
            <textarea name="complaint" id="create_complaint" required rows="3" placeholder="Complaint"
                      class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                             placeholder-slate-400 resize-none
                             focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                             hover:border-[#4A154B]/40 transition-all"></textarea>
          </div>

          <div class="space-y-2">
            <label for="create_class_name" class="block text-sm font-semibold text-slate-700">Class Name</label>
            <input type="text" name="class_name" id="create_class_name" placeholder="ClassName"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                          placeholder-slate-400
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="create_grievance_type" class="block text-sm font-semibold text-slate-700">Grievance Type <span class="text-[#E5097F]">*</span></label>
            <select name="grievance_type_id" id="create_grievance_type" required
                    class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl appearance-none bg-white text-slate-800 font-medium
                           focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                           hover:border-[#4A154B]/40 transition-all">
              <option value="">GrievanceType</option>
              <?php foreach ($grievanceTypeOptions as $opt): ?>
                <option value="<?= (int) $opt['id'] ?>"><?= e($opt['type_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div class="space-y-2">
            <label for="create_action_taken" class="block text-sm font-semibold text-slate-700">Action Taken <span class="text-[#E5097F]">*</span></label>
            <input type="text" name="action_taken" id="create_action_taken" required placeholder="ActionTaken"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                          placeholder-slate-400
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="create_cell_no" class="block text-sm font-semibold text-slate-700">Cell No <span class="text-[#E5097F]">*</span></label>
            <input type="tel" name="cell_no" id="create_cell_no" required
                   inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10"
                   title="Please enter exactly 10 digits" placeholder="CellNo"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                          placeholder-slate-400
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="create_posted_date" class="block text-sm font-semibold text-slate-700">Posted Date</label>
            <input type="date" name="posted_date" id="create_posted_date" value="<?= date('Y-m-d') ?>"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div class="space-y-2">
            <label for="create_reply_date" class="block text-sm font-semibold text-slate-700">Reply Date</label>
            <input type="date" name="reply_date" id="create_reply_date" value="<?= date('Y-m-d') ?>"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
          </div>

          <div class="space-y-2 md:col-span-2">
            <label for="create_replied_by" class="block text-sm font-semibold text-slate-700">Replied By <span class="text-[#E5097F]">*</span></label>
            <input type="text" name="replied_by" id="create_replied_by" required placeholder="Replied"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                          placeholder-slate-400
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
          </div>
        </div>

        <div class="flex justify-center pt-3">
          <button type="submit"
                  class="px-12 py-3 rounded-xl
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

  <!-- ============================================================
       BULK UPLOAD MODAL
       ============================================================ -->
  <div id="bulkUploadModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeBulkUploadModal()"></div>

    <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-purple-50 flex items-center justify-center">
            <i data-lucide="upload" class="w-5 h-5 text-[#8B1E7E]"></i>
          </div>
          <h3 class="text-lg font-bold text-slate-800">Bulk Upload Summary Details</h3>
        </div>
        <button type="button" onclick="closeBulkUploadModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="bulkUploadForm" method="POST" action="summary_details.php" enctype="multipart/form-data" class="p-6 space-y-5">
        <input type="hidden" name="action" value="bulk_upload" />

        <div class="rounded-xl border border-purple-100 bg-purple-50/60 px-4 py-3">
          <p class="text-xs text-slate-700 leading-relaxed">
            <span class="font-bold text-[#8B1E7E]">Column order required:</span>
            <code class="text-[11px] bg-white px-1.5 py-0.5 rounded border border-purple-100">role, name, academic_year, class_name, complaint, grievance_type, action_taken, cell_no, posted_date, reply_date, replied_by</code>
          </p>
        </div>

        <div class="space-y-2">
          <label for="csv_file" class="block text-sm font-semibold text-slate-700">
            Select CSV File <span class="text-[#E5097F]">*</span>
          </label>
          <input type="file" name="csv_file" id="csv_file" required accept=".csv, .txt"
                 class="w-full text-sm text-slate-700
                        file:mr-3 file:py-2.5 file:px-4
                        file:rounded-xl file:border-0
                        file:text-sm file:font-semibold
                        file:bg-[#4A154B] file:text-white
                        hover:file:bg-[#5A1B5C]
                        border-2 border-slate-200 rounded-xl
                        focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                        transition-all cursor-pointer" />
          <p class="text-xs text-slate-500">Accepted format: .csv</p>
        </div>

        <div class="flex items-center justify-between rounded-xl bg-slate-50 border border-slate-200 px-4 py-3">
          <div class="flex items-start gap-3">
            <i data-lucide="file-spreadsheet" class="w-5 h-5 text-[#8B1E7E] mt-0.5 flex-shrink-0"></i>
            <div>
              <p class="text-sm font-semibold text-slate-700">Need the correct format?</p>
              <p class="text-xs text-slate-500">Download the sample CSV template.</p>
            </div>
          </div>
          <a href="summary_details.php?download_template=1"
             class="inline-flex items-center gap-2 px-4 py-2 rounded-xl
                    bg-white hover:bg-purple-50
                    border-2 border-purple-100 hover:border-[#8B1E7E]
                    text-[#8B1E7E] font-semibold text-sm
                    transition-all duration-200 hover:scale-105 active:scale-95 whitespace-nowrap">
            <i data-lucide="download" class="w-4 h-4"></i>
            <span>Sample Template</span>
          </a>
        </div>

        <div class="flex justify-center pt-2 gap-3">
          <button type="button"
                  onclick="closeBulkUploadModal()"
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
                         transition-all duration-300 hover:-translate-y-0.5 active:scale-95
                         flex items-center gap-2">
            <i data-lucide="upload" class="w-4 h-4"></i>
            <span>Import</span>
          </button>
        </div>
      </form>

    </div>
  </div>

  <!-- ============================================================= -->
  <!-- CUSTOM LOGOUT CONFIRMATION MODAL                              -->
  <!-- ============================================================= -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4 no-print">
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

  <!-- HIDDEN DELETE FORM -->
  <form id="deleteForm" method="POST" action="summary_details.php" class="hidden">
    <input type="hidden" name="action" value="delete_summary" />
    <input type="hidden" name="grievance_id" id="deleteGrievanceId" value="" />
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
    // VIEW MODAL
    // ============================================================
    const viewModal = document.getElementById('viewModal');

    function openViewModal(data) {
      const setText = function (id, value) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = (value !== undefined && value !== null && String(value).trim() !== '')
          ? String(value)
          : '—';
      };

      setText('viewName',         data.name);
      setText('viewRole',         data.role);
      setText('viewClassDept',    data.class_department);
      setText('viewNumber',       data.grievance_number);
      setText('viewType',         data.grievance_type);
      setText('viewPostedDate',   data.posted_date);
      setText('viewReplyDate',    data.reply_date);
      setText('viewSubject',      data.subject);
      setText('viewDescription',  data.description);
      setText('viewReplyDetails', data.reply_details);
      setText('viewFeedbackDetails', data.feedback_details);

      const statusEl = document.getElementById('viewStatus');
      if (statusEl) {
        const status = String(data.status || 'Pending');
        statusEl.textContent = status;
        statusEl.className = 'inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full border';

        const key = status.toLowerCase();
        if (key === 'pending')          statusEl.classList.add('bg-amber-100', 'text-amber-800', 'border-amber-200');
        else if (key === 'in progress') statusEl.classList.add('bg-sky-100', 'text-sky-800', 'border-sky-200');
        else if (key === 'disposed')    statusEl.classList.add('bg-emerald-100', 'text-emerald-800', 'border-emerald-200');
        else if (key === 'closed')      statusEl.classList.add('bg-slate-100', 'text-slate-700', 'border-slate-200');
        else if (key === 'reopened')    statusEl.classList.add('bg-pink-100', 'text-pink-800', 'border-pink-200');
        else                            statusEl.classList.add('bg-slate-100', 'text-slate-700', 'border-slate-200');
      }

      viewModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeViewModal() {
      viewModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    // ============================================================
    // EDIT MODAL
    // ============================================================
    const editModal = document.getElementById('editModal');

    function openEditModal(data) {
      document.getElementById('editGrievanceId').value   = data.id || '';
      document.getElementById('editNumber').value        = data.number || '—';
      document.getElementById('edit_subject').value      = data.subject || '';
      document.getElementById('edit_description').value  = data.description || '';
      document.getElementById('edit_status').value       = data.status || 'Pending';
      document.getElementById('edit_reply_details').value= data.reply_details || '';

      editModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      setTimeout(function () {
        const subjectInput = document.getElementById('edit_subject');
        if (subjectInput) subjectInput.focus();
      }, 80);

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeEditModal() {
      editModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    // ============================================================
    // DELETE MODAL
    // ============================================================
    const deleteConfirmModal = document.getElementById('deleteConfirmModal');
    const deleteConfirmPanel = document.getElementById('deleteConfirmPanel');
    const deleteNameDisplay  = document.getElementById('deleteNameDisplay');
    const confirmDeleteBtn   = document.getElementById('confirmDeleteBtn');
    let pendingDeleteId = null;

    function confirmDelete(grievanceId, label) {
      pendingDeleteId = grievanceId;

      if (deleteNameDisplay) {
        deleteNameDisplay.textContent = '"' + label + '"';
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
        if (pendingDeleteId === null) return closeDeleteModal();
        const input = document.getElementById('deleteGrievanceId');
        const form  = document.getElementById('deleteForm');
        if (input && form) {
          input.value = String(pendingDeleteId);
          form.submit();
        }
      });
    }

    // ============================================================
    // CREATE MODAL
    // ============================================================
    const createModal = document.getElementById('createSummaryModal');
    const createForm  = document.getElementById('createSummaryForm');

    function openCreateModal() {
      createModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      setTimeout(function () {
        const roleSelect = document.getElementById('create_role');
        if (roleSelect) roleSelect.focus();
      }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeCreateModal() {
      createModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      if (createForm) createForm.reset();
    }

    // ============================================================
    // BULK UPLOAD MODAL
    // ============================================================
    const bulkModal = document.getElementById('bulkUploadModal');
    const bulkForm  = document.getElementById('bulkUploadForm');

    function openBulkUploadModal() {
      bulkModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      setTimeout(function () {
        const fileInput = document.getElementById('csv_file');
        if (fileInput) fileInput.focus();
      }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeBulkUploadModal() {
      bulkModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      if (bulkForm) bulkForm.reset();
    }

    // ============================================================
    // LOGOUT MODAL
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

      setTimeout(function () {
        if (confirmLogoutBtn) confirmLogoutBtn.focus();
      }, 80);

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
    // ENTRIES + SEARCH
    // ============================================================
    (function () {
      const entriesSelect = document.getElementById('entriesPerPage');
      const filterForm    = document.getElementById('filterForm');
      if (!entriesSelect || !filterForm) return;
      entriesSelect.addEventListener('change', function () {
        filterForm.submit();
      });
    })();

    (function () {
      const searchInput = document.getElementById('searchInput');
      const tableBody   = document.getElementById('summaryTableBody');
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

    // ---- Mobile: enforce 10 digits ----
    (function () {
      const cellInput = document.getElementById('create_cell_no');
      if (!cellInput) return;
      cellInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
      });
    })();

    // ============================================================
    // ESCAPE closes modals
    // ============================================================
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (viewModal && !viewModal.classList.contains('hidden')) closeViewModal();
      if (editModal && !editModal.classList.contains('hidden')) closeEditModal();
      if (deleteConfirmModal && !deleteConfirmModal.classList.contains('hidden')) closeDeleteModal();
      if (createModal && !createModal.classList.contains('hidden')) closeCreateModal();
      if (bulkModal && !bulkModal.classList.contains('hidden')) closeBulkUploadModal();
      if (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) closeLogoutModal();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>