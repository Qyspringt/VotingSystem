CREATE TABLE /*_*/voting_votes (
    vote_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vote_page_id INT UNSIGNED NOT NULL,
    vote_user_id INT UNSIGNED NOT NULL,
    vote_user_text VARCHAR(255) NOT NULL,
    vote_value DECIMAL(3,1) NOT NULL,
    vote_weight DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    vote_comment_id INT UNSIGNED DEFAULT NULL,
    vote_comment_text TEXT NULL,
    vote_comment_timestamp BINARY(14) DEFAULT NULL,
    vote_hide_score TINYINT NOT NULL DEFAULT 0,
    vote_timestamp BINARY(14) NOT NULL,
    vote_namespace INT NOT NULL DEFAULT 0
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/vote_page_user ON /*_*/voting_votes (vote_page_id, vote_user_id);
CREATE INDEX /*i*/vote_page_id ON /*_*/voting_votes (vote_page_id);
CREATE INDEX /*i*/vote_namespace ON /*_*/voting_votes (vote_namespace);
CREATE INDEX /*i*/vote_timestamp ON /*_*/voting_votes (vote_timestamp);
CREATE INDEX /*i*/vote_user_id ON /*_*/voting_votes (vote_user_id);