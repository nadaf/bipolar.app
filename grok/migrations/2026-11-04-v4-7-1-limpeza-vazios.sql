-- V4.7.1 (0.4.1-beta): apaga linhas VAZIAS deixadas ao limpar estresse e alimentação de um período.
-- Seguro rodar mais de uma vez. Só apaga linhas sem nenhum dado preenchido.
-- Compatível com MySQL 5.7 (HostGator, cPanel).
-- Não cria colunas nem índices, então não deve aparecer #1060 (Duplicate column) nem #1061 (Duplicate key name). Se aparecer, pode ignorar.

DELETE FROM period_markers
 WHERE stress_level IS NULL AND stress_note IS NULL
   AND food_sweets IS NULL AND food_processed IS NULL AND food_binge IS NULL;

DELETE FROM daily_markers
 WHERE bed_time IS NULL AND wake_time IS NULL AND sleep_quality IS NULL AND sun_minutes IS NULL AND sun_period IS NULL
   AND caffeine_cups IS NULL AND alcohol_units IS NULL AND substances IS NULL AND exercise_minutes IS NULL
   AND racing_thoughts IS NULL AND fast_speech IS NULL AND impulsive_spending IS NULL AND spending_amount IS NULL
   AND irritability_day IS NULL AND cycle_phase IS NULL AND stress_level IS NULL AND stress_note IS NULL
   AND food_sweets IS NULL AND food_processed IS NULL AND food_binge IS NULL AND doses_json IS NULL AND signs_json IS NULL;
