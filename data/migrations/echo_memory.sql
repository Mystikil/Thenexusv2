CREATE TABLE IF NOT EXISTS echo_memory (
  id INT AUTO_INCREMENT PRIMARY KEY,
  monster_type VARCHAR(64) NOT NULL,
  spawn_hash VARCHAR(64) NOT NULL,
  fights INT NOT NULL DEFAULT 0,
  total_damage_taken INT NOT NULL DEFAULT 0,
  dmg_taken_physical INT NOT NULL DEFAULT 0,
  dmg_taken_fire INT NOT NULL DEFAULT 0,
  dmg_taken_ice INT NOT NULL DEFAULT 0,
  dmg_taken_earth INT NOT NULL DEFAULT 0,
  dmg_taken_energy INT NOT NULL DEFAULT 0,
  dmg_taken_holy INT NOT NULL DEFAULT 0,
  dmg_taken_death INT NOT NULL DEFAULT 0,
  last_update TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_mem (monster_type, spawn_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

