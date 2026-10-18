<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'app.php';

$error = '';
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$newStudent = $_SESSION['new_student'] ?? null;
unset($_SESSION['new_student']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'login' && !isset($_SESSION['teacher_id'])) {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $attemptKey = hash(
                'sha256',
                strtolower($username) . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
            );
            $limitQuery = db()->prepare(
                'SELECT locked_until FROM teacher_login_limits WHERE attempt_key = ?'
            );
            $limitQuery->execute([$attemptKey]);
            $limit = $limitQuery->fetch();

            if ($limit !== false && $limit['locked_until'] !== null
                && strtotime($limit['locked_until']) > time()) {
                $error = 'Too many attempts. Wait 15 minutes, then try again.';
            } else {
                $query = db()->prepare('SELECT id, password_hash FROM teachers WHERE username = ?');
                $query->execute([$username]);
                $teacher = $query->fetch();

                if ($teacher !== false && password_verify($password, $teacher['password_hash'])) {
                    db()->prepare('DELETE FROM teacher_login_limits WHERE attempt_key = ?')
                        ->execute([$attemptKey]);
                    session_regenerate_id(true);
                    $_SESSION['teacher_id'] = (int) $teacher['id'];
                    header('Location: admin.php');
                    exit;
                }

                db()->prepare(
                    'INSERT INTO teacher_login_limits
                        (attempt_key, failures, first_failure, last_failure)
                     VALUES (?, 1, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                        failures = IF(last_failure < NOW() - INTERVAL 15 MINUTE, 1, failures + 1),
                        first_failure = IF(last_failure < NOW() - INTERVAL 15 MINUTE, NOW(), first_failure),
                        locked_until = IF(
                            last_failure < NOW() - INTERVAL 15 MINUTE,
                            NULL,
                            IF(failures >= 4, DATE_ADD(NOW(), INTERVAL 15 MINUTE), locked_until)
                        ),
                        last_failure = NOW()'
                )->execute([$attemptKey]);
                $error = 'The username or password is incorrect.';
            }
        } elseif ($action === 'logout') {
            require_teacher();
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['flash'] = 'You have been logged out.';
            header('Location: admin.php');
            exit;
        } else {
            require_teacher();

            if ($action === 'create_student') {
                $studentNumber = strtoupper(trim((string) ($_POST['student_number'] ?? '')));
                $name = trim((string) ($_POST['name'] ?? ''));
                $className = trim((string) ($_POST['class_name'] ?? ''));

                if (!preg_match('/^[A-Z0-9-]{2,40}$/', $studentNumber)
                    || $name === '' || strlen($name) > 160
                    || $className === '' || strlen($className) > 80) {
                    $error = 'Enter a valid student number, name, and class.';
                } else {
                    $pin = (string) random_int(100000, 999999);
                    db()->prepare(
                        'INSERT INTO students (student_number, name, class_name, pin_hash)
                         VALUES (?, ?, ?, ?)'
                    )->execute([
                        $studentNumber,
                        $name,
                        $className,
                        password_hash($pin, PASSWORD_DEFAULT),
                    ]);
                    $_SESSION['new_student'] = [
                        'student_number' => $studentNumber,
                        'pin' => $pin,
                    ];
                    $_SESSION['flash'] = 'Student added. Give them their PIN securely; it will only be shown once.';
                    header('Location: admin.php');
                    exit;
                }
            } elseif ($action === 'add_report') {
                $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
                $academicYear = trim((string) ($_POST['academic_year'] ?? ''));
                $term = trim((string) ($_POST['term'] ?? ''));
                $names = is_array($_POST['subject_name'] ?? null) ? $_POST['subject_name'] : [];
                $scores = is_array($_POST['score'] ?? null) ? $_POST['score'] : [];
                $grades = is_array($_POST['grade'] ?? null) ? $_POST['grade'] : [];
                $remarks = is_array($_POST['remark'] ?? null) ? $_POST['remark'] : [];
                $subjects = [];

                foreach ($names as $index => $subjectName) {
                    $subjectName = trim((string) $subjectName);
                    $scoreText = trim((string) ($scores[$index] ?? ''));
                    $grade = trim((string) ($grades[$index] ?? ''));
                    $remark = trim((string) ($remarks[$index] ?? ''));

                    if ($subjectName === '' && $scoreText === '' && $grade === '' && $remark === '') {
                        continue;
                    }

                    if ($subjectName === '' || strlen($subjectName) > 100
                        || !is_numeric($scoreText) || (float) $scoreText < 0 || (float) $scoreText > 100
                        || $grade === '' || strlen($grade) > 20
                        || strlen($remark) > 160) {
                        $error = 'Complete each subject row and enter a score from 0 to 100.';
                        break;
                    }

                    $subjects[] = [$subjectName, round((float) $scoreText, 2), $grade, $remark];
                }

                if ($error === '' && (!$studentId || count($subjects) === 0 || count($subjects) > 12
                    || !preg_match('/^\d{4}\/\d{2,4}$/', $academicYear)
                    || $term === '' || strlen($term) > 40)) {
                    $error = 'Choose a student, enter a year and term, and add between 1 and 12 subjects.';
                }

                if ($error === '') {
                    $pdo = db();
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare(
                            'INSERT INTO result_reports (student_id, academic_year, term) VALUES (?, ?, ?)'
                        )->execute([$studentId, $academicYear, $term]);
                        $reportId = (int) $pdo->lastInsertId();
                        $insertSubject = $pdo->prepare(
                            'INSERT INTO subjects (report_id, subject_name, score, grade, remark)
                             VALUES (?, ?, ?, ?, ?)'
                        );

                        foreach ($subjects as $subject) {
                            $insertSubject->execute([$reportId, ...$subject]);
                        }

                        $pdo->commit();
                    } catch (PDOException $exception) {
                        $pdo->rollBack();
                        throw $exception;
                    }

                    $_SESSION['flash'] = 'Result saved. The student can now check it on the student portal.';
                    header('Location: admin.php');
                    exit;
                }
            } else {
                $error = 'That action is not available.';
            }
        }
    } catch (PDOException $exception) {
        error_log((string) $exception);
        $error = 'Could not save or load data. Check the database setup and make sure this record is not already entered.';
    }
}

