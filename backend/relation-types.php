<?php
// Like object types, relation type history belongs to the user who introduced it.
function remember_relation_type($db,$user,$type) {
  query($db,'INSERT INTO user_relation_types(user_id,type) VALUES(?,?) ON DUPLICATE KEY UPDATE type=VALUES(type)',[$user['id'],$type]);
}
function relation_type_options($db,$user) {
  return array_column(query($db,'SELECT type FROM user_relation_types WHERE user_id=? ORDER BY type',[$user['id']])->fetchAll(),'type');
}
function migrate_relation_type_history($db) {
  // Seeded/imported legacy relations without an individual actor audit are not guessed.
  $db->exec("INSERT INTO user_relation_types(user_id,type)
    SELECT a.actor_id,TRIM(JSON_UNQUOTE(JSON_EXTRACT(a.after_json,'$.type')))
    FROM audit a JOIN users u ON u.id=a.actor_id
    WHERE a.kind='关系' AND a.action IN ('新增','修改')
      AND JSON_TYPE(JSON_EXTRACT(a.after_json,'$.type'))='STRING'
      AND CHAR_LENGTH(TRIM(JSON_UNQUOTE(JSON_EXTRACT(a.after_json,'$.type')))) BETWEEN 1 AND 60
      AND (a.action='新增' OR NOT (JSON_EXTRACT(a.before_json,'$.type') <=> JSON_EXTRACT(a.after_json,'$.type')))
    ON DUPLICATE KEY UPDATE type=VALUES(type)");
  query($db,"INSERT INTO settings(`key`,value) VALUES('schema_version','4') ON DUPLICATE KEY UPDATE value='4'");
}
