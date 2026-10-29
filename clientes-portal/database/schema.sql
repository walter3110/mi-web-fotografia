CREATE TABLE galleries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(180) NOT NULL,
    event_date DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE access_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    gallery_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_access_codes_hash (code_hash),
    KEY idx_access_codes_gallery (gallery_id),
    CONSTRAINT fk_access_codes_gallery
        FOREIGN KEY (gallery_id) REFERENCES galleries (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE photos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    gallery_id BIGINT UNSIGNED NOT NULL,
    relative_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_photos_relative_path (relative_path),
    KEY idx_photos_gallery (gallery_id),
    CONSTRAINT fk_photos_gallery
        FOREIGN KEY (gallery_id) REFERENCES galleries (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE access_attempts (
    fingerprint_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    PRIMARY KEY (fingerprint_hash),
    KEY idx_access_attempts_blocked_until (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
