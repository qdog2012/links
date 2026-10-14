<?php
// Personal type history is recorded only by successful user actions, never by seed data.
function remember_entity_type($db,$user,$type) {
  query($db,'INSERT INTO user_entity_types(user_id,type) VALUES(?,?) ON DUPLICATE KEY UPDATE type=VALUES(type)',[$user['id'],$type]);
}
function entity_type_options($db,$user) {
  return array_column(query($db,'SELECT type FROM user_entity_types WHERE user_id=? ORDER BY type',[$user['id']])->fetchAll(),'type');
}
function migrate_entity_type_history($db) {
  // Restore attribution from full audit history, including types introduced by edits.
  // Imported/seeded legacy objects without per-object actor history are not guessed.
  $db->exec("INSERT INTO user_entity_types(user_id,type)
    SELECT a.actor_id,TRIM(JSON_UNQUOTE(JSON_EXTRACT(a.after_json,'$.type')))
    FROM audit a JOIN users u ON u.id=a.actor_id
    WHERE a.kind='对象' AND a.action IN ('新增','修改')
      AND JSON_TYPE(JSON_EXTRACT(a.after_json,'$.type'))='STRING'
      AND CHAR_LENGTH(TRIM(JSON_UNQUOTE(JSON_EXTRACT(a.after_json,'$.type')))) BETWEEN 1 AND 40
      AND (a.action='新增' OR NOT (JSON_EXTRACT(a.before_json,'$.type') <=> JSON_EXTRACT(a.after_json,'$.type')))
    ON DUPLICATE KEY UPDATE type=VALUES(type)");
  query($db,"INSERT INTO settings(`key`,value) VALUES('schema_version','3') ON DUPLICATE KEY UPDATE value='3'");
}
