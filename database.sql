CREATE DATABASE IF NOT EXISTS student_results
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'student_app'@'localhost'
    IDENTIFIED BY 'Change-this-to-a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON student_results.* TO 'student_app'@'localhost';

USE student_results;

CREATE TABLE IF NOT EXISTS teachers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS teacher_login_limits (
    attempt_key CHAR(64) PRIMARY KEY,
    failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
    first_failure DATETIME NOT NULL,
    last_failure DATETIME NOT NULL,
    locked_until DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_lookup_limits (
    attempt_key CHAR(64) PRIMARY KEY,
    failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_failure DATETIME NOT NULL,
    locked_until DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_number VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    class_name VARCHAR(80) NOT NULL,
    pin_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS result_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    term VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY one_report_per_term (student_id, academic_year, term),
    CONSTRAINT report_student FOREIGN KEY (student_id)
        REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subjects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT UNSIGNED NOT NULL,
    subject_name VARCHAR(100) NOT NULL,
    score DECIMAL(5,2) UNSIGNED NOT NULL,
    grade VARCHAR(20) NOT NULL,
    remark VARCHAR(160) NOT NULL,
    CONSTRAINT subject_report FOREIGN KEY (report_id)
        REFERENCES result_reports (id) ON DELETE CASCADE
) ENGINE=InnoDB;
