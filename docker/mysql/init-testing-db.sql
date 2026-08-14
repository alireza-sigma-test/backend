CREATE DATABASE IF NOT EXISTS proposal_review_testing
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON proposal_review_testing.* TO 'proposal'@'%';
FLUSH PRIVILEGES;
