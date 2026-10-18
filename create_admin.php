<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . DIRECTORY_SEPARATOR . 'app.php';

$username = trim((string) readline('New teacher username: '));
$password = (string) readline('New teacher password (at least 12 characters): ');

if (!preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username)) {
    fwrite(STDERR, "Use 3-80 letters, numbers, dots, dashes, or underscores for the username.\n");
    exit(1);
}

if (strlen($password) < 12) {
    fwrite(STDERR, "The password must be at least 12 characters long.\n");
    exit(1);
}

$statement = db()->prepare('INSERT INTO teachers (username, password_hash) VALUES (?, ?)');

try {
    $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
} catch (PDOException $exception) {
    error_log((string) $exception);
    fwrite(STDERR, "Could not create the teacher account. The username may already exist.\n");
    exit(1);
}

fwrite(STDOUT, "Teacher account created. Delete create_admin.php from the web folder now.\n");
