CREATE TABLE /*_*/voting_rewrite_requests (
    request_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_page_id INT UNSIGNED NOT NULL,
    request_user_id INT UNSIGNED NOT NULL,
    request_user_text VARCHAR(255) NOT NULL,
    request_reason TEXT NOT NULL,
    request_status TINYINT NOT NULL DEFAULT 0,
    request_timestamp BINARY(14) NOT NULL,
    request_reviewer_id INT UNSIGNED DEFAULT NULL,
    request_reviewer_text VARCHAR(255) DEFAULT NULL,
    request_review_timestamp BINARY(14) DEFAULT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/request_status ON /*_*/voting_rewrite_requests (request_status);
CREATE INDEX /*i*/request_page_id ON /*_*/voting_rewrite_requests (request_page_id);