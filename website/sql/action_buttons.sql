-- Recursive, self-service "Action Buttons" engine (see
-- includes/action_buttons_helper.php): a user-defined button on any
-- custom_fields module that, for one selected record, opens a popup
-- listing that record's own child records. Each button's synthesized
-- module key ("action_btn_{id}") is used both as the custom_fields
-- linked_to key for its child records AND as parent_module for further
-- buttons defined on it -- that symmetry is what makes nesting recursive
-- with no depth limit and no special-casing per level.
--
-- Safe to run as-is (IF NOT EXISTS everywhere) -- no data, structure only.
-- Alternative to running this by hand: action_buttons_helper.php's
-- functions self-create both tables on first use, same shared-hosting-
-- compatibility pattern other-details.php already uses for its own tables.

CREATE TABLE IF NOT EXISTS action_buttons (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    parent_module VARCHAR(50) NOT NULL,
    label         VARCHAR(100) NOT NULL,
    button_order  INT DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_parent (user_id, parent_module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS action_button_records (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    user_id           INT NOT NULL,
    button_id         INT NOT NULL,
    parent_record_id  INT NOT NULL,
    label             VARCHAR(255) NOT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_button_parent (button_id, parent_record_id),
    FOREIGN KEY (button_id) REFERENCES action_buttons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
