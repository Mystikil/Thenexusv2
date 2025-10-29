function onUpdateDatabase()
    print('Updating database to add faction reputation and economy tables')

    db.query([[CREATE TABLE IF NOT EXISTS `factions` (
        `id` SMALLINT UNSIGNED NOT NULL,
        `name` VARCHAR(64) NOT NULL,
        `description` VARCHAR(255) NOT NULL DEFAULT '',
        `npc_buy_fee` DECIMAL(6,4) NOT NULL DEFAULT 0,
        `npc_sell_fee` DECIMAL(6,4) NOT NULL DEFAULT 0,
        `market_fee` DECIMAL(6,4) NOT NULL DEFAULT 0,
        `trade_buy_factor` DECIMAL(10,6) NOT NULL DEFAULT 0,
        `trade_sell_factor` DECIMAL(10,6) NOT NULL DEFAULT 0,
        `donation_multiplier` DECIMAL(10,6) NOT NULL DEFAULT 1,
        `kill_penalty` INT NOT NULL DEFAULT 0,
        `decay_per_week` INT NOT NULL DEFAULT 0,
        `soft_cap` INT NOT NULL DEFAULT 0,
        `hard_cap` INT NOT NULL DEFAULT 0,
        `soft_diminish` DECIMAL(6,4) NOT NULL DEFAULT 1,
        `created_at` INT NOT NULL DEFAULT 0,
        `updated_at` INT NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_factions_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;]])

    db.query([[CREATE TABLE IF NOT EXISTS `npc_factions` (
        `npc_name` VARCHAR(64) NOT NULL,
        `faction_id` SMALLINT UNSIGNED NOT NULL,
        PRIMARY KEY (`npc_name`),
        KEY `idx_npc_faction` (`faction_id`),
        CONSTRAINT `fk_npc_faction` FOREIGN KEY (`faction_id`) REFERENCES `factions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;]])

    db.query([[CREATE TABLE IF NOT EXISTS `player_faction_reputation` (
        `player_id` INT NOT NULL,
        `faction_id` SMALLINT UNSIGNED NOT NULL,
        `reputation` INT NOT NULL DEFAULT 0,
        `last_activity` INT NOT NULL DEFAULT 0,
        `last_decay` INT NOT NULL DEFAULT 0,
        PRIMARY KEY (`player_id`, `faction_id`),
        KEY `idx_faction_player` (`faction_id`, `player_id`),
        CONSTRAINT `fk_rep_player` FOREIGN KEY (`player_id`) REFERENCES `players`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_rep_faction` FOREIGN KEY (`faction_id`) REFERENCES `factions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;]])

    db.query([[CREATE TABLE IF NOT EXISTS `player_faction_reputation_log` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `player_id` INT NOT NULL,
        `faction_id` SMALLINT UNSIGNED NOT NULL,
        `delta` INT NOT NULL,
        `source` VARCHAR(64) NOT NULL,
        `context` TEXT NOT NULL,
        `created_at` INT NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_rep_log_player` (`player_id`),
        KEY `idx_rep_log_faction` (`faction_id`),
        CONSTRAINT `fk_rep_log_player` FOREIGN KEY (`player_id`) REFERENCES `players`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_rep_log_faction` FOREIGN KEY (`faction_id`) REFERENCES `factions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;]])

    db.query([[CREATE TABLE IF NOT EXISTS `faction_economy` (
        `faction_id` SMALLINT UNSIGNED NOT NULL,
        `pool` BIGINT NOT NULL DEFAULT 0,
        `updated_at` INT NOT NULL DEFAULT 0,
        PRIMARY KEY (`faction_id`),
        CONSTRAINT `fk_economy_faction` FOREIGN KEY (`faction_id`) REFERENCES `factions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;]])

    db.query([[CREATE TABLE IF NOT EXISTS `faction_economy_history` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `faction_id` SMALLINT UNSIGNED NOT NULL,
        `delta` BIGINT NOT NULL,
        `reason` VARCHAR(128) NOT NULL,
        `reference_id` INT NOT NULL DEFAULT 0,
        `created_at` INT NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_economy_history_faction` (`faction_id`),
        CONSTRAINT `fk_economy_history_faction` FOREIGN KEY (`faction_id`) REFERENCES `factions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;]])

    db.query([[CREATE TABLE IF NOT EXISTS `faction_economy_ledger` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `faction_id` SMALLINT UNSIGNED NOT NULL,
        `delta` BIGINT NOT NULL,
        `reason` VARCHAR(128) NOT NULL,
        `reference_id` INT NOT NULL DEFAULT 0,
        `created_at` INT NOT NULL,
        `processed` TINYINT(1) NOT NULL DEFAULT 0,
        `processed_at` INT NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_economy_ledger_processed` (`processed`, `id`),
        CONSTRAINT `fk_economy_ledger_faction` FOREIGN KEY (`faction_id`) REFERENCES `factions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;]])

    db.query([[CREATE TABLE IF NOT EXISTS `faction_market_cursor` (
        `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `last_history_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `updated_at` INT NOT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;]])

    -- Seed factions
    db.query([[INSERT INTO `factions` (`id`, `name`, `description`, `npc_buy_fee`, `npc_sell_fee`, `market_fee`, `trade_buy_factor`, `trade_sell_factor`, `donation_multiplier`, `kill_penalty`, `decay_per_week`, `soft_cap`, `hard_cap`, `soft_diminish`, `created_at`, `updated_at`)
        VALUES
            (1, 'Traders Guild', 'Merchants who control the central exchange and most caravans.', 0.0200, 0.0300, 0.0400, 0.0015, 0.0010, 2.0, 150, 100, 6000, 7500, 0.50, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
            (2, 'Artisan Assembly', 'Craftspeople seeking rare materials and bespoke commissions.', 0.0150, 0.0200, 0.0300, 0.0010, 0.0012, 3.0, 200, 50, 6500, 8000, 0.45, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
            (3, 'Central Exchange', 'Neutral clearing house for remote settlements; redistributes nightly.', 0.0000, 0.0000, 0.0500, 0.0, 0.0, 1.0, 0, 0, 0, 0, 0, 1.00, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            `description` = VALUES(`description`),
            `npc_buy_fee` = VALUES(`npc_buy_fee`),
            `npc_sell_fee` = VALUES(`npc_sell_fee`),
            `market_fee` = VALUES(`market_fee`),
            `trade_buy_factor` = VALUES(`trade_buy_factor`),
            `trade_sell_factor` = VALUES(`trade_sell_factor`),
            `donation_multiplier` = VALUES(`donation_multiplier`),
            `kill_penalty` = VALUES(`kill_penalty`),
            `decay_per_week` = VALUES(`decay_per_week`),
            `soft_cap` = VALUES(`soft_cap`),
            `hard_cap` = VALUES(`hard_cap`),
            `soft_diminish` = VALUES(`soft_diminish`),
            `updated_at` = UNIX_TIMESTAMP();]])

    db.query([[INSERT INTO `faction_economy` (`faction_id`, `pool`, `updated_at`)
        VALUES
            (1, 50000, UNIX_TIMESTAMP()),
            (2, 30000, UNIX_TIMESTAMP()),
            (3, 100000, UNIX_TIMESTAMP())
        ON DUPLICATE KEY UPDATE `pool` = VALUES(`pool`), `updated_at` = VALUES(`updated_at`)]])

    db.query([[INSERT INTO `npc_factions` (`npc_name`, `faction_id`) VALUES ('Faction Quartermaster', 1)
        ON DUPLICATE KEY UPDATE `faction_id` = VALUES(`faction_id`);]])

    return true
end
