<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'app.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'demo_results.php';

$studentNumber = '';
$pin = '';
$error = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentNumber = strtoupper(trim((string) ($_POST['student_number'] ?? '')));
    $pin = trim((string) ($_POST['pin'] ?? ''));

    if ($studentNumber === '' || $pin === '') {
        $error = 'Enter both your student number and result PIN.';
    } elseif (strlen($studentNumber) > 40 || strlen($pin) > 128) {
        $error = 'We could not find a result with those details. Check them and try again.';
    } else {
        foreach ($demoResults as $demoStudent) {
            if (
                hash_equals($demoStudent['student_number'], $studentNumber)
                && hash_equals($demoStudent['pin'], $pin)
            ) {
                $result = $demoStudent;
                break;
            }
        }

        if ($result === null) {
        try {
            $attemptKey = hash(
                'sha256',
                $studentNumber . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
            );
            $limitQuery = db()->prepare(
                'SELECT locked_until FROM student_lookup_limits WHERE attempt_key = ?'
            );
            $limitQuery->execute([$attemptKey]);
            $limit = $limitQuery->fetch();

            if ($limit !== false && $limit['locked_until'] !== null
                && strtotime($limit['locked_until']) > time()) {
                $error = 'Too many attempts. Wait 15 minutes, then try again.';
            } else {
                $query = db()->prepare(
                    'SELECT s.id, s.student_number, s.name, s.class_name, s.pin_hash,
                            r.id AS report_id, r.academic_year, r.term
                     FROM students s
                     INNER JOIN result_reports r ON r.student_id = s.id
                     WHERE s.student_number = ?
                     ORDER BY r.id DESC
                     LIMIT 1'
                );
                $query->execute([$studentNumber]);
                $student = $query->fetch();

                if ($student !== false && password_verify($pin, $student['pin_hash'])) {
                    $subjectsQuery = db()->prepare(
                        'SELECT subject_name AS name, score, grade, remark
                         FROM subjects WHERE report_id = ? ORDER BY id'
                    );
                    $subjectsQuery->execute([$student['report_id']]);
                    $result = [
                        'name' => $student['name'],
                        'student_number' => $student['student_number'],
                        'class' => $student['class_name'],
                        'academic_year' => $student['academic_year'],
                        'term' => $student['term'],
                        'subjects' => $subjectsQuery->fetchAll(),
                    ];
                    db()->prepare('DELETE FROM student_lookup_limits WHERE attempt_key = ?')
                        ->execute([$attemptKey]);
                } else {
                    db()->prepare(
                        'INSERT INTO student_lookup_limits (attempt_key, failures, last_failure)
                         VALUES (?, 1, NOW())
                         ON DUPLICATE KEY UPDATE
                            failures = IF(last_failure < NOW() - INTERVAL 15 MINUTE, 1, failures + 1),
                            locked_until = IF(
                                last_failure < NOW() - INTERVAL 15 MINUTE,
                                NULL,
                                IF(failures >= 4, DATE_ADD(NOW(), INTERVAL 15 MINUTE), locked_until)
                            ),
                            last_failure = NOW()'
                    )->execute([$attemptKey]);
                    $error = 'We could not find a result with those details. Check them and try again.';
                }
            }
        } catch (PDOException $exception) {
            error_log((string) $exception);
            http_response_code(503);
            $error = 'The result service is temporarily unavailable. Please try again later.';
        }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#090909">
    <title>CEO Campus Result Checker</title>
    <link rel="stylesheet" href="styles.css?v=2">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="index.php" aria-label="CEO Campus Result Checker home">
            <span class="brand-mark" aria-hidden="true">CEO</span>
            <span>CEO Campus <span class="brand-light">Result Checker</span></span>
        </a>
        <a class="header-label" href="admin.php">Teacher login</a>
    </header>

    <main class="page-shell">
        <section class="intro" aria-labelledby="page-title">
            <p class="eyebrow">ACADEMIC RECORDS</p>
            <h1 id="page-title">Your hard work,<br><span>in black and gold.</span></h1>
            <p class="intro-copy">Check a fictional demo result or use the student details provided by your school.</p>
            <div class="term-note">
                <span class="status-dot" aria-hidden="true"></span>
                <span>Demo portal <strong>Use a demo login below, or your school's details</strong></span>
            </div>
        </section>

        <section class="checker-card" aria-labelledby="checker-title">
            <?php if ($result !== null): ?>
                <?php
                $total = array_sum(array_column($result['subjects'], 'score'));
                $average = count($result['subjects']) > 0
                    ? round($total / count($result['subjects']))
                    : 0;
                ?>
                <div class="result-heading">
                    <div>
                        <p class="eyebrow">ACADEMIC RESULT</p>
                        <h2 id="checker-title">Result found</h2>
                    </div>
                    <span class="result-badge">Published</span>
                </div>

                <div class="student-summary">
                    <div class="avatar" aria-hidden="true"><?= h(substr($result['name'], 0, 1)) ?></div>
                    <div>
                        <h3><?= h($result['name']) ?></h3>
                        <p><?= h($result['student_number']) ?> <span aria-hidden="true">&middot;</span> <?= h($result['class']) ?></p>
                    </div>
                </div>

                <div class="stats" aria-label="Result summary">
                    <div class="stat">
                        <span class="stat-label">Average</span>
                        <strong><?= $average ?><small>%</small></strong>
                    </div>
                    <div class="stat">
                        <span class="stat-label">Subjects</span>
                        <strong><?= count($result['subjects']) ?></strong>
                    </div>
                    <div class="stat">
                        <span class="stat-label">Academic year</span>
                        <strong class="year-value"><?= h($result['academic_year']) ?></strong>
                    </div>
                </div>

                <div class="table-heading">
                    <h3>Subject breakdown</h3>
                    <span><?= h($result['term']) ?></span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Subject</th>
                                <th scope="col">Score</th>
                                <th scope="col">Grade</th>
                                <th scope="col">Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['subjects'] as $subject): ?>
                                <tr>
                                    <th scope="row"><?= h((string) $subject['name']) ?></th>
                                    <td><?= h((string) $subject['score']) ?>%</td>
                                    <td><span class="grade"><?= h((string) $subject['grade']) ?></span></td>
                                    <td><?= h((string) $subject['remark']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form method="get" class="check-another">
                    <button class="text-button" type="submit">Check another result <span aria-hidden="true">&rarr;</span></button>
                </form>
            <?php else: ?>
                <div class="card-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M7 3.75h7l5 5v11.5H7a2 2 0 0 1-2-2v-12.5a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                        <path d="M14 4v5h5M9 13h6M9 16.5h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <p class="eyebrow">RESULT PORTAL</p>
                <h2 id="checker-title">Check your result</h2>
                <p class="card-copy">Enter the details provided by your school to view your academic record.</p>

                <?php if ($error !== ''): ?>
                    <p class="form-error" role="alert"><?= h($error) ?></p>
                <?php endif; ?>

                <form method="post" action="index.php" class="lookup-form">
                    <label for="student_number">Student number</label>
                    <input
                        id="student_number"
                        name="student_number"
                        type="text"
                        value="<?= h($studentNumber) ?>"
                        placeholder="e.g. STU2026001"
                        autocomplete="username"
                        required
                    >

                    <div class="label-row">
                        <label for="pin">Result PIN</label>
                    </div>
                    <input
                        id="pin"
                        name="pin"
                        type="password"
                        placeholder="Enter your result PIN"
                        autocomplete="current-password"
                        required
                    >

                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <button class="submit-button" type="submit">View my result <span aria-hidden="true">&rarr;</span></button>
                </form>
                <p class="privacy-note"><span aria-hidden="true">&#128274;</span> Your result is only shown when both details match.</p>
            <?php endif; ?>
        </section>

        <section class="demo-notice" aria-label="Fictional demo logins">
            <strong>Fictional demo logins only</strong>
            <p>These accounts show example marks and are not real student records.</p>
            <ul>
                <?php foreach ($demoResults as $demoStudent): ?>
                    <li><?= h($demoStudent['student_number']) ?> — PIN <?= h($demoStudent['pin']) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>

    </main>

    <footer class="site-footer">
        <span>&copy; <?= date('Y') ?> CEO Campus Result Checker</span>
        <span>Need help? Contact your school administrator.</span>
    </footer>
</body>
</html>