$loggedIn = isset($_SESSION['teacher_id']);
$students = [];
$recentReports = [];

if ($loggedIn) {
    try {
        $students = db()->query(
            'SELECT id, student_number, name, class_name FROM students ORDER BY student_number'
        )->fetchAll();
        $recentReports = db()->query(
            'SELECT s.student_number, s.name, r.academic_year, r.term
             FROM result_reports r
             INNER JOIN students s ON s.id = r.student_id
             ORDER BY r.id DESC LIMIT 10'
        )->fetchAll();
    } catch (PDOException $exception) {
        error_log((string) $exception);
        $error = 'The database is not available. Check the XAMPP MySQL service and database setup.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Teacher portal | CEO Campus Result Checker</title>
    <link rel="stylesheet" href="styles.css?v=2">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="index.php" aria-label="CEO Campus Result Checker home"><span class="brand-mark" aria-hidden="true">CEO</span><span>CEO Campus <span class="brand-light">Result Checker</span></span></a>
        <a class="header-label" href="index.php">Student portal</a>
    </header>
    <main class="admin-shell">
        <?php if ($error !== ''): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
        <?php if ($flash !== ''): ?><p class="notice" role="status"><?= h($flash) ?></p><?php endif; ?>

        <?php if (!$loggedIn): ?>
            <section class="admin-card">
                <p class="eyebrow">STAFF ONLY</p>
                <h1>Teacher login</h1>
                <p>Sign in to manage students and examination results.</p>
                <form class="admin-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="login">
                    <label>Username<input name="username" autocomplete="username" maxlength="80" required></label>
                    <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
                    <button class="wide" type="submit">Sign in</button>
                </form>
            </section>
        <?php else: ?>
            <form class="logout-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="logout-button" type="submit">Log out</button>
            </form>
            <?php if (is_array($newStudent)): ?>
                <p class="notice" role="status"><strong>Give this student their login privately.</strong><br>
                    Student number: <?= h($newStudent['student_number']) ?><br>
                    One-time result PIN: <strong><?= h($newStudent['pin']) ?></strong>
                </p>
            <?php endif; ?>

            <section class="admin-card">
                <h1>Add a student</h1>
                <p>A unique six-digit PIN is generated and shown once after saving.</p>
                <form class="admin-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_student">
                    <label>Student number<input name="student_number" maxlength="40" required></label>
                    <label>Student name<input name="name" maxlength="160" required></label>
                    <label>Class/year group<input name="class_name" maxlength="80" required></label>
                    <button class="wide" type="submit">Add student</button>
                </form>
            </section>

            <section class="admin-card">
                <h2>Enter a result</h2>
                <?php if (count($students) === 0): ?>
                    <p>Add a student first, then return here to enter their result.</p>
                <?php else: ?>
                    <form class="admin-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_report">
                        <label>Student
                            <select name="student_id" required>
                                <option value="">Choose student</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?= (int) $student['id'] ?>"><?= h($student['student_number'] . ' — ' . $student['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Academic year<input name="academic_year" placeholder="2025/26" pattern="\d{4}/\d{2,4}" required></label>
                        <label class="wide">Term or exam name<input name="term" placeholder="Term 1" maxlength="40" required></label>
                        <div class="wide">
                            <strong>Subjects and marks</strong>
                            <?php for ($i = 0; $i < 6; $i++): ?>
                                <div class="subject-row">
                                    <input name="subject_name[]" aria-label="Subject <?= $i + 1 ?>" placeholder="Subject" maxlength="100" <?= $i === 0 ? 'required' : '' ?>>
                                    <input name="score[]" aria-label="Score <?= $i + 1 ?>" type="number" min="0" max="100" step="0.01" placeholder="Score">
                                    <input name="grade[]" aria-label="Grade <?= $i + 1 ?>" placeholder="Grade" maxlength="20">
                                    <input name="remark[]" aria-label="Remark <?= $i + 1 ?>" placeholder="Remark (optional)" maxlength="160">
                                </div>
                            <?php endfor; ?>
                        </div>
                        <button class="wide" type="submit">Save result</button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="admin-card">
                <h2>Recent results</h2>
                <?php if (count($recentReports) === 0): ?>
                    <p>No results have been entered yet.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Student</th><th>Name</th><th>Year</th><th>Term</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentReports as $report): ?>
                                <tr>
                                    <td><?= h($report['student_number']) ?></td>
                                    <td><?= h($report['name']) ?></td>
                                    <td><?= h($report['academic_year']) ?></td>
                                    <td><?= h($report['term']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
