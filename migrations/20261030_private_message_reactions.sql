-- Run once on existing databases to enable private-chat reactions.
CREATE TABLE IF NOT EXISTS private_message_reactions (
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    emoji VARCHAR(10) NOT NULL,
    reacted_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id, user_id, emoji),
    KEY user_id (user_id),
    CONSTRAINT private_message_reactions_message_fk
        FOREIGN KEY (message_id) REFERENCES private_messages (id) ON DELETE CASCADE,
    CONSTRAINT private_message_reactions_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
