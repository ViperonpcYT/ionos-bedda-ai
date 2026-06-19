-- PvP cascade Tier 2 queue columns on roast_pvp_matches (also applied via roast_pvp_migrate_schema).
-- tier2_pending rows are processed by api/roast-limited/cron-tier2.php (IONOS WebCron).

ALTER TABLE roast_pvp_matches ADD COLUMN player_a_live_seed INT UNSIGNED NULL AFTER opponent_npc_id;
ALTER TABLE roast_pvp_matches ADD COLUMN player_b_live_seed INT UNSIGNED NULL AFTER player_a_live_seed;
ALTER TABLE roast_pvp_matches ADD COLUMN player_a_score_tier TINYINT UNSIGNED NULL DEFAULT 0 AFTER player_b_live_seed;
ALTER TABLE roast_pvp_matches ADD COLUMN player_b_score_tier TINYINT UNSIGNED NULL DEFAULT 0 AFTER player_a_score_tier;
ALTER TABLE roast_pvp_matches ADD COLUMN player_a_display_score INT UNSIGNED NULL AFTER player_b_score_tier;
ALTER TABLE roast_pvp_matches ADD COLUMN player_b_display_score INT UNSIGNED NULL AFTER player_a_display_score;
ALTER TABLE roast_pvp_matches ADD COLUMN player_a_tier2_pending TINYINT UNSIGNED NULL DEFAULT 0 AFTER player_b_display_score;
ALTER TABLE roast_pvp_matches ADD COLUMN player_b_tier2_pending TINYINT UNSIGNED NULL DEFAULT 0 AFTER player_a_tier2_pending;
ALTER TABLE roast_pvp_matches ADD COLUMN player_a_tier2_frame_path VARCHAR(255) NULL AFTER player_b_tier2_pending;
ALTER TABLE roast_pvp_matches ADD COLUMN player_b_tier2_frame_path VARCHAR(255) NULL AFTER player_a_tier2_frame_path;
ALTER TABLE roast_pvp_matches ADD COLUMN player_a_tier2_requested_at TIMESTAMP NULL AFTER player_b_tier2_frame_path;
ALTER TABLE roast_pvp_matches ADD COLUMN player_b_tier2_requested_at TIMESTAMP NULL AFTER player_a_tier2_requested_at;
ALTER TABLE roast_pvp_matches ADD COLUMN player_a_tier2_ready TINYINT UNSIGNED NULL DEFAULT 0 AFTER player_b_tier2_requested_at;
ALTER TABLE roast_pvp_matches ADD COLUMN player_b_tier2_ready TINYINT UNSIGNED NULL DEFAULT 0 AFTER player_a_tier2_ready;
